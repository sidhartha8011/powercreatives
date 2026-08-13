/**
 * LinksPopup — inspect & edit a post/page's links (internal / external / dead).
 *
 * Opened by clicking a count in the Internal / External / Dead columns. Renders the links
 * in the SHARED SEO spreadsheet table (seo-table.tsx) with click-to-edit Anchor, To + HTML
 * cells (Enter/blur rewrites the <a> on the page; Esc cancels). Remove unwraps the <a> (keeps the
 * text). For the Dead view, "Remove all dead links" unwraps every broken link in one click.
 * Works on the local site and (via the connector) on connected sites.
 */

import { useEffect, useMemo, useState, type PointerEvent } from 'react';
import { ExternalLink, Unlink, Loader2, Trash2, RefreshCw, Lock, Shield, ShieldOff, Undo2 } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Table, TableHeader, TableBody, TableRow, TableHead, TableCell, EditableTextCell, SEO_TABLE_GRID,
} from './seo-table';
import { ColumnHead } from '@/components/ui/column-head';
import { useColumnLayout } from '@/hooks/useColumnLayout';

export type LinkKind = 'internal' | 'external' | 'broken';

interface LinkRow {
  id: number;
  anchor: string;
  from: string;
  to: string;
  html: string;
  status: number;
  kind: 'internal' | 'external';
  broken: boolean;
  /** False when the link lives outside the post's editable content (e.g. a page-builder
   *  layout) — shown read-only because a rewrite can't reach it. Defaults to editable. */
  editable?: boolean;
  /** Builder element id (Elementor/etc.) for this link, so editing rewrites just this one
   *  element — not every link that shares the same URL. Empty for post-body links. */
  elId?: string;
}

const isEditable = (l: LinkRow) => l.editable !== false;

interface LinksPopupProps {
  open: boolean;
  onClose: () => void;
  postId: number;
  kind: LinkKind;
  title: string;
  /** Local site vs a connected site (drives which endpoints are used). */
  isLocal: boolean;
  siteId: number | null;
  type: 'post' | 'page';
}

// Resizable columns (drag the right edge; widths persist per-browser), like the SEO table.
// Defaults sized for the wide dialog so the whole table fits without horizontal scrolling.
const LINK_COLS = [
  { key: 'anchor', label: 'Anchor', w: 220 },
  { key: 'from',   label: 'From',   w: 260 },
  { key: 'to',     label: 'To',     w: 300 },
  { key: 'html',   label: 'HTML',   w: 240 },
  { key: 'status', label: 'Status', w: 72 },
  { key: 'action', label: 'Action', w: 96 },
] as const;
const LINK_KEYS = LINK_COLS.map((c) => c.key);
const LINK_DEFAULT_WIDTHS: Record<string, number> = Object.fromEntries(LINK_COLS.map((c) => [c.key, c.w]));
const LINK_SELECT_W = 36;

function StatusCell({ link }: { link: LinkRow }) {
  if (link.broken) {
    return <span className="font-medium text-destructive">{link.status > 0 ? link.status : 'dead'}</span>;
  }
  if (link.status > 0) return <span className="text-muted-foreground">{link.status}</span>;
  return <span className="text-muted-foreground/50">—</span>;
}

