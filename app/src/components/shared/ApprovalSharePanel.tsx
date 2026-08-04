/**
 * ApprovalSharePanel — what you see the moment an approval set exists.
 *
 * The client link (copyable) + "email the link to your client" with an editable
 * message. Extracted VERBATIM from SendToApprovalSetDialog, which was the only
 * flow that showed it: Ads had a near-identical copy and the custom-card flow
 * had none at all — it just closed on a toast, so the link the whole feature
 * exists for was silently dropped (gap ebe6501, GAP B).
 *
 * One surface, three callers. It owns the email/message state and the share
 * mutation; the caller owns only the set it just created.
 */

import { useEffect, useState } from 'react';
import { Check, Copy, Loader2, Mail } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { colors, typography } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';
import { copyToClipboard } from '@/lib/utils';

/**
 * Default invite message for the editable "Message to client" box. Lives here
 * because this panel is the one place the message is ever sent from.
 */
export function buildDefaultInviteMessage(brandName?: string | null): string {
  return (
    `Hi there,\n\nA new set of creatives${brandName ? ` from ${brandName}` : ''} is ready for your review. ` +
    `You can approve each item, leave comments, or approve everything in one click.`
  );
}

export interface ApprovalSharePanelProps {
  /** The created set's id — the share endpoint is ownership-scoped by it. */
  setId: number;
  /** Public client-review URL (build it with buildPublicBoardUrl). */
  shareUrl: string;
  /** Pre-fills the recipient (brand's remembered client email). */
  defaultEmail?: string | null;
  /** Pre-fills the editable message. */
  defaultMessage?: string;
  /**
   * True when the SERVER already emailed the invite as part of create (an email
   * was supplied up front) — the panel then opens in its "Sent" state instead of
   * inviting a second, duplicate send.
   */
  alreadySent?: boolean;
}

export function ApprovalSharePanel({
  setId,
  shareUrl,
  defaultEmail,
  defaultMessage,
  alreadySent = false,
}: ApprovalSharePanelProps) {
  const [copied, setCopied] = useState(false);
  const [clientEmail, setClientEmail] = useState(defaultEmail || '');
  const [clientMessage, setClientMessage] = useState(defaultMessage ?? '');
  const [inviteSent, setInviteSent] = useState(alreadySent);

  // Adopt the caller's values when the panel is (re)used for a new set. Guarded
  // on setId so it can never wipe an edit the user is in the middle of typing.
  useEffect(() => {
    setCopied(false);
    setClientEmail(defaultEmail || '');
    setClientMessage(defaultMessage ?? '');
    setInviteSent(alreadySent);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setId]);

  const shareMutation = trpc.approvals.shareSet.useMutation({
    onSuccess: () => {
      setInviteSent(true);
      toast.success('Invite email sent to the client.');
    },
    onError: (err: any) => {
      toast.error(err.message || 'Failed to send invite email.');
    },
  });

  const handleCopyLink = async () => {
    if (!shareUrl) return;
    const ok = await copyToClipboard(shareUrl);
    if (ok) {
      setCopied(true);
      toast.success('Link copied to clipboard!');
      setTimeout(() => setCopied(false), 2000);
    } else {
      toast.error('Failed to copy. Please copy the link manually.');
    }
  };

  const handleSendInvite = () => {
    const email = clientEmail.trim();
    if (!setId || !email) {
      toast.error('Enter the client email to send an invite.');
      return;
    }
    shareMutation.mutate({ id: setId, email, message: clientMessage.trim() });
  };

  return (
    <div
      className="space-y-3 rounded-lg p-4"
      style={{ background: colors.bgPage, border: `1px solid ${colors.border}` }}
    >
      <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textSecondary }}>
        Generated Shareable Client Board Link
      </span>
      <div className="flex items-center gap-2">
        <Input
          value={shareUrl}
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
  );
}
