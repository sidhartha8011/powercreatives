/**
 * CreateCustomSetDialog — "+ Add Approval Set" for a Custom (Notion-style) document.
 *
 * ONE step. You author the document and fill in the three things the set needs — name,
 * project, client email — in the same dialog, then create.
 *
 * It used to hand off to the shared SendToApprovalSetDialog as a second popup. That dialog
 * still exists and is still used from Ads/Copy/Image, where you're sending EXISTING assets and
 * genuinely have to choose between "new set" and "append to an existing one". Here there is
 * nothing to choose — the document was just authored, so it can only be a new set — which made
 * the second popup a step that asked one real question (the name) and re-asked things the
 * caller already knew.
 *
 * Create payload is deliberately identical to the one that dialog sends (see
 * handleGenerateLink there), including escapeAstralDeep on the snapshot so a WAF that strips
 * 4-byte UTF-8 can't eat emoji in transit, and the untitled-doc-inherits-the-set-name rule.
 */

import { useEffect, useState } from 'react';

import { trpc } from '@/lib/trpc';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  ProjectPicker,
  resolveProjectId,
  EMPTY_PROJECT_PICK,
  type ProjectPickerValue,
} from '@/components/shared/ProjectPicker';
import { Send, Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import { escapeAstralDeep } from '@/lib/escapeAstral';

import { CustomCardEditor } from './CustomCardEditor';

function uid(): string {
  try { return crypto.randomUUID(); } catch { /* older browsers */ }
  return 'custom_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

interface CreateCustomSetDialogProps {
  open: boolean;
  onClose: () => void;
  /** Create-from-delivery preset: pre-selects brand/project/delivery (still overridable). */
  preset?: { brandId: number | null; projectId: number; deliveryId: number };
}

export function CreateCustomSetDialog({ open, onClose, preset }: CreateCustomSetDialogProps) {
  const [content, setContent] = useState('<p></p>');
  const [overlay, setOverlay] = useState<string | null>(null);
  const [name, setName] = useState('');
  // The set's ONE mapping: a project (existing, or created on submit).
  const [project, setProject] = useState<ProjectPickerValue>(EMPTY_PROJECT_PICK);
  const [clientEmail, setClientEmail] = useState('');

  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  const projects: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? (projectsRaw as any[]).map((p) => ({ id: Number(p.id), name: String(p.name) })) : [];

  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const deliveries: { id: number; name: string }[] = Array.isArray(deliveriesRaw)
    ? (deliveriesRaw as any[]).map((d) => ({ id: Number(d.id), name: String(d.name) })) : [];

  const createProjectMutation = trpc.assets.createProject.useMutation();

  // Fresh canvas + fields every time it opens, so a cancelled draft never leaks into the next one.
  useEffect(() => {
    if (!open) return;
    setContent('<p></p>');
    setOverlay(null);
    setName('');
    setProject(
      preset?.projectId
        ? { projectId: preset.projectId, newProjectName: null, newProjectDeliveryId: null }
        : EMPTY_PROJECT_PICK
    );
    setClientEmail('');
  }, [open, preset?.projectId]);

  const createMutation = trpc.approvals.createSet.useMutation({
    onSuccess: () => {
      toast.success(clientEmail.trim()
        ? 'Approval set created — invite emailed to the client.'
        : 'Approval set created.');
      onClose();
    },
    onError: (err: any) => toast.error(err?.message || 'Failed to create the approval set.'),
  }) as any;

  const handleCreate = async () => {
    const setName = name.trim();
    if (!setName) { toast.error('Please enter an approval set name.'); return; }

    // Create the project first when the user typed a new name, so the set is
    // saved with a real project id — or not saved at all if that fails.
    let effProjectId: number | null;
    try {
      effProjectId = await resolveProjectId(project, createProjectMutation.mutateAsync);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to create the project.');
      return;
    }

    const now = new Date().toISOString();
    const card = {
      id: uid(),
      type: 'custom' as const,
      content,
      overlay: overlay ?? undefined,
      createdAt: now,
      updatedAt: now,
      // An untitled doc inherits the set name — same rule as the old share step, so there is
      // still no separate "name the document" field.
      title: setName,
    };
    const inviteEmail = clientEmail.trim();

    createMutation.mutate({
      name: setName,
      brandId: preset?.brandId ?? null,
      // The set's ONE mapping. Delivery + brand are derived live from this
      // project server-side (PCM_Hierarchy) — never stored on the set.
      projectId: effProjectId,
      // When present the SERVER shares on create (moves the set to the client lane AND emails
      // the invite) — one request, nothing for the browser to miss.
      clientEmail: inviteEmail || null,
      clientMessage: null,
      snapshot: escapeAstralDeep({
        media: [],
        copy: [],
        custom: [card],
      }),
    });
  };

  const busy =
    (createMutation.isPending ?? createMutation.isLoading ?? false) ||
    (createProjectMutation.isPending ?? createProjectMutation.isLoading ?? false);

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o && !busy) onClose(); }}>
      <DialogContent
        className="sm:max-w-[min(64rem,calc(100vw-4rem))] max-h-[92vh] flex flex-col overflow-hidden"
        // Keep the dialog open while interacting with the WordPress media library frame or the
        // image annotator (both portal to <body>).
        onInteractOutside={(e) => {
          const t = e.target as HTMLElement | null;
          if (t?.closest?.('.media-modal, .media-frame, .media-modal-backdrop, .wp-core-ui, [data-pcm-annotator]')) {
            e.preventDefault();
          }
        }}
      >
        <DialogHeader className="shrink-0">
          <DialogTitle>New approval set</DialogTitle>
          <DialogDescription>
            Write the document, name the set, and send it — all here.
          </DialogDescription>
        </DialogHeader>

        {/* Only the BODY scrolls; header and footer stay put, so Create is always reachable
            on a long document. */}
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overflow-x-hidden py-2">
          {/* The three set fields, up top: they're short, and reading them before writing tells
              you what this document is going to be called. bg-card on every input — no
              transparent fields sitting on the dialog surface. */}
          <div className="grid gap-3 sm:grid-cols-3">
            <div className="space-y-1.5">
              <Label htmlFor="set-name">Name <span className="text-destructive">*</span></Label>
              <Input
                id="set-name"
                autoFocus
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="e.g. October campaign"
                className="bg-card"
              />
            </div>
            {/* The set's ONE mapping. Search the list or type a new name to create
                the project; delivery + brand follow from it. */}
            <ProjectPicker
              projects={projects}
              deliveries={deliveries}
              value={project}
              onChange={setProject}
              disabled={busy}
            />
            <div className="space-y-1.5">
              <Label htmlFor="set-email">Client email</Label>
              <Input
                id="set-email"
                type="email"
                value={clientEmail}
                onChange={(e) => setClientEmail(e.target.value)}
                placeholder="Optional — emails the link"
                className="bg-card"
              />
            </div>
          </div>

          <CustomCardEditor content={content} onChange={setContent} overlay={overlay} onOverlayChange={setOverlay} />
        </div>

        <DialogFooter className="shrink-0">
          <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
          <Button type="button" onClick={handleCreate} disabled={busy || !name.trim()} className="gap-1.5">
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
            Create approval set
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
