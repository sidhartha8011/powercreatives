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
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useEditor, EditorContent } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import StarterKit from '@tiptap/starter-kit';
import {
  X, Sparkles, Loader2, Check, Undo2, Trash2, MessageSquarePlus,
  BoldIcon, ItalicIcon, UnderlineIcon, Link as LinkIcon,
  Heading1, Heading2, List,
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
  mode: 'section' | 'insert';
  section?: SectionData;
  insert?: InsertData;
  /** Anchor choices when creating a NEW section. */
  anchors?: SectionAnchor[];
  /** Where the user clicked — the window opens right below it. */
  anchorPoint?: { x: number; y: number };
  onClose: () => void;
  onSaved: () => void;
}

const WIDTH = 440;
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
  siteId, postId, type, model, provider, readOnly, mode, section, insert, anchors, anchorPoint, onClose, onSaved,
}: SectionModalProps) {
  const isInsert = mode === 'insert';
  const rootRef = useRef<HTMLDivElement>(null);

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
  /** The section's TRUE original (no rules applied) — always live from the scan. */
  const originalHtml = !isInsert && section
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
        heading: { levels: [1, 2, 3, 4] },
        link: {
          openOnClick: false, autolink: true, defaultProtocol: 'https',
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
        codeBlock: false, blockquote: false, horizontalRule: false,
      }),
    ],
    content: openedHtml,
    // Baseline for dirty-checks must be the EDITOR's normalized form of the
    // opened content (TipTap reorders attrs etc.) — otherwise an untouched
    // window would "save" on every outside click.
    onCreate: ({ editor: ed }) => setSavedHtml(ed.getHTML()),
  });

  const saveMutation = trpc.seo.remoteSaveSectionRule.useMutation();
  const optimizeMutation = trpc.seo.remoteOptimizeSection.useMutation();

  // ── Version history (replace-sections only — inserts have no Original). ──
  const versionsQuery = trpc.seo.remoteSectionVersions.useQuery(
    {
      siteId: siteId as number, postId,
      text: section?.heading.text ?? '', occurrence: section?.heading.occurrence ?? 0,
    },
    { enabled: !readOnly && !isInsert && !!section, staleTime: 0 },
  );
  const versions: Array<{ id: number; replacement: string; createdAt: string }> =
    Array.isArray((versionsQuery.data as any)?.versions) ? (versionsQuery.data as any).versions : [];
  /** '' = viewing the current state; 'original' | version id as string. */
  const [versionPick, setVersionPick] = useState('');
  const [versionsOpen, setVersionsOpen] = useState(false);
  const deleteVersionMutation = trpc.seo.remoteDeleteSectionVersion.useMutation();
  const pickVersion = (v: string) => {
    setVersionPick(v);
    setVersionsOpen(false);
    if (v === 'original') editor?.commands.setContent(originalHtml);
    else if (v !== '') {
      const row = versions.find((x) => String(x.id) === v);
      if (row) editor?.commands.setContent(row.replacement);
    }
  };
  /** The dropdown ALWAYS names a state (owner law — never a counter):
   *  the picked version, else the latest saved one when a rule serves, else Original. */
  const hasActiveRule = !isInsert && !!section?.sectionRuleReplacement;
  const versionLabel = versionPick === 'original'
    ? 'Original'
    : versionPick !== ''
      ? (versions.find((v) => String(v.id) === versionPick)?.createdAt.slice(0, 16) ?? 'Version')
      : (hasActiveRule && versions.length > 0 ? versions[0].createdAt.slice(0, 16) : 'Original');
  const deleteVersion = async (id: number) => {
    try {
      await deleteVersionMutation.mutateAsync({ siteId: siteId as number, postId, versionId: id });
      if (versionPick === String(id)) setVersionPick('');
      await versionsQuery.refetch();
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not delete the version');
    }
  };

  const isDirty = () => !readOnly && !!editor && editor.getHTML() !== savedHtml;

  // ── Save (Acceptera / click outside): live rule, engine handles UPSERT/revert. ──
  const save = async (replacementOverride?: string): Promise<boolean> => {
    if (readOnly || (!isInsert && !section)) return true;
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

  const title = isInsert
    ? (insert?.ruleId ? '¶ Added section' : '¶ New section')
    : `¶ ${section?.heading.text ?? ''}`;
  const served = !isInsert && !!section?.sectionRuleReplacement;

  return createPortal(
    <div
      ref={rootRef}
      className="fixed z-40 flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl"
      style={{ left: pos.x, top: pos.y, width: WIDTH, maxWidth: 'calc(100vw - 16px)' }}
      role="dialog"
      aria-label={title}
    >
      {/* ── Header: ¶ title + [Ask AI] [Re-write] [X] — draggable ── */}
      <div
        className="flex cursor-grab select-none items-center gap-1.5 border-b border-slate-200 bg-white px-2.5 py-1.5 active:cursor-grabbing"
        onPointerDown={onDragStart}
        onPointerMove={onDragMove}
        onPointerUp={onDragEnd}
      >
        <div className="min-w-0 flex-1 truncate text-xs font-medium text-slate-800" title={title}>
          {served && <span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" title="Optimized — a section rule serves this content" />}
          {title}
        </div>
        {/* Version history: the button ALWAYS names the shown state (picked/latest/
            Original — never a counter). The list: Original (light-grey, undeletable)
            + each accepted save with date/time and a delete button. Picking one
            loads it in the editor; Acceptera makes it the version the site serves. */}
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
                <button
                  type="button"
                  onClick={() => pickVersion('original')}
                  className="block w-full bg-slate-50 px-2 py-1 text-left text-[11px] text-slate-700 hover:bg-slate-100"
                >
                  Original
                </button>
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
        {!readOnly && (
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
        className="h-[280px] overflow-auto bg-white px-3 py-2"
        title={readOnly ? 'Read-only here — section editing runs via dynamic rules on connected sites.' : undefined}
      >
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
        <EditorContent
          editor={editor}
          className={`${TYPE_SCALE} [&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[250px]`}
        />
      </div>

      {/* ── [✓ Acceptera] [↶ Ångra] (+ Remove for existing added sections) ── */}
      {!readOnly && (
        <div className="flex items-center gap-1.5 border-t border-slate-200 bg-white px-2.5 py-1.5">
          <button
            type="button"
            onClick={() => { void save().then((ok) => { if (ok) onClose(); }); }}
            disabled={busy}
            className="inline-flex items-center gap-1 rounded bg-green-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-green-700 disabled:opacity-60"
          >
            {busy ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} Acceptera
          </button>
          <button
            type="button"
            onClick={() => editor?.commands.setContent(savedHtml)}
            disabled={busy}
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
