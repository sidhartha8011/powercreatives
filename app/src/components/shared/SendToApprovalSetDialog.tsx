import { useState, useCallback, useEffect } from 'react';
import { Share2, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { escapeAstralDeep } from '@/lib/escapeAstral';
import { Input } from '@/components/ui/input';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { colors, typography } from '@/components/shared/design-tokens';
import { ApprovalSetPicker, type AppendableSet } from '@/components/shared/ApprovalSetPicker';
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
import { trpc } from '@/lib/trpc';

// buildDefaultInviteMessage moved to ApprovalSharePanel (the one place the invite
// is composed and sent from); re-exported here so existing importers keep working.
export { buildDefaultInviteMessage } from '@/components/shared/ApprovalSharePanel';

/**
 * Generic "Send selected items to an Approval Set" dialog.
 *
 * Module-agnostic version of Ads/components/CreateApprovalSetDialog: the caller
 * passes prebuilt `media` / `copy` snapshot arrays (so Copy, Image, or any future
 * module can reuse it). Flow is identical to Ads:
 *   1. Name the set → create it (status 'draft').
 *   2. Immediately move it to the "Sent to Client for Approval" lane ('client').
 *   3. Show the public share link + optionally email the client (Brevo).
 *
 * No backend changes: it reuses the existing approvals tRPC mutations, and the
 * approvals snapshot already supports empty buckets (Copy sets have no media,
 * Image sets have no copy).
 */

export interface SnapshotMediaItem {
  id: string;
  type: string; // 'image' | 'video'
  url: string;
  prompt?: string;
  provider?: string;
  modelId?: string;
}

export interface SnapshotCopyItem {
  id: string;
  headline?: string;
  body?: string;
  cta?: string;
  description?: string;
  hashtags?: string[];
  audienceName?: string;
  angleName?: string;
  modelUsed?: string;
}

/** Custom Notion-style document item (Approvals "custom" asset type). */
export interface SnapshotCustomItem {
  id: string;
  type?: 'custom';
  title?: string;
  content: string;
  /** Persistent freehand draw layer over the whole card (transparent PNG data-URL). */
  overlay?: string;
  images?: string[];
  annotation?: Record<string, { doc?: unknown; exportUrl?: string }>;
  createdAt?: string;
  updatedAt?: string;
}

interface SendToApprovalSetDialogProps {
  isOpen: boolean;
  onClose: () => void;
  /** Frozen snapshot items for the approval set. Any/all may be empty. */
  media?: SnapshotMediaItem[];
  copy?: SnapshotCopyItem[];
  custom?: SnapshotCustomItem[];
  /** Default name shown in the input (e.g. "Copy Set — Brand (Jun 9)"). */
  defaultName: string;
  brandId?: number | null;
  projectId?: number | null;
  brandName?: string | null;
  brandLogoUrl?: string | null;
  /** Pre-fills the "send invite" email from the brand's saved client email. */
  brandClientEmail?: string | null;
  /** Short human summary for the description line, e.g. "5 copies" / "3 images". */
  itemSummary?: string;
  /**
   * Default delivery for a project created inline from this dialog (e.g. the
   * create-from-delivery "+" flow). It never lands on the set — the set is mapped
   * to the project only; the project is what carries the delivery.
   */
  defaultDeliveryId?: number | null;
}

export function SendToApprovalSetDialog({
  isOpen,
  onClose,
  media = [],
  copy = [],
  custom = [],
  defaultName,
  brandId,
  projectId,
  brandName,
  brandLogoUrl,
  brandClientEmail,
  itemSummary,
  defaultDeliveryId,
}: SendToApprovalSetDialogProps) {
  const [setName, setSetName] = useState('');
  const [shareableLink, setShareableLink] = useState('');
  const [setId, setSetId] = useState<number | null>(null);
  const [clientEmail, setClientEmail] = useState('');
  const [clientMessage, setClientMessage] = useState('');
  const [inviteSent, setInviteSent] = useState(false);
  // The set's ONE mapping: a project (existing, or created on submit).
  const [project, setProject] = useState<ProjectPickerValue>(EMPTY_PROJECT_PICK);
  // 'create' = new set (existing flow); 'append' = add to an open set.
  const [mode, setMode] = useState<'create' | 'append'>('create');
  const [targetSet, setTargetSet] = useState<AppendableSet | null>(null);

  // Projects the set can belong to; deliveries feed the project→delivery link.
  const { projects: projectOptions, deliveries, brands } = useProjectPickerData();

  const createProjectMutation = trpc.assets.createProject.useMutation();
  const setProjectDeliveryMutation = trpc.assets.setProjectDelivery.useMutation();
  const approvalSetsCache = useApprovalSetsCache();

  // Reset the dialog to its initial state ONLY when it opens. Depending on the
  // default-value props here would re-run the effect if async brand context
  // resolves while the dialog is open, silently wiping the user's in-progress
  // name/email/message edits.
  useEffect(() => {
    if (isOpen) {
      setSetName(defaultName);
      setShareableLink('');
      setSetId(null);
      setClientEmail(brandClientEmail || '');
      setClientMessage(buildDefaultInviteMessage(brandName));
      setInviteSent(false);
      setMode('create');
      setTargetSet(null);
      setProject({
        ...EMPTY_PROJECT_PICK,
        projectId: projectId ?? null,
        deliveryId: defaultDeliveryId ?? null,
        brandId: brandId ?? null,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  // Moves the set into the "Sent to Client for Approval" lane (status 'client').
  const moveStatusMutation = trpc.approvals.updateSetStatus.useMutation({
    onError: (err: any) => {
      // The set was created but stayed in Draft — surface it instead of letting
      // the success toast above mask a board that never reached the client lane.
      toast.error(err.message || 'Set created, but moving it to the client lane failed — drag it on the Approvals board.');
    },
  });

  const createMutation = trpc.approvals.createSet.useMutation({
    onSuccess: (data: any) => {
      setShareableLink(buildPublicBoardUrl(data.token));
      setSetId(Number(data.id));

      // Put the new card on the board NOW (the response is the full row), then
      // reconcile — without this the board shows nothing until a page reload.
      approvalSetsCache.registerCreated(data);
      // Sharing the link == the set is now out for client review.
      moveStatusMutation.mutate({ id: Number(data.id), status: 'client' });

      // When a client email was entered, the SERVER already emailed the invite as
      // part of create (create_set → share_set → client_invite) — reliable, no
      // second browser request to miss. Just reflect it in the UI.
      if (clientEmail.trim()) {
        setInviteSent(true);
        toast.success('Approval set created — invite emailed to the client.');
      } else {
        toast.success('Client sharing board created successfully!');
      }
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to generate approval set.');
    },
  });

  const appendMutation = trpc.approvals.appendToSet.useMutation({
    onSuccess: () => {
      toast.success(`Added ${copy.length + media.length + custom.length} item(s) to “${targetSet?.name ?? 'the set'}”.`);
      onClose();
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to add items to the approval set.');
    },
  });

  const handleAppend = useCallback(() => {
    if (!targetSet) {
      toast.error('Pick the approval set to add to.');
      return;
    }
    if (media.length === 0 && copy.length === 0 && custom.length === 0) {
      toast.error('Select at least one item to send for approval.');
      return;
    }
    // Escape emoji to ASCII entities so a WAF that strips 4-byte UTF-8 can't drop them
    // in transit; the server decodes them back on append. (Same fix as the card editor.)
    appendMutation.mutate({ id: targetSet.id, snapshot: escapeAstralDeep({ media, copy, custom }) });
  }, [targetSet, media, copy, custom, appendMutation]);

  const handleGenerateLink = useCallback(async () => {
    if (!setName.trim()) {
      toast.error('Please enter an approval set name.');
      return;
    }
    if (media.length === 0 && copy.length === 0 && custom.length === 0) {
      toast.error('Select at least one item to send for approval.');
      return;
    }

    // Create the project first when the user typed a new name, so the set is
    // saved with a real project id — or not saved at all if that fails.
    let effProjectId: number | null;
    try {
      effProjectId = await resolveProjectId(
        project,
        createProjectMutation.mutateAsync,
        setProjectDeliveryMutation.mutateAsync,
        projectOptions
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to create the project.');
      return;
    }

    const inviteEmail = clientEmail.trim();
    createMutation.mutate({
      name: setName.trim(),
      brandId: brandId || null,
      // The set's ONE mapping. Delivery + brand are derived live from this
      // project server-side (PCM_Hierarchy) — never stored on the set.
      projectId: effProjectId,
      // When provided, the server shares the set on create — moves it to the client
      // lane AND emails the invite — so the client is notified in one request.
      clientEmail: inviteEmail || null,
      clientMessage: clientMessage.trim() || null,
      // Escape emoji to ASCII entities so a WAF that strips 4-byte UTF-8 can't drop them
      // in transit; the server decodes them back on create. (Same fix as the card editor.)
      snapshot: escapeAstralDeep({
        media,
        copy,
        // Custom docs with no title inherit the set name (so there's no separate "name the doc" step).
        custom: custom.map((c) => (c.title && c.title.trim() ? c : { ...c, title: setName.trim() || undefined })),
        brandName: brandName || 'PowerCreatives',
        brandLogoUrl: brandLogoUrl || null,
      }),
    });
  }, [setName, media, copy, custom, brandId, project, brandName, brandLogoUrl, clientEmail, clientMessage, createMutation, createProjectMutation]);

  const summary = itemSummary || `${copy.length + media.length} item(s)`;

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-md" showCloseButton={!createMutation.isLoading}>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Share2 className="w-5 h-5" style={{ color: colors.primary }} />
            Share Approval Set with Client
          </DialogTitle>
          <DialogDescription>
            Package your selected {summary} into a secure shareable client mockup board.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-4">
          {!shareableLink ? (
            <div className="space-y-2">
              {/* Create a new set, or append to one still in review. */}
              <label
                style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
              >
                Destination
              </label>
              <Select
                value={mode}
                onValueChange={(v) => setMode(v as 'create' | 'append')}
                disabled={createMutation.isLoading || appendMutation.isLoading}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="create">Create a new approval set</SelectItem>
                  <SelectItem value="append">Add to an existing set</SelectItem>
                </SelectContent>
              </Select>

              {mode === 'append' ? (
                <>
                  <label
                    style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                  >
                    Approval set (still in review)
                  </label>
                  <ApprovalSetPicker
                    value={targetSet?.id ?? null}
                    onChange={setTargetSet}
                    disabled={appendMutation.isLoading}
                  />
                  <p style={{ fontSize: typography.xs, color: colors.textMuted }}>
                    Fully approved or launched sets can’t receive new items — create a new set for those.
                  </p>
                </>
              ) : (
                <>
                  <label
                    htmlFor="set-name"
                    style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                  >
                    Approval Set Name (Visible to Client)
                  </label>
                  <Input
                    id="set-name"
                    value={setName}
                    onChange={(e) => setSetName(e.target.value)}
                    placeholder="e.g., Summer Product Launch"
                    disabled={createMutation.isLoading}
                  />

                  {/* The set's ONE mapping. Search the list or type a new name to
                      create the project; delivery + brand follow from it. */}
                  <ProjectPicker
                    projects={projectOptions}
                    deliveries={deliveries}
                    value={project}
                    onChange={setProject}
                    brands={brands}
                    disabled={createMutation.isLoading || createProjectMutation.isLoading}
                  />

                  <label
                    htmlFor="client-email"
                    style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                  >
                    Client email (we’ll email the link automatically)
                  </label>
                  <Input
                    id="client-email"
                    type="email"
                    value={clientEmail}
                    onChange={(e) => setClientEmail(e.target.value)}
                    placeholder="client@company.com"
                    disabled={createMutation.isLoading}
                  />
                  <p style={{ fontSize: typography.xs, color: colors.textMuted }}>
                    Leave blank to just generate a link. Sends via your Brevo integration.
                  </p>
                </>
              )}
            </div>
          ) : (
            <ApprovalSharePanel
              setId={setId ?? 0}
              shareUrl={shareableLink}
              defaultEmail={clientEmail}
              defaultMessage={clientMessage}
              alreadySent={inviteSent}
            />
          )}
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          {!shareableLink ? (
            <>
              <Button variant="ghost" onClick={onClose} disabled={createMutation.isLoading || appendMutation.isLoading}>
                Cancel
              </Button>
              {mode === 'append' ? (
                <Button onClick={handleAppend} disabled={appendMutation.isLoading || !targetSet} className="gap-2" style={{ background: colors.primary }}>
                  {appendMutation.isLoading ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      Adding…
                    </>
                  ) : (
                    <>
                      <Share2 className="w-4 h-4" />
                      Add to Set
                    </>
                  )}
                </Button>
              ) : (
                <Button onClick={handleGenerateLink} disabled={createMutation.isLoading} className="gap-2" style={{ background: colors.primary }}>
                  {createMutation.isLoading ? (
                    <>
                      <Loader2 className="w-4 h-4 animate-spin" />
                      Generating link...
                    </>
                  ) : (
                    <>
                      <Share2 className="w-4 h-4" />
                      {clientEmail.trim() ? 'Create & Email Client' : 'Generate Share Link'}
                    </>
                  )}
                </Button>
              )}
            </>
          ) : (
            <Button onClick={onClose} variant="secondary" className="w-full">
              Done &amp; Close
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
