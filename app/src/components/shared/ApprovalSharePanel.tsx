/**
 * ApprovalSharePanel — what you see the moment an approval set exists.
 *
 * The client link (copy is a deliberate second click, never automatic), a
 * Google-style recipient box where every committed address becomes a removable
 * pill, the editable default invite message, and a "move to lane after sending"
 * dropdown whose options come from the CONSUMER's lane registry — never a list
 * literal in here.
 *
 * Three actions, and the difference between the last two is only how much closes:
 *
 *   Cancel         — nothing sent, nothing moved, the step closes.
 *   Send           — sends, moves the lane, closes THIS step only.
 *   Send and Close — sends, moves the lane, and asks the host dialog to close too.
 *
 * A send is never undone by closing: by the time either Send returns, the email
 * is out and the lane has already changed.
 *
 * One surface for every send path (Ads / Copy / Image / the custom card), which
 * is why it lives in the shared layer and takes its lanes as a prop.
 */

import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { Check, Copy, Loader2, Send, X } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { trpc } from '@/lib/trpc';
import { copyToClipboard } from '@/lib/utils';

import { SearchableSelect } from './SearchableSelect';
import { useApprovalSetsCache } from './approvalSets';

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

/** A lane the set can be moved to after sending. Supplied by the consumer. */
export interface ShareLaneOption {
  id: string;
  label: string;
}

/** Sentinel for "leave the set where it is". */
const NO_MOVE = '__stay__';

/** Split on comma / semicolon / whitespace so a pasted list becomes pills. */
function splitAddresses(raw: string): string[] {
  return raw
    .split(/[,;\s]+/)
    .map((s) => s.trim())
    .filter(Boolean);
}

/** Same shape the server accepts — a light client-side check, not the authority. */
function looksLikeEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

export interface ApprovalSharePanelProps {
  /** The created set's id — the share endpoint is ownership-scoped by it. */
  setId: number;
  /** Public client-review URL (build it with buildPublicBoardUrl). */
  shareUrl: string;
  /** Seeds the first recipient pill (Row 1's "Recipient Email"). */
  defaultEmail?: string | null;
  /** Pre-fills the editable message. */
  defaultMessage?: string;
  /**
   * True when the SERVER already emailed the invite as part of create (an email
   * was supplied up front) — the panel then opens in its "Sent" state instead of
   * inviting a second, duplicate send.
   */
  alreadySent?: boolean;
  /**
   * Lanes offered by "move to lane after sending". Comes from the consumer's
   * registry (Approvals passes `setColumns`), so adding a lane stays a one-line
   * change in that one file.
   */
  lanes?: ReadonlyArray<ShareLaneOption>;
  /** Which lane the dropdown starts on. Omit for "leave it where it is". */
  defaultLaneAfterSend?: string | null;
  /** Sent + moved; close this step only. */
  onSaved?: () => void;
  /** Sent + moved; close this step AND the host dialog. */
  onSaveAndClose?: () => void;
  /** Nothing sent; close this step. Omit to hide the Cancel button. */
  onCancel?: () => void;
}

