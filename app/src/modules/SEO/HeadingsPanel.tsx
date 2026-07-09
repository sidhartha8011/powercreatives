/**
 * HeadingRows — the expandable page-content editor shown under a page row in the SEO table.
 *
 * Renders the page's CONTENT NODES as REAL rows of the parent table (same <tr>/<td>
 * primitives, so the SEO_TABLE_GRID borders, h-9 cell height, and the <colgroup> column
 * widths apply verbatim — the subrows read as part of the spreadsheet, not a foreign panel):
 *
 *  - HEADING nodes (H1–H6): exactly the editor that shipped before — indented by level, a
 *    colored H1–H6 tag chip (dropdown to retag), click-to-edit text, hover ✦ Optimize with
 *    the staged Accept/Reject UI. Local edits go through the EXISTING heading endpoints via
 *    the node's `headingIndex` (its position in the headings-only list).
 *  - PARAGRAPH nodes (pair 1, LOCAL only, read-only): every <p> as its own row in document
 *    order, indented one step under its heading (flush when orphaned), a neutral "P" chip,
 *    one-line text preview; click → popup showing the paragraph's actual HTML. Editing
 *    arrives via DYNAMIC RULES (pair 3) — paragraphs are never source-written.
 *
 * NODE IDENTITY CONTRACT v1: a node = { kind, index (position in the ordered node list),
 * normalized text } — the address future dynamic rules target. See
 * docs/DYNAMIC-OPTIMIZATION-ARCHITECTURE.md → "Node identity contract".
 *
 * Local uses seo.getContentNodes (headings + paragraphs). Remote (connected sites) keeps the
 * connector's builder-aware heading scan UNCHANGED — remote paragraphs arrive with pair 2's
 * connector scan-content (a body-only parse here would order falsely against builder headings).
 */

import { Fragment, useEffect, useState, type KeyboardEvent } from 'react';
import { Loader2, Sparkles, Check, X, RefreshCw, CornerDownRight, Lock, LayoutTemplate } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger,
} from '@/components/ui/select';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import { TableRow, TableCell } from './seo-table';

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
  /** Position in the ordered node list — the rule-target identity (contract v1). */
  index: number;
  kind: 'heading' | 'paragraph';
  /** Heading level (heading nodes only). */
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
  /** Paragraph nodes: 0-based position among same-normalized-text twins — half
   *  of the rule-target identity (matchText + occurrence). */
  occurrence?: number;
  /** Paragraph nodes: nearest preceding rendered heading (re-anchor context). */
  anchor?: { level: number; text: string } | null;
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
}

/** Default reason shown on a heading that can't be edited from here (mirrors the read-only
 *  copy in the Links editor). Applies when the scan flags a heading non-editable up front. */
const THEME_READONLY_REASON =
  'Read-only — this heading lives in your theme or a page template, not the editable page content. Edit it on the site.';

/** WP error codes that mean the heading genuinely has no editable source on the site (theme /
 *  template hardcoded, or no editable markup) — the row should flip to read-only, not retry. */
const HARDCODED_CODES = new Set(['pcm_seo_heading_not_found', 'pcm_seo_heading_not_editable']);
/** WP error code meaning the page changed since the scan — a fresh re-scan fixes it. */
const STALE_CODE = 'pcm_seo_heading_stale';

/** Tag-chip accents per level (H1 → H6) — border + text on the table's card bg. */
const TAG_STYLE: Record<number, string> = {
  1: 'bg-blue-500/10 text-blue-500 border-blue-500/40',
  2: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/40',
  3: 'bg-amber-500/10 text-amber-600 border-amber-500/40',
  4: 'bg-orange-600/10 text-orange-600 border-orange-600/40',
  5: 'bg-rose-500/10 text-rose-500 border-rose-500/40',
  6: 'bg-violet-500/10 text-violet-500 border-violet-500/40',
};

/** Indent so node text aligns with the page title text (chevron 14px + gap 4px),
 *  then 14px per heading level below H1; paragraphs sit one step under their heading. */
const indentFor = (level: number): number => 18 + Math.max(0, level - 1) * 14;

/** Display-side normalization for anchor matching (mirrors normalization spec v1
 *  minus entity decoding — a rare entity mismatch just demotes a paragraph to
 *  "after previous position" placement; DISPLAY ordering only, never targeting). */
