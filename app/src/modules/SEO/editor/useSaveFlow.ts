/**
 * THE SAVE FLOW (decomposition final squeeze, gap d0d063c): page-document
 * saves (hub slices back into sections, honest per-kind summary, the
 * corner's seeded verdict) and section/insert/slice rule saves — one
 * owner. Moved verbatim from SectionModal.tsx; a save bug lives HERE.
 */

import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';
import type { Editor } from '@tiptap/core';

import { trpc } from '@/lib/trpc';
import { stripDiffHtml } from '../word-diff';
import type { InsertData, SectionAnchor, SectionData } from './types';

export type SaveBusyAction = 'save' | 'saveClose' | 'remove';

export interface UseSaveFlowArgs {
  editor: Editor | null;
  isPage: boolean;
  isInsert: boolean;
  readOnly: boolean;
  docLoaded: boolean;
  reviewOpen: boolean;
  busy: boolean;
  siteId: number | 'local';
  postId: number;
  pageType: string;
  section?: SectionData;
  insert?: InsertData;
  anchors?: SectionAnchor[];
  anchorIdx: number;
  position: 'before' | 'after';
  savedHtml: string;
  setSavedHtml: (html: string) => void;
  setVersionPick: (v: string) => void;
  refetchPageVersions: () => void;
  refetchSectionVersions: () => void;
  setBusyAction: (action: SaveBusyAction | null) => void;
  onSaved: () => void;
}

export function useSaveFlow(args: UseSaveFlowArgs) {
  const {
    editor, isPage, isInsert, readOnly, docLoaded, reviewOpen, busy,
    siteId, postId, pageType, section, insert, anchors, anchorIdx, position,
    savedHtml, setSavedHtml, setVersionPick,
    refetchPageVersions, refetchSectionVersions, setBusyAction, onSaved,
  } = args;

  const queryClient = useQueryClient();
  const saveMutation = trpc.seo.remoteSaveSectionRule.useMutation();
  const savePageMutation = trpc.seo.remoteSavePageEdits.useMutation();

  const isDirty = () => !readOnly && !!editor && editor.getHTML() !== savedHtml;

  // ── Save (save buttons / click outside): live rules, engine handles
  //    UPSERT/revert. `action` names the control that invoked it — that one
  //    alone shows the working state. ──
  const save = async (replacementOverride?: string, action: SaveBusyAction = 'saveClose'): Promise<boolean> => {
    if (readOnly) return true;
    if (busy) return false; // one long action at a time — a guarded click is a no-op
    if (isPage) {
      if (!docLoaded) return true; // nothing loaded — nothing to save
      if (reviewOpen) {
        toast.info('Finish the AI review first — accept or reject each change.');
        return false;
      }
      // Guard: diff marks are presentation and must NEVER reach a save.
      const html = stripDiffHtml(replacementOverride ?? (editor?.getHTML() ?? ''));
      setBusyAction(action);
      try {
        // The hub slices the document back into sections and routes each
        // change through the existing rule paths — page-level editing,
        // section-level storage. Removals/hides are reversible via versions.
        const res: any = await savePageMutation.mutateAsync({ siteId: siteId as number, postId, html });
        onSaved();
        const parts: string[] = [];
        const n = (k: string) => Number(res?.[k] ?? 0);
        if (n('saved') > 0) parts.push(`${n('saved')} section${n('saved') === 1 ? '' : 's'} updated`);
        if (n('inserted') > 0) parts.push(`${n('inserted')} added`);
        if (n('removed') > 0) parts.push(`${n('removed')} removed`);
        if (n('restored') > 0) parts.push(`${n('restored')} restored`);
        if (n('hidden') > 0) parts.push(`${n('hidden')} image${n('hidden') === 1 ? '' : 's'} hidden`);
        if (n('unhidden') > 0) parts.push(`${n('unhidden')} image${n('unhidden') === 1 ? '' : 's'} back`);
        toast.success(parts.length > 0
          ? `Saved — ${parts.join(', ')} (page/CDN caches may need a purge).`
          : 'No content changes to save.');
        (Array.isArray(res?.notes) ? res.notes : []).forEach((nn: string) => toast.info(nn));
        setSavedHtml(html);
        setVersionPick('');
        // THE CORNER'S TRUTH (gap ATOMIC-SAVE): a PUSHED save is the site's
        // confirmed state — the connector accepted the commit push and echoed
        // this exact record — so the verdict is seeded from the save itself
        // (no 15-30s re-ask of what was just confirmed). A no-op save
        // (pushed=false) confirmed nothing and must not touch the verdict.
        if (res?.pushed === true && res?.pageState) {
          queryClient.setQueryData(['seo', 'pageState', { siteId: siteId as number, postId }], (prev: any) => ({
            brandId: prev?.brandId ?? 0,
            pageType: prev?.pageType ?? pageType,
            local: res.pageState,
            remote: { version: Number(res.pageState.version ?? 0), fingerprint: String(res.pageState.fingerprint ?? '') },
            drifted: false,
            error: null,
          }));
        }
        // Versions list only — the editor content is NEVER auto-replaced
        // (incident fix 2026-07-11: a degraded refetch must not clobber the doc).
        refetchPageVersions();
        return true;
      } catch (e: any) {
        toast.error(e?.message ?? 'Could not save the page');
        return false;
      } finally {
        setBusyAction(null);
      }
    }
    if (!isInsert && !section) return true;
    const replacement = replacementOverride ?? (editor?.getHTML() ?? '');
    setBusyAction(action);
    try {
      let res: any;
      if (isInsert) {
        const anchor = insert?.ruleId
          ? { text: insert.anchorText, level: insert.anchorLevel, occurrence: insert.anchorOccurrence }
          : anchors?.[anchorIdx];
        if (!anchor) { toast.error('Pick a section to anchor the new one to.'); return false; }
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'insert',
          anchorText: anchor.text, anchorLevel: anchor.level, anchorOccurrence: anchor.occurrence,
          position, replacement, ruleId: insert?.ruleId,
        });
      } else if (section?.slice) {
        // Rule-born section (served-truth): the edit splices the owning rule.
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'slice',
          ruleId: section.slice.ruleId,
          unitFrom: section.slice.unitFrom,
          unitTo: section.slice.unitTo,
          replacement,
        });
      } else if (section) {
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'replace',
          headingText: section.heading.text, // ALWAYS the scan's ORIGINAL — rule identity
          headingLevel: section.heading.level,
          headingOccurrence: section.heading.occurrence,
          // Identity = the SCAN's section membership (anchors) — the fix that
          // makes the serving-side verify agree with what we saved.
          paragraphs: section.paragraphs.map((p) => ({ text: p.text, occurrence: p.occurrence })),
          replacement,
        });
      }
      onSaved();
      if (res?.removed) toast.success('Section removed — the page serves without it again.');
      else if (res?.reverted) toast.success('Reverted — the original section serves again.');
      else toast.success('Saved — the site serves it now (page/CDN caches may need a purge).');
      setSavedHtml(replacement);
      setVersionPick('');
      if (!isInsert) refetchSectionVersions(); // the accepted state is a new version
      return true;
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the section');
      return false;
    } finally {
      setBusyAction(null);
    }
  };

  return { save, isDirty };
}
