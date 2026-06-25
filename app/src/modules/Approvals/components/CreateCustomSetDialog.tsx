/**
 * CreateCustomSetDialog — "+ Add Approval Set" for a Custom (Notion-style) document.
 *
 * Two steps, mirroring the Copy module's approval flow:
 *   1. Author the document with the shared Tiptap editor (rich text + images +
 *      annotations) and pick brand/project.
 *   2. Hand the authored card to the SHARED `SendToApprovalSetDialog` — the exact
 *      same dialog the Copy module uses — which names + creates the set, moves it to
 *      the "Sent to Client for Approval" lane, and shows the share link + email invite.
 *
 * So a custom approval set is created, shared, and reviewed identically to every
 * other approval set. No bespoke create/share logic here.
 */

import { useEffect, useMemo, useState } from 'react';

import { trpc } from '@/lib/trpc';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Send } from 'lucide-react';
import {
  SendToApprovalSetDialog, type SnapshotCustomItem,
} from '@/components/shared/SendToApprovalSetDialog';

import { CustomCardEditor } from './CustomCardEditor';

function uid(): string {
  try { return crypto.randomUUID(); } catch { /* older browsers */ }
  return 'custom_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

interface CreateCustomSetDialogProps {
  open: boolean;
  onClose: () => void;
}

export function CreateCustomSetDialog({ open, onClose }: CreateCustomSetDialogProps) {
  const [step, setStep] = useState<'author' | 'send'>('author');
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('<p></p>');
  const [brandId, setBrandId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [card, setCard] = useState<SnapshotCustomItem | null>(null);

  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();

  const brands: { id: number; name: string }[] = Array.isArray(brandsRaw)
    ? brandsRaw.map((b: any) => ({ id: Number(b.id), name: String(b.name) })) : [];
  const projects: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? projectsRaw.map((p: any) => ({ id: Number(p.id), name: String(p.name) })) : [];

  const brandName = useMemo(
    () => brands.find((b) => String(b.id) === brandId)?.name ?? null,
    [brands, brandId],
  );

  // Reset to the author step whenever the dialog opens.
  useEffect(() => {
    if (!open) return;
    setStep('author');
    setTitle(''); setContent('<p></p>'); setBrandId(''); setProjectId(''); setCard(null);
  }, [open]);

  const handleContinue = () => {
    const now = new Date().toISOString();
    setCard({
      id: uid(),
      type: 'custom',
      title: title.trim() || undefined,
      content,
      createdAt: now,
      updatedAt: now,
    });
    setStep('send');
  };

  // Step 2 — reuse the Copy module's exact share dialog with the custom card.
  if (step === 'send' && card) {
    const defaultName = `${title.trim() || 'Custom document'}${brandName ? ` — ${brandName}` : ''}`;
    return (
      <SendToApprovalSetDialog
        isOpen={open}
        onClose={onClose}
        custom={[card]}
        brandId={brandId ? Number(brandId) : null}
        projectId={projectId ? Number(projectId) : null}
        brandName={brandName}
        defaultName={defaultName}
        itemSummary="1 custom document"
      />
    );
  }

  // Step 1 — author the document.
  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent
        className="sm:max-w-2xl max-h-[90vh] overflow-y-auto"
        // Keep the dialog open while interacting with the WordPress media library
        // frame or the image annotator (both portal to <body>).
        onInteractOutside={(e) => {
          const t = e.target as HTMLElement | null;
          if (t?.closest?.('.media-modal, .media-frame, .media-modal-backdrop, .wp-core-ui, [data-pcm-annotator]')) {
            e.preventDefault();
          }
        }}
      >
        <DialogHeader>
          <DialogTitle>New approval set</DialogTitle>
          <DialogDescription>
            Author a custom, Notion-style document, then send it to your client for
            approval — same flow as every other approval set.
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-4 py-2">
          <div className="grid gap-2">
            <Label htmlFor="cset-card-title">Document title</Label>
            <Input id="cset-card-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Optional — shown on the card" autoFocus maxLength={256} />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="grid gap-2">
              <Label>Brand</Label>
              <Select value={brandId || 'none'} onValueChange={(v) => setBrandId(v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue placeholder="No brand" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No brand</SelectItem>
                  {brands.map((b) => <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label>Project</Label>
              <Select value={projectId || 'none'} onValueChange={(v) => setProjectId(v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue placeholder="No project" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No project</SelectItem>
                  {projects.map((p) => <SelectItem key={p.id} value={String(p.id)}>{p.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid gap-2">
            <Label>Content</Label>
            <CustomCardEditor content={content} onChange={setContent} />
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="button" onClick={handleContinue} className="gap-1.5">
            <Send className="h-4 w-4" /> Send to Approval Set
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