export function ApprovalSharePanel({
  setId,
  shareUrl,
  defaultEmail,
  defaultMessage,
  alreadySent = false,
  lanes,
  defaultLaneAfterSend,
  onSaved,
  onSaveAndClose,
  onCancel,
}: ApprovalSharePanelProps) {
  const [copied, setCopied] = useState(false);
  const [recipients, setRecipients] = useState<string[]>([]);
  const [draft, setDraft] = useState('');
  const [clientMessage, setClientMessage] = useState(defaultMessage ?? '');
  const [laneAfterSend, setLaneAfterSend] = useState<string>(defaultLaneAfterSend ?? NO_MOVE);
  const [inviteSent, setInviteSent] = useState(alreadySent);
  const [busy, setBusy] = useState(false);
  const draftRef = useRef<HTMLInputElement>(null);

  // Adopt the caller's values when the panel is (re)used for a new set. Guarded
  // on setId so it can never wipe an edit the user is in the middle of typing.
  useEffect(() => {
    setCopied(false);
    setRecipients(defaultEmail && looksLikeEmail(defaultEmail) ? [defaultEmail] : []);
    setDraft('');
    setClientMessage(defaultMessage ?? '');
    setLaneAfterSend(defaultLaneAfterSend ?? NO_MOVE);
    setInviteSent(alreadySent);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [setId]);

  const shareMutation = trpc.approvals.shareSet.useMutation();
  const statusMutation = trpc.approvals.updateSetStatus.useMutation();
  const approvalSetsCache = useApprovalSetsCache();

  const laneOptions = useMemo(() => lanes ?? [], [lanes]);

  /** Commit whatever is typed into a pill. Returns the resulting list. */
  const commitDraft = (): string[] => {
    const parts = splitAddresses(draft);
    if (parts.length === 0) return recipients;

    const invalid = parts.filter((p) => !looksLikeEmail(p));
    if (invalid.length > 0) {
      toast.error(`Not a valid email: ${invalid.join(', ')}`);
    }
    const next = [...recipients];
    for (const p of parts) {
      if (looksLikeEmail(p) && !next.includes(p)) next.push(p);
    }
    setRecipients(next);
    setDraft('');
    return next;
  };

  const handleDraftKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
      e.preventDefault();
      commitDraft();
      return;
    }
    // Backspace on an empty box removes the last pill — standard chip behaviour.
    if (e.key === 'Backspace' && draft === '' && recipients.length > 0) {
      setRecipients(recipients.slice(0, -1));
    }
  };

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

  /**
   * Send to every pill, then move the lane. Both steps are awaited so nothing
   * closes on a failure — and the lane move runs only after the send succeeded,
   * so a set never reports "sent" when no email went out.
   */
  const send = async (thenClose: boolean) => {
    const list = commitDraft();
    if (list.length === 0) {
      toast.error('Add at least one recipient.');
      draftRef.current?.focus();
      return;
    }

    setBusy(true);
    try {
      await shareMutation.mutateAsync({
        id: setId,
        emails: list,
        message: clientMessage.trim(),
      });

      if (laneAfterSend !== NO_MOVE) {
        await statusMutation.mutateAsync({ id: setId, status: laneAfterSend });
      }

      approvalSetsCache.invalidate();
      setInviteSent(true);
      toast.success(
        list.length === 1
          ? 'Invite sent to the client.'
          : `Invite sent to ${list.length} recipients.`
      );

      if (thenClose) onSaveAndClose?.();
      else onSaved?.();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to send the invite.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-3">
      <span className="text-xs font-semibold text-muted-foreground">
        Generated Shareable Client Board Link
      </span>
      <div className="flex items-center gap-2">
        <Input
          value={shareUrl}
          readOnly
          className="font-mono text-xs select-all shrink bg-card"
        />
        <Button size="icon" onClick={handleCopyLink} className="shrink-0">
          {copied ? <Check className="w-4 h-4" /> : <Copy className="w-4 h-4" />}
        </Button>
      </div>
      <p className="mt-2 text-xs text-muted-foreground">
        Your client can open this link in any browser, see dynamic platform mockups, granularly comment, and approve each asset.
      </p>

      <div className="space-y-2 border-t border-border pt-3">
        <span className="text-xs font-semibold text-muted-foreground">
          Or email the link to your client
        </span>

        {/* Recipients — every committed address becomes a removable pill. */}
        <div
          className="flex flex-wrap items-center gap-1.5 rounded-md border border-border bg-card p-1.5"
          onClick={() => draftRef.current?.focus()}
        >
          {recipients.map((address) => (
            <span
              key={address}
              className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs text-foreground"
            >
              {address}
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  setRecipients(recipients.filter((r) => r !== address));
                  setInviteSent(false);
                }}
                aria-label={`Remove ${address}`}
                className="opacity-60 transition-opacity hover:opacity-100"
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          ))}
          <input
            ref={draftRef}
            type="email"
            value={draft}
            onChange={(e) => {
              setDraft(e.target.value);
              setInviteSent(false);
            }}
            onKeyDown={handleDraftKeyDown}
            onBlur={() => commitDraft()}
            placeholder={recipients.length === 0 ? 'client@company.com' : 'Add another…'}
            className="min-w-[160px] flex-1 border-0 bg-transparent p-0.5 text-sm outline-none"
            disabled={busy}
            aria-label="Add recipient email"
          />
        </div>

        {/* Editable invite message — prefilled with a default; the sender
            can rewrite it before sending. Blank → standard template. */}
        <span className="text-xs font-semibold text-muted-foreground">
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
          className="text-sm bg-card"
          disabled={busy}
        />

        {/* Where the set goes once this is sent. Options come from the consumer's
            lane registry — never a list literal here. */}
        {laneOptions.length > 0 && (
          <>
            <span className="text-xs font-semibold text-muted-foreground">
              Move to lane after sending
            </span>
            {/* The shared control, so the choice can be CLEARED — clearing means
                "leave the set where it is". A plain Select had no clear
                affordance, so a pre-selected lane could never be un-chosen. */}
            <SearchableSelect
              options={laneOptions.map((lane) => ({ value: lane.id, label: lane.label }))}
              value={laneAfterSend === NO_MOVE ? null : laneAfterSend}
              onChange={(next) => setLaneAfterSend(next ?? NO_MOVE)}
              placeholder="Leave where it is"
              allLabel="Leave where it is"
              searchPlaceholder="Search lanes…"
              emptyLabel="No lanes match"
              className="w-full"
              ariaLabel="Move to lane after sending"
              disabled={busy}
            />
          </>
        )}

        <p className="text-xs text-muted-foreground">
          Sends a professional invite via your Brevo integration. Requires a Brevo API key and sender email in Settings.
        </p>

        <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
          {onCancel && (
            <Button type="button" variant="ghost" onClick={onCancel} disabled={busy}>
              Cancel
            </Button>
          )}
          <Button
            type="button"
            variant="secondary"
            onClick={() => void send(false)}
            disabled={busy}
            className="gap-2"
          >
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : inviteSent ? <Check className="h-4 w-4" /> : <Send className="h-4 w-4" />}
            Send
          </Button>
          <Button
            type="button"
            onClick={() => void send(true)}
            disabled={busy}
            className="gap-2"
          >
            {busy ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
            Send and Close
          </Button>
        </div>
      </div>
    </div>
  );
}
