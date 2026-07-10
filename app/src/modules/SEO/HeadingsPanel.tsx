/**
 * HeadingRows — the expandable page-content editor shown under a page row in the SEO table.
 *
 * Renders the page's content as REAL rows of the parent table (same <tr>/<td> primitives,
 * so the SEO_TABLE_GRID borders, cell heights and <colgroup> widths apply verbatim):
 *
 *  - HEADING rows (H1–H6): the shipped editor, unchanged — colored tag chip (dropdown to
 *    retag), click-to-edit text, hover ✦ Optimize with staged Accept/Reject. Local edits go
 *    through the EXISTING heading endpoints via `headingIndex`.
 *  - ¶ SECTION rows (owner-spec v2): ONE row per section (heading + its paragraphs) instead
 *    of a row per <p>. Click → the floating SectionModal opens BELOW the click with the whole
 *    section as directly editable, formatted text. Added sections (sectionInsert rules) render
 *    as NEW rows at their served spot; “+ Add section” creates one.
 *
 * SECTION MEMBERSHIP (the root-cause fix, contracts v2): a section's paragraphs come from the
 * CONNECTOR SCAN's own anchors (nearest preceding rendered heading — the serving engine's
 * definition), NEVER from display layout. Duplicate anchors: the k-th contiguous run of
 * same-anchor paragraphs belongs to the k-th matching heading. Paragraph runs whose anchor
 * heading is NOT in the heading scan (e.g. the theme's comments title) render as standalone
 * muted ¶ rows at the end — addressable, never silently dropped or mis-grouped.
 *
 * LOCAL tab: sections come from the single local parse (true document order) and open
 * read-only (routing law — no rule engine on the hub's own site).
 */

import { Fragment, useEffect, useState, type KeyboardEvent, type MouseEvent } from 'react';
import { Loader2, Sparkles, Check, X, RefreshCw, CornerDownRight, Lock, LayoutTemplate, Maximize2, Plus } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger,
} from '@/components/ui/select';
import { TableRow, TableCell } from './seo-table';
import { SectionModal, type SectionData, type SectionAnchor, type InsertData, type SectionParagraph } from './SectionModal';

export interface HeadingItem {
  index: number;
  level: number;
  text: string;
  html: string;
  source: string;
  elId: string;
  field: string;
  editable: boolean;
  /** Set for headings that live in a SHARED source (Elementor Theme Builder template or reusable
   *  block) rendered on many pages — `sourceLabel` names it; editing changes every page using it. */
  sourceLabel?: string;
  sourcePostId?: number;
}

/** One ordered page-content node (heading or paragraph) — mirrors get_post_content_nodes. */
export interface ContentNode {
  index: number;
  kind: 'heading' | 'paragraph';
  level?: number;
  text: string;
  html: string;
  source: string;
  elId: string;
  field?: string;
  editable: boolean;
  /** Heading nodes only: position in the headings-only list — the EXISTING
   *  heading endpoints' edit handle (local). Remote nodes reuse `index`. */
  headingIndex?: number;
  sourceLabel?: string;
  sourcePostId?: number;
  occurrence?: number;
  anchor?: { level: number; text: string } | null;
}

/** One raw paragraph from the connector scan (scan-content v1) — scan order. */
interface RawPara {
  text: string;
  html: string;
  occurrence: number;
  /** Nearest preceding rendered heading — SECTION MEMBERSHIP (normalized text). */
  anchor: { level: number; text: string } | null;
}

/** One hub-stored dynamic rule (the UI overlay's source of truth). */
interface DynamicRule {
  id: number;
  target: string;
  matchText: string;
  occurrence: number;
  replacement: string;
  active: boolean;
  staleCount: number;
  /** Section rules: {level, fingerprint?} / {level, position} (contracts v2). */
  section?: { level?: number; fingerprint?: string; position?: string } | null;
}

/** A section as the panel models it: heading node (or anchor-only) + its paragraphs. */
interface PanelSection {
  key: string;
  heading: { text: string; level: number; occurrence: number; html: string };
  /** The heading's node (undefined for anchor-only sections — e.g. comments title). */
  headingNode?: ContentNode;
  paragraphs: RawPara[];
}

