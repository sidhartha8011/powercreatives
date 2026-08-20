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
import { splitDocSections } from '../word-diff';
import { itemKey, type CompiledDirective, type KeywordPackage, type OptimizerItem, type TeacherMeta, type TeacherRun } from './types';

export interface UseOptimizerArgs {
  siteId: number;
  postId: number;
  pageType: string;
  model: string;
  provider: string;
  /** The live document, diff marks stripped — read at analyze time. */
  getHtml: () => string;
  /** THE KEYWORD PACKAGE — live editor state, read at analyze time (the
   *  same source-of-truth law as the html). */
  getKeywords: () => KeywordPackage;
  /** The site's other pages — the interlink researcher's map. */
  pages: Array<{ id: number; title: string; permalink: string }>;
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

/**
 * How many teachers may be in flight at once.
 *
 * "Analyze all" used to fire EVERY teacher simultaneously. Each one is a PHP
 * request that calls an LLM or an external API and holds a worker for many
 * seconds, so eight at once exhausts the pool a typical WordPress host allows
 * and the proxy answers 502 for the rest — which is exactly what a run looked
 * like: the single fast local check passed, everything slower came back
 * "API error: 502". Two at a time keeps the wall-clock benefit of overlapping
 * without ever asking the host for more workers than it has.
 */
const MAX_PARALLEL = 2;

/** Backoff before the single automatic retry. */
const RETRY_DELAY_MS = 1200;

const sleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

/**
 * A gateway/overload failure — transient by nature, so worth one silent retry.
 * Deliberately NOT 500: that is the server reporting a real fault, and retrying
 * it just doubles the wait before showing the same error.
 */
/**
 * The shim's no-JSON fallback ("API error: 502 Bad Gateway") — the proxy
 * answered with an HTML page instead of our API. Only THIS shape earns the
 * generic "too busy" wording; a served JSON error keeps its own message.
 */
const isGatewayPage = (e: unknown): boolean =>
  e instanceof Error && /^API error: 50[234]\b/.test(e.message);

const isTransient = (e: unknown): boolean => {
  const status = (e as { status?: number } | null)?.status;
  if (status === 502 || status === 503 || status === 504) return true;
  const msg = e instanceof Error ? e.message : String(e ?? '');
  return /\b(50[234])\b|bad gateway|gateway time-?out|service unavailable/i.test(msg);
};

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

  const analyzeOne = useCallback(async (teacherId: string): Promise<void> => {
    const seq = (runSeq.current[teacherId] = (runSeq.current[teacherId] ?? 0) + 1);
    setRuns((cur) => ({ ...cur, [teacherId]: { status: 'running', items: cur[teacherId]?.items ?? [] } }));

    const send = () => analyzeMutation.mutateAsync({
      teacherId,
      siteId: args.siteId,
      postId: args.postId,
      html: args.getHtml(),
      pageType: args.pageType,
      model: args.model,
      provider: args.provider,
      keywords: args.getKeywords(),
      pages: args.pages,
    });

    try {
      let res: any;
      try {
        res = await send();
      }
      catch (e) {
        // One silent retry for a busy gateway. A teacher that lost the race for
        // a worker almost always succeeds a moment later, and making the author
        // press retry per section for that is pure friction.
        if (!isTransient(e)) throw e;
        await sleep(RETRY_DELAY_MS);
        if (runSeq.current[teacherId] !== seq) return; // superseded while waiting
        res = await send();
      }

      if (runSeq.current[teacherId] !== seq) return; // superseded — drop
      const items: OptimizerItem[] = Array.isArray(res?.items) ? res.items : [];
      // THE PEEK: what this run was actually given (server-reported).
      const context = res?.contextUsed && typeof res.contextUsed === 'object' ? res.contextUsed : undefined;
      setRuns((cur) => ({ ...cur, [teacherId]: { status: 'done', items, context } }));
      // Fresh gaps come PRE-TICKED (owner spec); resolved ones leave the
      // basket. An informational finding without a directive (e.g. "no
      // primary keyword set", an unreachable engine) can't ride the
      // basket — it renders, but never ticks.
      setBasket((cur) => {
        const next = new Set(cur);
        items.forEach((it) => {
          if (it.found && it.instruction !== '') next.add(itemKey(it));
          else next.delete(itemKey(it));
        });
        return next;
      });
    }
    catch (e: unknown) {
      if (runSeq.current[teacherId] !== seq) return;
      setRuns((cur) => ({
        ...cur,
        [teacherId]: {
          status: 'failed',
          items: cur[teacherId]?.items ?? [],
          // A bare "API error: 502" is the shim's fallback for an UNPARSEABLE
          // body — a real gateway page — and deserves the plain-language line.
          // A 502 WITH a message is the server reporting an actual fault (the
          // optimizer controller maps teacher exceptions to 502, e.g. an LLM
          // timeout), and hiding that behind "too busy" buries the diagnosis.
          error: isGatewayPage(e)
            ? 'The site was too busy to answer (502). Already retried once — press retry to try again.'
            : (e instanceof Error ? e.message : 'Analysis failed'),
        },
      }));
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [args.siteId, args.postId, args.pageType, args.model, args.provider, args.pages]);

  const analyzeAll = useCallback(() => {
    // A bounded worker pool, NOT forEach: see MAX_PARALLEL. The queue is drained
    // by a fixed number of workers, so the host never sees more than that many
    // long-running analyses at once however many teachers exist.
    const queue = teachers.map((t) => t.id);
    const drain = async (): Promise<void> => {
      for (;;) {
        const id = queue.shift();
        if (id === undefined) return;
        await analyzeOne(id);
      }
    };
    void Promise.all(
      Array.from({ length: Math.min(MAX_PARALLEL, queue.length) }, drain),
    );
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
        // The same package the teachers analyzed with — the merge respects
        // the keyword hierarchy and real business facts (spine D6).
        siteId: args.siteId,
        keywords: args.getKeywords(),
        // THE ROUTER (gap eeec6b9): the live outline lets the compiler
        // assign every directive its target sections in the same call.
        outline: splitDocSections(args.getHtml()).sections.map((s) => s.heading || '(untitled section)'),
      });
      return Array.isArray(res?.directives) ? (res.directives as CompiledDirective[]) : [];
    } finally {
      setCompiling(false);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [teachers, runs, basket, args.model, args.provider, args.siteId]);

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
