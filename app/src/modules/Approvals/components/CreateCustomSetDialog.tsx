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

import { useEffect, useRef, useState } from 'react';

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
  useProjectPickerData,
  type ProjectPickerValue,
} from '@/components/shared/ProjectPicker';
import {
  ApprovalSharePanel,
  buildDefaultInviteMessage,
} from '@/components/shared/ApprovalSharePanel';
import { buildPublicBoardUrl, useApprovalSetsCache } from '@/components/shared/approvalSets';
import { SearchableSelect } from '@/components/shared/SearchableSelect';
import { Send, Loader2, Check, Link as LinkIcon } from 'lucide-react';
import { toast } from 'sonner';
import { escapeAstralDeep } from '@/lib/escapeAstral';

import { CustomCardEditor } from './CustomCardEditor';
import { setColumns } from '../kanban/setColumns';
import { APPROVAL_STATUSES, type ApprovalStatus } from '../types';

/**
 * Lane choices for Row 1 and for the share step's "move after sending" — both
 * read the ONE registry, so adding a lane stays a one-line change in
 * setColumns.ts and can never disagree between the two dropdowns.
 */
const laneOptions = setColumns.map((c) => ({ value: c.id as string, label: c.label }));

function uid(): string {
  try { return crypto.randomUUID(); } catch { /* older browsers */ }
  return 'custom_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

interface CreateCustomSetDialogProps {
  open: boolean;
  onClose: () => void;
  /**
   * Pre-selection carried in from wherever the create was started — a delivery
   * card's "+", or a board lane's "+" with the active filters. Every field is a
   * hint the user can still change.
   *
   * `status` = the lane the set is born in (the board's lane "+"); omitted means
   * 'draft', the server's default. `deliveryId` never lands on the set — it only
   * seeds the project→delivery link (the set maps to a project and nothing else).
   */
  preset?: {
    brandId?: number | null;
    projectId?: number | null;
    deliveryId?: number | null;
    status?: string | null;
  };
}

export function CreateCustomSetDialog({ open, onClose, preset }: CreateCustomSetDialogProps) {
  const [content, setContent] = useState('<p></p>');
  const [overlay, setOverlay] = useState<string | null>(null);
  const [name, setName] = useState('');
  // The set's ONE mapping: a project (existing, or created on submit).
  const [project, setProject] = useState<ProjectPickerValue>(EMPTY_PROJECT_PICK);
  const [clientEmail, setClientEmail] = useState('');
  // Which lane the set is CREATED in (distinct from the share step's
  // "move to lane after sending"). Registry-driven, defaults to the clicked "+".
  const [lane, setLane] = useState<ApprovalStatus>('draft');
  // Set once created — reveals the share panel; the editor stays open beside it.
  const [created, setCreated] = useState<{ id: number; shareUrl: string } | null>(null);
  // Whether the share step is showing. "Save" in that step closes it and leaves
  // you in the editor; the header button re-opens it.
  const [shareOpen, setShareOpen] = useState(false);
  // Set by "Save and Close" so the create's success handler closes the dialog.
  const closeAfterCreate = useRef(false);

  const { projects, deliveries, brands } = useProjectPickerData();

  const createProjectMutation = trpc.assets.createProject.useMutation();
  const setProjectDeliveryMutation = trpc.assets.setProjectDelivery.useMutation();
  const approvalSetsCache = useApprovalSetsCache();

  // Fresh canvas + fields every time it opens, so a cancelled draft never leaks into the next one.
  useEffect(() => {
    if (!open) return;
    setContent('<p></p>');
    setOverlay(null);
    setName('');
    // Seed the mapping row from wherever the create was started (lane "+" with
    // filters on, or a delivery card's "+"). Every value stays changeable.
    setProject({
      projectId: preset?.projectId ?? null,
      newProjectName: null,
      deliveryId: preset?.deliveryId ?? null,
      brandId: preset?.brandId ?? null,
    });
    setClientEmail('');
    setShareOpen(false);
    closeAfterCreate.current = false;
    setLane(
      preset?.status && (APPROVAL_STATUSES as ReadonlyArray<string>).includes(preset.status)
        ? (preset.status as ApprovalStatus)
        : 'draft'
    );
    setCreated(null);
  }, [open, preset?.projectId, preset?.status]);

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

      // "Save and Close" finishes here; "Generate Share Link" opens the step.
      if (closeAfterCreate.current) {
        closeAfterCreate.current = false;
        onClose();
      } else {
        setShareOpen(true);
      }
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
      effProjectId = await resolveProjectId(
        project,
        createProjectMutation.mutateAsync,
        setProjectDeliveryMutation.mutateAsync,
        projects
      );
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
      // Narrowing value from the mapping row; the live chain overwrites it the
      // moment the set has a project, so it can never contradict the mapping.
      brandId: project.brandId ?? preset?.brandId ?? null,
      // The lane picked in Row 1 (seeded from the board "+").
      status: lane,
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
          <div className="flex items-start justify-between gap-4">
            <div>
              <DialogTitle>{created ? 'Approval set' : 'New approval set'}</DialogTitle>
              <DialogDescription>
                {created
                  ? 'Saved. Copy the link or email it — the editor stays open.'
                  : 'Write the document, name the set, and send it — all here.'}
              </DialogDescription>
            </div>
            {/* Generate Share Link: saves the set and reveals the link WITHOUT
                closing anything. Once saved it reports that, and the panel below
                owns copying and sending. */}
            <Button
              type="button"
              variant={created ? 'secondary' : 'default'}
              onClick={() => (created ? setShareOpen((v) => !v) : void handleCreate())}
              disabled={busy || !name.trim()}
              className="shrink-0 gap-1.5"
            >
              {busy ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : created ? (
                <Check className="h-4 w-4" />
              ) : (
                <LinkIcon className="h-4 w-4" />
              )}
              {created ? (shareOpen ? 'Saved' : 'Share link') : 'Generate Share Link'}
            </Button>
          </div>
        </DialogHeader>

        {/* Only the BODY scrolls; header and footer stay put, so the actions are
            always reachable on a long document. */}
        <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overflow-x-hidden py-2">
          {/* Row 1 — what the set IS. bg-card on every input; no transparent
              fields sitting on the dialog surface. */}
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
            <div className="space-y-1.5">
              <Label htmlFor="set-email">Recipient Email</Label>
              <Input
                id="set-email"
                type="email"
                value={clientEmail}
                onChange={(e) => setClientEmail(e.target.value)}
                placeholder="Optional — emails the link"
                className="bg-card"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="set-lane">Lane</Label>
              {/* Options come from the lane registry — adding a lane stays a
                  one-line change in setColumns.ts, never here. */}
              <SearchableSelect
                options={laneOptions}
                value={lane}
                onChange={(next) => setLane((next as ApprovalStatus) ?? 'draft')}
                placeholder="Draft"
                allLabel="Draft"
                searchPlaceholder="Search lanes…"
                emptyLabel="No lanes match"
                className="w-full"
                ariaLabel="Lane"
                disabled={busy || created !== null}
              />
            </div>
          </div>

          {/* Row 2 — the mapping. Any one narrows the other two. */}
          <div className="grid gap-3 sm:grid-cols-3">
            <ProjectPicker
              projects={projects}
              deliveries={deliveries}
              brands={brands}
              value={project}
              onChange={setProject}
              disabled={busy}
            />
          </div>

          {/* The link + send step, revealed by Generate Share Link. The editor
              stays mounted below it — nothing is lost by sharing. */}
          {created && shareOpen && (
            <ApprovalSharePanel
              setId={created.id}
              shareUrl={created.shareUrl}
              defaultEmail={clientEmail}
              defaultMessage={buildDefaultInviteMessage(null)}
              // An email entered before saving was already sent by the server
              // (create_set → share_set → client_invite) — don't invite a resend.
              alreadySent={clientEmail.trim() !== ''}
              lanes={laneOptions.map((l) => ({ id: l.value, label: l.label }))}
              defaultLaneAfterSend="client"
              // Save = sent + moved, close THIS step only; the editor stays.
              onSaved={() => setShareOpen(false)}
              // Save and Close = sent + moved, everything closes.
              onSaveAndClose={onClose}
              onCancel={() => setShareOpen(false)}
            />
          )}

          <CustomCardEditor content={content} onChange={setContent} overlay={overlay} onOverlayChange={setOverlay} />
        </div>

        <DialogFooter className="shrink-0">
          <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>
            Cancel
          </Button>
          <Button
            type="button"
            onClick={() => {
              // Already saved? There is nothing left to create — just close.
              if (created) { onClose(); return; }
              closeAfterCreate.current = true;
              void handleCreate();
            }}
            disabled={busy || !name.trim()}
            className="gap-1.5"
          >
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
            Save and Close
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
