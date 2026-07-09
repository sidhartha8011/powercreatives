/**
 * SectionModal — the floating section editor (section-editor phase 2).
 *
 * A DRAGGABLE, NON-blocking floating panel (custom portal — the shared Dialog
 * is modal by construction): click a paragraph in the SEO outline and its
 * WHOLE section (heading + paragraphs) opens as readable, formatted text.
 * The table behind stays fully interactive.
 *
 *  - READ view: the section as rendered HTML (served state when a section rule
 *    is active — original otherwise, with active paragraph rules folded in).
 *  - EDIT view: a TipTap editor (headings, paragraphs, lists, bold/italic/
 *    underline/strike, links) with a selection bubble toolbar — merge, split,
 *    delete and add paragraphs freely; the engine's fingerprint guard and the
 *    all-or-nothing serving keep every change reversible and honest.
 *  - AI: ✦ rewrites the whole section (staged Accept/Reject — never saved
 *    until accepted). Create mode drafts a NEW section from a topic.
 *  - SAVE: one `section` replace rule (UPSERT; editing back to the original
 *    deletes the rule server-side) or one `sectionInsert` rule (new sections,
 *    anchored before/after an existing heading; emptying an insert removes it).
 *
 * LOCAL tab: read-only formatted view (routing law — no rule engine on the
 * hub's own site; the honest tooltip says so).
 *
 * Contracts: docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Section contracts v2".
 */

import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useEditor, EditorContent } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import StarterKit from '@tiptap/starter-kit';
import {
  X, Sparkles, Loader2, Check, RefreshCw, Pencil, Code, GripHorizontal,
  BoldIcon, ItalicIcon, UnderlineIcon, StrikethroughIcon, Link as LinkIcon,
  Heading2, Heading3, List, ListOrdered, Trash2,
} from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';

/** One paragraph of the section, as scanned (ORIGINAL text = rule identity). */
export interface SectionParagraph {
  text: string;
  occurrence: number;
  html: string;
  /** An active paragraph rule's replacement (folded into the initial state). */
  servedHtml?: string;
}

/** The section a modal instance works on (mode 'section'). */
export interface SectionData {
  heading: { text: string; level: number; occurrence: number; html: string };
  paragraphs: SectionParagraph[];
  /** Active `section` rule serving this section, when one exists. */
  sectionRuleReplacement?: string | null;
}

/** An anchor option for placing a NEW section. */
export interface SectionAnchor {
  text: string;
  level: number;
  occurrence: number;
}

/** Existing insert-rule data when editing an already-added section. */
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
  /** Local tab = read-only formatted view (no engine on the hub's own site). */
  readOnly: boolean;
  mode: 'section' | 'insert';
  section?: SectionData;
  insert?: InsertData;
  /** Anchor choices for a NEW section (create mode without an existing rule). */
  anchors?: SectionAnchor[];
  onClose: () => void;
  /** Called after any successful save/removal so the panel refetches rules. */
  onSaved: () => void;
}

/** Readable type scale for the rendered section (no `prose` plugin in this build). */
const READ_VIEW_CLASS =
  'text-sm leading-relaxed text-foreground break-words ' +
  '[&_h1]:text-lg [&_h1]:font-semibold [&_h1]:mt-3 [&_h1]:mb-1.5 ' +
  '[&_h2]:text-base [&_h2]:font-semibold [&_h2]:mt-3 [&_h2]:mb-1.5 ' +
  '[&_h3]:text-sm [&_h3]:font-semibold [&_h3]:mt-2.5 [&_h3]:mb-1 ' +
  '[&_h4]:text-sm [&_h4]:font-medium [&_h4]:mt-2 [&_h4]:mb-1 ' +
  '[&_p]:my-1.5 [&_ul]:my-1.5 [&_ul]:pl-5 [&_ul]:list-disc [&_ol]:my-1.5 [&_ol]:pl-5 [&_ol]:list-decimal ' +
  '[&_li]:my-0.5 [&_a]:text-primary [&_a]:underline [&_a]:decoration-dotted';

