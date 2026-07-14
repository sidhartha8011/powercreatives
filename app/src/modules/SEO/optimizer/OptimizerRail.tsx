/**
 * THE ANALYZE RAIL (owner UX law, backlog "spine" spec): purpose sections
 * in registry order — each split into WHAT WE FOUND (tickable, pre-
 * selected) and NOTHING TO FIX (quiet) — each with its OWN re-analyze.
 * Items are SUGGESTIONS for the basket, never accept/reject: the basket
 * compiles into ONE optimization run whose changes then land as the
 * normal red/green review.
 *
 * Mounting the rail runs every teacher once (the Analyze button's
 * promise: "analyze with all the inputs it has").
 */

import { useEffect, useRef } from 'react';
import { Check, Loader2, RotateCw, X } from 'lucide-react';
import { useOptimizer, type UseOptimizerArgs } from './useOptimizer';
import { teacherSectionBodies } from './sections';
import { itemKey } from './types';

interface OptimizerRailProps extends UseOptimizerArgs {
  onClose: () => void;
  /** Hands the compiled directives to the page's ONE optimize pipeline. */
  onOptimize: (directives: string) => void;
}

export function OptimizerRail({ onClose, onOptimize, ...args }: OptimizerRailProps) {
  const opt = useOptimizer(args);

  // First mount with a loaded registry = the Analyze click's full run.
  const startedRef = useRef(false);
  useEffect(() => {
    if (startedRef.current || opt.teachers.length === 0) return;
    startedRef.current = true;
    opt.analyzeAll();
  }, [opt]);

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
        {opt.teachers.map((t) => {
          const run = opt.runs[t.id];
          const found = run.items.filter((it) => it.found);
          const clean = run.items.filter((it) => !it.found);
          const Custom = teacherSectionBodies[t.id];
          return (
            <section key={t.id} className="border-b border-slate-100 px-2.5 py-1.5">
              <div className="flex items-center gap-1.5">
                <div className="min-w-0 flex-1 truncate text-[11px] font-medium text-slate-700" title={t.label}>
                  {t.label}
                </div>
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

              {Custom && <Custom run={run} siteId={args.siteId} postId={args.postId} />}

              {found.length > 0 && (
                <div className="mt-1 space-y-1">
                  {found.map((it) => (
                    <label key={itemKey(it)} className="flex cursor-pointer items-start gap-1.5">
                      <input
                        type="checkbox"
                        checked={opt.basket.has(itemKey(it))}
                        onChange={() => opt.toggle(it)}
                        className="mt-0.5 h-3 w-3 shrink-0 accent-[#007bff]"
                      />
                      <span className="min-w-0">
                        <span className="block text-[11px] leading-tight text-slate-700">{it.label}</span>
                        {it.evidence !== '' && (
                          <span className="block text-[10px] leading-tight text-slate-400">{it.evidence}</span>
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
                      <span className="min-w-0 text-[10px] leading-tight text-slate-500">{it.label}</span>
                    </div>
                  ))}
                </div>
              )}
            </section>
          );
        })}
      </div>

      <div className="border-t border-slate-200 px-2.5 py-1.5">
        <button
          type="button"
          onClick={() => {
            const directives = opt.buildDirectives();
            if (directives !== '') onOptimize(directives);
          }}
          disabled={opt.selectedCount === 0}
          title="Run ONE optimization applying every selected suggestion — changes land as red/green to accept or reject"
          className="inline-flex w-full items-center justify-center gap-1 rounded-full bg-[#e7f5ff] px-2.5 py-1 text-xs font-semibold text-primary disabled:opacity-50"
        >
          Optimize selected{opt.selectedCount > 0 ? ` (${opt.selectedCount})` : ''}
        </button>
      </div>
    </aside>
  );
}
