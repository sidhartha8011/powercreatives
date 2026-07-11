/**
 * SectionModal — the floating section editor (owner-spec v2, 2026-07-09).
 *
 * EXACTLY the owner's sketch — one constant shape, nothing else:
 *
 *   ┌──────────────────────────────────────┐
 *   │ ¶ Heading……            [Ask AI] [Re-write] [X] │  ← draggable header
 *   ├──────────────────────────────────────┤
 *   │ (optional Ask-AI instruction input)  │
 *   │ formatted, DIRECTLY editable text    │  ← fixed-height TipTap, white
 *   │ [B][I][U][Link] [H1][H2][•]          │  ← persistent toolbar
 *   │ [✓ Acceptera]  [↶ Ångra]             │
 *   └──────────────────────────────────────┘
 *
 * Laws (owner-set): opens BELOW the clicked row · editable on FIRST click
 * (no Edit button, no read mode, the shape never changes) · white background,
 * compact text · Acceptera OR clicking outside SAVES · Ångra restores the
 * last saved state · Esc closes without saving.
 *
 * SECTION IDENTITY (the root-cause fix): the paragraphs this modal saves
 * against come from the CONNECTOR SCAN's anchors — the same section
 * definition the serving engine verifies — never from display layout.
 * See DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Section membership".
 *
 * Saving creates/updates ONE dynamic rule (UPSERT; editing back to the
 * original deletes it; push-fail rolls back — phase-1 engine). LOCAL tab =
 * read-only formatted view (no rule engine on the hub's own site).
 *
 * PAGE MODE (full-page editor V1, 2026-07-10): the SAME component maximized
 * (~90vw/85vh, centered) — self-fetches the inventory's `contentHtml` (served
 * content region, hub-assembled) and saves the whole document through
 * `seo.remoteSavePageEdits`, which slices it back into sections server-side
 * and routes each change through the EXISTING rule paths. Images render as
 * locked context (never persisted — the live page's images are untouched
 * between-content by construction). Versions + Ask AI are section-mode only
 * (V2 brings AI to page mode). Section/insert behavior is byte-identical.
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useEditor, EditorContent } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import {
  X, Sparkles, Loader2, Check, Undo2, Trash2, MessageSquarePlus,
  BoldIcon, ItalicIcon, UnderlineIcon, Link as LinkIcon,
  Heading1, Heading2, List, ExternalLink,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';

/** One paragraph of the section, from the SCAN (original text = rule identity). */
export interface SectionParagraph {
  text: string;
  occurrence: number;
  html: string;
  /** An active paragraph rule's replacement (folded into the shown state). */
  servedHtml?: string;
}

export interface SectionData {
  heading: { text: string; level: number; occurrence: number; html: string };
  paragraphs: SectionParagraph[];
  /** Active `section` rule serving this section, when one exists. */
  sectionRuleReplacement?: string | null;
  /** Served-truth editor (contracts v2.2): this section is a SLICE of an
   *  owning rule's replacement — saves splice that unit range of the rule. */
  slice?: { ruleId: number; unitFrom: number; unitTo: number };
}

export interface SectionAnchor { text: string; level: number; occurrence: number }

export interface InsertData {
  ruleId?: number;
  anchorText: string;
  anchorLevel: number;
  anchorOccurrence: number;
  position: 'before' | 'after';
  replacement: string;
}

export interface SectionModalProps {
  siteId: number | 'local';
  postId: number;
  type: 'post' | 'page';
  model?: string;
  provider?: string;
  /** Local tab: read-only formatted view. */
  readOnly: boolean;
  mode: 'section' | 'insert' | 'page';
  section?: SectionData;
  insert?: InsertData;
  /** Page mode: the row's title + the demoted WP-editor escape hatch. */
  page?: { title: string; editUrl?: string };
  /** Anchor choices when creating a NEW section. */
  anchors?: SectionAnchor[];
  /** Where the user clicked — the window opens right below it. */
  anchorPoint?: { x: number; y: number };
  onClose: () => void;
  onSaved: () => void;
}

const WIDTH = 440;

