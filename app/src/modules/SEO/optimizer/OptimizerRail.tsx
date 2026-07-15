/**
 * THE ANALYZE RAIL (owner UX law, backlog "spine" spec): TWO top groups —
 * Search optimization · AI optimization (owner taxonomy ruling 2026-07-14)
 * — each rendering its teachers as purpose sections, each split into WHAT
 * WE FOUND (tickable, pre-selected) and NOTHING TO FIX (quiet) — each with
 * its OWN re-analyze. Items are SUGGESTIONS for the basket, never
 * accept/reject: the basket compiles into ONE optimization run whose
 * changes then land as the normal red/green review.
 *
 * THE PEEK (owner confirmation tool): every section's info icon reveals
 * exactly what that run was given — keywords, business facts, page type,
 * engine — read-only. Items that came from a data tap carry its name.
 *
 * Mounting the rail runs every teacher once (the Analyze button's
 * promise: "analyze with all the inputs it has").
 */

import { useEffect, useRef, useState } from 'react';
import { Check, Info, Loader2, RotateCw, X } from 'lucide-react';
import { useOptimizer, type UseOptimizerArgs } from './useOptimizer';
import { itemKey, RAIL_GROUPS, type CompiledDirective, type RunContext, type TeacherMeta, type TeacherRun } from './types';

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
  /** Which section's peek is open (one at a time — the rail is narrow). */
  const [peekOpen, setPeekOpen] = useState<string | null>(null);

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

  const renderSection = (t: TeacherMeta, run: TeacherRun) => {
    const found = run.items.filter((it) => it.found);
    const clean = run.items.filter((it) => !it.found);
    return (
      <section key={t.id} className="border-b border-slate-100 px-2.5 py-1.5">
        <div className="flex items-center gap-1.5">
          <div className="min-w-0 flex-1 truncate text-[11px] font-medium text-slate-700" title={t.label}>
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
          <div className="mt-1 space-y-1">
            {found.map((it) => (
              <label key={itemKey(it)} className="flex cursor-pointer items-start gap-1.5">
                <input
                  type="checkbox"
                  checked={opt.basket.has(itemKey(it))}
                  onChange={() => opt.toggle(it)}
                  disabled={it.instruction === ''}
                  className="mt-0.5 h-3 w-3 shrink-0 accent-[#007bff] disabled:opacity-40"
                />
                {/* POSITIVES law (owner 2026-07-14): the row says what
                    TO DO — the finding is the quiet subtext. */}
                <span className="min-w-0" title={it.label}>
                  <span className="block text-[11px] leading-tight text-slate-700">{it.instruction !== '' ? it.instruction : it.label}</span>
                  {(it.evidence !== '' || it.source !== undefined) && (
                    <span className="block text-[10px] leading-tight text-slate-400">
                      {it.evidence}
                      {it.source !== undefined && it.source !== '' && (
                        <span className="text-slate-300"> · via {it.source}</span>
                      )}
                    </span>
                  )}
                </span>
              </label>
            ))}
          </div>
        )}

        {run.status === 'done' && clean.length > 0 && (
          <div className="mt-1 space-y-0.5">
            {clean.map((it) => (
              <div key={itemKey(it)} className="flex items-start gap-1.5 opacity-60" title={it.evidence}>
                <Check className="mt-0.5 h-3 w-3 shrink-0 text-green-600" />
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
              <div className="bg-slate-100/70 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                {group.label}
              </div>
              {members.map((t) => renderSection(t, opt.runs[t.id]))}
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
