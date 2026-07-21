/**
 * useWriterPersistence — Syncs Jotai Writer store with the DB backend.
 *
 * Responsibilities:
 *   1. Load persisted articles from DB on mount  (writer.list → writerDocumentsAtom)
 *   2. Create new articles server-side when added locally (writer.create)
 *   3. Autosave on change with debounce           (writer.update)
 *   4. Delete server-side when removed locally    (writer.delete)
 *
 * Architecture: This is a "dumb bridge" hook — it observes Jotai atom changes
 * and syncs them to the backend. No UI logic lives here.
 *
 * ID Strategy: DB uses auto-increment int; frontend uses UUID strings.
 * We maintain a bidirectional map (localId ↔ serverId) so the frontend
 * never has to change its ID model. The serverId is stored as a property
 * on each WriterDocument for backend round-trips.
 */

import { useEffect, useRef, useCallback } from 'react';
import { useAtom, useSetAtom } from 'jotai';
import {
  writerDocumentsAtom,
  activeDocumentIdAtom,
  type WriterDocument,
} from '../store';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';

// ── Types ──

/** DB article shape returned by REST API (camelCase from PHP) */
interface ServerArticle {
  id: number;
  userId: number;
  strategyId: number | null;
  brandId: number | null;
  title: string;
  slug: string | null;
  content: string | null;
  metaTitle: string | null;
  metaDescription: string | null;
  schemaType: string;
  status: string;
  featuredImage: string | null;
  seoScore: number | null;
  publishedUrl: string | null;
  publishedPostId: number | null;
  siteId: number | null;
  createdAt: string;
  updatedAt: string;
}

// ── Helpers ──

/** Convert a server article row into a WriterDocument for the Jotai store */
function serverToLocal(article: ServerArticle): WriterDocument {
  return {
    // Use the server ID as string — this is the canonical ID after load
    id: String(article.id),
    title: article.title || '',
    slug: article.slug || '',
    content: article.content || '',
    metaTitle: article.metaTitle || '',
    metaDescription: article.metaDescription || '',
    schemaType: (article.schemaType as 'Article' | 'WebPage') || 'Article',
    status: (article.status as WriterDocument['status']) || 'draft',
    featuredImage: article.featuredImage || '',
    projectId: article.strategyId ? String(article.strategyId) : '',
    projectName: '',
    // Hydrate the article's Target Site into the doc settings — without this,
    // every server-loaded article read as "no Target Site selected" and the
    // Publish button stayed disabled even though the row carries a siteId
    // (strategy-generated articles always do). Partial objects are safe: the
    // settings panel reads `doc.generationSettings || {}` and merges writes.
    ...(article.siteId ? { generationSettings: { siteId: article.siteId } } : {}),
    // Preserve the server ID for future API calls
    _serverId: article.id,
  } as WriterDocument & { _serverId: number };
}

/** Extract PATCH-able fields from a WriterDocument for the update endpoint */
function localToUpdatePayload(doc: WriterDocument): Record<string, unknown> {
  return {
    title: doc.title,
    slug: doc.slug,
    content: doc.content,
    metaTitle: doc.metaTitle,
    metaDescription: doc.metaDescription,
    schemaType: doc.schemaType,
    status: doc.status,
    featuredImage: doc.featuredImage,
    // Persist the Target Site picked in the settings panel (round-trips the
    // hydration above; the writer PATCH whitelists siteId server-side).
    ...(doc.generationSettings?.siteId ? { siteId: doc.generationSettings.siteId } : {}),
  };
}

// ── Constants ──

/** Debounce delay for autosave (ms) — prevents hammering the server on every keystroke */
const AUTOSAVE_DEBOUNCE_MS = 2_000;

// ── Hook ──

