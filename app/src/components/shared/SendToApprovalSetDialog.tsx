import { useState, useCallback, useEffect } from 'react';
import { Share2, Copy, Check, Loader2, Mail } from 'lucide-react';
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
import { Textarea } from '@/components/ui/textarea';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { colors, typography } from '@/components/shared/design-tokens';
import { ApprovalSetPicker, type AppendableSet } from '@/components/shared/ApprovalSetPicker';
import {
  ProjectPicker,
  resolveProjectId,
  EMPTY_PROJECT_PICK,
  type ProjectPickerValue,
} from '@/components/shared/ProjectPicker';
import { trpc } from '@/lib/trpc';
import { copyToClipboard } from '@/lib/utils';

/**
 * Default invite message for the editable "Message to client" box. Exported so
 * the Ads dialog (CreateApprovalSetDialog) shows the identical default.
 */
export function buildDefaultInviteMessage(brandName?: string | null): string {
  return (
    `Hi there,\n\nA new set of creatives${brandName ? ` from ${brandName}` : ''} is ready for your review. ` +
    `You can approve each item, leave comments, or approve everything in one click.`
  );
}

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
  const [copied, setCopied] = useState(false);
  const [setId, setSetId] = useState<number | null>(null);
  const [clientEmail, setClientEmail] = useState('');
  const [clientMessage, setClientMessage] = useState('');
  const [inviteSent, setInviteSent] = useState(false);
  // The set's ONE mapping: a project (existing, or created on submit).
  const [project, setProject] = useState<ProjectPickerValue>(EMPTY_PROJECT_PICK);
  // 'create' = new set (existing flow); 'append' = add to an open set.
  const [mode, setMode] = useState<'create' | 'append'>('create');
  const [targetSet, setTargetSet] = useState<AppendableSet | null>(null);

  // Projects the set can belong to; deliveries only feed the "new project" row.
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  const projectOptions: { id: number; name: string }[] = Array.isArray(projectsRaw)
    ? (projectsRaw as any[]).map((p) => ({ id: Number(p.id), name: String(p.name) }))
    : [];

  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const deliveries: { id: number; name: string }[] = Array.isArray(deliveriesRaw)
    ? (deliveriesRaw as any[]).map((d) => ({ id: Number(d.id), name: String(d.name) }))
    : [];

  const createProjectMutation = trpc.assets.createProject.useMutation();

  // Reset the dialog to its initial state ONLY when it opens. Depending on the
  // default-value props here would re-run the effect if async brand context
  // resolves while the dialog is open, silently wiping the user's in-progress
  // name/email/message edits.
  useEffect(() => {
    if (isOpen) {
      setSetName(defaultName);
      setShareableLink('');
      setCopied(false);
      setSetId(null);
      setClientEmail(brandClientEmail || '');
      setClientMessage(buildDefaultInviteMessage(brandName));
      setInviteSent(false);
      setMode('create');
      setTargetSet(null);
      setProject(
        projectId != null
          ? { projectId, newProjectName: null, newProjectDeliveryId: null }
          : EMPTY_PROJECT_PICK
      );
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
      const config = window.pcmConfig ?? { shortcodePageUrl: window.location.origin + '/' };
      const baseUrl = config.shortcodePageUrl || (window.location.origin + '/');
      const separator = baseUrl.includes('?') ? '&' : '?';
      const publicLink = `${baseUrl}${separator}pcm_public_token=${data.token}`;

      setShareableLink(publicLink);
      setSetId(Number(data.id));
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

  const shareMutation = trpc.approvals.shareSet.useMutation({
    onSuccess: () => {
      setInviteSent(true);
      toast.success('Invite email sent to the client.');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to send invite email.');
    },
  });

  const handleSendInvite = useCallback(() => {
    const email = clientEmail.trim();
    if (!setId || !email) {
      toast.error('Enter the client email to send an invite.');
      return;
    }
    shareMutation.mutate({ id: setId, email, message: clientMessage.trim() });
  }, [setId, clientEmail, clientMessage, shareMutation]);

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
      effProjectId = await resolveProjectId(project, createProjectMutation.mutateAsync);
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

  const handleCopyLink = useCallback(async () => {
    if (!shareableLink) return;
    const ok = await copyToClipboard(shareableLink);
    if (ok) {
      setCopied(true);
      toast.success('Link copied to clipboard!');
      setTimeout(() => setCopied(false), 2000);
    } else {
      toast.error('Failed to copy. Please copy the link manually.');
    }
  }, [shareableLink]);

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
                    onChange={(next) =>
                      setProject(
                        // Seed the caller's delivery the moment a NEW project is
                        // started (create-from-delivery flow); the user can change
                        // it, and clearing it back to "No delivery" sticks.
                        next.newProjectName !== null && project.newProjectName === null
                          ? { ...next, newProjectDeliveryId: defaultDeliveryId ?? null }
                          : next
                      )
                    }
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
            <div className="space-y-3 rounded-lg p-4" style={{ background: colors.bgPage, border: `1px solid ${colors.border}` }}>
              <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}>
                Generated Shareable Client Board Link
              </span>
              <div className="flex items-center gap-2">
                <Input
                  value={shareableLink}
                  readOnly
                  className="font-mono text-xs select-all shrink"
                  style={{ background: colors.bgSurface }}
                />
                <Button size="icon" onClick={handleCopyLink} className="shrink-0" style={{ background: colors.primary }}>
                  {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
                </Button>
              </div>
              <p style={{ fontSize: typography.xs, color: colors.textMuted, marginTop: '8px' }}>
                Your client can open this link in any browser, see dynamic platform mockups, granularly comment, and approve each asset.
              </p>

              <div className="space-y-2 pt-2" style={{ borderTop: `1px solid ${colors.border}` }}>
                <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}>
                  Or email the link to your client
                </span>
                <div className="flex items-center gap-2">
                  <Input
                    type="email"
                    value={clientEmail}
                    onChange={(e) => {
                      setClientEmail(e.target.value);
                      setInviteSent(false);
                    }}
                    placeholder="client@company.com"
                    className="text-sm shrink"
                    style={{ background: colors.bgSurface }}
                    disabled={shareMutation.isLoading}
                  />
                  <Button
                    onClick={handleSendInvite}
                    className="shrink-0 gap-2"
                    style={{ background: colors.primary }}
                    disabled={shareMutation.isLoading || !clientEmail.trim() || inviteSent}
                  >
                    {shareMutation.isLoading ? (
                      <Loader2 className="w-4 h-4 animate-spin" />
                    ) : inviteSent ? (
                      <Check className="w-4 h-4" />
                    ) : (
                      <Mail className="w-4 h-4" />
                    )}
                    {inviteSent ? 'Sent' : 'Send'}
                  </Button>
                </div>

                {/* Editable invite message — prefilled with a default; the sender
                    can rewrite it before sending. Blank → standard template. */}
                <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}>
                  Message to client
                </span>
                <Textarea
                  value={clientMessage}
                  onChange={(e) => {
                    setClientMessage(e.target.value);
                    setInviteSent(false);
                  }}
                  rows={4}
                  placeholder="Write a short note to your client…"
                  className="text-sm"
                  style={{ background: colors.bgSurface }}
                  disabled={shareMutation.isLoading}
                />

                <p style={{ fontSize: typography.xs, color: colors.textMuted }}>
                  Sends a professional invite via your Brevo integration. Requires a Brevo API key and sender email in Settings.
                </p>
              </div>
            </div>
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
