/**
 * THE REVIEW RAIL (decomposition S2, gap 325d280/10a0233): the AI review's
 * overview column — header + bulk actions, the run's order (once), THE
 * FILTER (its state lives HERE: pure view state, gap e533bc5 F9), the
 * revise box, and one card per section. Rendered into the outside rail
 * host by the composer; every decision flows back through the explicit
 * props contract — a rail bug lives in THIS file, nowhere else.
 */

import { useState } from 'react';
import { Check, Loader2, X } from 'lucide-react';
import { GROUP_PILLS, TEACHER_PILLS, type CompiledDirective, type TeacherMeta } from '../optimizer/types';
import type { ReviewSection } from './types';

export interface ReviewRailProps {
  review: ReviewSection[];
  /** The run's compiled order — shown ONCE at the top (gap e533bc5 F4). */
  runDirectives: CompiledDirective[] | null;
  teacherById: Record<string, TeacherMeta>;
  reviseTarget: number | 'all' | null;
  reviseNote: string;
  onReviseTargetChange: (target: number | 'all' | null) => void;
  onReviseNoteChange: (note: string) => void;
  onRunRevise: () => void;
  onAcceptAll: () => void;
  onFinish: () => void;
  onResolve: (i: number, action: 'accept' | 'reject') => void;
  /** T2 (gap e1eb677): toggle one change's keep-tick. */
  onToggleKept: (sectionIdx: number, changeIdx: number) => void;
  /** Rebuild a section keeping only the ticked changes (one bounded re-run). */
  onUpdateProposal: (sectionIdx: number, note: string) => void;
  onFocusSection: (i: number) => void;
  onHoverQuote: (i: number, quote: string) => void;
  onHoverEnd: () => void;
}

