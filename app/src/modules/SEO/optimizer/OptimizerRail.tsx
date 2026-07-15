/**
 * THE ANALYZE RAIL (owner UX law + hierarchy order 2026-07-15): TWO top
 * groups — Search optimization · AI optimization — each rendering its
 * teachers as WHITE PURPOSE CARDS on the slate rail. Inside a card every
 * suggestion is ONE scannable line (checkbox + short label + chevron);
 * the full detail — what we found, the fix that rides the basket, the
 * data source — lives in the row's disclosure. Passed checks collapse
 * into one quiet summary row. NOTHING is removed; everything is one tap
 * deep (owner: all information stays).
 *
 * Items are SUGGESTIONS for the basket, never accept/reject: the basket
 * compiles into ONE optimization run whose changes land as the normal
 * red/green review. THE PEEK: the card's info icon reveals exactly what
 * the run was given — read-only provenance.
 *
 * Mounting the rail runs every teacher once (the Analyze button's
 * promise: "analyze with all the inputs it has").
 */

import { useEffect, useRef, useState } from 'react';
import { Check, ChevronDown, Info, Loader2, RotateCw, X } from 'lucide-react';
import { useOptimizer, type UseOptimizerArgs } from './useOptimizer';
import { itemKey, RAIL_GROUPS, type CompiledDirective, type OptimizerItem, type RunContext, type TeacherMeta, type TeacherRun } from './types';

interface OptimizerRailProps extends UseOptimizerArgs {
  onClose: () => void;
  /** Hands the COMPILED directives to the page's ONE optimize pipeline. */
  onOptimize: (directives: CompiledDirective[]) => void;
}

/** The peek's readable lines — only what the run actually had. */
function peekLines(ctx: RunContext): string[] {
  const lines: string[] = [];
  if (ctx.keywords.primary !== '') lines.push(`Primary keyword: ${ctx.keywords.primary}`);
  if (ctx.keywords.supporting.length > 0) lines.push(`Supporting: ${ctx.keywords.supporting.join(', ')}`);
  if (ctx.keywords.additional.length > 0) lines.push(`Additional: ${ctx.keywords.additional.join(', ')}`);
  if (ctx.keywords.primary === '' && ctx.keywords.supporting.length === 0 && ctx.keywords.additional.length === 0) {
    lines.push('No keywords were set for this run.');
  }
  lines.push(ctx.businessName !== ''
    ? `Business: ${ctx.businessName} (${ctx.businessFields.filter((f) => !['name', 'siteUrl'].includes(f)).join(', ') || 'name only'})`
    : 'No business linked for this run.');
  if (ctx.pageType !== '' && ctx.pageType !== 'general') lines.push(`Page type: ${ctx.pageType}`);
  if (ctx.pageCount > 0) lines.push(`Site pages provided: ${ctx.pageCount}`);
  if (ctx.model !== '') lines.push(`Model: ${ctx.model}${ctx.provider !== '' ? ` (${ctx.provider})` : ''}`);
  return lines;
}

