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
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { colors, typography } from '@/components/shared/design-tokens';
import { ApprovalSetPicker, type AppendableSet } from '@/components/shared/ApprovalSetPicker';
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
}: SendToApprovalSetDialogProps) {
  const [setName, setSetName] = useState('');
  const [shareableLink, setShareableLink] = useState('');
  const [copied, setCopied] = useState(false);
  const [setId, setSetId] = useState<number | null>(null);
  const [clientEmail, setClientEmail] = useState('');
  const [clientMessage, setClientMessage] = useState('');
  const [inviteSent, setInviteSent] = useState(false);
  // '' = none — Select values are strings; converted to number|null on submit.
  const [deliveryId, setDeliveryId] = useState('');
  // 'create' = new set (existing flow); 'append' = add to an open set.
  const [mode, setMode] = useState<'create' | 'append'>('create');
  const [targetSet, setTargetSet] = useState<AppendableSet | null>(null);

  // Deliveries for the linkage picker (the chosen one is stored on the set —
  // drives the Approvals board Delivery filter + webhook enrichment).
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const deliveries: { id: number; name: string; brandId: number | null }[] = Array.isArray(deliveriesRaw)
    ? deliveriesRaw.map((d: any) => ({
        id: Number(d.id),
        name: String(d.name),
        brandId: d.brandId != null ? Number(d.brandId) : null,
      }))
    : [];

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
      // Pre-pick the delivery linked to the current brand, when there is one.
      const brandDelivery = brandId
        ? deliveries.find((d) => d.brandId === Number(brandId))
        : undefined;
      setDeliveryId(brandDelivery ? String(brandDelivery.id) : '');
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
      toast.success('Client sharing board created successfully!');
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
    appendMutation.mutate({ id: targetSet.id, snapshot: { media, copy, custom } });
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

  const handleGenerateLink = useCallback(() => {
    if (!setName.trim()) {
      toast.error('Please enter an approval set name.');
      return;
    }
    if (media.length === 0 && copy.length === 0 && custom.length === 0) {
      toast.error('Select at least one item to send for approval.');
      return;
    }

    createMutation.mutate({
      name: setName.trim(),
      brandId: brandId || null,
      projectId: projectId || null,
      deliveryId: deliveryId ? Number(deliveryId) : null,
      snapshot: {
        media,
        copy,
        custom,
        brandName: brandName || 'PowerCreatives',
        brandLogoUrl: brandLogoUrl || null,
      },
    });
  }, [setName, media, copy, custom, brandId, projectId, deliveryId, brandName, brandLogoUrl, createMutation]);

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

                  <label
                    style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}
                  >
                    Delivery (optional)
                  </label>
                  <Select
                    value={deliveryId || 'none'}
                    onValueChange={(v) => setDeliveryId(v === 'none' ? '' : v)}
                    disabled={createMutation.isLoading}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="No delivery linked" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="none">No delivery</SelectItem>
                      {deliveries.map((d) => (
                        <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
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
                      Generate Share Link
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