export function ReviewRail({
  review, runDirectives, teacherById, reviseTarget, reviseNote,
  onReviseTargetChange, onReviseNoteChange, onRunRevise, onAcceptAll, onFinish,
  onResolve, onToggleKept, onUpdateProposal, onFocusSection, onHoverQuote, onHoverEnd,
}: ReviewRailProps) {
  // ── THE FILTER (gap e533bc5 F9): pure view state — the SEO/AI tab and
  //    the multi-select purpose tags narrow what the rail lists; the
  //    review data and every decision path stay untouched. ──
  const [filterGroup, setFilterGroup] = useState<'search' | 'ai' | null>(null);
  const [filterTags, setFilterTags] = useState<Set<string>>(new Set());
  const tagGroup = (p: string): 'search' | 'ai' => teacherById[p]?.group ?? 'search';
  const filterActive = filterGroup !== null || filterTags.size > 0;
  const tagMatches = (p: string): boolean =>
    (filterGroup === null || tagGroup(p) === filterGroup)
    && (filterTags.size === 0 || filterTags.has(p));
  /** A change without a purpose tag passes only an inactive filter. */
  const changeMatches = (why: string): boolean => (why === '' ? !filterActive : tagMatches(why));
  const sectionMatches = (s: ReviewSection): boolean => {
    if (!filterActive) return true;
    if (s.status === 'failed') return true; // failures always show — honesty law
    if (s.status === 'diff' && (s.changes?.length ?? 0) > 0) return (s.changes ?? []).some((c) => changeMatches(c.why));
    return (s.directives ?? []).some((d) => d.purposes.some(tagMatches));
  };

  /** The Update-proposal note: revert the unticked, keep everything else. */
  const proposalNote = (s: ReviewSection): string => {
    const kept = (s.changes ?? []).filter((_, k) => s.kept?.[k] ?? true);
    const dropped = (s.changes ?? []).filter((_, k) => !(s.kept?.[k] ?? true));
    return 'Remove these changes from the draft — revert those parts to how the original text read: '
      + dropped.map((c, k) => `${k + 1}) ${c.what} (the part reading: "${c.quote}")`).join('; ')
      + '. Keep every other change and ALL other text exactly as the draft reads'
      + (kept.length > 0 ? ` — especially: ${kept.map((c) => c.what).join('; ')}` : '')
      + '.';
  };

  const railTags = Array.from(new Set(review.flatMap((s) => [
    ...(s.changes ?? []).map((c) => c.why).filter((w) => w !== ''),
    ...(s.directives ?? []).flatMap((d) => d.purposes),
  ])));
  const visibleTags = filterGroup === null ? railTags : railTags.filter((t) => tagGroup(t) === filterGroup);

  return (
    <aside className="flex h-full w-[250px] shrink-0 flex-col bg-slate-50/60">
      <div className="border-b border-slate-200 px-2.5 py-1.5">
        <div className="text-[11px] font-medium text-slate-700">
          AI review — {review.filter((s) => s.status === 'pending' || s.status === 'diff').length} of {review.filter((s) => s.status !== 'clean').length} left
        </div>
        <div className="mt-1 flex items-center gap-1.5">
          <button
            type="button"
            onClick={onAcceptAll}
            disabled={!review.some((s) => s.status === 'diff')}
            className="inline-flex items-center gap-1 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-500 disabled:opacity-50"
          >
            <Check className="h-3 w-3" /> Accept all
          </button>
          <button
            type="button"
            onClick={() => { onReviseTargetChange('all'); onReviseNoteChange(''); }}
            disabled={!review.some((s) => s.status === 'diff')}
            title="Send every undecided section back to the AI with ONE adjustment"
            className="inline-flex items-center gap-1 rounded border border-primary/40 px-1.5 py-0.5 text-[10px] text-primary hover:bg-[#e7f5ff] disabled:opacity-50"
          >
            ↻ Revise all
          </button>
          <button
            type="button"
            onClick={onFinish}
            title="Finish — accepted changes stay, everything undecided keeps its original"
            className="inline-flex items-center gap-1 rounded border border-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-700 hover:bg-slate-100"
          >
            <Check className="h-3 w-3" /> OK
          </button>
        </div>
      </div>
      {/* THE RUN'S ORDER — stated ONCE at the top (gap e533bc5 F4); the
          identical dump under every card died with this block. */}
      {runDirectives && (
        <div className="border-b border-slate-200 bg-white px-2.5 py-1.5">
          <div className="text-[8px] font-semibold uppercase tracking-wide text-slate-400">This run's order</div>
          <div className="mt-0.5 max-h-24 space-y-px overflow-auto">
            {runDirectives.map((d, k) => (
              <div key={k} className="text-[9px] leading-tight text-slate-500">{k + 1}. {d.text}</div>
            ))}
          </div>
        </div>
      )}
      {/* THE FILTER (gap e533bc5 F9): SEO/AI tabs, then the run's own
          purpose tags (multi-select). Each level carries its own Clear. */}
      {railTags.length > 0 && (
        <div className="border-b border-slate-200 bg-white px-2.5 py-1.5">
          <div className="flex items-center gap-1">
            {(['search', 'ai'] as const).map((g) => (
              <button
                key={g}
                type="button"
                onClick={() => {
                  const next = filterGroup === g ? null : g;
                  setFilterGroup(next);
                  // Tags outside the chosen group would filter invisibly — prune.
                  if (next !== null) setFilterTags((cur) => new Set([...cur].filter((t) => tagGroup(t) === next)));
                }}
                className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                  filterGroup === g ? 'bg-[#e7f5ff] text-primary' : 'text-slate-500 hover:bg-slate-100 hover:text-slate-700'
                }`}
              >
                {GROUP_PILLS[g].label}
              </button>
            ))}
            {filterGroup !== null && (
              <button
                type="button"
                onClick={() => setFilterGroup(null)}
                className="ml-auto text-[9px] text-slate-400 hover:text-slate-600"
              >
                Clear
              </button>
            )}
          </div>
          <div className="mt-1 flex flex-wrap items-center gap-1">
            {visibleTags.map((t) => (
              <button
                key={t}
                type="button"
                onClick={() => setFilterTags((cur) => {
                  const next = new Set(cur);
                  next.has(t) ? next.delete(t) : next.add(t);
                  return next;
                })}
                className={`rounded-full border px-1.5 py-px text-[9px] ${
                  filterTags.has(t)
                    ? 'border-primary/40 bg-[#e7f5ff] font-medium text-primary'
                    : 'border-slate-200 text-slate-500 hover:bg-slate-50'
                }`}
              >
                {(teacherById[t]?.label ?? TEACHER_PILLS[t] ?? t).toLowerCase()}
              </button>
            ))}
          </div>
          {filterTags.size > 0 && (
            <button
              type="button"
              onClick={() => setFilterTags(new Set())}
              className="mt-1 text-[9px] text-slate-400 hover:text-slate-600"
            >
              Clear
            </button>
          )}
        </div>
      )}
      {/* The Revise box (owner F2): one note, Adjust sends it. Serves both
          a single section (chip or rail Revise) and Revise-all. */}
      {reviseTarget !== null && (
        <div className="border-b border-slate-200 bg-white px-2.5 py-1.5">
          <div className="mb-1 truncate text-[10px] font-medium text-slate-500">
            {reviseTarget === 'all'
              ? 'Revise all undecided sections'
              : `Revise: ${review[reviseTarget]?.heading || '(untitled section)'}`}
          </div>
          <input
            autoFocus
            value={reviseNote}
            onChange={(e) => onReviseNoteChange(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') onRunRevise();
              if (e.key === 'Escape') onReviseTargetChange(null);
            }}
            placeholder="e.g. “shorter, and mention the guarantee”"
            className="h-6 w-full rounded border border-slate-200 bg-white px-1.5 text-[11px] text-slate-800 outline-none focus:border-primary"
          />
          <div className="mt-1 flex items-center gap-1.5">
            <button
              type="button"
              onClick={onRunRevise}
              disabled={!reviseNote.trim()}
              className="inline-flex items-center gap-1 rounded-full bg-green-600 px-2 py-0.5 text-[10px] font-medium text-white hover:bg-green-500 disabled:opacity-50"
            >
              Adjust
            </button>
            <button
              type="button"
              onClick={() => onReviseTargetChange(null)}
              className="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[10px] text-slate-500 hover:bg-slate-100"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
      <div className="min-h-0 flex-1 overflow-auto py-1">
        {filterActive && review.every((s) => s.status === 'clean' || !sectionMatches(s)) && (
          <div className="px-2.5 py-3 text-[10px] text-slate-500">
            No changes match the filter — clear it to see everything.
          </div>
        )}
        {review.map((s, i) => (s.status === 'clean' || !sectionMatches(s)) ? null : (
          <div
            key={`${s.heading}-${i}`}
            onClick={() => onFocusSection(i)}
            title="Click to jump to this section"
            className="cursor-pointer border-b border-slate-100 px-2.5 py-1.5 hover:bg-slate-100/60"
          >
            {/* Hierarchy (gap e533bc5 F5): the section TITLE leads. */}
            <div className="truncate text-[12px] font-semibold text-slate-800" title={s.heading}>{s.heading || '(untitled section)'}</div>
            {/* THE CHANGE CARDS (gap 0a0a3c3): the section's OWN verified
                changes — WHAT was done + WHY, hover lights the exact words
                in the text (claim-to-text verification). Server-checked:
                every line's quote was found in the produced text. */}
            {s.status === 'diff' && (s.changes?.length ?? 0) > 0 ? (
              <div title={s.genModel ? `Generated by ${s.genModel}` : undefined}>
                {s.rewritten && (
                  <div className="mt-0.5 inline-flex rounded-full bg-slate-100 px-1.5 py-px text-[8px] font-semibold text-slate-500">
                    Section rewritten
                  </div>
                )}
                <div className="mt-1 space-y-1">
                  {/* THE CHANGE ROW (owner 2026-07-19): a FIXED grid — 12px
                      checkbox column + text column. The box sits centered on
                      the FIRST text line by construction; wrapped lines stay
                      in their column, never under the box. */}
                  {(s.changes ?? []).map((c, k) => ((filterActive && !changeMatches(c.why)) ? null : (
                    <div
                      key={k}
                      className="grid cursor-default grid-cols-[12px_1fr] items-start gap-x-1.5 rounded px-0.5 py-px hover:bg-blue-50"
                      onMouseEnter={() => onHoverQuote(i, c.quote)}
                      onMouseLeave={onHoverEnd}
                      title="Hover shows exactly where this landed in the text"
                    >
                      {/* T2 (gap e1eb677): the per-change tick — untick to
                          shape the proposal; a partial set rebuilds via ONE
                          bounded re-run, never a splice guess. */}
                      <input
                        type="checkbox"
                        checked={s.kept?.[k] ?? true}
                        onClick={(e) => e.stopPropagation()}
                        onChange={() => onToggleKept(i, k)}
                        className="mt-[2px] h-3 w-3 accent-green-600"
                      />
                      <span className="min-w-0 text-[10px] leading-4 text-slate-500">
                        {c.what}
                        {c.why !== '' && (
                          <span className="ml-1 text-[8px] italic text-slate-400">
                            {(teacherById[c.why]?.label ?? TEACHER_PILLS[c.why] ?? c.why).toLowerCase()}
                          </span>
                        )}
                      </span>
                    </div>
                  )))}
                  {(s.kept ?? []).some((v) => !v) && (s.kept ?? []).some((v) => v) && (
                    <button
                      type="button"
                      onClick={() => onUpdateProposal(i, proposalNote(s))}
                      title="Rebuilds this section keeping only the ticked changes — the result comes back for review"
                      className="mt-0.5 inline-flex items-center rounded-full border border-primary/40 bg-white px-1.5 py-px text-[9px] font-medium text-primary hover:bg-[#e7f5ff]"
                    >
                      Update proposal ({(s.kept ?? []).filter(Boolean).length} of {(s.changes ?? []).length})
                    </button>
                  )}
                </div>
              </div>
            ) : /* THE SECTION'S OWN ORDERS (gap e533bc5 F4): while pending
                and as the card-less floor, the card shows what was ordered
                HERE — never the run-level dump (that lives once at the rail
                top now). */
            (s.directives?.length ?? 0) > 0 ? (
              <div title={s.genModel ? `Generated by ${s.genModel}` : undefined} className="mt-1 space-y-1">
                {(s.directives ?? [])
                  .filter((d) => !filterActive || d.purposes.some(tagMatches))
                  .map((d, k) => (
                    <div key={k} className="flex items-start gap-1.5 leading-snug">
                      <span className="text-slate-300">•</span>
                      <span className="min-w-0">
                        <span className="text-[10px] text-slate-500">{d.text}</span>
                        <span className="ml-1 text-[8px] italic text-slate-400">
                          {d.purposes.map((p) => (teacherById[p]?.label ?? TEACHER_PILLS[p] ?? p).toLowerCase()).join(', ')}
                        </span>
                      </span>
                    </div>
                  ))}
              </div>
            ) : s.genModel ? (
              <div className="truncate text-[9px] text-slate-400" title={`Generated by ${s.genModel}`}>{s.genModel}</div>
            ) : null}
            {s.status === 'pending' && (
              <div className="mt-0.5 inline-flex items-center gap-1 text-[10px] text-slate-500">
                <Loader2 className="h-3 w-3 animate-spin text-primary" /> Rewriting…
              </div>
            )}
            {s.status === 'diff' && (
              <div className="mt-1 flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); onResolve(i, 'accept'); }}
                  className="inline-flex items-center gap-1 rounded-full bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-500"
                >
                  <Check className="h-3 w-3" /> Accept
                </button>
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); onReviseTargetChange(i); onReviseNoteChange(''); }}
                  title="Send this section back to the AI with an adjustment"
                  className="inline-flex items-center gap-1 rounded-full border border-primary/40 bg-white px-1.5 py-0.5 text-[10px] text-primary hover:bg-[#e7f5ff]"
                >
                  ↻ Revise
                </button>
                <button
                  type="button"
                  onClick={(e) => { e.stopPropagation(); onResolve(i, 'reject'); }}
                  className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] text-slate-600 hover:bg-slate-50"
                >
                  <X className="h-3 w-3" /> Reject
                </button>
              </div>
            )}
            {s.status === 'accepted' && <div className="mt-0.5 text-[10px] font-medium text-green-700">✓ Accepted</div>}
            {s.status === 'rejected' && <div className="mt-0.5 text-[10px] text-slate-500">Rejected — original kept</div>}
            {s.status === 'failed' && (
              <div className="mt-0.5 text-[10px] text-red-600" title={s.error}>AI failed — original kept</div>
            )}
          </div>
        ))}
      </div>
    </aside>
  );
}
