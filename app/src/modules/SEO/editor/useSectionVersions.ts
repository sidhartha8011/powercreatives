/**
 * THE VERSIONS MACHINERY (decomposition S4a, gap d0d063c): version rows
 * (page + section), the lazily-fetched Original with its honest states,
 * picking, labels, single + bulk deletion — one owner. Moved verbatim
 * from SectionModal.tsx; a versions bug lives in THIS file.
 */

import { useState } from 'react';
import { toast } from 'sonner';
import type { Editor } from '@tiptap/core';

import { trpc } from '@/lib/trpc';
import type { SectionData } from './types';

export interface UseSectionVersionsArgs {
  /** Ref, not instance: the hook mounts BEFORE useEditor (the instant-open
   *  block needs the version rows) — picks resolve the editor at click time. */
  editorRef: { current: Editor | null };
  isPage: boolean;
  isInsert: boolean;
  readOnly: boolean;
  siteId: number | 'local';
  postId: number;
  section?: SectionData;
  /** The page row's own date — the Original row's label. */
  pageDate?: string;
  /** Section mode's original (live from the scan) — page mode fetches its own. */
  sectionOriginalHtml: string;
}

export function useSectionVersions(args: UseSectionVersionsArgs) {
  const { editorRef, isPage, isInsert, readOnly, siteId, postId, section, pageDate, sectionOriginalHtml } = args;

  // Page versions — ROWS ONLY (gap 02d3cb7 D1): a pure DB read in
  // milliseconds; the OPEN gates on this. The Original (a remote snapshot
  // round-trip) loads LAZILY below, only when the dropdown opens — gating
  // the open on it was the 30-second white screen.
  const pageVersionsQuery = trpc.seo.remotePageVersions.useQuery(
    { siteId: siteId as number, postId, rowsOnly: 1 },
    { enabled: isPage && !readOnly, staleTime: 0 },
  );
  const [versionsOpen, setVersionsOpen] = useState(false);
  const pageOriginalQuery = trpc.seo.remotePageVersions.useQuery(
    { siteId: siteId as number, postId },
    { enabled: isPage && !readOnly && versionsOpen, staleTime: 60_000 },
  );
  /** Page mode's saved documents, newest first — W0 (2026-07-16): the open
   *  loads [0]; the versions dropdown lists the same rows. */
  const pageVersions: Array<{ id: number; replacement: string; createdAt: string }> =
    isPage && Array.isArray((pageVersionsQuery.data as any)?.versions)
      ? (pageVersionsQuery.data as any).versions
      : [];
  /** Settled = answered, either way — the one-time load must not wait forever
   *  on a failed versions read (the assembly is then the honest fallback). */
  const pageVersionsSettled = pageVersionsQuery.isSuccess || pageVersionsQuery.isError;

  /** The TRUE original (no rules applied): section mode = live from the scan;
   *  page mode = the hub-assembled rules-input document ('' = honest unavailable). */
  const originalHtml = isPage
    ? String((pageOriginalQuery.data as any)?.originalHtml ?? '')
    : sectionOriginalHtml;

  // ── Version history (replace-sections only — inserts have no Original). ──
  const versionsQuery = trpc.seo.remoteSectionVersions.useQuery(
    {
      siteId: siteId as number, postId,
      text: section?.heading.text ?? '', occurrence: section?.heading.occurrence ?? 0,
    },
    { enabled: !readOnly && !isInsert && !!section, staleTime: 0 },
  );
  const versions: Array<{ id: number; replacement: string; createdAt: string }> = isPage
    ? pageVersions
    : Array.isArray((versionsQuery.data as any)?.versions) ? (versionsQuery.data as any).versions : [];
  /** '' = viewing the current state; 'original' | version id as string. */
  const [versionPick, setVersionPick] = useState('');
  const deleteVersionMutation = trpc.seo.remoteDeleteSectionVersion.useMutation();
  const pickVersion = (v: string) => {
    setVersionPick(v);
    setVersionsOpen(false);
    if (v === 'original') editorRef.current?.commands.setContent(originalHtml);
    else if (v !== '') {
      const row = versions.find((x) => String(x.id) === v);
      if (row) editorRef.current?.commands.setContent(row.replacement);
    }
  };
  /** Page rows (owner order 2026-07-11): NO separate Current choice — the
   *  newest saved version IS what the site serves and carries the suffix;
   *  the Original row shows the page's own date. When the newest save IS a
   *  restore of the original, the label SAYS so (2026-07-13, live-caught:
   *  a perfect restore looked like "another version" and read as a bug). */
  const normDoc = (h: string) => h.replace(/\s*data-pcm-origin="[^"]*"/g, '').replace(/\s+/g, ' ').trim();
  const currentIsOriginal = versions.length > 0 && originalHtml !== ''
    && normDoc(String((versions[0] as any).replacement ?? '')) === normDoc(originalHtml);
  const pageRowLabel = (v: { createdAt: string }, idx: number) =>
    `${v.createdAt.slice(0, 16)}${idx === 0 ? (currentIsOriginal ? ' (Current — original)' : ' (Current)') : ''}`;
  const pageOriginalLabel = pageDate ? `${String(pageDate).slice(0, 16)} (original)` : 'Original';
  /** The dropdown ALWAYS names a state (owner law — never a counter).
   *  A function of the live dirty flag — the composer computes that after
   *  the editor mounts, the label stays one formula here. */
  const hasActiveRule = !isInsert && !isPage && !!section?.sectionRuleReplacement;
  const versionLabelFor = (pageDirty: boolean): string => (versionPick === 'original'
    ? (isPage ? pageOriginalLabel : 'Original')
    : versionPick !== ''
      ? (isPage
        ? (versions.some((x) => String(x.id) === versionPick)
          ? pageRowLabel(
            versions[versions.findIndex((x) => String(x.id) === versionPick)],
            versions.findIndex((x) => String(x.id) === versionPick),
          )
          : 'Version')
        : (versions.find((v) => String(v.id) === versionPick)?.createdAt.slice(0, 16) ?? 'Version'))
      : isPage && pageDirty
        ? 'Draft (unsaved)'
      : isPage
        ? (versions.length > 0 ? pageRowLabel(versions[0], 0) : pageOriginalLabel)
        : (hasActiveRule && versions.length > 0 ? versions[0].createdAt.slice(0, 16) : 'Original'));
  const deleteVersion = async (id: number) => {
    try {
      await deleteVersionMutation.mutateAsync({ siteId: siteId as number, postId, versionId: id });
      if (versionPick === String(id)) setVersionPick('');
      await (isPage ? pageVersionsQuery : versionsQuery).refetch();
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not delete the version');
    }
  };
  // ── Bulk version cleanup (owner order 2026-07-16): tick many / select
  //    all, ONE delete. The CURRENT version (idx 0) is excluded from
  //    select-all and carries no checkbox — deleting what the editor opens
  //    from would recreate the old-version trap (W0). Sequential deletes
  //    (the bulk-status precedent), one refetch, one honest summary. ──
  const [versionSel, setVersionSel] = useState<Set<number>>(new Set());
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const toggleVersionSel = (id: number) => setVersionSel((cur) => {
    const next = new Set(cur);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
  });
  const deletableVersionIds = versions.slice(1).map((v) => Number(v.id));
  const deleteSelectedVersions = async () => {
    const ids = Array.from(versionSel);
    if (ids.length === 0 || bulkDeleting) return;
    setBulkDeleting(true);
    let failed = 0;
    for (const id of ids) {
      try {
        await deleteVersionMutation.mutateAsync({ siteId: siteId as number, postId, versionId: id });
      } catch {
        failed++;
      }
    }
    setBulkDeleting(false);
    setVersionSel(new Set());
    if (ids.some((id) => versionPick === String(id))) setVersionPick('');
    await (isPage ? pageVersionsQuery : versionsQuery).refetch();
    if (failed > 0) toast.error(`${failed} of ${ids.length} versions could not be deleted — the rest are gone.`);
    else toast.success(`${ids.length} version${ids.length === 1 ? '' : 's'} deleted.`);
  };

  return {
    pageVersionsQuery, pageOriginalQuery, pageVersions, pageVersionsSettled,
    versionsOpen, setVersionsOpen, originalHtml,
    versionsQuery, versions, versionPick, setVersionPick, pickVersion,
    pageRowLabel, pageOriginalLabel, hasActiveRule, versionLabelFor,
    deleteVersion, versionSel, setVersionSel, bulkDeleting, toggleVersionSel,
    deletableVersionIds, deleteSelectedVersions,
  };
}