export function useWriterPersistence() {
  const [documents, setDocuments] = useAtom(writerDocumentsAtom);
  const setActiveDocumentId = useSetAtom(activeDocumentIdAtom);

  // Track which local IDs have been created on the server
  // Map<localUUID, serverIntId>
  const idMapRef = useRef<Map<string, number>>(new Map());
  // Track which IDs we've already loaded from server to avoid double-load
  const loadedRef = useRef(false);
  // Debounce timers per document ID
  const debounceTimersRef = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map());
  // Previous document snapshots for dirty-checking
  const prevSnapshotsRef = useRef<Map<string, string>>(new Map());

  // ── Mutations ──
  const createMutation = trpc.writer.create.useMutation({
    onError: (err: any) => {
      console.error('[WriterPersistence] Create failed:', err);
      toast.error('Failed to save new article to server.');
    },
  });

  const updateMutation = trpc.writer.update.useMutation({
    onError: (err: any) => {
      console.error('[WriterPersistence] Update failed:', err);
      // Silent — autosave failures should not disrupt the user
    },
  });

  const deleteMutation = trpc.writer.delete.useMutation({
    onError: (err: any) => {
      console.error('[WriterPersistence] Delete failed:', err);
    },
  });

  // ── Load from DB on mount ──
  const listQuery = trpc.writer.list.useQuery(undefined, {
    enabled: !loadedRef.current,
    staleTime: 30_000,
  });

  useEffect(() => {
    if (loadedRef.current || !listQuery.data) return;
    loadedRef.current = true;

    const serverArticles = listQuery.data as ServerArticle[];
    if (!serverArticles || serverArticles.length === 0) return;

    // Convert server articles to local WriterDocuments
    const loaded = serverArticles.map(serverToLocal);

    // Build the ID map (server IDs are used as string IDs after load)
    loaded.forEach((doc) => {
      const serverDoc = doc as WriterDocument & { _serverId?: number };
      if (serverDoc._serverId) {
        idMapRef.current.set(doc.id, serverDoc._serverId);
      }
    });

    // Merge with any locally-created docs that might already exist
    setDocuments((existing) => {
      if (existing.length === 0) {
        // Simple case: no local docs yet — just set loaded ones
        return loaded;
      }

      // Merge: keep local-only docs, add server docs that aren't already present
      const existingIds = new Set(existing.map((d) => d.id));
      const newFromServer = loaded.filter((d) => !existingIds.has(d.id));
      return [...existing, ...newFromServer];
    });

    // Set first article as active if nothing is selected
    if (loaded.length > 0) {
      setActiveDocumentId((current) => current ?? loaded[0].id);
    }
  }, [listQuery.data, setDocuments, setActiveDocumentId]);

  // ── Create server-side article for new local docs ──
  const createOnServer = useCallback(
    async (doc: WriterDocument) => {
      // Skip if already mapped to a server ID
      if (idMapRef.current.has(doc.id)) return;

      try {
        const result = await createMutation.mutateAsync({
          title: doc.title || 'Untitled',
          slug: doc.slug || '',
          content: doc.content || '',
          metaTitle: doc.metaTitle || '',
          metaDescription: doc.metaDescription || '',
          schemaType: doc.schemaType || 'Article',
          featuredImage: doc.featuredImage || '',
        });

        const serverArticle = result as ServerArticle;
        if (serverArticle?.id) {
          idMapRef.current.set(doc.id, serverArticle.id);
        }
      } catch {
        // Error already handled by mutation onError
      }
    },
    [createMutation],
  );

  // ── Autosave: debounced update to server ──
  const saveToServer = useCallback(
    (doc: WriterDocument) => {
      const serverId = idMapRef.current.get(doc.id);
      if (!serverId) return; // Not yet created on server

      // Clear any existing debounce timer for this doc
      const existing = debounceTimersRef.current.get(doc.id);
      if (existing) clearTimeout(existing);

      const timer = setTimeout(() => {
        // Dirty check — only send if content actually changed
        const snapshot = JSON.stringify(localToUpdatePayload(doc));
        const prev = prevSnapshotsRef.current.get(doc.id);
        if (snapshot === prev) return;

        prevSnapshotsRef.current.set(doc.id, snapshot);

        updateMutation.mutate({
          id: serverId,
          ...localToUpdatePayload(doc),
        });
      }, AUTOSAVE_DEBOUNCE_MS);

      debounceTimersRef.current.set(doc.id, timer);
    },
    [updateMutation],
  );

  // ── Watch documents for changes and sync ──
  const prevDocsRef = useRef<WriterDocument[]>([]);

  useEffect(() => {
    if (!loadedRef.current) return; // Wait until initial load completes

    const prev = prevDocsRef.current;
    const curr = documents;
    prevDocsRef.current = curr;

    // Detect new documents (present in curr but not in prev)
    const prevIds = new Set(prev.map((d) => d.id));
    const currIds = new Set(curr.map((d) => d.id));

    curr.forEach((doc) => {
      if (!prevIds.has(doc.id)) {
        // New document — create on server
        createOnServer(doc);
      } else {
        // Existing document — check if it changed and autosave
        saveToServer(doc);
      }
    });

    // Detect deleted documents (present in prev but not in curr)
    prev.forEach((doc) => {
      if (!currIds.has(doc.id)) {
        const serverId = idMapRef.current.get(doc.id);
        if (serverId) {
          deleteMutation.mutate({ id: serverId });
          idMapRef.current.delete(doc.id);
          prevSnapshotsRef.current.delete(doc.id);
        }
        // Clean up debounce timer
        const timer = debounceTimersRef.current.get(doc.id);
        if (timer) {
          clearTimeout(timer);
          debounceTimersRef.current.delete(doc.id);
        }
      }
    });
  }, [documents, createOnServer, saveToServer, deleteMutation]);

  // ── Cleanup debounce timers on unmount ──
  useEffect(() => {
    return () => {
      debounceTimersRef.current.forEach((timer) => clearTimeout(timer));
      debounceTimersRef.current.clear();
    };
  }, []);

  return {
    /** True while initial article list is loading from the server */
    isLoading: listQuery.isLoading,
    /** True if there was an error loading articles from the server */
    isError: listQuery.isError,
    /** Number of documents tracked by the persistence layer */
    trackedCount: idMapRef.current.size,
  };
}