export function LinksPopup({ open, onClose, postId, kind, title, isLocal, siteId, type }: LinksPopupProps) {
  const [links, setLinks] = useState<LinkRow[]>([]);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busyIdx, setBusyIdx] = useState<number | null>(null);
  const [bulkBusy, setBulkBusy] = useState(false);
  // The target a link was just edited to — kept visible even if its type (internal/external/broken)
  // changed and it would otherwise fall out of this filtered view, so the edit is confirmable.
  const [justEditedTo, setJustEditedTo] = useState<string | null>(null);

  const localQuery = trpc.seo.getLinks.useQuery({ id: postId }, { enabled: open && isLocal });
  const remoteQuery = trpc.seo.remoteGetLinks.useQuery(
    { siteId: siteId ?? 0, postId, type },
    { enabled: open && !isLocal && siteId != null },
  );
  const updateLocal = trpc.seo.updateLink.useMutation();
  const removeLocal = trpc.seo.removeLink.useMutation();
  const updateRemote = trpc.seo.remoteUpdateLink.useMutation();
  const removeRemote = trpc.seo.remoteRemoveLink.useMutation();
  // Raw-HTML edit (the popup's HTML column) — replaces the whole <a> element on the page.
  const updateHtmlLocal = trpc.seo.updateLinkHtml.useMutation();
  const updateHtmlRemote = trpc.seo.remoteUpdateLinkHtml.useMutation();
  // Link optimization (card): rel toggle + whole-element delete with a restorable ledger.
  const relLocal = trpc.seo.setLinkRel.useMutation();
  const relRemote = trpc.seo.remoteSetLinkRel.useMutation();
  const deleteLocal = trpc.seo.deleteLink.useMutation();
  const deleteRemote = trpc.seo.remoteDeleteLink.useMutation();
  const restoreLocal = trpc.seo.restoreDeletedLink.useMutation();
  const restoreRemote = trpc.seo.remoteRestoreDeletedLink.useMutation();
  const deletedLocalQuery = trpc.seo.deletedLinks.useQuery({ id: postId }, { enabled: open && isLocal });
  const deletedRemoteQuery = trpc.seo.remoteDeletedLinks.useQuery(
    { siteId: siteId ?? 0, postId },
    { enabled: open && !isLocal && siteId != null },
  );
  const deletedRows: { ledgerId: number; anchor: string; to: string; html: string; deletedAt: string }[] =
    ((isLocal ? deletedLocalQuery.data : deletedRemoteQuery.data) as any)?.deleted ?? [];
  const refetchDeleted = () => { void (isLocal ? deletedLocalQuery.refetch() : deletedRemoteQuery.refetch()); };
  const localScan = trpc.seo.scanLinks.useMutation();
  const remoteScan = trpc.seo.remoteScanLinks.useMutation();
  const [rescanning, setRescanning] = useState(false);

  // Resizable, persisted column widths (shared SEO-table mechanism).
  // v2 storage key: the dialog got wider + column defaults grew — a saved v1 layout would keep the
  // old cramped widths, so start fresh (users' future resizes still persist under v2).
  const { width: colWidth, setWidth: setColWidth } = useColumnLayout(LINK_KEYS, LINK_DEFAULT_WIDTHS, 'pcm:seo:links:col-layout:v2');
  const startResize = (key: string) => (e: PointerEvent<HTMLSpanElement>) => {
    e.preventDefault();
    e.stopPropagation();
    const startX = e.clientX;
    const startW = colWidth(key);
    const onMove = (ev: globalThis.PointerEvent) => setColWidth(key, startW + (ev.clientX - startX));
    const onUp = () => {
      window.removeEventListener('pointermove', onMove);
      window.removeEventListener('pointerup', onUp);
      document.body.style.cursor = '';
    };
    document.body.style.cursor = 'col-resize';
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
  };
  const tableWidth = LINK_SELECT_W + LINK_KEYS.reduce((s, k) => s + colWidth(k), 0);

  const queryData: any = isLocal ? localQuery.data : remoteQuery.data;
  const loading = isLocal ? localQuery.isLoading : remoteQuery.isLoading;

  // Hydrate from the active query whenever it resolves / the dialog opens.
  useEffect(() => {
    if (!open) return;
    const list: LinkRow[] = Array.isArray(queryData?.links) ? queryData.links : [];
    setLinks(list);
    setSelected(new Set());
    setJustEditedTo(null);
  }, [open, queryData]);

  const filtered = useMemo(() => {
    const base = links.filter((l) => (kind === 'broken' ? l.broken : l.kind === kind));
    // Keep a just-edited link in view even if its type changed (e.g. external→internal, or it's no
    // longer broken) — otherwise it vanishes after a successful edit and looks like it was lost.
    if (justEditedTo) {
      for (const l of links) {
        if (l.to === justEditedTo && !base.includes(l)) { base.push(l); }
      }
    }
    // De-dupe exact-duplicate BUILDER links (same element id + target + text). A re-scan taken mid
    // page-builder regeneration (right after an edit) can momentarily return the same element twice,
    // so the list briefly doubles before the next scan settles. Distinct on-page elements have
    // distinct element ids, so the real links (e.g. several identical buttons) are all preserved;
    // only a same-element duplicate is collapsed. Content links (no element id) are left untouched.
    const seen = new Set<string>();
    const deduped: LinkRow[] = [];
    for (const l of base) {
      if (l.elId) {
        const key = `${l.elId}|${l.to}|${l.anchor}`;
        if (seen.has(key)) continue;
        seen.add(key);
      }
      deduped.push(l);
    }
    return deduped;
  }, [links, kind, justEditedTo]);

  const applyResult = (res: any) => {
    setLinks(Array.isArray(res?.links) ? res.links : []);
    setSelected(new Set());
  };

  // Save one field (anchor or to) — keeps the other field's current value. Rewrites the
  // <a> on the page and re-scans (server returns the refreshed list).
  const saveField = async (l: LinkRow, field: 'anchor' | 'to', val: string) => {
    if (val === (field === 'anchor' ? l.anchor : l.to)) return;
    setBusyIdx(l.id);
    try {
      const anchor = field === 'anchor' ? val : l.anchor;
      const href = field === 'to' ? val : l.to;
      const res = isLocal
        ? await updateLocal.mutateAsync({ id: postId, index: l.id, anchor, href } as any)
        // oldHref lets the backend replace the URL across builder data (Elementor/Divi), reaching
        // links the post-body scan can't — the only way to edit links on builder-built pages.
        : await updateRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id, anchor, href, oldHref: l.to, elId: l.elId ?? '', oldAnchor: l.anchor } as any);
      applyResult(res);
      setJustEditedTo(field === 'to' ? href : l.to); // keep the edited link visible after the re-scan
      // When editing in the Dead view, the just-edited link drops off the list (its broken flag is
      // cleared on the fast re-scan) — explain that so it doesn't look like the link was deleted.
      const movedNote = kind === 'broken'
        ? ' It’s no longer flagged broken, so it left this Dead-links list — click “Re-scan this page”, or open the Internal/External count, to see it with its new URL.'
        : '';
      // Remote pages are often behind a page/CDN cache (e.g. Cloudflare) — the edit saves to
      // the post, but the cached HTML can keep showing the old link until purged. Set that
      // expectation so a cached page isn't mistaken for the edit not working.
      toast.success(
        (isLocal
          ? 'Link saved. If the live page still shows the old link, hard-refresh or clear your site/CDN cache (e.g. Cloudflare).'
          : 'Link saved. If the live page still shows the old link, clear its page/CDN cache (e.g. Cloudflare).')
        + movedNote,
      );
    } catch (err: any) {
      toast.error(err?.message || 'Failed to save the link');
    } finally {
      setBusyIdx(null);
    }
  };

  // Save user-edited raw HTML — the whole <a> element is replaced on the page (sanitized
  // server-side; must stay a single <a …>…</a>). Body links only: a builder link's HTML is
  // synthesized from builder data, so raw-markup replacement can't reach it.
  const saveHtml = async (l: LinkRow, val: string) => {
    if (val.trim() === l.html.trim()) return;
    if (!/^<a\s/i.test(val.trim()) || !/<\/a>\s*$/i.test(val.trim())) {
      toast.error('The HTML must be a single link element: <a href="…">text</a>.');
      return;
    }
    setBusyIdx(l.id);
    try {
      const res = isLocal
        ? await updateHtmlLocal.mutateAsync({ id: postId, index: l.id, html: val } as any)
        : await updateHtmlRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id, html: val } as any);
      applyResult(res);
      // Keep the edited link visible after the re-scan, even if its href (and thus its
      // internal/external/broken bucket) changed with the new markup.
      const href = /href=['"]([^'"]+)['"]/i.exec(val)?.[1];
      setJustEditedTo(href ?? l.to);
      toast.success(
        'Link HTML saved. If the live page still shows the old link, clear the site/CDN cache (e.g. Cloudflare).'
        + (kind === 'broken' ? ' If it’s no longer flagged broken it leaves this Dead-links list — re-scan or check the Internal/External counts.' : ''),
      );
    } catch (err: any) {
      toast.error(err?.message || 'Failed to save the link HTML');
    } finally {
      setBusyIdx(null);
    }
  };

  const remove = async (l: LinkRow) => {
    setBusyIdx(l.id);
    try {
      const res = isLocal
        ? await removeLocal.mutateAsync({ id: postId, index: l.id } as any)
        : await removeRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id } as any);
      applyResult(res);
      toast.success('Link removed');
    } catch (err: any) {
      toast.error(err?.message || 'Failed to remove the link');
    } finally {
      setBusyIdx(null);
    }
  };

  // rel="nofollow" toggle — "it just changes the link type in the code". Current state is
  // read off the row's own html, so the button always shows the OTHER state.
  const setRel = async (l: LinkRow, nofollow: boolean) => {
    setBusyIdx(l.id);
    try {
      const res = isLocal
        ? await relLocal.mutateAsync({ id: postId, index: l.id, nofollow } as any)
        : await relRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id, nofollow } as any);
      applyResult(res);
      toast.success(nofollow ? 'Link set to nofollow' : 'Link set to follow');
    } catch (err: any) {
      toast.error(err?.message || 'Failed to change the link rel');
    } finally {
      setBusyIdx(null);
    }
  };

  // DELETE the whole element (the entire <a>…</a>), not just the wrap. Reversible by
  // design: the cut lands in the Deleted list below, where Restore puts it back.
  const deleteWhole = async (l: LinkRow) => {
    if (!window.confirm('Delete this entire link element from the page? It moves to the Deleted list below, where you can restore it.')) return;
    setBusyIdx(l.id);
    try {
      const res = isLocal
        ? await deleteLocal.mutateAsync({ id: postId, index: l.id } as any)
        : await deleteRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id } as any);
      applyResult(res);
      refetchDeleted();
      toast.success('Element deleted — restorable from the Deleted list below.');
    } catch (err: any) {
      toast.error(err?.message || 'Failed to delete the element');
    } finally {
      setBusyIdx(null);
    }
  };

  const restoreDeleted = async (ledgerId: number) => {
    setBulkBusy(true);
    try {
      if (isLocal) await restoreLocal.mutateAsync({ ledgerId } as any);
      else await restoreRemote.mutateAsync({ siteId: siteId ?? 0, ledgerId } as any);
      refetchDeleted();
      void (isLocal ? localQuery.refetch() : remoteQuery.refetch());
      toast.success('Link restored to the page.');
    } catch (err: any) {
      toast.error(err?.message || 'Failed to restore the link');
    } finally {
      setBulkBusy(false);
    }
  };

  // Remove a set of links by their html (indices shift after each server re-scan, so we
  // re-resolve each by html against the freshest list every iteration).
  const removeLinks = async (htmls: string[]) => {
    if (htmls.length === 0) return;
    setBulkBusy(true);
    let current = links;
    for (const html of htmls) {
      const target = current.find((l) => l.html === html);
      if (!target) continue;
      try {
        const res: any = isLocal
          ? await removeLocal.mutateAsync({ id: postId, index: target.id } as any)
          : await removeRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: target.id } as any);
        current = Array.isArray(res?.links) ? res.links : current;
      } catch { /* keep going */ }
    }
    setLinks(current);
    setSelected(new Set());
    setBulkBusy(false);
  };

  const removeSelected = async () => {
    await removeLinks(filtered.filter((l) => selected.has(l.id) && isEditable(l)).map((l) => l.html));
    toast.success('Removed selected links');
  };

  // One-click fix for dead links: unwrap every (editable) broken link (keeps the anchor text).
  const removeAllBroken = async () => {
    const htmls = filtered.filter(isEditable).map((l) => l.html);
    if (htmls.length === 0) return;
    if (!window.confirm(`Remove all ${htmls.length} dead link(s)? This unwraps each broken <a> (the text stays).`)) return;
    await removeLinks(htmls);
    toast.success('Removed all dead links');
  };

  // Re-scan this page from inside the popup (recovers an empty/stale list).
  const rescan = async () => {
    setRescanning(true);
    try {
      if (isLocal) {
        await localScan.mutateAsync({ id: postId } as any);
        const r: any = await localQuery.refetch();
        setLinks(Array.isArray(r?.data?.links) ? r.data.links : []);
      } else {
        await remoteScan.mutateAsync({ siteId: siteId ?? 0, postId, type } as any);
        const r: any = await remoteQuery.refetch();
        setLinks(Array.isArray(r?.data?.links) ? r.data.links : []);
      }
      setSelected(new Set());
      toast.success('Page re-scanned');
    } catch (err: any) {
      toast.error(err?.message || 'Re-scan failed');
    } finally {
      setRescanning(false);
    }
  };

  const toggleOne = (id: number) =>
    setSelected((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });
  // Only editable links are selectable / bulk-removable.
  const selectable = filtered.filter(isEditable);
  const allSelected = selectable.length > 0 && selectable.every((l) => selected.has(l.id));
  const toggleAll = () =>
    setSelected(allSelected ? new Set() : new Set(selectable.map((l) => l.id)));
  const hasReadOnly = filtered.some((l) => !isEditable(l));

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-[min(1400px,95vw)] max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="capitalize">{kind === 'broken' ? 'Dead' : kind} links · {title}</DialogTitle>
          <DialogDescription>
            Click a link's text, target, or HTML to edit it (Enter saves, Esc cancels) — the change is
            rewritten on the page. Remove unwraps the link (keeps the text). Redirect opens the target in a new tab.
          </DialogDescription>
        </DialogHeader>

        {/* Dead-links one-click fix. */}
        {kind === 'broken' && filtered.length > 0 && (
          <div className="flex items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2">
            <span className="text-xs text-muted-foreground">
              {filtered.length} dead link{filtered.length === 1 ? '' : 's'} found. One-click fix unwraps each broken link, keeping its text.
            </span>
            <Button variant="destructive" size="sm" disabled={bulkBusy} onClick={removeAllBroken} className="gap-1.5 shrink-0">
              {bulkBusy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Unlink className="h-3.5 w-3.5" />}
              Remove all dead links
            </Button>
          </div>
        )}

        {/* Some links live outside the editable post content (page-builder / theme layout). */}
        {!loading && hasReadOnly && (
          <div className="flex items-start gap-2 rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            <Lock className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            <span>
              Some links sit in a page-builder or theme layout, not the editable post content — they're shown
              <span className="font-medium"> read-only</span>. Edit those on the site itself.
            </span>
          </div>
        )}

        {loading ? (
          <div className="flex justify-center py-12"><Loader2 className="h-5 w-5 animate-spin text-primary" /></div>
        ) : filtered.length === 0 ? (
          <div className="rounded-md border border-border py-8 text-center text-muted-foreground">
            <div className="flex flex-col items-center gap-2">
              <span>
                No {kind === 'broken' ? 'dead' : kind} links
                {links.length === 0 ? ' — this page may not have been scanned yet.' : ' found.'}
              </span>
              <Button variant="outline" size="sm" disabled={rescanning} onClick={rescan} className="gap-1.5">
                {rescanning ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                Re-scan this page
              </Button>
            </div>
          </div>
        ) : (
          <div className="rounded-md border border-border overflow-auto">
            <Table style={{ width: tableWidth, minWidth: '100%' }} className={`table-fixed ${SEO_TABLE_GRID}`}>
              <colgroup>
                <col style={{ width: LINK_SELECT_W }} />
                {LINK_COLS.map((c) => <col key={c.key} style={{ width: colWidth(c.key) }} />)}
              </colgroup>
              <TableHeader>
                <TableRow>
                  <TableHead className="text-center"><Checkbox checked={allSelected} onCheckedChange={toggleAll} aria-label="Select all" /></TableHead>
                  {LINK_COLS.map((c) => (
                    <ColumnHead
                      key={c.key}
                      label={c.label}
                      width={`${colWidth(c.key)}px`}
                      className={c.key === 'status' || c.key === 'action' ? 'text-center' : undefined}
                      onResizeStart={startResize(c.key)}
                    />
                  ))}
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered.map((l) => {
                  const rowBusy = busyIdx === l.id || bulkBusy;
                  const editable = isEditable(l);
                  const roTitle = 'Read-only — this link lives in a page-builder or theme layout, not the editable post content. Edit it on the site.';
                  return (
                    <TableRow key={l.id} className="hover:bg-muted/60">
                      <TableCell className="text-center"><Checkbox checked={selected.has(l.id)} disabled={!editable} onCheckedChange={() => toggleOne(l.id)} aria-label="Select link" /></TableCell>
                      <TableCell>
                        {editable
                          ? <EditableTextCell value={l.anchor} placeholder="(no text)" onSave={(v) => saveField(l, 'anchor', v)} />
                          : <div className="truncate" title={l.anchor}>{l.anchor || <span className="text-muted-foreground/50">(no text)</span>}</div>}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        <a
                          href={l.from}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="block truncate hover:text-primary hover:underline"
                          title={`${l.from} — open in a new tab`}
                        >
                          {l.from}
                        </a>
                      </TableCell>
                      <TableCell>
                        {editable ? (
                          // Text stays click-to-edit; the icon opens the target directly.
                          <div className="flex min-w-0 items-center gap-1.5">
                            <a
                              href={l.to}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="shrink-0 text-muted-foreground hover:text-primary"
                              title="Open in a new tab"
                            >
                              <ExternalLink className="h-3 w-3" />
                            </a>
                            <div className="min-w-0 flex-1">
                              <EditableTextCell value={l.to} onSave={(v) => saveField(l, 'to', v)} />
                            </div>
                          </div>
                        ) : (
                          <a
                            href={l.to}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="block truncate hover:text-primary hover:underline"
                            title={`${l.to} — open in a new tab`}
                          >
                            {l.to}
                          </a>
                        )}
                      </TableCell>
                      <TableCell className="text-muted-foreground">
                        {editable && !l.elId ? (
                          // Click-to-edit raw markup (card: "every column … edit manually and save").
                          <div className="font-mono text-[10px]">
                            <EditableTextCell value={l.html} onSave={(v) => saveHtml(l, v)} />
                          </div>
                        ) : (
                          <div
                            className="truncate font-mono text-[10px]"
                            title={l.elId ? `${l.html} — builder link: its HTML is generated from builder data; edit its Anchor or To instead` : l.html}
                          >
                            {l.html}
                          </div>
                        )}
                      </TableCell>
                      <TableCell className="text-center"><StatusCell link={l} /></TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-1">
                          <Button variant="outline" size="sm" className="h-7 px-2" onClick={() => window.open(l.to, '_blank', 'noopener,noreferrer')} title="Open link in a new tab">
                            <ExternalLink className="h-3 w-3" />
                          </Button>
                          {editable ? (
                            <>
                              {/* Unlink = unwrap, text/element stays (card wording). */}
                              <Button variant="ghost" size="sm" className="h-7 px-2 text-muted-foreground hover:text-destructive" disabled={rowBusy} onClick={() => remove(l)} title="Unlink — remove the link but keep the text">
                                {busyIdx === l.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Unlink className="h-3 w-3" />}
                              </Button>
                              {/* Follow/nofollow — state read from the row's own html. */}
                              {/nofollow/i.test(l.html) ? (
                                <Button variant="ghost" size="sm" className="h-7 px-2 text-muted-foreground" disabled={rowBusy} onClick={() => setRel(l, false)} title="Currently nofollow — set to follow">
                                  <Shield className="h-3 w-3" />
                                </Button>
                              ) : (
                                <Button variant="ghost" size="sm" className="h-7 px-2 text-muted-foreground" disabled={rowBusy} onClick={() => setRel(l, true)} title="Currently follow — set to nofollow">
                                  <ShieldOff className="h-3 w-3" />
                                </Button>
                              )}
                              {/* Delete = the ENTIRE element, restorable from the list below. */}
                              <Button variant="ghost" size="sm" className="h-7 px-2 text-muted-foreground hover:text-destructive" disabled={rowBusy} onClick={() => deleteWhole(l)} title="Delete the entire element (restorable below)">
                                <Trash2 className="h-3 w-3" />
                              </Button>
                            </>
                          ) : (
                            <span className="inline-flex h-7 w-7 items-center justify-center text-muted-foreground/50" title={roTitle}>
                              <Lock className="h-3 w-3" />
                            </span>
                          )}
                        </div>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </div>
        )}

        {selected.size > 0 && (
          <div className="flex justify-end pt-2">
            <Button variant="destructive" size="sm" disabled={bulkBusy} onClick={removeSelected} className="gap-1.5">
              {bulkBusy ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <Trash2 className="h-3.5 w-3.5" />}
              Remove {selected.size} selected
            </Button>
          </div>
        )}

        {/* Deleted links — the card's contract: a deleted element "is still
            visible so we can add it back". Rows come from the hub's
            seo_deleted_links ledger for THIS page and restore in one click. */}
        {deletedRows.length > 0 && (
          <div className="mt-4 space-y-1.5">
            <p className="text-xs font-semibold text-muted-foreground">
              Deleted links on this page — restorable
            </p>
            <div className="rounded-md border border-border">
              {deletedRows.map((d) => (
                <div key={d.ledgerId} className="flex items-center gap-3 border-b border-border px-3 py-1.5 last:border-b-0">
                  <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground line-through" title={d.html}>
                    {d.anchor || d.to || d.html}
                  </span>
                  <a href={d.to} target="_blank" rel="noopener noreferrer" className="max-w-[16rem] shrink-0 truncate text-xs text-muted-foreground hover:text-primary hover:underline" title={d.to}>
                    {d.to}
                  </a>
                  <span className="shrink-0 text-[10px] text-muted-foreground/60">{d.deletedAt}</span>
                  <Button variant="outline" size="sm" className="h-7 shrink-0 gap-1.5" disabled={bulkBusy} onClick={() => restoreDeleted(d.ledgerId)} title="Put this element back on the page">
                    <Undo2 className="h-3 w-3" /> Restore
                  </Button>
                </div>
              ))}
            </div>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
