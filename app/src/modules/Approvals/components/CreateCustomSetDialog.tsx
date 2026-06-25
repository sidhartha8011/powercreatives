/**
 * CreateCustomSetDialog — "+ Add Approval Set" creation flow for the Approvals board.
 *
 * Creates a blank approval set (Draft lane) containing one Custom card authored with
 * the shared Tiptap editor. Reuses the existing `approvals.createSet` endpoint and the
 * existing brand/project/delivery metadata — no new APIs, no new review system. Once
 * created, the set flows through the normal lifecycle (share → public review → approve).
 */

import { useState } from 'react';
import { toast } from 'sonner';

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

import { CustomCardEditor } from './CustomCardEditor';
import type { CustomAsset } from '../types';

function uid(): string {
  try { return crypto.randomUUID(); } catch { /* older browsers */ }
  return 'custom_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

interface CreateCustomSetDialogProps {
  open: boolean;
  onClose: () => void;
  /** Called after a set is created so the board can refresh. */
  onCreated?: () => void;
}

export function CreateCustomSetDialog({ open, onClose, onCreated }: CreateCustomSetDialogProps) {
  const [name, setName] = useState('');
  const [brandId, setBrandId] = useState('');
  const [projectId, setProjectId] = useState('');
  const [deliveryId, setDeliveryId] = useState('');
  const [cardTitle, setCardTitle] = useState('');
  const [content, setContent] = useState('<p></p>');
  const [submitting, setSubmitting] = useState(false);

  const { data: brandsRaw } = trpc.brands.list.useQuery();
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const utils = trpc.useUtils();
  const createMutation = trpc.approvals.createSet.useMutation();

  const brands: { id: number; name: string }[] = Array.isArray(brandsRaw)
    ? brandsRaw.map((b: any) => ({ id: Number(b.id), name: String(b.name) })) : [];
  const projects: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? projectsRaw.map((p: any) => ({ id: Number(p.id), name: String(p.name) })) : [];
  const deliveries: { id: number; name: string }[] = Array.isArray(deliveriesRaw)
    ? deliveriesRaw.map((d: any) => ({ id: Number(d.id), name: String(d.name) })) : [];

  const reset = () => {
    setName(''); setBrandId(''); setProjectId(''); setDeliveryId('');
    setCardTitle(''); setContent('<p></p>'); setSubmitting(false);
  };

  const close = () => { reset(); onClose(); };

  const handleCreate = async () => {
    if (!name.trim()) { toast.error('Enter a set name first.'); return; }
    setSubmitting(true);
    const now = new Date().toISOString();
    const card: CustomAsset = {
      id: uid(),
      type: 'custom',
      title: cardTitle.trim() || undefined,
      content,
      createdAt: now,
      updatedAt: now,
    };
    try {
      await createMutation.mutateAsync({
        name: name.trim(),
        brandId: brandId ? Number(brandId) : null,
        projectId: projectId ? Number(projectId) : null,
        deliveryId: deliveryId ? Number(deliveryId) : null,
        snapshot: { media: [], copy: [], articles: [], custom: [card] },
      } as any);
      toast.success('Custom approval set created (Draft).');
      utils.approvals.listSets.invalidate();
      onCreated?.();
      close();
    } catch (e: any) {
      toast.error(e?.message || 'Failed to create approval set.');
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) close(); }}>
      <DialogContent className="sm:max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>New approval set</DialogTitle>
          <DialogDescription>
            Author a custom, Notion-style document. It starts in the Draft lane — share it
            for review when you're ready.
          </DialogDescription>
        </DialogHeader>

        <div className="grid gap-4 py-2">
          <div className="grid gap-2">
            <Label htmlFor="cset-name">Set name <span className="text-destructive">*</span></Label>
            <Input
              id="cset-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="e.g. ACME — Landing page copy"
              autoFocus
              maxLength={256}
            />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
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
            <div className="grid gap-2">
              <Label>Delivery</Label>
              <Select value={deliveryId || 'none'} onValueChange={(v) => setDeliveryId(v === 'none' ? '' : v)}>
                <SelectTrigger><SelectValue placeholder="No delivery" /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">No delivery</SelectItem>
                  {deliveries.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid gap-2">
            <Label htmlFor="cset-card-title">Document title</Label>
            <Input
              id="cset-card-title"
              value={cardTitle}
              onChange={(e) => setCardTitle(e.target.value)}
              placeholder="Optional — shown on the card"
              maxLength={256}
            />
          </div>

          <div className="grid gap-2">
            <Label>Content</Label>
            <CustomCardEditor content={content} onChange={setContent} />
          </div>
        </div>

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={close} disabled={submitting}>Cancel</Button>
          <Button type="button" onClick={handleCreate} disabled={submitting || !name.trim()}>
            {submitting ? 'Creating…' : 'Create approval set'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