export function OptimizerRail({ onClose, onOptimize, ...args }: OptimizerRailProps) {
  const opt = useOptimizer(args);
  const [compileError, setCompileError] = useState<string | null>(null);
  /** Which card's peek is open (one at a time — the rail is narrow). */
  const [peekOpen, setPeekOpen] = useState<string | null>(null);
  /** Open item disclosures (per itemKey) + open passed-summaries (per teacher). */
  const [openItems, setOpenItems] = useState<Set<string>>(new Set());
  const [openPassed, setOpenPassed] = useState<Set<string>>(new Set());

  // First mount with a loaded registry = the Analyze click's full run.
  const startedRef = useRef(false);
  useEffect(() => {
    if (startedRef.current || opt.teachers.length === 0) return;
    startedRef.current = true;
    opt.analyzeAll();
  }, [opt]);

  const runOptimize = () => {
    setCompileError(null);
    void opt.compileBasket()
      .then((directives) => {
        if (directives.length > 0) onOptimize(directives);
      })
      .catch((e: unknown) => {
        // The certainty contract failed loudly — show it, never run partial.
        setCompileError(e instanceof Error ? e.message : 'Compiling the selection failed');
      });
  };

  const toggleSet = (set: Set<string>, key: string): Set<string> => {
    const next = new Set(set);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    return next;
  };

  /** ONE scannable line + its disclosure — the item's whole story lives
   *  one tap deep (label → evidence → the fix → the source). */
  const renderItem = (it: OptimizerItem) => {
    const key = itemKey(it);
    const open = openItems.has(key);
    const actionable = it.instruction !== '';
    return (
      <div key={key}>
        <div className="flex items-center gap-1.5">
          {actionable ? (
            <input
              type="checkbox"
              checked={opt.basket.has(key)}
              onChange={() => opt.toggle(it)}
              className="h-3 w-3 shrink-0 accent-[#007bff]"
            />
          ) : (
            <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-400" title={it.evidence} />
          )}
          <button
            type="button"
            onClick={() => setOpenItems((cur) => toggleSet(cur, key))}
            className="flex min-w-0 flex-1 items-center gap-1 rounded py-0.5 text-left hover:bg-slate-50"
          >
            <span className="min-w-0 flex-1 truncate text-[11px] leading-tight text-slate-700">{it.label}</span>
            <ChevronDown className={`h-3 w-3 shrink-0 text-slate-300 transition-transform ${open ? 'rotate-180' : ''}`} />
          </button>
        </div>
        {open && (
          <div className="mb-1 ml-[18px] space-y-1">
            {it.evidence !== '' && (
              <div className="text-[10px] leading-snug text-slate-500">{it.evidence}</div>
            )}
            {actionable && (
              /* The FIX — exactly what rides the basket when ticked. */
              <div className="rounded border-l-2 border-[#007bff]/50 bg-[#e7f5ff]/50 px-1.5 py-1 text-[10px] leading-snug text-slate-600">
                {it.instruction}
              </div>
            )}
            {it.source !== undefined && it.source !== '' && (
              <div className="text-[9px] uppercase tracking-wide text-slate-300">via {it.source}</div>
            )}
          </div>
        )}
      </div>
    );
  };

  /** One purpose = one white card: header (name · peek · re-analyze),
   *  found items as one-liners, passed checks behind a summary row. */
  const renderCard = (t: TeacherMeta, run: TeacherRun) => {
    const found = run.items.filter((it) => it.found);
    const clean = run.items.filter((it) => !it.found);
    const passedOpen = openPassed.has(t.id);
    return (
      <section key={t.id} className="mx-2 mb-1.5 rounded-lg border border-slate-200/70 bg-white px-2 py-1.5 shadow-sm">
        <div className="flex items-center gap-1.5">
          <div className="min-w-0 flex-1 truncate text-[11px] font-semibold text-slate-700" title={t.label}>
            {t.label}
          </div>
          {run.context !== undefined && (
            <button
              type="button"
              onClick={() => setPeekOpen((cur) => (cur === t.id ? null : t.id))}
              title="What this analysis was given"
              className={`shrink-0 rounded p-0.5 hover:bg-slate-100 ${peekOpen === t.id ? 'text-primary' : 'text-slate-400 hover:text-primary'}`}
            >
              <Info className="h-3 w-3" />
            </button>
          )}
          <button
            type="button"
            onClick={() => opt.analyzeOne(t.id)}
            title="Re-analyze this purpose for fresh suggestions"
            className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-primary"
          >
            {run.status === 'running'
              ? <Loader2 className="h-3 w-3 animate-spin text-primary" />
              : <RotateCw className="h-3 w-3" />}
          </button>
        </div>

        {/* THE PEEK — read-only, exactly what the run received. */}
        {peekOpen === t.id && run.context !== undefined && (
          <div className="mt-1 space-y-0.5 rounded bg-slate-100/80 px-1.5 py-1">
            {peekLines(run.context).map((line) => (
              <div key={line} className="text-[10px] leading-tight text-slate-500">{line}</div>
            ))}
          </div>
        )}

        {run.status === 'failed' && (
          <div className="mt-1 text-[10px] text-red-600" title={run.error}>
            {run.error || 'Analysis failed'} — retry with the button above.
          </div>
        )}

        {/* An empty result is STATED, never a blank section. */}
        {run.status === 'done' && run.items.length === 0 && (
          <div className="mt-1 text-[10px] text-slate-400">
            The analysis returned no results — re-analyze with the button above.
          </div>
        )}

        {found.length > 0 && (
          <div className="mt-1 space-y-0.5">
            {found.map(renderItem)}
          </div>
        )}

        {/* Passed checks: ONE quiet summary row — the list is a tap away. */}
        {run.status === 'done' && clean.length > 0 && (
          <div className="mt-1">
            <button
              type="button"
              onClick={() => setOpenPassed((cur) => toggleSet(cur, t.id))}
              className="flex w-full items-center gap-1.5 rounded py-0.5 text-left hover:bg-slate-50"
            >
              <Check className="h-3 w-3 shrink-0 text-green-600" />
              <span className="min-w-0 flex-1 truncate text-[10px] text-slate-400">{clean.length} passed</span>
              <ChevronDown className={`h-3 w-3 shrink-0 text-slate-300 transition-transform ${passedOpen ? 'rotate-180' : ''}`} />
            </button>
            {passedOpen && (
              <div className="ml-[18px] space-y-0.5">
                {clean.map((it) => (
                  <div key={itemKey(it)} className="flex items-start gap-1.5 opacity-70" title={it.evidence}>
                    <Check className="mt-0.5 h-2.5 w-2.5 shrink-0 text-green-600" />
                    <span className="min-w-0 text-[10px] leading-tight text-slate-500">
                      {it.label}
                      {it.source !== undefined && it.source !== '' && (
                        <span className="text-slate-300"> · via {it.source}</span>
                      )}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </section>
    );
  };

  return (
    <aside className="flex w-[250px] shrink-0 flex-col border-l border-slate-200 bg-slate-50/60">
      <div className="flex items-center gap-1.5 border-b border-slate-200 px-2.5 py-1.5">
        <div className="min-w-0 flex-1 truncate text-[11px] font-medium text-slate-700">Analyze</div>
        <button
          type="button"
          onClick={onClose}
          title="Close the analysis"
          className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
        >
          <X className="h-3 w-3" />
        </button>
      </div>

      <div className="min-h-0 flex-1 overflow-auto py-1">
        {RAIL_GROUPS.map((group) => {
          const members = opt.teachers.filter((t) => t.group === group.id);
          if (members.length === 0) return null;
          return (
            <div key={group.id}>
              <div className="px-3 pb-1 pt-1.5 text-[9px] font-semibold uppercase tracking-widest text-slate-400">
                {group.label}
              </div>
              {members.map((t) => renderCard(t, opt.runs[t.id]))}
            </div>
          );
        })}
      </div>

      <div className="border-t border-slate-200 px-2.5 py-1.5">
        {compileError !== null && (
          <div className="mb-1 text-[10px] text-red-600">{compileError}</div>
        )}
        <button
          type="button"
          onClick={runOptimize}
          disabled={opt.selectedCount === 0 || opt.compiling}
          title="Compiles every selected suggestion into ONE optimization order — changes land as red/green to accept or reject"
          className="inline-flex w-full items-center justify-center gap-1 rounded-full bg-[#e7f5ff] px-2.5 py-1 text-xs font-semibold text-primary disabled:opacity-50"
        >
          {opt.compiling && <Loader2 className="h-3 w-3 animate-spin" />}
          {opt.compiling ? 'Compiling the order…' : `Optimize selected${opt.selectedCount > 0 ? ` (${opt.selectedCount})` : ''}`}
        </button>
      </div>
    </aside>
  );
}
