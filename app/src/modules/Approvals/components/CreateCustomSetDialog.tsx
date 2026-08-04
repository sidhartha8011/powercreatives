/**
 * CreateCustomSetDialog — "+ Add Approval Set" for a Custom (Notion-style) document.
 *
 * TWO views, ONE dialog. You author the document and fill in the three things the set needs
 * — name, project, client email — then create; the dialog then swaps to the shared
 * ApprovalSharePanel with the client link, instead of closing.
 *
 * It used to hand off to the shared SendToApprovalSetDialog as a second popup. That dialog
 * still exists and is still used from Ads/Copy/Image, where you're sending EXISTING assets and
 * genuinely have to choose between "new set" and "append to an existing one". Here there is
 * nothing to choose — the document was just authored, so it can only be a new set — which made
 * the second popup a step that asked one real question (the name) and re-asked things the
 * caller already knew.
 *
 * What that removal DID drop, until 2026-08-04, was the client link: this dialog closed on a
 * toast, so the one thing the flow exists to produce was never shown (gap ebe6501). The share
 * panel is now the same component all three create flows end on.
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
import {
  ApprovalSharePanel,
  buildDefaultInviteMessage,
} from '@/components/shared/ApprovalSharePanel';
import { buildPublicBoardUrl, useApprovalSetsCache } from '@/components/shared/approvalSets';
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
  // Set once created — switches the dialog from authoring to the share panel.
  const [created, setCreated] = useState<{ id: number; shareUrl: string } | null>(null);

  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  const projects: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? (projectsRaw as any[]).map((p) => ({ id: Number(p.id), name: String(p.name) })) : [];

  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const deliveries: { id: number; name: string }[] = Array.isArray(deliveriesRaw)
    ? (deliveriesRaw as any[]).map((d) => ({ id: Number(d.id), name: String(d.name) })) : [];

  const createProjectMutation = trpc.assets.createProject.useMutation();
  const approvalSetsCache = useApprovalSetsCache();

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
    setCreated(null);
  }, [open, preset?.projectId]);

  const createMutation = trpc.approvals.createSet.useMutation({
    onSuccess: (data: any) => {
      // Put the new card on the board NOW (the response is the full row), then
      // reconcile — without this the board showed nothing until a page reload.
      approvalSetsCache.registerCreated(data);

      // Stay open on the share panel: this set has a token, and the client link
      // is the point of the whole flow. Closing on a toast (what this dialog used
      // to do) dropped it silently — the only create path that did.
      setCreated({ id: Number(data.id), shareUrl: buildPublicBoardUrl(data.token) });

      toast.success(clientEmail.trim()
        ? 'Approval set created — invite emailed to the client.'
        : 'Approval set created.');
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
          <DialogTitle>{created ? 'Approval set created' : 'New approval set'}</DialogTitle>
          <DialogDescription>
            {created
              ? 'Send this link to your client, or email it from here.'
              : 'Write the document, name the set, and send it — all here.'}
          </DialogDescription>
        </DialogHeader>

        {created ? (
          <div className="min-h-0 flex-1 overflow-y-auto py-2">
            <ApprovalSharePanel
              setId={created.id}
              shareUrl={created.shareUrl}
              defaultEmail={clientEmail}
              defaultMessage={buildDefaultInviteMessage(null)}
              // An email entered before Create was already sent by the server
              // (create_set → share_set → client_invite) — don't invite a resend.
              alreadySent={clientEmail.trim() !== ''}
            />
          </div>
        ) : (
        <>
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
              onChange={(next) =>
                setProject(
                  // Came from a delivery's "+" and you're creating a project?
                  // Seed that delivery — you can still change or clear it.
                  next.newProjectName !== null && project.newProjectName === null
                    ? { ...next, newProjectDeliveryId: preset?.deliveryId ?? null }
                    : next
                )
              }
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
        </>
        )}

        <DialogFooter className="shrink-0">
          {created ? (
            <Button type="button" onClick={onClose} variant="secondary" className="w-full">
              Done &amp; Close
            </Button>
          ) : (
            <>
              <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>Cancel</Button>
              <Button type="button" onClick={handleCreate} disabled={busy || !name.trim()} className="gap-1.5">
                {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                Create approval set
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
