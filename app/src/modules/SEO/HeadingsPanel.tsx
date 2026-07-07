/**
 * HeadingRows — the expandable heading editor shown under a page row in the SEO table.
 *
 * Renders each H1–H6 as a REAL row of the parent table (same <tr>/<td> primitives, so
 * the SEO_TABLE_GRID borders, h-9 cell height, and the <colgroup> column widths apply
 * verbatim — the subrows read as part of the spreadsheet, not a foreign panel). The
 * heading lives in the `title` column: indented by level, a colored H1–H6 tag chip
 * (dropdown to retag), click-to-edit text, and a hover ✦ Optimize that stages an AI
 * suggestion with the same Accept/Reject UI as every other cell. All other columns
 * render as empty cells to keep the gridlines continuous.
 *
 * Local + remote: picks the local or connector-backed trpc routes off `siteId`.
 */

import { Fragment, useEffect, useState, type KeyboardEvent } from 'react';
import { Loader2, Sparkles, Check, X, RefreshCw, CornerDownRight, Lock } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger,
} from '@/components/ui/select';
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

  // Re-scan the page's headings EVERY time its accordion is opened (HeadingRows mounts on
  // expand, unmounts on collapse). Without this the global 30s staleTime serves cached headings
  // on re-open, so a page edited/re-scanned recently — or one whose first scan came back partial
  // or read-only — would show stale data. `refetchOnMount: 'always'` forces a fresh scan per open
  // so the headings (and their editable flags) are always current.
  const localQuery = trpc.seo.getHeadings.useQuery(
    { id: postId },
    { enabled: isLocal, staleTime: 0, refetchOnMount: 'always' },
  );
  const remoteQuery = trpc.seo.remoteGetHeadings.useQuery(
    { siteId: siteId as number, postId, type },
    { enabled: !isLocal, staleTime: 0, refetchOnMount: 'always' },
  );
  const query = isLocal ? localQuery : remoteQuery;

  const [headings, setHeadings] = useState<HeadingItem[]>([]);
  useEffect(() => {
    const list = (query.data as any)?.headings;
    if (Array.isArray(list)) setHeadings(list as HeadingItem[]);
  }, [query.data]);

  const localUpdate = trpc.seo.updateHeading.useMutation();
  const remoteUpdate = trpc.seo.remoteUpdateHeading.useMutation();
  const localOptimize = trpc.seo.optimizeHeading.useMutation();
  const remoteOptimize = trpc.seo.remoteOptimizeHeading.useMutation();

  const [busyIndex, setBusyIndex] = useState<number | null>(null);
  const [suggestions, setSuggestions] = useState<Record<number, string>>({});
  // Headings discovered read-only at SAVE time (theme/template-hardcoded) → keep them flagged
  // for this session so the row shows the lock + reason instead of looking editable again.
  const [readOnlyReason, setReadOnlyReason] = useState<Record<number, string>>({});

  const saveHeading = async (index: number, patch: { text?: string; level?: number }) => {
    setBusyIndex(index);
    try {
      const data = isLocal
        ? await localUpdate.mutateAsync({ id: postId, index, ...patch })
        : await remoteUpdate.mutateAsync({ siteId: siteId as number, postId, type, index, ...patch });
      const list = (data as any)?.headings;
      if (Array.isArray(list)) setHeadings(list as HeadingItem[]);
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
        setReadOnlyReason((r) => ({ ...r, [index]: message }));
        toast.error(message);
      } else {
        toast.error(message);
      }
    } finally {
      setBusyIndex(null);
    }
  };

  const optimize = async (h: HeadingItem) => {
    setBusyIndex(h.index);
    try {
      const data = isLocal
        ? await localOptimize.mutateAsync({ id: postId, index: h.index, text: h.text, brandId, model, provider })
        : await remoteOptimize.mutateAsync({ siteId: siteId as number, postId, type, index: h.index, text: h.text, model, provider });
      const value = String((data as any)?.value ?? '').trim();
      if (value && value !== h.text) {
        setSuggestions((s) => ({ ...s, [h.index]: value }));
      } else {
        toast.info('The heading already looks optimized.');
      }
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not optimize the heading');
    } finally {
      setBusyIndex(null);
    }
  };

  const acceptSuggestion = (index: number) => {
    const value = suggestions[index];
    setSuggestions(({ [index]: _drop, ...rest }) => rest);
    if (value != null) void saveHeading(index, { text: value });
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
        <Loader2 className="w-3.5 h-3.5 animate-spin" /> Loading headings…
      </span>
    ));
  }
  if (headings.length === 0) {
    return shellRow('empty', (
      <span className="text-muted-foreground/70">
        No headings found on this page{isLocal ? '' : ' (or the connector is older than v2.1.7)'}.
      </span>
    ));
  }

  return (
    <Fragment>
      {headings.map((h) => {
        const busy = busyIndex === h.index;
        const suggestion = suggestions[h.index];
        const reason = readOnlyReason[h.index];
        const readOnly = !h.editable || reason != null;
        const roTitle = reason ?? THEME_READONLY_REASON;
        return (
          <TableRow key={h.index} className="bg-muted/30 hover:bg-muted/50">
            <TableCell className="px-2 text-center">
              <CornerDownRight className="inline-block w-3 h-3 text-muted-foreground/40" />
            </TableCell>
            {orderedCols.map((col) => {
              if (col !== 'title') {
                return <TableCell key={col} />;
              }
              return (
                <TableCell key={col} className={suggestion != null ? '!h-auto !py-1 !whitespace-normal' : ''}>
                  {/* Indent: 18px aligns with the page title text (chevron 14px + gap 4px),
                      then 14px per heading level below H1. */}
                  <div className="flex items-center gap-1.5 min-w-0" style={{ paddingLeft: `${18 + (h.level - 1) * 14}px` }}>
                    {!readOnly && suggestion == null ? (
                      <Select value={String(h.level)} onValueChange={(v) => saveHeading(h.index, { level: Number(v) })} disabled={busy}>
                        <SelectTrigger
                          className={`!h-5 w-auto min-w-[40px] shrink-0 rounded-[3px] border px-1 py-0 text-[10px] font-semibold leading-none justify-center gap-0.5 shadow-none [&>svg]:w-2.5 [&>svg]:h-2.5 [&>svg]:opacity-50 ${TAG_STYLE[h.level] ?? TAG_STYLE[2]}`}
                          title="Change heading level"
                        >
                          {`H${h.level}`}
                        </SelectTrigger>
                        <SelectContent>
                          {[1, 2, 3, 4, 5, 6].map((n) => (
                            <SelectItem key={n} value={String(n)} className="text-xs">{`H${n}`}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    ) : (
                      <span className={`inline-flex h-5 min-w-[40px] shrink-0 items-center justify-center rounded-[3px] border px-1 text-[10px] font-semibold leading-none ${TAG_STYLE[h.level] ?? TAG_STYLE[2]}`}>
                        {`H${h.level}`}
                      </span>
                    )}
                    <div className="flex-1 min-w-0">
                      {suggestion != null ? (
                        /* Same staged-suggestion UI as every other cell (EditableCell). */
                        <div className="space-y-1 rounded-md bg-accent border border-primary/20 p-1.5">
                          <div className="text-xs text-foreground break-words whitespace-normal" title={suggestion}>{suggestion}</div>
                          <div className="flex items-center gap-1">
                            <button type="button" onClick={() => acceptSuggestion(h.index)} disabled={busy} title="Accept" className="inline-flex items-center gap-0.5 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white hover:bg-green-700 disabled:opacity-60">
                              <Check className="w-3 h-3" /> Accept
                            </button>
                            <button type="button" onClick={() => rejectSuggestion(h.index)} disabled={busy} title="Reject" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
                              <X className="w-3 h-3" /> Reject
                            </button>
                            <button type="button" onClick={() => optimize(h)} disabled={busy} title="Re-generate" className="inline-flex items-center gap-0.5 rounded border border-border px-1.5 py-0.5 text-[10px] text-muted-foreground hover:bg-muted disabled:opacity-60">
                              {busy ? <Loader2 className="w-3 h-3 animate-spin text-primary" /> : <RefreshCw className="w-3 h-3" />} Re-generate
                            </button>
                          </div>
                        </div>
                      ) : !readOnly ? (
                        <HeadingText
                          value={h.text}
                          busy={busy}
                          onSave={(v) => saveHeading(h.index, { text: v })}
                          onOptimize={() => optimize(h)}
                        />
                      ) : (
                        /* Read-only: heading isn't in editable content — theme/template-hardcoded.
                           Lock + reason tooltip mirrors the Links editor's read-only affordance. */
                        <span className="flex items-center gap-1 text-xs text-muted-foreground" title={roTitle}>
                          <Lock className="w-3 h-3 shrink-0 opacity-60" />
                          <span className="truncate">{h.text}</span>
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
