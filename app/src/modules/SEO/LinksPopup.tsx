/**
 * LinksPopup — inspect & edit a post/page's links (internal / external / dead).
 *
 * Opened by clicking a count in the Internal / External / Dead columns. Shows the
 * links in a spreadsheet-style table (same grid look as the SEO table) with columns:
 *   select · anchor · from · to · html · status · action (redirect, remove).
 * Anchor + To are inline-editable; Save rewrites the <a> in the page's content
 * (local or, for connected sites, via the connector). Remove unwraps the <a>.
 * Redirect opens the target URL in a new tab.
 */

import { useEffect, useMemo, useState } from 'react';
import { ExternalLink, Unlink, Save, Loader2, Trash2, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';

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

// Same spreadsheet grid styling as the SEO table.
const GRID =
  'w-full border-collapse text-xs bg-card ' +
  '[&_th]:border [&_th]:border-border/60 [&_td]:border [&_td]:border-border/60 ' +
  '[&_th]:px-2 [&_th]:h-9 [&_th]:font-normal [&_th]:text-foreground/80 ' +
  '[&_td]:px-2 [&_td]:py-1 [&_td]:align-middle ' +
  '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10 [&_thead_th]:bg-card';

function StatusCell({ link }: { link: LinkRow }) {
  if (link.broken) {
    return <span className="font-medium text-destructive">{link.status > 0 ? link.status : 'dead'}</span>;
  }
  if (link.status > 0) return <span className="text-muted-foreground">{link.status}</span>;
  return <span className="text-muted-foreground/50">—</span>;
}

export function LinksPopup({ open, onClose, postId, kind, title, isLocal, siteId, type }: LinksPopupProps) {
  const [links, setLinks] = useState<LinkRow[]>([]);
  const [edits, setEdits] = useState<Record<number, { anchor: string; to: string }>>({});
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
    setEdits({});
    setSelected(new Set());
  }, [open, queryData]);

  const filtered = useMemo(
    () => links.filter((l) => (kind === 'broken' ? l.broken : l.kind === kind)),
    [links, kind],
  );

  const editFor = (l: LinkRow) => edits[l.id] ?? { anchor: l.anchor, to: l.to };
  const setEdit = (l: LinkRow, field: 'anchor' | 'to', val: string) =>
    setEdits((e) => ({ ...e, [l.id]: { ...editFor(l), [field]: val } }));
  const isDirty = (l: LinkRow) => {
    const e = edits[l.id];
    return !!e && (e.anchor !== l.anchor || e.to !== l.to);
  };

  const applyResult = (res: any) => {
    setLinks(Array.isArray(res?.links) ? res.links : []);
    setEdits({});
    setSelected(new Set());
  };

  const save = async (l: LinkRow) => {
    const e = edits[l.id];
    if (!e) return;
    setBusyIdx(l.id);
    try {
      const res = isLocal
        ? await updateLocal.mutateAsync({ id: postId, index: l.id, anchor: e.anchor, href: e.to } as any)
        : await updateRemote.mutateAsync({ siteId: siteId ?? 0, postId, type, index: l.id, anchor: e.anchor, href: e.to } as any);
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

  // Bulk remove — re-resolves each selected link by its html against the fresh
  // list every iteration (indices shift after each server re-scan).
  const removeSelected = async () => {
    const htmls = filtered.filter((l) => selected.has(l.id)).map((l) => l.html);
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
    setEdits({});
    setSelected(new Set());
    setBulkBusy(false);
    toast.success('Removed selected links');
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
      setEdits({});
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
            Edit a link's text or target and Save to rewrite it on the page. Remove unwraps the
            link (keeps the text). Redirect opens the target in a new tab.
          </DialogDescription>
        </DialogHeader>

        {loading ? (
          <div className="flex justify-center py-12"><Loader2 className="h-5 w-5 animate-spin text-primary" /></div>
        ) : (
          <div className="rounded-md border border-border overflow-auto">
            <table className={GRID}>
              <thead>
                <tr>
                  <th className="w-8 text-center"><Checkbox checked={allSelected} onCheckedChange={toggleAll} aria-label="Select all" /></th>
                  <th>Anchor</th>
                  <th>From</th>
                  <th>To</th>
                  <th>HTML</th>
                  <th className="text-center">Status</th>
                  <th className="text-center">Action</th>
                </tr>
              </thead>
              <tbody>
                {filtered.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-6 text-center text-muted-foreground">
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
                    </td>
                  </tr>
                ) : (
                  filtered.map((l) => {
                    const e = editFor(l);
                    const rowBusy = busyIdx === l.id || bulkBusy;
                    return (
                      <tr key={l.id} className="hover:bg-muted/60">
                        <td className="text-center"><Checkbox checked={selected.has(l.id)} onCheckedChange={() => toggleOne(l.id)} aria-label="Select link" /></td>
                        <td><Input value={e.anchor} onChange={(ev) => setEdit(l, 'anchor', ev.target.value)} className="h-7 text-xs" /></td>
                        <td className="max-w-[150px] truncate text-muted-foreground" title={l.from}>{l.from}</td>
                        <td><Input value={e.to} onChange={(ev) => setEdit(l, 'to', ev.target.value)} className="h-7 text-xs" /></td>
                        <td className="max-w-[180px] truncate font-mono text-[10px] text-muted-foreground" title={l.html}>{l.html}</td>
                        <td className="text-center"><StatusCell link={l} /></td>
                        <td>
                          <div className="flex items-center justify-center gap-1">
                            {isDirty(l) && (
                              <Button size="sm" className="h-7 px-2 text-xs" disabled={rowBusy} onClick={() => save(l)} title="Save to page">
                                {busyIdx === l.id ? <Loader2 className="h-3 w-3 animate-spin" /> : <Save className="h-3 w-3" />}
                              </Button>
                            )}
                            <Button variant="outline" size="sm" className="h-7 px-2" onClick={() => window.open(l.to, '_blank', 'noopener,noreferrer')} title="Open link in a new tab">
                              <ExternalLink className="h-3 w-3" />
                            </Button>
                            <Button variant="ghost" size="sm" className="h-7 px-2 text-muted-foreground hover:text-destructive" disabled={rowBusy} onClick={() => remove(l)} title="Remove link">
                              <Unlink className="h-3 w-3" />
                            </Button>
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
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
