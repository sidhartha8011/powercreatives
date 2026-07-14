/**
 * THE OPTIMIZER's state engine: teacher registry, per-teacher runs, the
 * basket, and the directives it compiles for the ONE optimization run.
 *
 * Teachers analyze independently and in parallel — a slow or failed
 * purpose fails ALONE (its section shows its own retry); the catalog
 * merge is the basket, nothing else.
 */

import { useCallback, useRef, useState } from 'react';
import { trpc } from '@/lib/trpc';
import { itemKey, type CompiledDirective, type OptimizerItem, type TeacherMeta, type TeacherRun } from './types';

export interface UseOptimizerArgs {
  siteId: number;
  postId: number;
  pageType: string;
  model: string;
  provider: string;
  /** The live document, diff marks stripped — read at analyze time. */
  getHtml: () => string;
}

export interface UseOptimizer {
  teachers: TeacherMeta[];
  runs: Record<string, TeacherRun>;
  basket: Set<string>;
  /** Every teacher, in parallel — the Analyze button. */
  analyzeAll: () => void;
  /** One purpose — the section's own re-analyze button. */
  analyzeOne: (teacherId: string) => void;
  toggle: (item: OptimizerItem) => void;
  /** THE COMPILER: merges the ticked suggestions into the run's ordered,
   *  provenance-tagged to-do list (server-enforced: no intent may drop). */
  compileBasket: () => Promise<CompiledDirective[]>;
  compiling: boolean;
  selectedCount: number;
}

const IDLE: TeacherRun = { status: 'idle', items: [] };

export function useOptimizer(args: UseOptimizerArgs): UseOptimizer {
  const teachersQuery = trpc.optimizer.teachers.useQuery(undefined, { staleTime: 60_000 });
  const teachers: TeacherMeta[] = Array.isArray((teachersQuery.data as any)?.teachers)
    ? ((teachersQuery.data as any).teachers as TeacherMeta[])
    : [];

  const analyzeMutation = trpc.optimizer.analyze.useMutation();
  const [runs, setRuns] = useState<Record<string, TeacherRun>>({});
  const [basket, setBasket] = useState<Set<string>>(new Set());
  // Guard against out-of-order results: only the LATEST run per teacher lands.
  const runSeq = useRef<Record<string, number>>({});

  const analyzeOne = useCallback((teacherId: string) => {
    const seq = (runSeq.current[teacherId] = (runSeq.current[teacherId] ?? 0) + 1);
    setRuns((cur) => ({ ...cur, [teacherId]: { status: 'running', items: cur[teacherId]?.items ?? [] } }));
    analyzeMutation
      .mutateAsync({
        teacherId,
        siteId: args.siteId,
        postId: args.postId,
        html: args.getHtml(),
        pageType: args.pageType,
        model: args.model,
        provider: args.provider,
      })
      .then((res: any) => {
        if (runSeq.current[teacherId] !== seq) return; // superseded — drop
        const items: OptimizerItem[] = Array.isArray(res?.items) ? res.items : [];
        setRuns((cur) => ({ ...cur, [teacherId]: { status: 'done', items } }));
        // Fresh gaps come PRE-TICKED (owner spec); resolved ones leave the basket.
        setBasket((cur) => {
          const next = new Set(cur);
          items.forEach((it) => {
            if (it.found) next.add(itemKey(it));
            else next.delete(itemKey(it));
          });
          return next;
        });
      })
      .catch((e: unknown) => {
        if (runSeq.current[teacherId] !== seq) return;
        setRuns((cur) => ({
          ...cur,
          [teacherId]: {
            status: 'failed',
            items: cur[teacherId]?.items ?? [],
            error: e instanceof Error ? e.message : 'Analysis failed',
          },
        }));
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [args.siteId, args.postId, args.pageType, args.model, args.provider]);

  const analyzeAll = useCallback(() => {
    teachers.forEach((t) => analyzeOne(t.id));
  }, [teachers, analyzeOne]);

  const toggle = useCallback((item: OptimizerItem) => {
    const key = itemKey(item);
    setBasket((cur) => {
      const next = new Set(cur);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }, []);

  const compileMutation = trpc.optimizer.compile.useMutation();
  const [compiling, setCompiling] = useState(false);
  const compileBasket = useCallback(async (): Promise<CompiledDirective[]> => {
    const selected: Array<{ instruction: string; teacherId: string; label: string }> = [];
    teachers.forEach((t) => {
      (runs[t.id]?.items ?? []).forEach((it) => {
        if (basket.has(itemKey(it))) selected.push({ instruction: it.instruction, teacherId: it.teacherId, label: it.label });
      });
    });
    if (selected.length === 0) return [];
    setCompiling(true);
    try {
      const res: any = await compileMutation.mutateAsync({
        items: selected,
        model: args.model,
        provider: args.provider,
      });
      return Array.isArray(res?.directives) ? (res.directives as CompiledDirective[]) : [];
    } finally {
      setCompiling(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [teachers, runs, basket, args.model, args.provider]);

  const selectedCount = teachers.reduce(
    (n, t) => n + (runs[t.id]?.items ?? []).filter((it) => basket.has(itemKey(it))).length,
    0,
  );

  return {
    teachers,
    runs: teachers.reduce<Record<string, TeacherRun>>(
      (acc, t) => ({ ...acc, [t.id]: runs[t.id] ?? IDLE }),
      {},
    ),
    basket,
    analyzeAll,
    analyzeOne,
    toggle,
    compileBasket,
    compiling,
    selectedCount,
  };
}
