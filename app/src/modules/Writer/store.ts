import { atom } from 'jotai';
import type { StatusKey } from '@/components/shared/design-tokens';

// ─── Document model ──────────────────────────────────────
export interface WriterGenerationSettings {
  brandId?: number;
  templateId?: number;
  primaryKeyword?: string;
  supportingKeywords?: string;
  format?: string;
  perspective?: string;
  toneOfVoice?: string;
  contentHierarchyParent?: string;
  contentHierarchyChildren?: string[];
  customPromptId?: number;
  customPromptText?: string;
  groundWithLiveSerp?: boolean;
  [key: string]: any;
}

/** Represents a document in the Writer queue */
export interface WriterDocument {
  id: string;
  title: string;
  slug: string;
  /** HTML content for Tiptap editor */
  content: string;
  metaTitle: string;
  metaDescription: string;
  schemaType: 'Article' | 'WebPage';
  status: StatusKey;
  featuredImage: string;
  projectId: string;
  projectName: string;
  generationSettings?: WriterGenerationSettings;
}

// ─── Base atoms ──────────────────────────────────────────
/** All documents in the Writer queue. Starts empty — populated via Keywords transfer or manual creation. */
export const writerDocumentsAtom = atom<WriterDocument[]>([]);

/** ID of the currently active/selected document. Null when queue is empty. */
export const activeDocumentIdAtom = atom<string | null>(null);

/** Active project filter for queue panel */
export const activeProjectIdAtom = atom<string | null>(null);

/** Selected model ID for generation. Synced from Settings defaultWriterModel on mount. */
export const writerSelectedModelAtom = atom<string>('auto');

// ─── Derived atoms (read-only) ──────────────────────────
/** The currently active document object */
export const activeDocumentAtom = atom((get) => {
  const docs = get(writerDocumentsAtom);
  const activeId = get(activeDocumentIdAtom);
  return docs.find((d) => d.id === activeId) || null;
});

/** Documents filtered by active project (null = show all) */
export const filteredDocumentsAtom = atom((get) => {
  const docs = get(writerDocumentsAtom);
  const projectId = get(activeProjectIdAtom);
  if (!projectId) return docs;
  return docs.filter((d) => d.projectId === projectId);
});

/** Unique projects extracted from the document list for dropdown */
export const uniqueProjectsAtom = atom((get) => {
  const docs = get(writerDocumentsAtom);
  const projectsMap = new Map<string, string>();
  docs.forEach((d) => {
    if (!projectsMap.has(d.projectId)) {
      projectsMap.set(d.projectId, d.projectName);
    }
  });
  return Array.from(projectsMap.entries()).map(([id, name]) => ({ id, name }));
});

// ─── Writable derived atom ──────────────────────────────
/**
 * Updates a single field on the active document.
 * Usage: const updateDoc = useSetAtom(updateActiveDocumentAtom);
 * updateDoc({ title: 'New title' })
 * updateDoc({ generationSettings: { ...activeDoc.generationSettings, format: 'listicle' } })
 */
export const updateActiveDocumentAtom = atom(
  null,
  (get, set, update: Partial<WriterDocument>) => {
    const activeId = get(activeDocumentIdAtom);
    if (!activeId) return;

    const docs = get(writerDocumentsAtom);
    const updated = docs.map((doc) =>
      doc.id === activeId ? { ...doc, ...update } : doc,
    );
    set(writerDocumentsAtom, updated);
  },
);

/**
 * Adds a new document to the queue and sets it as active.
 * Used by cross-module transfers (e.g., Keywords → Writer).
 */
export const addDocumentAtom = atom(
  null,
  (get, set, newDoc: WriterDocument) => {
    const docs = get(writerDocumentsAtom);
    set(writerDocumentsAtom, [newDoc, ...docs]);
    set(activeDocumentIdAtom, newDoc.id);
  },
);

/** Batch-add documents. First doc becomes active. */
export const addDocumentsAtom = atom(
  null,
  (get, set, newDocs: WriterDocument[]) => {
    if (newDocs.length === 0) return;
    const docs = get(writerDocumentsAtom);
    set(writerDocumentsAtom, [...newDocs, ...docs]);
    set(activeDocumentIdAtom, newDocs[0].id);
  },
);

/**
 * Remove a document from the queue by ID.
 * Auto-selects the next available document (or null if empty).
 */
export const removeDocumentAtom = atom(
  null,
  (get, set, docId: string) => {
    const docs = get(writerDocumentsAtom);
    const activeId = get(activeDocumentIdAtom);
    const index = docs.findIndex((d) => d.id === docId);
    const remaining = docs.filter((d) => d.id !== docId);

    set(writerDocumentsAtom, remaining);

    // If we removed the active doc, select the closest neighbor
    if (activeId === docId) {
      if (remaining.length === 0) {
        set(activeDocumentIdAtom, null);
      } else {
        // Select the item at the same index (or last if we removed the last)
        const nextIndex = Math.min(index, remaining.length - 1);
        set(activeDocumentIdAtom, remaining[nextIndex].id);
      }
    }
  },
);
