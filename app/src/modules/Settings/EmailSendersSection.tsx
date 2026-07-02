/**
 * Email-sender rows for Settings → Module Defaults.
 *
 * Each email-capable module card embeds a <ModuleEmailSenderRow moduleId=…/> —
 * a picker choosing which verified Brevo sender THAT module's emails are sent
 * from (client invites, rule emails, …). Modules without a pick fall back to
 * the default sender (<DefaultEmailSenderRow/>, its own card). Options come
 * from the account's verified Brevo senders (only those deliver).
 *
 * Saves: module_email_senders ({ module → { email, name } }) and
 * automations_from_email / automations_from_name (the default). The Brevo
 * channel resolves rule override → module sender → default at send time.
 */
import { Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Label } from '@/components/ui/label';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

interface Sender { email: string; name: string; active: boolean }

const GLOBAL = '__global__'; // sentinel: "use the default sender" (no module entry)

/** Shared data + save plumbing. Queries are deduped across rows by react-query. */
function useEmailSenders() {
  const utils = trpc.useUtils();
  const sendersQuery = trpc.integrations.brevoSenders.useQuery(undefined) as any;
  const settingsQuery = trpc.settings.get.useQuery(undefined) as any;
  const saveMutation = trpc.settings.update.useMutation({
    onSuccess: () => {
      toast.success('Email sender saved.');
      (utils as any).settings?.get?.invalidate?.();
    },
    onError: (err: any) => toast.error(err.message || 'Failed to save sender.'),
  });
  return {
    senders: (Array.isArray(sendersQuery.data) ? sendersQuery.data : []) as Sender[],
    settings: (settingsQuery.data && typeof settingsQuery.data === 'object' ? settingsQuery.data : {}) as Record<string, any>,
    loading: sendersQuery.isLoading || settingsQuery.isLoading,
    save: (patch: Record<string, unknown>) => saveMutation.mutate(patch),
    saving: saveMutation.isPending as boolean,
  };
}

function SenderItems({ senders }: { senders: Sender[] }) {
  return (
    <>
      {senders.map((s) => (
        <SelectItem key={s.email} value={s.email}>
          {s.name ? `${s.name} <${s.email}>` : s.email}
        </SelectItem>
      ))}
    </>
  );
}

function NoSenders() {
  return (
    <p className="text-xs text-muted-foreground">
      No verified senders in Brevo — add one at{' '}
      <a href="https://app.brevo.com/senders/list" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">
        Brevo → Senders
      </a>
      .
    </p>
  );
}

/** One module's sender picker — a row matching the Module Defaults card style. */
export function ModuleEmailSenderRow({ moduleId }: { moduleId: string }) {
  const { senders, settings, loading, save, saving } = useEmailSenders();

  if (loading) {
    return (
      <div className="flex items-center gap-2 pl-10 text-xs text-muted-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" /> Loading senders…
      </div>
    );
  }

  const map: Record<string, { email: string; name: string }> =
    settings.module_email_senders && typeof settings.module_email_senders === 'object' && !Array.isArray(settings.module_email_senders)
      ? settings.module_email_senders
      : {};

  const pick = (email: string) => {
    const next = { ...map };
    if (email === GLOBAL) {
      delete next[moduleId]; // no entry = fall back to the default sender
    } else {
      const s = senders.find((x) => x.email === email);
      next[moduleId] = { email, name: s?.name || '' };
    }
    save({ module_email_senders: next });
  };

  return (
    <div className="flex items-center justify-between pl-10 mt-4">
      <div>
        <Label className="text-sm">Email sender</Label>
        <p className="text-xs text-muted-foreground">Verified Brevo sender for this module's emails</p>
      </div>
      {senders.length === 0 ? (
        <NoSenders />
      ) : (
        <Select value={map[moduleId]?.email || GLOBAL} onValueChange={pick} disabled={saving}>
          <SelectTrigger className="h-9 w-[260px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={GLOBAL}>Use default sender</SelectItem>
            <SenderItems senders={senders} />
          </SelectContent>
        </Select>
      )}
    </div>
  );
}

/** The global default sender — used by every module without its own pick. */
export function DefaultEmailSenderRow() {
  const { senders, settings, loading, save, saving } = useEmailSenders();

  if (loading) {
    return (
      <div className="flex items-center gap-2 pl-10 text-xs text-muted-foreground">
        <Loader2 className="h-3.5 w-3.5 animate-spin" /> Loading senders…
      </div>
    );
  }

  const current = String(settings.automations_from_email ?? '');
  const pick = (email: string) => {
    const s = senders.find((x) => x.email === email);
    save({ automations_from_email: email, automations_from_name: s?.name || 'Power Creatives' });
  };

  return (
    <div className="flex items-center justify-between pl-10">
      <div>
        <Label className="text-sm">Default sender</Label>
        <p className="text-xs text-muted-foreground">Used by any module without its own email sender</p>
      </div>
      {senders.length === 0 ? (
        <NoSenders />
      ) : (
        <Select value={current || undefined} onValueChange={pick} disabled={saving}>
          <SelectTrigger className="h-9 w-[260px]">
            <SelectValue placeholder="Choose a verified sender" />
          </SelectTrigger>
          <SelectContent>
            <SenderItems senders={senders} />
          </SelectContent>
        </Select>
      )}
    </div>
  );
}
