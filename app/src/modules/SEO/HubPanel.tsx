/**
 * HubPanel — manage remote WordPress sites (Phase 8).
 *
 * Create a site → download its generated connector plugin → install it on the
 * remote site. The connector registers back via an HMAC handshake and the
 * site flips to "active". Admin-only (the rows carry secrets server-side; this
 * UI never sees them).
 */

import { useState, useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Loader2, Plus, Download, Trash2, Ban, Globe } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { trpc, getConfig } from '@/lib/trpc';

interface Site { id: number; name: string; domain: string | null; siteUrl: string | null; status: string; lastPingAt: string | null }

const STATUS_STYLES: Record<string, string> = {
  active: 'bg-green-100 text-green-800',
  pending: 'bg-amber-100 text-amber-800',
  revoked: 'bg-red-100 text-red-700',
};
const HUB_KEY = ['seohub', 'listSites'] as const;

export function HubPanel() {
  const queryClient = useQueryClient();
  const { data, isLoading } = trpc.seohub.listSites.useQuery() as { data?: unknown; isLoading: boolean };
  const sites: Site[] = Array.isArray(data) ? (data as Site[]) : [];

  const createM = trpc.seohub.createSite.useMutation();
  const revokeM = trpc.seohub.revokeSite.useMutation();
  const deleteM = trpc.seohub.deleteSite.useMutation();
  const [name, setName] = useState('');
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(() => queryClient.invalidateQueries({ queryKey: HUB_KEY }), [queryClient]);

  const handleCreate = useCallback(async () => {
    if (!name.trim()) return;
    setBusy(true);
    try {
      await createM.mutateAsync({ name: name.trim() });
      setName('');
      toast.success('Site created — download its connector to finish setup');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Create failed');
    } finally { setBusy(false); }
  }, [name, createM, refresh]);

  const handleDownload = useCallback(async (id: number, label: string) => {
    try {
      const cfg = getConfig();
      const res = await fetch(`${cfg.restUrl}seohub/sites/${id}/connector`, { headers: { 'X-WP-Nonce': cfg.nonce } });
      if (!res.ok) throw new Error('Download failed');
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `pcm-connector-${label}.zip`;
      a.click();
      URL.revokeObjectURL(url);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Download failed');
    }
  }, []);

  const handleRevoke = useCallback(async (id: number) => {
    await revokeM.mutateAsync({ id }); toast.success('Site revoked'); refresh();
  }, [revokeM, refresh]);

  const handleDelete = useCallback(async (id: number) => {
    await deleteM.mutateAsync({ id }); toast.success('Site deleted'); refresh();
  }, [deleteM, refresh]);

  return (
    <div className="space-y-5 max-w-3xl">
      <div className="rounded-xl border border-border p-4 space-y-2">
        <p className="text-sm font-medium">Add a managed site</p>
        <p className="text-xs text-muted-foreground">Create a site, download its connector plugin, and install it on the remote WordPress. It registers back automatically over a signed handshake.</p>
        <div className="flex gap-2">
          <Input value={name} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') handleCreate(); }} placeholder="Site / client name" className="text-sm" />
          <Button onClick={handleCreate} disabled={busy || !name.trim()} className="gap-1.5">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />} Add
          </Button>
        </div>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-12"><Loader2 className="w-6 h-6 animate-spin text-primary" /></div>
      ) : sites.length === 0 ? (
        <div className="border border-dashed border-border rounded-xl p-10 text-center text-sm text-muted-foreground">No managed sites yet.</div>
      ) : (
        <div className="rounded-xl border border-border overflow-hidden">
          {sites.map((s) => (
            <div key={s.id} className="flex items-center justify-between gap-3 px-4 py-3 border-b border-border last:border-0">
              <div className="min-w-0">
                <div className="text-sm font-medium flex items-center gap-1.5"><Globe className="w-3.5 h-3.5 text-muted-foreground" /> {s.name || '(unnamed)'}</div>
                <div className="text-xs text-muted-foreground truncate">{s.siteUrl || s.domain || 'not connected yet'}</div>
              </div>
              <div className="flex items-center gap-2 shrink-0">
                <span className={`text-xs rounded px-1.5 py-0.5 capitalize ${STATUS_STYLES[s.status] ?? 'bg-muted'}`}>{s.status}</span>
                <Button variant="ghost" size="icon" title="Download connector" onClick={() => handleDownload(s.id, s.name || String(s.id))}><Download className="w-4 h-4" /></Button>
                {s.status === 'active' && <Button variant="ghost" size="icon" title="Revoke" onClick={() => handleRevoke(s.id)}><Ban className="w-4 h-4" /></Button>}
                <Button variant="ghost" size="icon" title="Delete" onClick={() => handleDelete(s.id)}><Trash2 className="w-4 h-4 text-destructive" /></Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
