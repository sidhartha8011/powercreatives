/**
 * RedirectPopup — offered after a slug change on a CONNECTED site: place a
 * redirect from the old URL to the new one (301/302/307/308, both URLs
 * editable, everything prefilled) and optionally rewrite the internal links
 * that still point at the old URL. "No thanks" writes nothing. The redirect
 * itself lives in the site settings panel afterwards (visible, deletable).
 */

import { useEffect, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const REDIRECT_CODES = [
  { code: 301, label: '301 — Permanent' },
  { code: 302, label: '302 — Temporary' },
  { code: 307, label: '307 — Temporary (method kept)' },
  { code: 308, label: '308 — Permanent (method kept)' },
] as const;

interface RedirectPopupProps {
  open: boolean;
  onClose: () => void;
  siteId: number;
  /** The pre-change permalink (prefills From). */
  fromUrl: string;
  /** The post-change permalink (prefills To). */
  toUrl: string;
}

export function RedirectPopup({ open, onClose, siteId, fromUrl, toUrl }: RedirectPopupProps) {
  const [from, setFrom] = useState(fromUrl);
  const [to, setTo] = useState(toUrl);
  const [code, setCode] = useState<number>(301);
  const [updateLinks, setUpdateLinks] = useState(false);
  const [saving, setSaving] = useState(false);

  // Re-prime on every offer (the component stays mounted between opens).
  useEffect(() => {
    if (open) {
      setFrom(fromUrl);
      setTo(toUrl);
      setCode(301);
      setUpdateLinks(false);
    }
  }, [open, fromUrl, toUrl]);

  const usageQuery = trpc.seo.remoteUrlUsage.useQuery(
    { siteId, url: fromUrl },
    { enabled: open && fromUrl !== '' },
  ) as { data?: { total?: number; posts?: unknown[] } };
  const linkCount = Number(usageQuery.data?.total ?? 0);

  const saveMutation = trpc.seo.remoteSaveRedirect.useMutation();

  const save = () => {
    setSaving(true);
    saveMutation
      .mutateAsync({ siteId, from, to, code, updateLinks: updateLinks && linkCount > 0 })
      .then((res: any) => {
        const rewrote = Number(res?.linksUpdated ?? 0);
        toast.success(rewrote > 0 ? `Redirect saved — ${rewrote} internal link(s) updated` : 'Redirect saved');
        onClose();
      })
      .catch((err: unknown) => {
        toast.error(err instanceof Error ? err.message : 'Failed to save the redirect');
      })
      .finally(() => setSaving(false));
  };

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-[560px]">
        <DialogHeader>
          <DialogTitle>Redirect the old URL?</DialogTitle>
          <DialogDescription>
            The address of this page changed — visitors and search engines may still use the old one.
          </DialogDescription>
        </DialogHeader>
        <div className="space-y-3">
          <div className="space-y-1.5">
            <Label htmlFor="pcm-redirect-from">From</Label>
            <Input id="pcm-redirect-from" value={from} onChange={(e) => setFrom(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="pcm-redirect-to">To</Label>
            <Input id="pcm-redirect-to" value={to} onChange={(e) => setTo(e.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="pcm-redirect-code">Type</Label>
            <select
              id="pcm-redirect-code"
              className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
              value={code}
              onChange={(e) => setCode(Number(e.target.value))}
            >
              {REDIRECT_CODES.map((c) => (
                <option key={c.code} value={c.code}>{c.label}</option>
              ))}
            </select>
          </div>
          {linkCount > 0 && (
            <label className="flex cursor-pointer items-center gap-2 text-sm">
              <Checkbox checked={updateLinks} onCheckedChange={(v) => setUpdateLinks(v === true)} />
              Also update {linkCount} internal link(s) pointing at the old URL
            </label>
          )}
        </div>
        <DialogFooter>
          <Button variant="ghost" onClick={onClose} disabled={saving}>No thanks</Button>
          <Button onClick={save} disabled={saving || from.trim() === '' || to.trim() === ''}>
            {saving && <Loader2 className="mr-1.5 size-4 animate-spin" />}
            Add redirect
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