/** Page mode's OWN reading scale (owner order U2): the document must read
 *  like the live page — real paragraph air, stepped heading sizes — while
 *  section mode keeps the compact scale above, byte-identical. */
const PAGE_TYPE_SCALE =
  'text-sm leading-relaxed text-slate-800 break-words ' +
  '[&_h1]:text-xl [&_h1]:font-semibold [&_h1]:mt-6 [&_h1]:mb-2 ' +
  '[&_h2]:text-lg [&_h2]:font-semibold [&_h2]:mt-5 [&_h2]:mb-2 ' +
  '[&_h3]:text-base [&_h3]:font-semibold [&_h3]:mt-4 [&_h3]:mb-1.5 ' +
  '[&_h4]:text-sm [&_h4]:font-semibold [&_h4]:mt-4 [&_h4]:mb-1 ' +
  '[&_h5]:text-sm [&_h5]:font-medium [&_h5]:mt-3 [&_h5]:mb-1 ' +
  '[&_h6]:text-sm [&_h6]:font-medium [&_h6]:mt-3 [&_h6]:mb-1 ' +
  '[&_p]:my-3 [&_ul]:my-3 [&_ul]:pl-5 [&_ul]:list-disc [&_ol]:my-3 [&_ol]:pl-5 [&_ol]:list-decimal ' +
  '[&_li]:my-1 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted ' +
  '[&_.ProseMirror>*:first-child]:mt-0';

/** Page-mode images: locked context — visible, atomic, never draggable; the
 *  hub strips every image from saves (F9 law), so the live page's images are
 *  untouched by construction. `data-pcm-locked` survives the round-trip only
 *  to style the lock. */
const LockedImage = Image.extend({
  draggable: false,
  addAttributes() {
    return { ...this.parent?.(), 'data-pcm-locked': { default: null } };
  },
});
/** Compact readable scale (no `prose` plugin in this build). */
const TYPE_SCALE =
  'text-xs leading-relaxed text-slate-800 break-words ' +
  '[&_h1]:text-sm [&_h1]:font-semibold [&_h1]:mt-2 [&_h1]:mb-1 ' +
  '[&_h2]:text-sm [&_h2]:font-semibold [&_h2]:mt-2 [&_h2]:mb-1 ' +
  '[&_h3]:text-xs [&_h3]:font-semibold [&_h3]:mt-1.5 [&_h3]:mb-0.5 ' +
  '[&_h4]:text-xs [&_h4]:font-medium [&_h4]:mt-1.5 [&_h4]:mb-0.5 ' +
  '[&_p]:my-1 [&_ul]:my-1 [&_ul]:pl-4 [&_ul]:list-disc [&_ol]:my-1 [&_ol]:pl-4 [&_ol]:list-decimal ' +
  '[&_li]:my-0.5 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted';