/** Compose a section's CURRENT html: served rule > original with paragraph rules folded in. */
export function composeSectionHtml(section: SectionData): string {
  if (section.sectionRuleReplacement) {
    return section.sectionRuleReplacement;
  }
  const headingHtml = section.heading.html
    || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`;
  const body = section.paragraphs
    .map((p) => (p.servedHtml != null ? `<p>${p.servedHtml}</p>` : p.html))
    .join('');
  return headingHtml + body;
}

function escapeHtml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

/** Shared small icon-button for the bubble toolbar. */
function ToolButton({ onClick, active, title, children }: {
  onClick: () => void;
  active?: boolean;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault() /* keep the text selection */}
      onClick={onClick}
      title={title}
      className={`rounded p-1 transition-colors hover:bg-accent ${active ? 'bg-accent text-accent-foreground' : 'text-muted-foreground'}`}
    >
      {children}
    </button>
  );
}

export function SectionModal({
  siteId, postId, type, model, provider, readOnly, mode, section, insert, anchors, onClose, onSaved,
}: SectionModalProps) {
  const isInsert = mode === 'insert';

  // ── Draggable position (pointer-drag on the header; no dependency) ──
  const [pos, setPos] = useState<{ x: number; y: number }>(() => ({
    x: Math.max(16, window.innerWidth - 560),
    y: 96,
  }));
  const dragRef = useRef<{ dx: number; dy: number } | null>(null);
  const onDragStart = (e: React.PointerEvent) => {
    // Header buttons (Re-write / Edit / HTML / X) must click, never start a drag.
    if ((e.target as HTMLElement).closest('button')) return;
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

  // ── Content state ──
  const initialHtml = isInsert
    ? (insert?.replacement ?? '')
    : (section ? composeSectionHtml(section) : '');
  /** What the site serves NOW — read view + cancel target; updated after every
   *  successful save so the modal never flips back to stale pre-save content. */
  const [currentHtml, setCurrentHtml] = useState(initialHtml);
  /** The section's plain ORIGINAL (no rules) — what a clean revert serves. */
  const originalHtml = !isInsert && section
    ? (section.heading.html || `<h${section.heading.level}>${escapeHtml(section.heading.text)}</h${section.heading.level}>`)
      + section.paragraphs.map((p) => p.html).join('')
    : '';
  const [editMode, setEditMode] = useState(isInsert && !insert?.ruleId); // new sections start in edit
  const [showHtml, setShowHtml] = useState(false);
  const [busy, setBusy] = useState(false);
  const [aiSuggestion, setAiSuggestion] = useState<string | null>(null);
  const [topic, setTopic] = useState('');
  // Insert placement (create mode): default = after the LAST heading on the page.
  const [anchorIdx, setAnchorIdx] = useState<number>(() => Math.max(0, (anchors?.length ?? 1) - 1));
  const [position, setPosition] = useState<'before' | 'after'>(insert?.position ?? 'after');

  const editor = useEditor({
    editable: !readOnly,
    extensions: [
      // Inline marks + headings + lists + links — the block kinds the section
      // engine serves legally. No images/tables (they'd need per-builder care).
      StarterKit.configure({
        heading: { levels: [1, 2, 3, 4] },
        link: {
          openOnClick: false,
          autolink: true,
          defaultProtocol: 'https',
          HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        },
        codeBlock: false,
        blockquote: false,
        horizontalRule: false,
      }),
    ],
    content: initialHtml,
  });

  // Esc: leave edit mode first, then close (never while a drag is running).
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      if (aiSuggestion != null) { setAiSuggestion(null); return; }
      if (editMode && !isInsert) { setEditMode(false); editor?.commands.setContent(currentHtml); return; }
      onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editMode, aiSuggestion, editor, onClose]);

  const saveMutation = trpc.seo.remoteSaveSectionRule.useMutation();
  const optimizeMutation = trpc.seo.remoteOptimizeSection.useMutation();

  // ── AI (staged — nothing saves until Accept) ──
  const runOptimize = async () => {
    setBusy(true);
    try {
      const current = isInsert && !editor?.getText().trim()
        ? '' // empty new section → generate mode (topic drives it)
        : (editor?.getHTML() ?? currentHtml);
      const res: any = await optimizeMutation.mutateAsync({
        siteId: siteId as number, postId, type,
        html: current, topic, model, provider,
      });
      const value = String(res?.value ?? '').trim();
      if (value) setAiSuggestion(value);
      else toast.info('The section already looks optimized.');
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not optimize the section');
    } finally {
      setBusy(false);
    }
  };
  const acceptSuggestion = () => {
    if (aiSuggestion == null) return;
    editor?.commands.setContent(aiSuggestion);
    setAiSuggestion(null);
    setEditMode(true); // land in the editor so the user can tweak before saving
  };

  // ── Save (section replace / section insert) ──
  const save = async (replacementOverride?: string) => {
    if (!isInsert && !section) return; // nothing to save against
    const replacement = replacementOverride ?? (editor?.getHTML() ?? '');
    setBusy(true);
    try {
      let res: any;
      if (isInsert) {
        const anchor = insert?.ruleId
          ? { text: insert.anchorText, level: insert.anchorLevel, occurrence: insert.anchorOccurrence }
          : (anchors?.[anchorIdx] ? { text: anchors[anchorIdx].text, level: anchors[anchorIdx].level, occurrence: anchors[anchorIdx].occurrence } : null);
        if (!anchor) { toast.error('Pick a section to anchor the new one to.'); return; }
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'insert',
          anchorText: anchor.text, anchorLevel: anchor.level, anchorOccurrence: anchor.occurrence,
          position, replacement, ruleId: insert?.ruleId,
        });
      } else if (section) {
        res = await saveMutation.mutateAsync({
          siteId: siteId as number, postId, kind: 'replace',
          headingText: section.heading.text,       // ALWAYS the scan's ORIGINAL — rule identity
          headingLevel: section.heading.level,
          headingOccurrence: section.heading.occurrence,
          paragraphs: section.paragraphs.map((p) => ({ text: p.text, occurrence: p.occurrence })),
          replacement,
        });
      }
      onSaved();
      if (res?.removed) {
        toast.success('Section removed — the page serves without it again.');
        onClose();
      } else if (res?.reverted) {
        toast.success('Reverted — the original section serves again.');
        setCurrentHtml(originalHtml);
        editor?.commands.setContent(originalHtml);
        setEditMode(false);
      } else {
        toast.success('Section saved — the site serves it now (page/CDN caches may need a purge).');
        setCurrentHtml(replacement); // the read view now shows what actually serves
        setEditMode(false);
        if (isInsert) onClose();
      }
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the section');
    } finally {
      setBusy(false);
    }
  };

  /** Remove an existing added section (insert rule) — empty replacement = clean removal. */
  const removeInsert = () => { void save(''); };

  const title = isInsert
    ? (insert?.ruleId ? 'Added section' : 'New section')
    : `Section — H${section?.heading.level ?? 2}: ${section?.heading.text ?? ''}`;

  const canEdit = !readOnly && !isInsert;
  const served = !isInsert && !!section?.sectionRuleReplacement;

  return createPortal(
    <div
      className="fixed z-40 flex max-h-[80vh] w-[540px] max-w-[calc(100vw-24px)] flex-col overflow-hidden rounded-lg border border-border bg-background shadow-2xl"
      style={{ left: pos.x, top: pos.y }}
      role="dialog"
      aria-label={title}
    >
      {/* ── Header (drag handle + AI actions, always visible) ── */}
      <div
        className="flex cursor-grab select-none items-center gap-2 border-b border-border bg-muted/40 px-3 py-2 active:cursor-grabbing"
        onPointerDown={onDragStart}
        onPointerMove={onDragMove}
        onPointerUp={onDragEnd}
      >
        <GripHorizontal className="h-3.5 w-3.5 shrink-0 text-muted-foreground/50" />
        <div className="min-w-0 flex-1 truncate text-xs font-medium" title={title}>
          {served && <span className="mr-1.5 inline-block h-1.5 w-1.5 rounded-full bg-primary align-middle" title="Optimized — a section rule serves this content" />}
          {title}
        </div>
        {!readOnly && (
          <button
            type="button"
            onClick={runOptimize}
            disabled={busy}
            title={isInsert ? 'Draft this section with AI' : 'Rewrite this section with AI'}
            className="inline-flex shrink-0 items-center gap-1 rounded border border-border px-1.5 py-0.5 text-[11px] text-muted-foreground hover:bg-accent hover:text-primary disabled:opacity-60"
          >
            {busy ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <Sparkles className="h-3 w-3" />}
            {isInsert ? 'Generate' : 'Re-write'}
          </button>
        )}
        {canEdit && !editMode && (
          <button
            type="button"
            onClick={() => setEditMode(true)}
            title="Edit this section"
            className="inline-flex shrink-0 items-center gap-1 rounded border border-border px-1.5 py-0.5 text-[11px] text-muted-foreground hover:bg-accent hover:text-foreground"
          >
            <Pencil className="h-3 w-3" /> Edit
          </button>
        )}
        <button
          type="button"
          onClick={() => setShowHtml((v) => !v)}
          title="View HTML"
          className={`shrink-0 rounded p-1 hover:bg-accent ${showHtml ? 'text-primary' : 'text-muted-foreground'}`}
        >
          <Code className="h-3.5 w-3.5" />
        </button>
        <button type="button" onClick={onClose} title="Close" className="shrink-0 rounded p-1 text-muted-foreground hover:bg-accent hover:text-foreground">
          <X className="h-3.5 w-3.5" />
        </button>
      </div>

      {/* ── New-section placement (create mode only) ── */}
      {isInsert && !insert?.ruleId && (
        <div className="flex items-center gap-2 border-b border-border px-3 py-2 text-xs">
          <span className="shrink-0 text-muted-foreground">Place</span>
          <select
            value={position}
            onChange={(e) => setPosition(e.target.value === 'before' ? 'before' : 'after')}
            className="h-6 rounded border border-input bg-card px-1 text-xs"
          >
            <option value="after">after</option>
            <option value="before">before</option>
          </select>
          <select
            value={anchorIdx}
            onChange={(e) => setAnchorIdx(Number(e.target.value))}
            className="h-6 min-w-0 flex-1 truncate rounded border border-input bg-card px-1 text-xs"
          >
            {(anchors ?? []).map((a, i) => (
              <option key={`${a.text}-${i}`} value={i}>{`H${a.level}: ${a.text}`}</option>
            ))}
          </select>
        </div>
      )}
      {isInsert && !insert?.ruleId && (
        <div className="border-b border-border px-3 py-2">
          <input
            value={topic}
            onChange={(e) => setTopic(e.target.value)}
            placeholder="Topic for AI (e.g. “FAQ about pricing”) — or just write below"
            className="h-7 w-full rounded border border-input bg-card px-2 text-xs outline-none focus:border-primary"
          />
        </div>
      )}

      {/* ── Staged AI suggestion (Accept / Reject / Re-generate) ── */}
      {aiSuggestion != null && (
        <div className="border-b border-primary/20 bg-accent px-3 py-2">
          <div
            className={`${READ_VIEW_CLASS} max-h-56 overflow-auto`}
            // eslint-disable-next-line react/no-danger
            dangerouslySetInnerHTML={{ __html: aiSuggestion }}
          />
          <div className="mt-1.5 flex items-center gap-1">
            <button type="button" onClick={acceptSuggestion} disabled={busy} className="inline-flex items-center gap-0.5 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-60">
              <Check className="h-3 w-3" /> Accept
            </button>
            <button type="button" onClick={() => setAiSuggestion(null)} disabled={busy} className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
              <X className="h-3 w-3" /> Reject
            </button>
            <button type="button" onClick={runOptimize} disabled={busy} className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
              {busy ? <Loader2 className="h-3 w-3 animate-spin text-primary" /> : <RefreshCw className="h-3 w-3" />} Re-generate
            </button>
          </div>
        </div>
      )}

      {/* ── Body: HTML inspector / editor / read view ── */}
      <div className="min-h-0 flex-1 overflow-auto p-3">
        {showHtml ? (
          <pre className="rounded-md border border-border bg-muted/40 p-2.5 font-mono text-[11px] leading-4 whitespace-pre-wrap break-words">
            {editMode || isInsert ? (editor?.getHTML() ?? currentHtml) : currentHtml}
          </pre>
        ) : (editMode || isInsert) && !readOnly ? (
          <>
            {editor && (
              <BubbleMenu
                editor={editor}
                shouldShow={({ state }: { state: any }) => !state.selection.empty && !state.selection.node}
                className="flex items-center gap-0.5 rounded-md border border-border bg-popover p-0.5 shadow-md"
              >
                <ToolButton title="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><BoldIcon className="h-3.5 w-3.5" /></ToolButton>
                <ToolButton title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><ItalicIcon className="h-3.5 w-3.5" /></ToolButton>
                <ToolButton title="Underline" active={editor.isActive('underline')} onClick={() => editor.chain().focus().toggleUnderline().run()}><UnderlineIcon className="h-3.5 w-3.5" /></ToolButton>
                <ToolButton title="Strikethrough" active={editor.isActive('strike')} onClick={() => editor.chain().focus().toggleStrike().run()}><StrikethroughIcon className="h-3.5 w-3.5" /></ToolButton>
                <div className="mx-0.5 h-4 w-px bg-border" />
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
                <div className="mx-0.5 h-4 w-px bg-border" />
                <ToolButton title="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}><Heading2 className="h-3.5 w-3.5" /></ToolButton>
                <ToolButton title="Heading 3" active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}><Heading3 className="h-3.5 w-3.5" /></ToolButton>
                <ToolButton title="Bullet list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}><List className="h-3.5 w-3.5" /></ToolButton>
                <ToolButton title="Numbered list" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}><ListOrdered className="h-3.5 w-3.5" /></ToolButton>
              </BubbleMenu>
            )}
            <EditorContent
              editor={editor}
              className={`${READ_VIEW_CLASS} min-h-[160px] rounded-md border border-input bg-card p-2.5 [&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[140px]`}
            />
          </>
        ) : (
          <div
            className={READ_VIEW_CLASS}
            title={readOnly ? 'Read-only here — section editing runs via dynamic rules on connected sites.' : undefined}
            // Server-sanitized content (rule replacements are wp_kses_post'd;
            // originals are the site's own published markup).
            // eslint-disable-next-line react/no-danger
            dangerouslySetInnerHTML={{ __html: currentHtml }}
          />
        )}
      </div>

      {/* ── Footer: Save / Cancel (+ Remove for existing added sections) ── */}
      {!readOnly && (editMode || isInsert) && !showHtml && (
        <div className="flex items-center gap-1.5 border-t border-border px-3 py-2">
          <button
            type="button"
            onClick={() => save()}
            disabled={busy}
            className="inline-flex items-center gap-1 rounded bg-green-600 px-2 py-1 text-[11px] font-medium text-white hover:bg-green-700 disabled:opacity-60"
          >
            {busy ? <Loader2 className="h-3 w-3 animate-spin" /> : <Check className="h-3 w-3" />} Accept
          </button>
          <button
            type="button"
            onClick={() => {
              if (isInsert && !insert?.ruleId) { onClose(); return; }
              editor?.commands.setContent(currentHtml);
              setEditMode(false);
            }}
            disabled={busy}
            className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-[11px] text-muted-foreground hover:bg-muted disabled:opacity-60"
          >
            <X className="h-3 w-3" /> Cancel
          </button>
          <div className="flex-1" />
          {isInsert && !!insert?.ruleId && (
            <button
              type="button"
              onClick={removeInsert}
              disabled={busy}
              title="Remove this added section from the live page"
              className="inline-flex items-center gap-1 rounded border border-border px-2 py-1 text-[11px] text-muted-foreground hover:bg-muted hover:text-destructive disabled:opacity-60"
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
