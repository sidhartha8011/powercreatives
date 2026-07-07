/**
 * CreateCustomSetDialog — "+ Add Approval Set" for a Custom (Notion-style) document.
 *
 * Two steps only:
 *   1. Author the document on a clean canvas (rich text + images + clipboard paste + draw).
 *   2. ONE combined popup (the shared SendToApprovalSetDialog) that names the set, picks the
 *      Project (brand + delivery are inherited live from it — never set here), and emails the
 *      client the link. The document inherits the set name as its title, so there's no separate
 *      "name the doc" step.
 */

import { useEffect, useState } from 'react';

import { trpc } from '@/lib/trpc';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
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
  /** Create-from-delivery preset: pre-selects brand/project/delivery in the
   *  share step (the user can still change them). */
  preset?: { brandId: number | null; projectId: number; deliveryId: number };
}

export function CreateCustomSetDialog({ open, onClose, preset }: CreateCustomSetDialogProps) {
  const [step, setStep] = useState<'author' | 'send'>('author');
  const [content, setContent] = useState('<p></p>');
  const [overlay, setOverlay] = useState<string | null>(null);
  const [card, setCard] = useState<SnapshotCustomItem | null>(null);

  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  const projects: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? (projectsRaw as any[]).map((p) => ({ id: Number(p.id), name: String(p.name) })) : [];

  // Reset to the author step whenever the dialog opens.
  useEffect(() => {
    if (!open) return;
    setStep('author'); setContent('<p></p>'); setOverlay(null); setCard(null);
  }, [open]);

  // Canvas → the single combined popup: freeze the card, then open the share dialog (which
  // collects name + project + recipient and sends).
  const handleSend = () => {
    const now = new Date().toISOString();
    setCard({ id: uid(), type: 'custom', content, overlay: overlay ?? undefined, createdAt: now, updatedAt: now });
    setStep('send');
  };

  // Step 2 — the single combined popup (shared share dialog, Project picker enabled).
  if (step === 'send' && card) {
    return (
      <SendToApprovalSetDialog
        isOpen={open}
        onClose={onClose}
        custom={[card]}
        projects={projects}
        defaultName="Custom document"
        itemSummary="1 custom document"
        brandId={preset?.brandId}
        projectId={preset?.projectId}
        defaultDeliveryId={preset?.deliveryId}
      />
    );
  }

  // Step 1 — author on a clean canvas.
  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent
        className="sm:max-w-5xl max-h-[94vh] overflow-y-auto"
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
            Author your custom, Notion-style document, then send it to your client — you’ll name it,
            pick the project, and email the link all in one step.
          </DialogDescription>
        </DialogHeader>

        <div className="py-2">
          <CustomCardEditor content={content} onChange={setContent} overlay={overlay} onOverlayChange={setOverlay} />
        </div>

        <DialogFooter>
          <Button type="button" variant="ghost" onClick={onClose}>Cancel</Button>
          <Button type="button" onClick={handleSend} className="gap-1.5">
            <Send className="h-4 w-4" /> Send to Approval Set
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
