/**
 * LinksPopup — inspect & edit a post/page's links (internal / external / dead).
 *
 * Opened by clicking a count in the Internal / External / Dead columns. Renders the links
 * in the SHARED SEO spreadsheet table (seo-table.tsx) with click-to-edit Anchor + To cells
 * (Enter/blur rewrites the <a> on the page; Esc cancels). Remove unwraps the <a> (keeps the
 * text). For the Dead view, "Remove all dead links" unwraps every broken link in one click.
 * Works on the local site and (via the connector) on connected sites.
 */

import { useEffect, useMemo, useState } from 'react';
import { ExternalLink, Unlink, Loader2, Trash2, RefreshCw } from 'lucide-react';
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
}

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

  const localQuery = trpc.seo.getLinks.useQuery({ id: postId }, { enabled: open && isLocal });
  const remoteQuery = trpc.seo.remoteGetLinks.useQuery(
    { siteId: siteId ?? 0, postId, type },
    { enabled: open && !isLocal && siteId != null },
  );
  const updateLocal = trpc.seo.updateLink.useMutation();
  const removeLocal = trpc.seo.removeLink.useMutation();
  const updateRemote = trpc.seo.remoteUpdateLink.useMutation();
  const removeRemote = trpc.seo.remoteRemoveLink.useMutation();
  const localScan = trpc.seo.scanLinks.useMutation();
  const remoteScan = trpc.seo.remoteScanLinks.useMutation();
  const [rescanning, setRescanning] = useState(false);

  const queryData: any = isLocal ? localQuery.data : remoteQuery.data;
  const loading = isLocal ? localQuery.isLoading : remoteQuery.isLoading;

  // Hydrate from the active query whenever it resolves / the dialog opens.
  useEffect(() => {
    if (!open) return;
    const list: LinkRow[] = Array.isArray(queryData?.links) ? queryData.links : [];
    setLinks(list);
    setSelected(new Set());
  }, [open, queryData]);

  const filtered = useMemo(
    () => links.filter((l) => (kind === 'broken' ? l.broken : l.kind === kind)),
    [links, kind],
  );

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
        : await updateRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id, anchor, href } as any);
      applyResult(res);
      toast.success('Link saved to the page');
    } catch (err: any) {
      toast.error(err?.message || 'Failed to save the link');
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
    await removeLinks(filtered.filter((l) => selected.has(l.id)).map((l) => l.html));
    toast.success('Removed selected links');
  };

  // One-click fix for dead links: unwrap every broken link (keeps the anchor text).
  const removeAllBroken = async () => {
    const htmls = filtered.map((l) => l.html);
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
  const allSelected = filtered.length > 0 && filtered.every((l) => selected.has(l.id));
  const toggleAll = () =>
    setSelected(allSelected ? new Set() : new Set(filtered.map((l) => l.id)));

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-4xl max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="capitalize">{kind === 'broken' ? 'Dead' : kind} links · {title}</DialogTitle>
          <DialogDescription>
            Click a link's text or target to edit it (Enter saves, Esc cancels) — the change is rewritten on
            the page. Remove unwraps the link (keeps the text). Redirect opens the target in a new tab.
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
            <Table className={`w-full ${SEO_TABLE_GRID}`}>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-8 text-center"><Checkbox checked={allSelected} onCheckedChange={toggleAll} aria-label="Select all" /></TableHead>
                  <TableHead>Anchor</TableHead>
                  <TableHead>From</TableHead>
                  <TableHead>To</TableHead>
                  <TableHead>HTML</TableHead>
                  <TableHead className="text-center">Status</TableHead>
                  <TableHead className="text-center">Action</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered.map((l) => {
                  const rowBusy = busyIdx === l.id || bulkBusy;
                  return (
                    <TableRow key={l.id} className="hover:bg-muted/60">
                      <TableCell className="text-center"><Checkbox checked={selected.has(l.id)} onCheckedChange={() => toggleOne(l.id)} aria-label="Select link" /></TableCell>
                      <TableCell><EditableTextCell value={l.anchor} placeholder="(no text)" onSave={(v) => saveField(l, 'anchor', v)} /></TableCell>
                      <TableCell className="text-muted-foreground"><div className="max-w-[150px] truncate" title={l.from}>{l.from}</div></TableCell>
                      <TableCell><EditableTextCell value={l.to} onSave={(v) => saveField(l, 'to', v)} /></TableCell>
                      <TableCell className="text-muted-foreground"><div className="max-w-[180px] truncate font-mono text-[10px]" title={l.html}>{l.html}</div></TableCell>
                      <TableCell className="text-center"><StatusCell link={l} /></TableCell>
                      <TableCell>
                        <div className="flex items-center justify-center gap-1">
                          <Button variant="outline" size="sm" className="h-7 px-2" onClick={() => window.open(l.to, '_blank', 'noopener,noreferrer')} title="Open link in a new tab">
                            <ExternalLink className="h-3 w-3" />
                          </Button>
                          <Button variant="ghost" size="sm" className="h-7 px-2 text-muted-foreground hover:text-destructive" disabled={rowBusy} onClick={() => remove(l)} title="Remove link">
                            {busyIdx === l.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Unlink className="h-3 w-3" />}
                          </Button>
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
      </DialogContent>
    </Dialog>
  );
}