const normAnchor = (s: string): string =>
  s.replace(/ /g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();

/**
 * Interleave remote paragraphs among the (builder-aware) heading rows using each
 * paragraph's rendered-order anchor (nearest preceding heading). Deterministic:
 * anchors resolve to the first matching heading at/after the current cursor;
 * unmatched anchors keep the paragraph after the previous paragraph's position.
 * Paragraph node indexes are offset by 10000 — display/key space only (they are
 * read-only; no mutation ever uses a paragraph index).
 */
function mergeRemoteNodes(headings: ContentNode[], paragraphs: any[]): ContentNode[] {
  const byKey = new Map<string, number[]>();
  headings.forEach((h, pos) => {
    const key = `${h.level ?? 0}|${normAnchor(h.text)}`;
    const arr = byKey.get(key) ?? [];
    arr.push(pos);
    byKey.set(key, arr);
  });
  const assigned = new Map<number, ContentNode[]>(); // heading pos (-1 = before first) → paragraphs
  let cursor = -1;
  paragraphs.forEach((p, i) => {
    if (p?.anchor && typeof p.anchor.text === 'string') {
      const key = `${Number(p.anchor.level) || 0}|${normAnchor(String(p.anchor.text))}`;
      const positions = byKey.get(key) ?? [];
      const hit = positions.find((pos) => pos >= cursor);
      if (hit !== undefined) cursor = hit;
    }
    const node: ContentNode = {
      index: 10000 + i,
      kind: 'paragraph',
      text: String(p?.text ?? ''),
      html: String(p?.html ?? ''),
      source: String(p?.source ?? 'rendered'),
      elId: '',
      editable: true, // via dynamic rules (remote-only; the save path enforces it)
      occurrence: Number(p?.occurrence ?? 0),
      anchor: p?.anchor && typeof p.anchor.text === 'string'
        ? { level: Number(p.anchor.level) || 0, text: String(p.anchor.text) }
        : null,
    };
    const bucket = assigned.get(cursor) ?? [];
    bucket.push(node);
    assigned.set(cursor, bucket);
  });
  const out: ContentNode[] = [...(assigned.get(-1) ?? [])];
  headings.forEach((h, pos) => {
    out.push(h);
    out.push(...(assigned.get(pos) ?? []));
  });
  return out;
}

export function HeadingRows({
  postId, type, siteId, brandId, model, provider, orderedCols,
}: {
  postId: number;
  type: 'post' | 'page';
  siteId: number | 'local';
  brandId?: number;
  model?: string;
  provider?: string;
  /** The parent table's visible column keys, in order — one <td> per column. */
  orderedCols: string[];
}) {
  const isLocal = siteId === 'local';

  // Re-scan the page's content EVERY time its accordion is opened (HeadingRows mounts on
  // expand, unmounts on collapse). Without this the global 30s staleTime serves cached nodes
  // on re-open, so a page edited/re-scanned recently — or one whose first scan came back partial
  // or read-only — would show stale data. `refetchOnMount: 'always'` forces a fresh scan per open.
  const localQuery = trpc.seo.getContentNodes.useQuery(
    { id: postId },
    { enabled: isLocal, staleTime: 0, refetchOnMount: 'always' },
  );
  const remoteQuery = trpc.seo.remoteGetHeadings.useQuery(
    { siteId: siteId as number, postId, type },
    { enabled: !isLocal, staleTime: 0, refetchOnMount: 'always' },
  );
  // Remote paragraph inventory (scan-content v1, connector 2.7.0+). Heading rows +
  // their editing stay on remoteGetHeadings — this only ADDS paragraph rows.
  // SERIALIZED after the heading query (2.7.1): both scans can trigger a loopback
  // on the connected site; firing them together starves worker-limited hosts.
  const remoteNodesQuery = trpc.seo.remoteGetContentNodes.useQuery(
    { siteId: siteId as number, postId },
    { enabled: !isLocal && remoteQuery.isFetched, staleTime: 0, refetchOnMount: 'always' },
  );
  const query = isLocal ? localQuery : remoteQuery;

  const [nodes, setNodes] = useState<ContentNode[]>([]);
  // Honest paragraph-availability note for remote (old connector / blocked loopback).
  const [remoteParaNote, setRemoteParaNote] = useState<string | null>(null);
  /** Remote headings → heading nodes (index doubles as the remote edit handle). */
  const headingsToNodes = (list: HeadingItem[]): ContentNode[] =>
    list.map((h) => ({ ...h, kind: 'heading' as const, headingIndex: h.index }));
  useEffect(() => {
    if (isLocal) {
      const list = (localQuery.data as any)?.nodes;
      if (Array.isArray(list)) setNodes(list as ContentNode[]);
      setRemoteParaNote(null);
      return;
    }
    const list = (remoteQuery.data as any)?.headings;
    if (!Array.isArray(list)) return;
    const headingNodes = headingsToNodes(list as HeadingItem[]);
    const meta: any = remoteNodesQuery.data ?? null;
    const paragraphs: any[] = Array.isArray(meta?.nodes) ? meta.nodes : [];
    if (meta && meta.supported === false) {
      setRemoteParaNote('Paragraphs need connector v2.7.0+ on this site — update it from the Sites module.');
    } else if (meta && meta.error === 'loopback_blocked') {
      setRemoteParaNote('Paragraphs unavailable — the site blocked the connector’s content scan (loopback request).');
    } else {
      setRemoteParaNote(null);
    }
    setNodes(mergeRemoteNodes(headingNodes, paragraphs));
  }, [isLocal, localQuery.data, remoteQuery.data, remoteNodesQuery.data]);

  const localUpdate = trpc.seo.updateHeading.useMutation();
  const remoteUpdate = trpc.seo.remoteUpdateHeading.useMutation();
  const localOptimize = trpc.seo.optimizeHeading.useMutation();
  const remoteOptimize = trpc.seo.remoteOptimizeHeading.useMutation();

  // ── Dynamic paragraph rules (remote only): the hub's stored rules overlay the
  // scan's ORIGINAL text so the panel shows what the site actually SERVES. ──
  const rulesQuery = trpc.seo.remoteGetParagraphRules.useQuery(
    { siteId: siteId as number, postId },
    { enabled: !isLocal, staleTime: 0 },
  );
  const rules: DynamicRule[] = Array.isArray((rulesQuery.data as any)?.rules)
    ? ((rulesQuery.data as any).rules as DynamicRule[])
    : [];
  const saveParagraphMutation = trpc.seo.remoteSaveParagraphRule.useMutation();
  const optimizeParagraphMutation = trpc.seo.remoteOptimizeParagraph.useMutation();

  /** Match-side normalization for the overlay (entity decode via textarea +
   *  NBSP/whitespace/case — mirrors normalization spec v1 close enough for
   *  DISPLAY matching; the authoritative normalize runs server-side). */
  const jsNormalize = (s: string): string => {
    const el = document.createElement('textarea');
    el.innerHTML = s;
    return el.value.replace(/ /g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  };
  /** The paragraph node's active rule, if any (matchText + occurrence identity). */
  const ruleFor = (n: ContentNode): DynamicRule | undefined =>
    rules.find((r) => r.target === 'paragraph' && r.active
      && r.occurrence === (n.occurrence ?? 0) && r.matchText === jsNormalize(n.text));
  /** Plain-text preview of a rule's replacement (may carry inline HTML). */
  const rulePreview = (r: DynamicRule): string => {
    const el = document.createElement('div');
    el.innerHTML = r.replacement;
    return (el.textContent ?? '').replace(/\s+/g, ' ').trim();
  };

  /** Save a paragraph's rule (or clean-revert when edited back to the original). */
  const saveParagraph = async (n: ContentNode, replacement: string) => {
    setBusyIndex(n.index);
    try {
      const res: any = await saveParagraphMutation.mutateAsync({
        siteId: siteId as number,
        postId,
        text: n.text, // ALWAYS the scan's ORIGINAL text — the rule's identity
        occurrence: n.occurrence ?? 0,
        replacement,
        anchor: n.anchor ?? undefined,
      });
      await rulesQuery.refetch();
      toast.success(res?.reverted
        ? 'Reverted — the original paragraph serves again.'
        : 'Paragraph rule saved — the site serves the new text (page/CDN caches may need a purge).');
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the paragraph change');
    } finally {
      setBusyIndex(null);
    }
  };

  // Per-node UI state, keyed by node.index (unique within the current list).
  const [busyIndex, setBusyIndex] = useState<number | null>(null);
  const [suggestions, setSuggestions] = useState<Record<number, string>>({});
  // Headings discovered read-only at SAVE time (theme/template-hardcoded) → keep them flagged
  // for this session so the row shows the lock + reason instead of looking editable again.
  const [readOnlyReason, setReadOnlyReason] = useState<Record<number, string>>({});
  // Paragraph HTML popup (read-only inspector).
  const [htmlPopup, setHtmlPopup] = useState<ContentNode | null>(null);

  /** The heading-endpoint edit handle for a heading node (local = headings-only index). */
  const editIndex = (n: ContentNode): number => (isLocal ? (n.headingIndex ?? n.index) : n.index);

  const saveHeading = async (n: ContentNode, patch: { text?: string; level?: number }) => {
    setBusyIndex(n.index);
    try {
      if (isLocal) {
        await localUpdate.mutateAsync({ id: postId, index: editIndex(n), ...patch });
        // The heading endpoint returns the headings-only list; this panel renders the
        // combined node list — re-fetch it so paragraphs keep their place (one GET, honest).
        await localQuery.refetch();
      } else {
        const data = await remoteUpdate.mutateAsync({ siteId: siteId as number, postId, type, index: editIndex(n), ...patch });
        const list = (data as any)?.headings;
        if (Array.isArray(list)) setNodes(headingsToNodes(list as HeadingItem[]));
      }
    } catch (e: any) {
      const code: string | undefined = e?.code;
      const message: string = e?.message ?? 'Could not update the heading';
      if (code === STALE_CODE) {
        // The page changed on the site since the scan — offer a one-click re-scan.
        toast.error('This page changed on the site since it was scanned. Re-scan to load the current headings.', {
          action: { label: 'Re-scan', onClick: () => { void query.refetch(); } },
        });
      } else if (code && HARDCODED_CODES.has(code)) {
        // Genuinely not editable from here — flip the row to read-only with the server's reason.
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
      // Paragraphs optimize their SERVED text (the active rule's replacement when
      // one exists) — re-optimizing improves what the visitor actually reads.
      const current = n.kind === 'paragraph'
        ? (() => { const r = ruleFor(n); return r ? rulePreview(r) : n.text; })()
        : n.text;
      const data = n.kind === 'paragraph'
        ? await optimizeParagraphMutation.mutateAsync({ siteId: siteId as number, postId, type, text: current, model, provider })
        : isLocal
          ? await localOptimize.mutateAsync({ id: postId, index: editIndex(n), text: n.text, brandId, model, provider })
          : await remoteOptimize.mutateAsync({ siteId: siteId as number, postId, type, index: editIndex(n), text: n.text, model, provider });
      const value = String((data as any)?.value ?? '').trim();
      if (value && value !== current) {
        setSuggestions((s) => ({ ...s, [n.index]: value }));
      } else {
        toast.info(n.kind === 'paragraph' ? 'The paragraph already looks optimized.' : 'The heading already looks optimized.');
      }
    } catch (e: any) {
      toast.error(e?.message ?? (n.kind === 'paragraph' ? 'Could not optimize the paragraph' : 'Could not optimize the heading'));
    } finally {
      setBusyIndex(null);
    }
  };

  const acceptSuggestion = (n: ContentNode) => {
    const value = suggestions[n.index];
    setSuggestions(({ [n.index]: _drop, ...rest }) => rest);
    if (value == null) return;
    if (n.kind === 'paragraph') void saveParagraph(n, value);
    else void saveHeading(n, { text: value });
  };
  const rejectSuggestion = (index: number) =>
    setSuggestions(({ [index]: _drop, ...rest }) => rest);

  /** One full-width subrow whose title column holds `content`; other cells stay empty. */
  const shellRow = (key: string, content: React.ReactNode) => (
    <TableRow key={key} className="bg-muted/30">
      <TableCell />
      {orderedCols.map((col) => (
        <TableCell key={col}>{col === 'title' ? content : null}</TableCell>
      ))}
    </TableRow>
  );

  if (query.isLoading) {
    return shellRow('loading', (
      <span className="inline-flex items-center gap-1.5 text-muted-foreground">
        <Loader2 className="w-3.5 h-3.5 animate-spin" /> {isLocal ? 'Loading content…' : 'Loading headings…'}
      </span>
    ));
  }
  if (nodes.length === 0) {
    return shellRow('empty', (
      <span className="text-muted-foreground/70">
        {isLocal
          ? 'No content found on this page.'
          : 'No headings found on this page (or the connector is older than v2.1.7).'}
      </span>
    ));
  }

  // Paragraph indent: one step under the nearest preceding heading (flush when orphaned).
  let lastLevel = 0;
  const rows = nodes.map((n) => {
    if (n.kind === 'heading') { lastLevel = n.level ?? 2; return { n, indent: indentFor(lastLevel) }; }
    return { n, indent: lastLevel > 0 ? indentFor(lastLevel) + 14 : indentFor(1) };
  });

  return (
    <Fragment>
      {rows.map(({ n, indent }) => {
        // ── Paragraph row: remote = click-to-edit + ✦ (dynamic rule); local = read-only.
        // The P chip opens the HTML popup on both. A served rule shows its text + a dot. ──
        if (n.kind === 'paragraph') {
          const pBusy = busyIndex === n.index;
          const pSuggestion = suggestions[n.index];
          const served = !isLocal ? ruleFor(n) : undefined;
          const displayText = served ? rulePreview(served) : n.text;
          return (
            <TableRow key={`p-${n.index}`} className="bg-muted/30 hover:bg-muted/50">
              <TableCell className="px-2 text-center">
                <CornerDownRight className="inline-block w-3 h-3 text-muted-foreground/40" />
              </TableCell>
              {orderedCols.map((col) => {
                if (col !== 'title') {
                  return <TableCell key={col} />;
                }
                return (
                  <TableCell key={col} className={pSuggestion != null ? '!h-auto !py-1 !whitespace-normal' : ''}>
                    <div className="flex items-center gap-1.5 min-w-0" style={{ paddingLeft: `${indent}px` }}>
                      <button
                        type="button"
                        onClick={() => setHtmlPopup(n)}
                        title="Show this paragraph's HTML"
                        className="inline-flex h-5 min-w-[40px] shrink-0 items-center justify-center rounded-[3px] border border-border bg-muted/60 px-1 text-[10px] font-semibold leading-none text-muted-foreground hover:text-foreground"
                      >
                        P
                      </button>
                      <div className="flex-1 min-w-0">
                        {pSuggestion != null ? (
                          /* Same staged-suggestion UI as every other cell. */
                          <div className="space-y-1 rounded-md bg-accent border border-primary/20 p-1.5">
                            <div className="text-xs text-foreground break-words whitespace-normal" title={pSuggestion}>{pSuggestion}</div>
                            <div className="flex items-center gap-1">
                              <button type="button" onClick={() => acceptSuggestion(n)} disabled={pBusy} title="Accept" className="inline-flex items-center gap-0.5 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-60">
                                <Check className="w-3 h-3" /> Accept
                              </button>
                              <button type="button" onClick={() => rejectSuggestion(n.index)} disabled={pBusy} title="Reject" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
                                <X className="w-3 h-3" /> Reject
                              </button>
                              <button type="button" onClick={() => optimize(n)} disabled={pBusy} title="Re-generate" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
                                {pBusy ? <Loader2 className="w-3 h-3 animate-spin text-primary" /> : <RefreshCw className="w-3 h-3" />} Re-generate
                              </button>
                            </div>
                          </div>
                        ) : !isLocal ? (
                          <ParagraphText
                            value={displayText}
                            originalText={served ? n.text : undefined}
                            busy={pBusy}
                            onSave={(v) => saveParagraph(n, v)}
                            onOptimize={() => optimize(n)}
                          />
                        ) : (
                          <span
                            className="block truncate text-xs text-muted-foreground"
                            title="Read-only here — paragraph editing runs via dynamic rules on connected sites."
                          >
                            {n.text}
                          </span>
                        )}
                      </div>
                    </div>
                  </TableCell>
                );
              })}
            </TableRow>
          );
        }

        // ── Heading row (unchanged editor) ──
        const busy = busyIndex === n.index;
        const suggestion = suggestions[n.index];
        const reason = readOnlyReason[n.index];
        const readOnly = !n.editable || reason != null;
        const roTitle = reason ?? THEME_READONLY_REASON;
        const level = n.level ?? 2;
        return (
          <TableRow key={`h-${n.index}`} className="bg-muted/30 hover:bg-muted/50">
            <TableCell className="px-2 text-center">
              <CornerDownRight className="inline-block w-3 h-3 text-muted-foreground/40" />
            </TableCell>
            {orderedCols.map((col) => {
              if (col !== 'title') {
                return <TableCell key={col} />;
              }
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
                        {/* Compact menu (~30% smaller than the shadcn default —
                            six two-character options don't need a full-size panel). */}
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
                    {/* Shared-source badge: this heading lives in a template/reusable block used by many
                        pages — editing it changes them all. Tooltip spells out the cross-page effect. */}
                    {n.sourceLabel ? (
                      <span
                        className="inline-flex h-5 max-w-[150px] shrink-0 items-center gap-0.5 rounded-[3px] border border-amber-500/40 bg-amber-500/10 px-1 text-[10px] font-medium text-amber-600"
                        title={`Shared source — editing this heading changes it on EVERY page that uses it. Lives in: ${n.sourceLabel}.`}
                      >
                        <LayoutTemplate className="h-2.5 w-2.5 shrink-0" />
                        <span className="truncate">{n.sourceLabel}</span>
                      </span>
                    ) : null}
                    <div className="flex-1 min-w-0">
                      {suggestion != null ? (
                        /* Same staged-suggestion UI as every other cell (EditableCell). */
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
                        /* Read-only: heading isn't in editable content — theme/template-hardcoded.
                           Lock + reason tooltip mirrors the Links editor's read-only affordance. */
                        <span className="flex items-center gap-1 text-xs text-muted-foreground" title={roTitle}>
                          <Lock className="w-3 h-3 shrink-0 opacity-60" />
                          <span className="truncate">{n.text}</span>
                        </span>
                      )}
                    </div>
                  </div>
                </TableCell>
              );
            })}
          </TableRow>
        );
      })}

      {/* Honest availability note (remote only): old connector / blocked loopback. */}
      {remoteParaNote && shellRow('para-note', (
        <span className="text-xs text-muted-foreground/60">{remoteParaNote}</span>
      ))}

      {/* Paragraph HTML inspector — read-only (editing arrives via dynamic rules, pair 3). */}
      {htmlPopup && (
        <Dialog open onOpenChange={(o) => { if (!o) setHtmlPopup(null); }}>
          <DialogContent className="sm:max-w-2xl">
            <DialogHeader>
              <DialogTitle>Paragraph HTML</DialogTitle>
              <DialogDescription className="line-clamp-2">{htmlPopup.text}</DialogDescription>
            </DialogHeader>
            <pre className="max-h-[50vh] overflow-auto rounded-md border border-border bg-muted/40 p-3 font-mono text-[11px] leading-4 whitespace-pre-wrap break-words">
              {htmlPopup.html}
            </pre>
          </DialogContent>
        </Dialog>
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

/**
 * Click-to-edit paragraph text + hover ✦ Optimize — the dynamic-rule editor
 * (remote sites). Textarea editor (paragraphs are long): Enter saves,
 * Shift+Enter = newline, Esc cancels. A paragraph currently served by a rule
 * shows a primary dot; its tooltip carries the ORIGINAL text — editing back
 * to the original deletes the rule (clean revert, handled server-side).
 */
function ParagraphText({ value, originalText, busy, onSave, onOptimize }: {
  value: string;
  /** Set when a rule serves this paragraph — the scan's original text. */
  originalText?: string;
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
  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); commit(); }
    if (e.key === 'Escape') { setDraft(value); setEditing(false); }
  };

  if (editing) {
    return (
      <textarea
        autoFocus
        rows={3}
        value={draft}
        disabled={busy}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={onKey}
        className="w-full resize-y rounded-md border border-input bg-card p-2 text-xs leading-snug outline-none focus:border-primary"
      />
    );
  }
  return (
    <div className="flex items-center gap-1.5 group min-w-0">
      {originalText !== undefined && (
        <span
          className="h-1.5 w-1.5 shrink-0 rounded-full bg-primary"
          title={`Optimized (dynamic rule) — original: ${originalText}`}
        />
      )}
      <button
        type="button"
        onClick={() => { setDraft(value); setEditing(true); }}
        disabled={busy}
        className="flex-1 min-w-0 text-left truncate text-xs leading-snug text-muted-foreground hover:text-foreground hover:underline decoration-dotted disabled:opacity-60"
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