/** The section's CURRENT html: served rule > original with paragraph rules folded in. */
export function composeSectionHtml(section: SectionData): string {
  if (section.sectionRuleReplacement) return section.sectionRuleReplacement;
  const h = section.heading.html
    || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`;
  return h + section.paragraphs
    .map((p) => (p.servedHtml != null ? `<p>${p.servedHtml}</p>` : p.html))
    .join('');
}

function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function ToolButton({ onClick, active, title, children }: {
  onClick: () => void; active?: boolean; title: string; children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault() /* keep the selection */}
      onClick={onClick}
      title={title}
      className={`rounded p-1 transition-colors hover:bg-slate-100 ${active ? 'bg-slate-100 text-slate-900' : 'text-slate-500'}`}
    >
      {children}
    </button>
  );
}

export function SectionModal({
  siteId, postId, type, model, provider, readOnly, mode, section, insert, page, anchors, anchorPoint, onClose, onSaved,
}: SectionModalProps) {
  const isInsert = mode === 'insert';
  const isPage = mode === 'page';
  const rootRef = useRef<HTMLDivElement>(null);

  // ── Page mode: self-fetch the hub-assembled served content document. ──
  const pageQuery = trpc.seo.remoteGetInventory.useQuery(
    { siteId: siteId as number, postId, type },
    { enabled: isPage && !readOnly, staleTime: 0, refetchOnMount: 'always' },
  );
  const pageHtml = isPage ? String((pageQuery.data as any)?.contentHtml ?? '') : '';
  const pageReady = isPage && (pageQuery.data as any)?.view === 'served' && pageHtml !== '';
  const pageError = isPage && !pageQuery.isLoading && !pageReady
    ? ((pageQuery.data as any)?.error === 'loopback_blocked'
      ? 'Page editing unavailable — the site blocked the connector’s content fetch.'
      : 'Page editing needs the served page view (connector 3.0.1+ on this site) — update it from the Sites module, then re-open.')
    : null;
  // Page versions: saved page documents + the true no-rules Original
  // (rules-input snapshot, hub-assembled) — one read.
  const pageVersionsQuery = trpc.seo.remotePageVersions.useQuery(
    { siteId: siteId as number, postId },
    { enabled: isPage && !readOnly, staleTime: 0 },
  );

  // ── Position: right below the click, draggable from the header. ──
  const [pos, setPos] = useState(() => ({
    x: Math.min(Math.max(8, (anchorPoint?.x ?? 120)), Math.max(8, window.innerWidth - WIDTH - 12)),
    y: Math.min(Math.max(8, (anchorPoint?.y ?? 80) + 6), Math.max(8, window.innerHeight - 200)),
  }));
  const dragRef = useRef<{ dx: number; dy: number } | null>(null);
  const onDragStart = (e: React.PointerEvent) => {
    if ((e.target as HTMLElement).closest('button')) return; // buttons click, never drag
    dragRef.current = { dx: e.clientX - pos.x, dy: e.clientY - pos.y };
    (e.target as HTMLElement).setPointerCapture?.(e.pointerId);
  };
  const onDragMove = (e: React.PointerEvent) => {
    if (!dragRef.current) return;
    setPos({
      x: Math.min(Math.max(8, e.clientX - dragRef.current.dx), window.innerWidth - 120),
      y: Math.min(Math.max(8, e.clientY - dragRef.current.dy), window.innerHeight - 60),
    });
  };
  const onDragEnd = () => { dragRef.current = null; };

  // ── Content: ONE state — the editor. `savedHtml` = last saved/opened state. ──
  const openedHtml = isInsert ? (insert?.replacement ?? '') : (section ? composeSectionHtml(section) : '');
  /** The TRUE original (no rules applied): section mode = live from the scan;
   *  page mode = the hub-assembled rules-input document ('' = honest unavailable). */
  const originalHtml = isPage
    ? String((pageVersionsQuery.data as any)?.originalHtml ?? '')
    : !isInsert && section
      ? (section.heading.html || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`)
        + section.paragraphs.map((p) => p.html).join('')
      : '';
  const [savedHtml, setSavedHtml] = useState(openedHtml);
  const [busy, setBusy] = useState(false);
  const [askOpen, setAskOpen] = useState(false);
  const [instruction, setInstruction] = useState('');
  const [position, setPosition] = useState<'before' | 'after'>(insert?.position ?? 'after');
  const [anchorIdx, setAnchorIdx] = useState<number>(() => Math.max(0, (anchors?.length ?? 1) - 1));

  const editor = useEditor({
    editable: !readOnly,
    extensions: [
      StarterKit.configure({
        // Page mode edits the whole served document — every legal level.
        heading: { levels: isPage ? [1, 2, 3, 4, 5, 6] : [1, 2, 3, 4] },
        link: {
          openOnClick: false, autolink: true, defaultProtocol: 'https',
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
        codeBlock: false, blockquote: false, horizontalRule: false,
      }),
      ...(isPage ? [LockedImage] : []),
    ],
    content: openedHtml,
    // Baseline for dirty-checks must be the EDITOR's normalized form of the
    // opened content (TipTap reorders attrs etc.) — otherwise an untouched
    // window would "save" on every outside click.
    onCreate: ({ editor: ed }) => setSavedHtml(ed.getHTML()),
  });

  // Page mode opens empty and loads the fetched document (dirty-baseline =
  // the editor's normalized form of it, same law as onCreate).
  useEffect(() => {
    if (!isPage || !editor || !pageReady) return;
    editor.commands.setContent(pageHtml);
    setSavedHtml(editor.getHTML());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPage, editor, pageReady, pageHtml]);

  const saveMutation = trpc.seo.remoteSaveSectionRule.useMutation();
  const savePageMutation = trpc.seo.remoteSavePageEdits.useMutation();
  const optimizeMutation = trpc.seo.remoteOptimizeSection.useMutation();

  // ── Version history (replace-sections only — inserts have no Original). ──
  const versionsQuery = trpc.seo.remoteSectionVersions.useQuery(
    {
      siteId: siteId as number, postId,
      text: section?.heading.text ?? '', occurrence: section?.heading.occurrence ?? 0,
    },
    { enabled: !readOnly && !isInsert && !!section, staleTime: 0 },
  );
  const versionsData: any = isPage ? pageVersionsQuery.data : versionsQuery.data;
  const versions: Array<{ id: number; replacement: string; createdAt: string }> =
    Array.isArray(versionsData?.versions) ? versionsData.versions : [];
  /** '' = viewing the current state; 'current' | 'original' | version id as string. */
  const [versionPick, setVersionPick] = useState('');
  const [versionsOpen, setVersionsOpen] = useState(false);
  const deleteVersionMutation = trpc.seo.remoteDeleteSectionVersion.useMutation();
  const pickVersion = (v: string) => {
    setVersionPick(v);
    setVersionsOpen(false);
    if (v === 'current') editor?.commands.setContent(pageHtml);
    else if (v === 'original') editor?.commands.setContent(originalHtml);
    else if (v !== '') {
      const row = versions.find((x) => String(x.id) === v);
      if (row) editor?.commands.setContent(row.replacement);
    }
  };
  /** The dropdown ALWAYS names a state (owner law — never a counter):
   *  the picked version, else page mode = Current (the live served doc),
   *  else the latest saved one when a rule serves, else Original. */
  const hasActiveRule = !isInsert && !isPage && !!section?.sectionRuleReplacement;
  const versionLabel = versionPick === 'original'
    ? 'Original'
    : versionPick === 'current'
      ? 'Current'
      : versionPick !== ''
        ? (versions.find((v) => String(v.id) === versionPick)?.createdAt.slice(0, 16) ?? 'Version')
        : isPage
          ? 'Current'
          : (hasActiveRule && versions.length > 0 ? versions[0].createdAt.slice(0, 16) : 'Original');
  const deleteVersion = async (id: number) => {
    try {
      await deleteVersionMutation.mutateAsync({ siteId: siteId as number, postId, versionId: id });
      if (versionPick === String(id)) setVersionPick('');
      await (isPage ? pageVersionsQuery : versionsQuery).refetch();
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not delete the version');
    }
  };

  const isDirty = () => !readOnly && !!editor && editor.getHTML() !== savedHtml;

  // ── Save (Acceptera / click outside): live rule, engine handles UPSERT/revert. ──
  const save = async (replacementOverride?: string): Promise<boolean> => {
    if (readOnly) return true;
    if (isPage) {
      if (!pageReady) return true; // nothing loaded — nothing to save
      const html = replacementOverride ?? (editor?.getHTML() ?? '');
      setBusy(true);
      try {
        // The hub slices the document back into sections and routes each
        // change through the existing rule paths — page-level editing,
        // section-level storage.
        const res: any = await savePageMutation.mutateAsync({ siteId: siteId as number, postId, html });
        onSaved();
        const changed = Number(res?.saved ?? 0) + Number(res?.inserted ?? 0);
        toast.success(changed > 0
          ? `Saved — ${changed} section${changed === 1 ? '' : 's'} now served dynamically (page/CDN caches may need a purge).`
          : 'No content changes to save.');
        (Array.isArray(res?.notes) ? res.notes : []).forEach((n: string) => toast.info(n));
        setSavedHtml(html);
        setVersionPick('');
        // The accepted state is a new page version; Current = fresh served truth.
        void pageVersionsQuery.refetch();
        void pageQuery.refetch();
        return true;
      } catch (e: any) {
        toast.error(e?.message ?? 'Could not save the page');
        return false;
      } finally {
        setBusy(false);
      }
    }
    if (!isInsert && !section) return true;
    const replacement = replacementOverride ?? (editor?.getHTML() ?? '');
    setBusy(true);
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
      if (!isInsert) void versionsQuery.refetch(); // the accepted state is a new version
      return true;
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the section');
      return false;
    } finally {
      setBusy(false);
    }
  };

  // ── Click OUTSIDE = save (when changed) then close. Esc = close without saving. ──
  useEffect(() => {
    const onDown = (e: PointerEvent) => {
      const t = e.target as HTMLElement;
      if (rootRef.current?.contains(t)) return;
      if (t.closest('[data-sonner-toaster]')) return; // toasts are not "outside"
      if (isDirty()) void save().then((ok) => { if (ok) onClose(); });
      else onClose();
    };
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('pointerdown', onDown, true);
    window.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('pointerdown', onDown, true);
      window.removeEventListener('keydown', onKey);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor, savedHtml, busy, position, anchorIdx]);

  // ── AI: Re-write = whole-section rewrite into the editor; Ask AI adds an instruction. ──
  const runAi = async (withInstruction: string) => {
    setBusy(true);
    try {
      const current = editor?.getText().trim() ? (editor?.getHTML() ?? '') : '';
      const res: any = await optimizeMutation.mutateAsync({
        siteId: siteId as number, postId, type,
        html: current, topic: withInstruction, model, provider,
      });
      const value = String(res?.value ?? '').trim();
      if (value) { editor?.commands.setContent(value); setAskOpen(false); }
      else toast.info('The section already looks optimized.');
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not run the AI on this section');
    } finally {
      setBusy(false);
    }
  };

  const title = isPage
    ? `📄 ${page?.title ?? 'Page'}`
    : isInsert
      ? (insert?.ruleId ? '¶ Added section' : '¶ New section')
      : `¶ ${section?.heading.text ?? ''}`;
  const served = !isInsert && !isPage && !!section?.sectionRuleReplacement;

  return createPortal(
    <div
      ref={rootRef}
      className="fixed z-40 flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
      style={isPage
        ? { left: '50%', top: '50%', transform: 'translate(-50%, -50%)', width: '58vw', minWidth: 720, height: '85vh', maxWidth: 'calc(100vw - 32px)' }
        : { left: pos.x, top: pos.y, width: WIDTH, maxWidth: 'calc(100vw - 16px)' }}
      role="dialog"
      aria-label={title}
    >
      {/* ── Header: ¶ title + [Ask AI] [Re-write] [X] — draggable (page mode: fixed, centered) ── */}
      <div
        className={`flex select-none items-center gap-1.5 border-b border-slate-200 bg-white px-2.5 py-1.5 ${isPage ? '' : 'cursor-grab active:cursor-grabbing'}`}
        onPointerDown={isPage ? undefined : onDragStart}
        onPointerMove={isPage ? undefined : onDragMove}
        onPointerUp={isPage ? undefined : onDragEnd}
      >
        <div className="min-w-0 flex-1 truncate text-xs font-medium text-slate-800" title={title}>
          {served && <span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" title="Optimized — a section rule serves this content" />}
          {title}
        </div>
        {/* Page mode: the image law + the demoted WP-editor escape hatch. */}
        {isPage && (
          <>
            <span className="hidden shrink-0 text-[11px] text-slate-400 sm:inline">
              Images are context — editable in a later version
            </span>
            {page?.editUrl && (
              <a
                href={page.editUrl}
                target="_blank"
                rel="noopener noreferrer"
                title="Open this page in the site’s WP editor (source editing)"
                className="inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-foreground"
              >
                <ExternalLink className="h-3 w-3" /> Open in WP editor
              </a>
            )}
          </>
        )}
        {/* Version history: the button ALWAYS names the shown state (picked/
            Current/latest/Original — never a counter). The list: page mode adds
            Current (the live served document); Original (light-grey,
            undeletable — page mode: the rules-input document, hidden when the
            input view is unavailable); each accepted save with date/time and a
            delete button. Picking one loads it in the editor; Acceptera makes
            it live (page mode: through the normal per-section save). */}
        {!readOnly && !isInsert && (
          <div className="relative shrink-0">
            <button
              type="button"
              onClick={() => setVersionsOpen((v) => !v)}
              title="Versions — pick one to view it; Acceptera makes it live"
              className="inline-flex h-6 max-w-[140px] items-center gap-1 truncate rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-600 hover:bg-slate-50"
            >
              <span className="truncate">{versionLabel}</span>
              <span className="text-slate-400">▾</span>
            </button>
            {versionsOpen && (
              <div className="absolute right-0 top-full z-10 mt-1 w-[190px] overflow-hidden rounded-md border border-slate-200 bg-white py-0.5 shadow-md">
                {isPage && (
                  <button
                    type="button"
                    onClick={() => pickVersion('current')}
                    className="block w-full px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-100"
                  >
                    Current
                  </button>
                )}
                {(!isPage || originalHtml !== '') && (
                <button
                  type="button"
                  onClick={() => pickVersion('original')}
                  className="block w-full bg-slate-50 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-100"
                >
                  Original
                </button>
                )}
                {versions.map((v) => (
                  <div key={v.id} className="flex items-center hover:bg-slate-50">
                    <button
                      type="button"
                      onClick={() => pickVersion(String(v.id))}
                      className="min-w-0 flex-1 truncate px-2 py-1 text-left text-[11px] text-slate-700"
                    >
                      {v.createdAt.slice(0, 16)}
                    </button>
                    <button
                      type="button"
                      onClick={() => { void deleteVersion(v.id); }}
                      title="Delete this version"
                      className="shrink-0 rounded p-1 text-slate-400 hover:text-destructive"
                    >
                      <Trash2 className="h-3 w-3" />
                    </button>
                  </div>
                ))}
                {versions.length === 0 && (
                  <div className="px-2 py-1 text-[11px] text-slate-400">No saved versions yet</div>
                )}
              </div>
            )}
          </div>
        )}
        {!readOnly && !isPage && (
          <>
            <button
              type="button"
              onClick={() => setAskOpen((v) => !v)}
              disabled={busy}
              title="Tell the AI what to do with this section"
              className={`inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] hover:bg-slate-50 disabled:opacity-60 ${askOpen ? 'text-primary border-primary/40' : 'text-slate-600'}`}
            >
              <MessageSquarePlus className="h-3 w-3" /> Ask AI
            </button>
            <button
              type="button"
              onClick={() => runAi('')}
              disabled={busy}
              title={isInsert ? 'Draft this section with AI' : 'Rewrite this section with AI'}
              className="inline-flex shrink-0 items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-primary disabled:opacity-60"
            >
              {busy ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <Sparkles className="h-3 w-3" />}
              {isInsert ? 'Generate' : 'Re-write'}
            </button>
          </>
        )}
        <button type="button" onClick={onClose} title="Close (Esc) — closes without saving" className="shrink-0 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
          <X className="h-3.5 w-3.5" />
        </button>
      </div>

      {/* ── Ask-AI instruction (Enter runs it) ── */}
      {askOpen && !readOnly && (
        <div className="border-b border-slate-200 px-2.5 py-1.5">
          <input
            autoFocus
            value={instruction}
            onChange={(e) => setInstruction(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && instruction.trim()) void runAi(instruction.trim()); }}
            placeholder="e.g. “make it shorter and add a price example” — Enter to run"
            className="h-6 w-full rounded border border-slate-200 bg-white px-2 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
        </div>
      )}

      {/* ── New-section placement (create mode only) ── */}
      {isInsert && !insert?.ruleId && (
        <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5 text-[11px]">
          <span className="shrink-0 text-slate-500">Place</span>
          <select value={position} onChange={(e) => setPosition(e.target.value === 'before' ? 'before' : 'after')} className="h-6 rounded border border-slate-200 bg-white px-1 text-[11px]">
            <option value="after">after</option>
            <option value="before">before</option>
          </select>
          <select value={anchorIdx} onChange={(e) => setAnchorIdx(Number(e.target.value))} className="h-6 min-w-0 flex-1 truncate rounded border border-slate-200 bg-white px-1 text-[11px]">
            {(anchors ?? []).map((a, i) => (
              <option key={`${a.text}-${i}`} value={i}>{`H${a.level}: ${a.text}`}</option>
            ))}
          </select>
        </div>
      )}

      {/* ── The text: ONE fixed-shape, directly editable surface. Formatting
             lives in the SELECT-TEXT popover (owner correction — no permanent
             toolbar): select text → the floating B/I/U/Link/H1/H2/• menu. ── */}
      <div
        className={`${isPage ? 'min-h-0 flex-1 px-8 py-4' : 'h-[280px] px-3 py-2'} overflow-auto bg-white`}
        title={readOnly ? 'Read-only here — section editing runs via dynamic rules on connected sites.' : undefined}
      >
        {isPage && pageQuery.isLoading && (
          <div className="flex h-full items-center justify-center gap-2 text-xs text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin text-primary" /> Loading the served page…
          </div>
        )}
        {isPage && pageError && (
          <div className="flex h-full items-center justify-center px-8 text-center text-xs text-slate-500">{pageError}</div>
        )}
        {!readOnly && editor && (
          <BubbleMenu
            editor={editor}
            shouldShow={({ state }: { state: any }) => !state.selection.empty && !state.selection.node}
            className="flex items-center gap-0.5 rounded-md border border-slate-200 bg-white p-0.5 shadow-md"
          >
            <ToolButton title="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><BoldIcon className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><ItalicIcon className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}><UnderlineIcon className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton
              title={editor.isActive('link') ? 'Remove link' : 'Add link'}
              active={editor.isActive('link')}
              onClick={() => {
                if (editor.isActive('link')) { editor.chain().focus().unsetLink().run(); return; }
                // eslint-disable-next-line no-alert
                const url = window.prompt('Link URL');
                if (url) editor.chain().focus().setLink({ href: url }).run();
              }}
            ><LinkIcon className="h-3.5 w-3.5" /></ToolButton>
            <div className="mx-0.5 h-4 w-px bg-slate-200" />
            <ToolButton title="Heading 1" active={editor.isActive('heading', { level: 1 })} onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}><Heading1 className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}><Heading2 className="h-3.5 w-3.5" /></ToolButton>
            <ToolButton title="Bullet list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}><List className="h-3.5 w-3.5" /></ToolButton>
          </BubbleMenu>
        )}
        {(!isPage || pageReady) && (
          <EditorContent
            editor={editor}
            className={`${isPage ? PAGE_TYPE_SCALE : TYPE_SCALE} [&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[250px]`
              // Locked context images: visible, clearly not editable.
              + (isPage ? ' [&_img]:my-2 [&_img]:max-w-full [&_img]:rounded [&_img[data-pcm-locked]]:cursor-not-allowed [&_img[data-pcm-locked]]:opacity-90' : '')}
          />
        )}
      </div>

      {/* ── [✓ Acceptera] [↶ Ångra] (+ Remove for existing added sections) ── */}
      {!readOnly && (
        <div className="flex items-center gap-1.5 border-t border-slate-200 bg-white px-2.5 py-1.5">
          <button
            type="button"
            onClick={() => { void save().then((ok) => { if (ok) onClose(); }); }}
            disabled={busy || (isPage && !pageReady)}
            className="inline-flex items-center gap-1 rounded bg-green-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-green-700 disabled:opacity-60"
          >
            {busy ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} Acceptera
          </button>
          <button
            type="button"
            onClick={() => editor?.commands.setContent(savedHtml)}
            disabled={busy || (isPage && !pageReady)}
            title="Restore the last saved state"
            className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50 disabled:opacity-60"
          >
            <Undo2 className="h-3 w-3" /> Ångra
          </button>
          <div className="flex-1" />
          {isInsert && !!insert?.ruleId && (
            <button
              type="button"
              onClick={() => { void save('').then((ok) => { if (ok) onClose(); }); }}
              disabled={busy}
              title="Remove this added section from the live page"
              className="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50 hover:text-destructive disabled:opacity-60"
            >
              <Trash2 className="h-3 w-3" /> Remove
            </button>
          )}
        </div>
      )}
    </div>,
    document.body,
  );
}
