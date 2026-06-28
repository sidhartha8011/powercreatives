/**
 * BrevoSenderPicker — on the Brevo integration card, choose the verified sender that
 * client-invite emails are sent FROM. Only senders verified in Brevo actually deliver,
 * so we list the account's verified senders (via the stored Brevo key) and save the
 * choice to the server settings the email channel reads (automations_from_email /
 * automations_from_name). Without this set, every invite fails with
 * "No valid from-email configured."
 */
import { useEffect, useState } from 'react';
import { Loader2, Check } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

interface Sender { email: string; name: string; active: boolean }

export function BrevoSenderPicker() {
  const [current, setCurrent] = useState('');

  const sendersQuery = trpc.integrations.brevoSenders.useQuery(undefined) as any;
  const settingsQuery = trpc.settings.get.useQuery(undefined) as any;
  const senders: Sender[] = Array.isArray(sendersQuery.data) ? sendersQuery.data : [];

  useEffect(() => {
    const s = settingsQuery.data;
    if (s && typeof s === 'object') setCurrent(String(s.automations_from_email ?? ''));
  }, [settingsQuery.data]);

  const saveMutation = trpc.settings.update.useMutation({
    onSuccess: () => toast.success('Sender saved — client invites will send from this address.'),
    onError: (err: any) => toast.error(err.message || 'Failed to save sender.'),
  });

  const pick = (email: string) => {
    setCurrent(email);
    const s = senders.find((x) => x.email === email);
    saveMutation.mutate({ automations_from_email: email, automations_from_name: s?.name || 'Power Creatives' });
  };

  return (
    <div className="mt-3 max-w-md">
      <label className="text-xs font-medium text-muted-foreground">Sender for client emails (From)</label>
      {sendersQuery.isLoading ? (
        <div className="mt-1 flex items-center gap-2 text-xs text-muted-foreground">
          <Loader2 className="h-3.5 w-3.5 animate-spin" /> Loading verified senders…
        </div>
      ) : senders.length === 0 ? (
        <p className="mt-1 text-xs text-muted-foreground">
          No verified senders found in Brevo. Add &amp; verify one at{' '}
          <a href="https://app.brevo.com/senders/list" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
            Brevo → Senders
          </a>
          , then reopen this page.
        </p>
      ) : (
        <>
          <Select value={current || undefined} onValueChange={pick} disabled={saveMutation.isLoading}>
            <SelectTrigger className="mt-1 h-9">
              <SelectValue placeholder="Choose a verified sender" />
            </SelectTrigger>
            <SelectContent>
              {senders.map((s) => (
                <SelectItem key={s.email} value={s.email}>
                  {s.name ? `${s.name} <${s.email}>` : s.email}{!s.active ? ' (unverified)' : ''}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {current ? (
            <p className="mt-1 flex items-center gap-1 text-[11px] text-emerald-600">
              <Check className="h-3 w-3" /> Client invites send from {current}
            </p>
          ) : (
            <p className="mt-1 text-[11px] text-muted-foreground">Pick a sender, or invites won’t be emailed.</p>
          )}
        </>
      )}
    </div>
  );
}