const THEME_READONLY_REASON =
  'Read-only — this heading lives in your theme or a page template, not the editable page content. Edit it on the site.';
const HARDCODED_CODES = new Set(['pcm_seo_heading_not_found', 'pcm_seo_heading_not_editable']);
const STALE_CODE = 'pcm_seo_heading_stale';

const TAG_STYLE: Record<number, string> = {
  1: 'bg-blue-500/10 text-blue-500 border-blue-500/40',
  2: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/40',
  3: 'bg-amber-500/10 text-amber-600 border-amber-500/40',
  4: 'bg-orange-600/10 text-orange-600 border-orange-600/40',
  5: 'bg-rose-500/10 text-rose-500 border-rose-500/40',
  6: 'bg-violet-500/10 text-violet-500 border-violet-500/40',
};

const indentFor = (level: number): number => 18 + Math.max(0, level - 1) * 14;

export function HeadingRows({
  postId, type, siteId, brandId, model, provider, orderedCols,
}: {
  postId: number;
  type: 'post' | 'page';
  siteId: number | 'local';
  brandId?: number;
  model?: string;
  provider?: string;
  orderedCols: string[];
}) {
  const isLocal = siteId === 'local';

  // Fresh scan on every accordion open (mount) — see the shipped rationale.
  const localQuery = trpc.seo.getContentNodes.useQuery(
    { id: postId },
    { enabled: isLocal, staleTime: 0, refetchOnMount: 'always' },
  );
  // ONE inventory read (cleanup C2): heading rows + paragraph nodes from a
  // single hub-side parse — replaces the serialized headings→content-nodes
  // query chain.
  const remoteQuery = trpc.seo.remoteGetInventory.useQuery(
    { siteId: siteId as number, postId, type },
    { enabled: !isLocal, staleTime: 0, refetchOnMount: 'always' },
  );
  const query = isLocal ? localQuery : remoteQuery;

  const [nodes, setNodes] = useState<ContentNode[]>([]);
  /** Remote: the RAW scan paragraphs in scan order — section membership's source of truth. */
  const [rawParas, setRawParas] = useState<RawPara[]>([]);
  const [remoteParaNote, setRemoteParaNote] = useState<string | null>(null);
  const headingsToNodes = (list: HeadingItem[]): ContentNode[] =>
    list.map((h) => ({ ...h, kind: 'heading' as const, headingIndex: h.index }));
  useEffect(() => {
    if (isLocal) {
      const list = (localQuery.data as any)?.nodes;
      if (Array.isArray(list)) setNodes(list as ContentNode[]);
      setRawParas([]);
      setRemoteParaNote(null);
      return;
    }
    const meta: any = remoteQuery.data ?? null;
    const list = meta?.headings;
    if (!Array.isArray(list)) return;
    setNodes(headingsToNodes(list as HeadingItem[]));
    const paragraphs: any[] = Array.isArray(meta?.nodes) ? meta.nodes : [];
    setRawParas(paragraphs.map((p) => ({
      text: String(p?.text ?? ''),
      html: String(p?.html ?? ''),
      occurrence: Number(p?.occurrence ?? 0),
      anchor: p?.anchor && typeof p.anchor.text === 'string'
        ? { level: Number(p.anchor.level) || 0, text: String(p.anchor.text) }
        : null,
    })));
    if (meta && meta.supported === false) {
      setRemoteParaNote('Sections need connector v2.7.0+ on this site — update it from the Sites module.');
    } else if (meta && meta.error === 'loopback_blocked') {
      setRemoteParaNote('Sections unavailable — the site blocked the connector’s content scan (loopback request).');
    } else {
      setRemoteParaNote(null);
    }
  }, [isLocal, localQuery.data, remoteQuery.data]);

  const localUpdate = trpc.seo.updateHeading.useMutation();
  const remoteUpdate = trpc.seo.remoteUpdateHeading.useMutation();
  const localOptimize = trpc.seo.optimizeHeading.useMutation();
  const remoteOptimize = trpc.seo.remoteOptimizeHeading.useMutation();

  // ── Dynamic rules overlay (remote): what the site actually SERVES. ──
  const rulesQuery = trpc.seo.remoteGetParagraphRules.useQuery(
    { siteId: siteId as number, postId },
    { enabled: !isLocal, staleTime: 0 },
  );
  const rules: DynamicRule[] = Array.isArray((rulesQuery.data as any)?.rules)
    ? ((rulesQuery.data as any).rules as DynamicRule[])
    : [];

  /** Display-side normalization (entity decode + NBSP/whitespace/case) — mirrors
   *  spec v1 for MATCHING display state; the authoritative normalize is server-side. */
  const jsNormalize = (s: string): string => {
    const el = document.createElement('textarea');
    el.innerHTML = s;
    return el.value.replace(/ /g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  };
  /** A paragraph's active rule (matchText + occurrence identity) — folded into sections. */
  const paraRuleFor = (p: RawPara): DynamicRule | undefined =>
    rules.find((r) => r.target === 'paragraph' && r.active
      && r.occurrence === p.occurrence && r.matchText === jsNormalize(p.text));
  /** Plain-text preview of an HTML fragment. */
  const textOf = (html: string): string => {
    const el = document.createElement('div');
    el.innerHTML = html;
    return (el.textContent ?? '').replace(/\s+/g, ' ').trim();
  };

  // ── Sections (contracts v2 membership) ──────────────────────────────────
  const sectionKey = (level: number, normText: string) => `${level}|${normText}`;

  /** Remote: contiguous same-anchor RUNS of scan paragraphs, scan order. */
  const paraRuns = (() => {
    const runs: Array<{ key: string; paras: RawPara[] }> = [];
    rawParas.forEach((p) => {
      const key = p.anchor ? sectionKey(p.anchor.level, p.anchor.text) : '';
      const last = runs[runs.length - 1];
      if (last && last.key === key) last.paras.push(p);
      else runs.push({ key, paras: [p] });
    });
    return runs;
  })();

  /** 0-based occurrence per heading node among same-key headings. */
  const headingOcc = new Map<number, number>();
  {
    const seen = new Map<string, number>();
    nodes.forEach((n) => {
      if (n.kind !== 'heading') return;
      const key = sectionKey(n.level ?? 0, jsNormalize(n.text));
      const occ = seen.get(key) ?? 0;
      headingOcc.set(n.index, occ);
      seen.set(key, occ + 1);
    });
  }

  /** The section owned by a heading node. Remote: k-th same-key run (scan anchors).
   *  Local: the paragraphs FOLLOWING the heading in the single local parse. */
  const sectionFor = (heading: ContentNode): PanelSection => {
    const level = heading.level ?? 2;
    const occ = headingOcc.get(heading.index) ?? 0;
    const key = sectionKey(level, jsNormalize(heading.text));
    let paras: RawPara[] = [];
    if (isLocal) {
      const pos = nodes.findIndex((n) => n === heading);
      for (let j = pos + 1; j < nodes.length; j++) {
        if (nodes[j].kind !== 'paragraph') break;
        paras.push({ text: nodes[j].text, html: nodes[j].html, occurrence: nodes[j].occurrence ?? 0, anchor: null });
      }
    } else {
      const matching = paraRuns.filter((r) => r.key === key);
      paras = matching[occ]?.paras ?? [];
    }
    return {
      key: `${key}#${occ}`,
      heading: { text: heading.text, level, occurrence: occ, html: heading.html || '' },
      headingNode: heading,
      paragraphs: paras,
    };
  };

  /** Remote runs whose anchor heading is NOT in the heading scan (comments title
   *  etc.) — rendered as standalone ¶ rows so nothing is silently mis-grouped. */
  const unlistedSections = (): PanelSection[] => {
    if (isLocal) return [];
    const headingKeys = new Set(
      nodes.filter((n) => n.kind === 'heading').map((n) => sectionKey(n.level ?? 0, jsNormalize(n.text))),
    );
    const occPerKey = new Map<string, number>();
    const out: PanelSection[] = [];
    paraRuns.forEach((r) => {
      if (r.key === '') return; // orphans handled separately
      const occ = occPerKey.get(r.key) ?? 0;
      occPerKey.set(r.key, occ + 1);
      if (headingKeys.has(r.key)) return; // belongs to a listed heading
      const [lvl, txt] = [Number(r.key.split('|')[0]) || 2, r.key.slice(r.key.indexOf('|') + 1)];
      out.push({
        key: `${r.key}#${occ}`,
        heading: { text: txt, level: lvl, occurrence: occ, html: '' },
        paragraphs: r.paras,
      });
    });
    return out;
  };

  /** Leading paragraphs with no heading at all (orphans) — read-only ¶ row. */
  const orphanParas = (): RawPara[] => {
    if (isLocal) {
      const out: RawPara[] = [];
      for (const n of nodes) {
        if (n.kind === 'heading') break;
        if (n.kind === 'paragraph') out.push({ text: n.text, html: n.html, occurrence: n.occurrence ?? 0, anchor: null });
      }
      return out;
    }
    return paraRuns.filter((r) => r.key === '').flatMap((r) => r.paras);
  };

  /** The active `section` rule serving a section, if any. */
  const sectionRuleOf = (s: PanelSection): DynamicRule | undefined =>
    rules.find((r) => r.target === 'section' && r.active
      && r.matchText === jsNormalize(s.heading.text) && r.occurrence === s.heading.occurrence);

  /** `sectionInsert` rules anchored to a section's heading. */
  const insertRulesOf = (s: PanelSection): DynamicRule[] =>
    rules.filter((r) => r.target === 'sectionInsert' && r.active
      && r.matchText === jsNormalize(s.heading.text) && r.occurrence === s.heading.occurrence);

  const insertTitle = (r: DynamicRule): string => {
    const m = /<h[1-6][^>]*>([\s\S]*?)<\/h[1-6]>/i.exec(r.replacement);
    return textOf(m ? m[1] : r.replacement) || 'New section';
  };

  // ── The floating editor ──
  const [sectionModal, setSectionModal] = useState<
    | { mode: 'section'; section: SectionData; at: { x: number; y: number } }
    | { mode: 'insert'; insert?: InsertData; anchors?: SectionAnchor[]; at: { x: number; y: number } }
    | null
  >(null);

  const clickPoint = (e: MouseEvent): { x: number; y: number } => {
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
    return { x: r.left, y: r.bottom };
  };

  const openSection = (s: PanelSection, at: { x: number; y: number }) => {
    const sRule = !isLocal ? sectionRuleOf(s) : undefined;
    const paragraphs: SectionParagraph[] = s.paragraphs.map((p) => {
      const pr = !isLocal ? paraRuleFor(p) : undefined;
      return { text: p.text, occurrence: p.occurrence, html: p.html, servedHtml: pr ? pr.replacement : undefined };
    });
    setSectionModal({
      mode: 'section',
      at,
      section: { heading: s.heading, paragraphs, sectionRuleReplacement: sRule ? sRule.replacement : null },
    });
  };

  const sectionAnchors = (): SectionAnchor[] =>
    nodes.filter((n) => n.kind === 'heading').map((n) => ({
      text: n.text, level: n.level ?? 2, occurrence: headingOcc.get(n.index) ?? 0,
    }));

  const openInsert = (rule: DynamicRule, s: PanelSection, at: { x: number; y: number }) => {
    setSectionModal({
      mode: 'insert',
      at,
      insert: {
        ruleId: rule.id,
        anchorText: s.heading.text,
        anchorLevel: s.heading.level,
        anchorOccurrence: s.heading.occurrence,
        position: (rule.section?.position === 'before' ? 'before' : 'after'),
        replacement: rule.replacement,
      },
    });
  };

  // ── Heading editor state (unchanged behavior) ──
  const [busyIndex, setBusyIndex] = useState<number | null>(null);
  const [suggestions, setSuggestions] = useState<Record<number, string>>({});
  const [readOnlyReason, setReadOnlyReason] = useState<Record<number, string>>({});

  const editIndex = (n: ContentNode): number => (isLocal ? (n.headingIndex ?? n.index) : n.index);

  const saveHeading = async (n: ContentNode, patch: { text?: string; level?: number }) => {
    setBusyIndex(n.index);
    try {
      if (isLocal) {
        await localUpdate.mutateAsync({ id: postId, index: editIndex(n), ...patch });
        await localQuery.refetch();
      } else {
        const data = await remoteUpdate.mutateAsync({ siteId: siteId as number, postId, type, index: editIndex(n), ...patch });
        const list = (data as any)?.headings;
        if (Array.isArray(list)) setNodes(headingsToNodes(list as HeadingItem[]));
        void rulesQuery.refetch(); // heading edits RE-KEY section rules server-side
      }
    } catch (e: any) {
      const code: string | undefined = e?.code;
      const message: string = e?.message ?? 'Could not update the heading';
      if (code === STALE_CODE) {
        toast.error('This page changed on the site since it was scanned. Re-scan to load the current headings.', {
          action: { label: 'Re-scan', onClick: () => { void query.refetch(); } },
        });
      } else if (code && HARDCODED_CODES.has(code)) {
        setReadOnlyReason((r) => ({ ...r, [n.index]: message }));
        toast.error(message);
      } else {
        toast.error(message);
      }
    } finally {
      setBusyIndex(null);
    }
  };

  const optimize = async (n: ContentNode) => {
    setBusyIndex(n.index);
    try {
      const data = isLocal
        ? await localOptimize.mutateAsync({ id: postId, index: editIndex(n), text: n.text, brandId, model, provider })
        : await remoteOptimize.mutateAsync({ siteId: siteId as number, postId, type, index: editIndex(n), text: n.text, model, provider });
      const value = String((data as any)?.value ?? '').trim();
      if (value && value !== n.text) setSuggestions((s) => ({ ...s, [n.index]: value }));
      else toast.info('The heading already looks optimized.');
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not optimize the heading');
    } finally {
      setBusyIndex(null);
    }
  };

  const acceptSuggestion = (n: ContentNode) => {
    const value = suggestions[n.index];
    setSuggestions(({ [n.index]: _drop, ...rest }) => rest);
    if (value != null) void saveHeading(n, { text: value });
  };
  const rejectSuggestion = (index: number) =>
    setSuggestions(({ [index]: _drop, ...rest }) => rest);

  /** One full-width subrow whose title column holds `content`. */
  const shellRow = (key: string, content: React.ReactNode) => (
    <TableRow key={key} className="bg-muted/30">
      <TableCell />
      {orderedCols.map((col) => (
        <TableCell key={col}>{col === 'title' ? content : null}</TableCell>
      ))}
    </TableRow>
  );

  /** ONE ¶ row per section — the editor's opener; shows the SERVED state. */
  const sectionRow = (s: PanelSection, indent: number, opts?: { muted?: boolean; readOnly?: boolean }) => {
    const sRule = !isLocal ? sectionRuleOf(s) : undefined;
    const preview = sRule
      ? textOf(sRule.replacement)
      : s.paragraphs.map((p) => {
        const pr = !isLocal ? paraRuleFor(p) : undefined;
        return pr ? textOf(pr.replacement) : p.text;
      }).join(' · ');
    return (
      <TableRow key={`sec-${s.key}`} className="bg-muted/30 hover:bg-muted/50">
        <TableCell className="px-2 text-center">
          <CornerDownRight className="inline-block w-3 h-3 text-muted-foreground/40" />
        </TableCell>
        {orderedCols.map((col) => {
          if (col !== 'title') return <TableCell key={col} />;
          return (
            <TableCell key={col}>
              <div className="flex items-center gap-1.5 min-w-0" style={{ paddingLeft: `${indent}px` }}>
                {/* The regular P chip — same family as the H1–H6 tag chips (owner correction). */}
                <span className="inline-flex h-5 min-w-[40px] shrink-0 items-center justify-center rounded-[3px] border border-border bg-muted/60 px-1 text-[10px] font-semibold leading-none text-muted-foreground">
                  P
                </span>
                {sRule && (
                  <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-primary" title="Optimized — a section rule serves this content" />
                )}
                <button
                  type="button"
                  onClick={(e) => openSection(s, clickPoint(e))}
                  className={`flex-1 min-w-0 text-left truncate text-xs hover:underline decoration-dotted ${opts?.muted ? 'text-muted-foreground/70' : 'text-muted-foreground hover:text-foreground'}`}
                  title={isLocal || opts?.readOnly
                    ? 'Open this section (read-only here — editing runs via dynamic rules on connected sites)'
                    : 'Open this section in the editor'}
                >
                  {preview || '(empty section)'}
                </button>
              </div>
            </TableCell>
          );
        })}
      </TableRow>
    );
  };

  /** An added section (sectionInsert rule) as a row at its served spot. */
  const insertRow = (rule: DynamicRule, s: PanelSection, indent: number) => (
    <TableRow key={`ins-${rule.id}`} className="bg-muted/30 hover:bg-muted/50">
      <TableCell className="px-2 text-center">
        <CornerDownRight className="inline-block w-3 h-3 text-muted-foreground/40" />
      </TableCell>
      {orderedCols.map((col) => {
        if (col !== 'title') return <TableCell key={col} />;
        return (
          <TableCell key={col}>
            <div className="flex items-center gap-1.5 min-w-0" style={{ paddingLeft: `${indent}px` }}>
              <span className="inline-flex h-5 min-w-[40px] shrink-0 items-center justify-center rounded-[3px] border border-primary/40 bg-primary/10 px-1 text-[10px] font-semibold leading-none text-primary">
                NEW
              </span>
              <button
                type="button"
                onClick={(e) => openInsert(rule, s, clickPoint(e))}
                className="flex-1 min-w-0 text-left truncate text-xs hover:underline decoration-dotted"
                title="Added section (served dynamically) — click to edit or remove"
              >
                {insertTitle(rule)}
              </button>
            </div>
          </TableCell>
        );
      })}
    </TableRow>
  );

  if (query.isLoading) {
    return shellRow('loading', (
      <span className="inline-flex items-center gap-1.5 text-muted-foreground">
        <Loader2 className="w-3.5 h-3.5 animate-spin" /> {isLocal ? 'Loading content…' : 'Loading headings…'}
      </span>
    ));
  }
  if (nodes.length === 0 && rawParas.length === 0) {
    return shellRow('empty', (
      <span className="text-muted-foreground/70">
        {isLocal
          ? 'No content found on this page.'
          : 'No headings found on this page (or the connector is older than v2.1.7).'}
      </span>
    ));
  }

  const headingNodes = nodes.filter((n) => n.kind === 'heading');
  const orphans = orphanParas();

  return (
    <Fragment>
      {/* Orphan paragraphs (before any heading) — one row, read-only (no heading = no section anchor). */}
      {orphans.length > 0 && sectionRow(
        { key: 'orphan', heading: { text: '', level: 1, occurrence: 0, html: '' }, paragraphs: orphans },
        indentFor(1),
        { muted: true, readOnly: true },
      )}

      {headingNodes.map((n) => {
        const s = sectionFor(n);
        const indent = indentFor(n.level ?? 2);
        const inserts = !isLocal ? insertRulesOf(s) : [];
        const before = inserts.filter((r) => r.section?.position === 'before');
        const after = inserts.filter((r) => r.section?.position !== 'before');

        // ── Heading row (the shipped editor, unchanged) ──
        const busy = busyIndex === n.index;
        const suggestion = suggestions[n.index];
        const reason = readOnlyReason[n.index];
        const readOnly = !n.editable || reason != null;
        const roTitle = reason ?? THEME_READONLY_REASON;
        const level = n.level ?? 2;
        const hSecRule = !isLocal ? sectionRuleOf(s) : undefined;

        return (
          <Fragment key={`h-${n.index}`}>
            {before.map((r) => insertRow(r, s, indent))}
            <TableRow className="bg-muted/30 hover:bg-muted/50">
              <TableCell className="px-2 text-center">
                <CornerDownRight className="inline-block w-3 h-3 text-muted-foreground/40" />
              </TableCell>
              {orderedCols.map((col) => {
                if (col !== 'title') return <TableCell key={col} />;
                return (
                  <TableCell key={col} className={suggestion != null ? '!h-auto !py-1 !whitespace-normal' : ''}>
                    <div className="flex items-center gap-1.5 min-w-0" style={{ paddingLeft: `${indent}px` }}>
                      {!readOnly && suggestion == null ? (
                        <Select value={String(level)} onValueChange={(v) => saveHeading(n, { level: Number(v) })} disabled={busy}>
                          <SelectTrigger
                            className={`!h-5 w-auto min-w-[40px] shrink-0 rounded-[3px] border px-1 py-0 text-[10px] font-semibold leading-none justify-center gap-0.5 shadow-none [&>svg]:w-2.5 [&>svg]:h-2.5 [&>svg]:opacity-50 ${TAG_STYLE[level] ?? TAG_STYLE[2]}`}
                            title="Change heading level"
                          >
                            {`H${level}`}
                          </SelectTrigger>
                          <SelectContent className="min-w-[56px] w-[56px]">
                            {[1, 2, 3, 4, 5, 6].map((num) => (
                              <SelectItem
                                key={num}
                                value={String(num)}
                                className="justify-center py-1 pl-2 pr-2 text-[10px] font-semibold [&>span:first-child]:hidden"
                              >{`H${num}`}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      ) : (
                        <span className={`inline-flex h-5 min-w-[40px] shrink-0 items-center justify-center rounded-[3px] border px-1 text-[10px] font-semibold leading-none ${TAG_STYLE[level] ?? TAG_STYLE[2]}`}>
                          {`H${level}`}
                        </span>
                      )}
                      {n.sourceLabel ? (
                        <span
                          className="inline-flex h-5 max-w-[150px] shrink-0 items-center gap-0.5 rounded-[3px] border border-amber-500/40 bg-amber-500/10 px-1 text-[10px] font-medium text-amber-600"
                          title={`Shared source — editing this heading changes it on EVERY page that uses it. Lives in: ${n.sourceLabel}.`}
                        >
                          <LayoutTemplate className="h-2.5 w-2.5 shrink-0" />
                          <span className="truncate">{n.sourceLabel}</span>
                        </span>
                      ) : null}
                      {hSecRule && (
                        <span
                          className="h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
                          title="Section optimized (dynamic rule) — the live page serves the section editor's version."
                        />
                      )}
                      <div className="flex-1 min-w-0">
                        {suggestion != null ? (
                          <div className="space-y-1 rounded-md bg-accent border border-primary/20 p-1.5">
                            <div className="text-xs text-foreground break-words whitespace-normal" title={suggestion}>{suggestion}</div>
                            <div className="flex items-center gap-1">
                              <button type="button" onClick={() => acceptSuggestion(n)} disabled={busy} title="Accept" className="inline-flex items-center gap-0.5 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-60">
                                <Check className="w-3 h-3" /> Accept
                              </button>
                              <button type="button" onClick={() => rejectSuggestion(n.index)} disabled={busy} title="Reject" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
                                <X className="w-3 h-3" /> Reject
                              </button>
                              <button type="button" onClick={() => optimize(n)} disabled={busy} title="Re-generate" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
                                {busy ? <Loader2 className="w-3 h-3 animate-spin text-primary" /> : <RefreshCw className="w-3 h-3" />} Re-generate
                              </button>
                            </div>
                          </div>
                        ) : !readOnly ? (
                          <HeadingText
                            value={n.text}
                            busy={busy}
                            onSave={(v) => saveHeading(n, { text: v })}
                            onOptimize={() => optimize(n)}
                          />
                        ) : (
                          <span className="flex items-center gap-1 text-xs text-muted-foreground" title={roTitle}>
                            <Lock className="w-3 h-3 shrink-0 opacity-60" />
                            <span className="truncate">{n.text}</span>
                          </span>
                        )}
                      </div>
                      {/* Open the whole section in the floating editor (below the click). */}
                      <button
                        type="button"
                        onClick={(e) => openSection(s, clickPoint(e))}
                        title="Open this section in the section editor"
                        className="shrink-0 text-muted-foreground/50 hover:text-primary opacity-0 group-hover:opacity-100"
                      >
                        <Maximize2 className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </TableCell>
                );
              })}
            </TableRow>
            {/* ONE ¶ row per section (owner-spec v2 — no per-<p> rows). */}
            {(s.paragraphs.length > 0 || hSecRule) && sectionRow(s, indent + 14)}
            {after.map((r) => insertRow(r, s, indent))}
          </Fragment>
        );
      })}

      {/* Sections whose anchor heading isn't in the heading scan (comments title etc.). */}
      {unlistedSections().map((s) => sectionRow(s, indentFor(s.heading.level), { muted: true }))}

      {/* Add a brand-new section (FAQ etc.) — anchored to an existing heading. */}
      {!isLocal && headingNodes.length > 0 && shellRow('add-section', (
        <button
          type="button"
          onClick={(e) => setSectionModal({ mode: 'insert', anchors: sectionAnchors(), at: clickPoint(e) })}
          className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-primary"
        >
          <Plus className="w-3.5 h-3.5" /> Add section
        </button>
      ))}

      {remoteParaNote && shellRow('para-note', (
        <span className="text-xs text-muted-foreground/60">{remoteParaNote}</span>
      ))}

      {/* The floating section editor — keyed per target so switching sections
          never bleeds state; stale onClose from a replaced instance is ignored. */}
      {sectionModal && (
        <SectionModal
          key={sectionModal.mode === 'section'
            ? `s-${(sectionModal as any).section.heading.text}-${(sectionModal as any).section.heading.occurrence}`
            : `i-${(sectionModal as any).insert?.ruleId ?? 'new'}`}
          siteId={siteId}
          postId={postId}
          type={type}
          model={model}
          provider={provider}
          readOnly={isLocal}
          mode={sectionModal.mode}
          section={sectionModal.mode === 'section' ? sectionModal.section : undefined}
          insert={sectionModal.mode === 'insert' ? sectionModal.insert : undefined}
          anchors={sectionModal.mode === 'insert' ? sectionModal.anchors : undefined}
          anchorPoint={sectionModal.at}
          onClose={() => setSectionModal((cur) => (cur === sectionModal ? null : cur))}
          onSaved={() => { void rulesQuery.refetch(); }}
        />
      )}
    </Fragment>
  );
}

/** Click-to-edit heading text + hover ✦ Optimize — mirrors the table's EditableCell. */
function HeadingText({ value, busy, onSave, onOptimize }: {
  value: string;
  busy?: boolean;
  onSave: (v: string) => void;
  onOptimize: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  useEffect(() => setDraft(value), [value]);

  const commit = () => {
    setEditing(false);
    const v = draft.trim();
    if (v && v !== value) onSave(v); else setDraft(value);
  };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    if (e.key === 'Escape') { setDraft(value); setEditing(false); }
  };

  if (editing) {
    return (
      <Input
        autoFocus
        value={draft}
        disabled={busy}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={onKey}
        className="h-7 text-xs"
      />
    );
  }
  return (
    <div className="flex items-center gap-1 group">
      <button
        type="button"
        onClick={() => { setDraft(value); setEditing(true); }}
        className="flex-1 min-w-0 text-left truncate text-xs leading-snug hover:underline decoration-dotted"
        title={value}
      >
        {value}
      </button>
      <button
        type="button"
        onClick={onOptimize}
        disabled={busy}
        title="Optimize with AI"
        className="shrink-0 text-muted-foreground/50 hover:text-primary opacity-0 group-hover:opacity-100 disabled:opacity-100"
      >
        {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin text-primary" /> : <Sparkles className="w-3.5 h-3.5" />}
      </button>
    </div>
  );
}
