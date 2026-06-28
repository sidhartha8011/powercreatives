/**
 * EmailDiagnostics — a collapsible panel on the Automations page to (1) send a
 * test email through the Brevo integration and (2) view the recent automation
 * send log. Lets email delivery be diagnosed on ANY site without DB access:
 * if a share produced no log row, the request never reached the send; if it
 * shows "sent (201)" the invite left for Brevo (check spam / sender verification).
 */
import { useEffect, useState } from 'react';
import { Loader2, Mail, RefreshCw, CheckCircle2, XCircle, Save } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface LogRow {
  id: number;
  userId?: number;
  event: string;
  channel: string;
  target?: string;
  status: string;
  httpCode?: number | string;
  error?: string | null;
  createdAt: string;
}

export function EmailDiagnostics() {
  const [open, setOpen] = useState(false);
  const [to, setTo] = useState('');
  const [fromEmail, setFromEmail] = useState('');
  const [fromName, setFromName] = useState('');

  const logsQuery = trpc.automations.logs.useQuery(undefined, { enabled: open }) as any;
  const rows: LogRow[] = Array.isArray(logsQuery.data) ? logsQuery.data : [];

  // Load the current server-side sender (PCM_Settings) when the panel opens.
  const settingsQuery = trpc.settings.get.useQuery(undefined, { enabled: open }) as any;
  useEffect(() => {
    const s = settingsQuery.data;
    if (s && typeof s === 'object') {
      setFromEmail(String(s.automations_from_email ?? ''));
      setFromName(String(s.automations_from_name ?? ''));
    }
  }, [settingsQuery.data]);

  const saveSenderMutation = trpc.settings.update.useMutation({
    onSuccess: () => toast.success('Sender saved — new invites will send from this address.'),
    onError: (err: any) => toast.error(err.message || 'Failed to save sender.'),
  });
  const saveSender = () => {
    const email = fromEmail.trim();
    if (!email || !/^\S+@\S+\.\S+$/.test(email)) { toast.error('Enter a valid sender email.'); return; }
    saveSenderMutation.mutate({ automations_from_email: email, automations_from_name: fromName.trim() || 'Power Creatives' });
  };

  const testMutation = trpc.automations.test.useMutation({
    onSuccess: (res: any) => {
      if (res?.ok) {
        toast.success(`Test email accepted by Brevo (HTTP ${res.code ?? 201}). Check the inbox — and the spam folder.`);
      } else {
        toast.error(`Brevo did NOT send it: ${res?.error || 'unknown error'}${res?.code ? ` (HTTP ${res.code})` : ''}`);
      }
      logsQuery.refetch?.();
    },
    onError: (err: any) => toast.error(err.message || 'Test send failed.'),
  });

  const sendTest = () => {
    const email = to.trim();
    if (!email) { toast.error('Enter an email address to test.'); return; }
    testMutation.mutate({ channel: 'email', to: email, config: {} });
  };

  return (
    <div className="mb-6 rounded-lg border border-border bg-card">
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between px-4 py-3 text-left"
      >
        <span className="flex items-center gap-2 text-sm font-medium">
          <Mail className="h-4 w-4 text-primary" /> Email diagnostics &amp; send log
        </span>
        <span className="text-xs text-muted-foreground">{open ? 'Hide' : 'Show'}</span>
      </button>

      {open && (
        <div className="space-y-4 border-t border-border p-4">
          {/* Sender (from) — REQUIRED, or every email fails with "No valid from-email configured" */}
          <div>
            <p className="mb-2 text-xs font-semibold text-muted-foreground">
              Sender — the address invites are sent from (required; must be a verified sender in Brevo)
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Input
                type="email"
                value={fromEmail}
                onChange={(e) => setFromEmail(e.target.value)}
                placeholder="hello@yourdomain.com"
                className="max-w-xs"
              />
              <Input
                type="text"
                value={fromName}
                onChange={(e) => setFromName(e.target.value)}
                placeholder="Sender name"
                className="max-w-[180px]"
              />
              <Button onClick={saveSender} disabled={saveSenderMutation.isLoading || !fromEmail.trim()} className="gap-2">
                {saveSenderMutation.isLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                Save sender
              </Button>
            </div>
            <p className="mt-1 text-[11px] text-muted-foreground">
              If this is blank, every invite fails with <em>“No valid from-email configured.”</em> Brevo accepts (201)
              but never delivers from an unverified sender.
            </p>
          </div>

          {/* Test email */}
          <div>
            <p className="mb-2 text-xs font-semibold text-muted-foreground">
              Send a test email — verifies your Brevo API key + sender are set up on this site
            </p>
            <div className="flex items-center gap-2">
              <Input
                type="email"
                value={to}
                onChange={(e) => setTo(e.target.value)}
                placeholder="you@example.com"
                className="max-w-xs"
              />
              <Button onClick={sendTest} disabled={testMutation.isLoading || !to.trim()} className="gap-2">
                {testMutation.isLoading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Mail className="h-4 w-4" />}
                Send test
              </Button>
            </div>
          </div>

          {/* Recent send log */}
          <div>
            <div className="mb-2 flex items-center justify-between">
              <p className="text-xs font-semibold text-muted-foreground">Recent automation sends (newest first)</p>
              <Button
                variant="outline"
                size="sm"
                className="gap-1.5"
                onClick={() => logsQuery.refetch?.()}
                disabled={logsQuery.isFetching}
              >
                {logsQuery.isFetching ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
                Refresh
              </Button>
            </div>
            <div className="overflow-auto rounded-md border border-border">
              <table className="w-full text-xs">
                <thead className="bg-muted/50 text-muted-foreground">
                  <tr>
                    <th className="px-2 py-1.5 text-left font-medium">When</th>
                    <th className="px-2 py-1.5 text-left font-medium">Event</th>
                    <th className="px-2 py-1.5 text-left font-medium">Channel</th>
                    <th className="px-2 py-1.5 text-left font-medium">To</th>
                    <th className="px-2 py-1.5 text-left font-medium">Result</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-2 py-4 text-center text-muted-foreground">
                        {logsQuery.isLoading ? 'Loading…' : 'No log entries yet. Share an approval set, then Refresh.'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((r) => {
                      const ok = String(r.status) === 'sent';
                      return (
                        <tr key={r.id} className="border-t border-border">
                          <td className="whitespace-nowrap px-2 py-1.5 text-muted-foreground">{r.createdAt}</td>
                          <td className="px-2 py-1.5">{r.event}</td>
                          <td className="px-2 py-1.5">{r.channel}</td>
                          <td className="px-2 py-1.5">{r.target || '—'}</td>
                          <td className="px-2 py-1.5">
                            <span className={`inline-flex items-center gap-1 ${ok ? 'text-emerald-600' : 'text-destructive'}`}>
                              {ok ? <CheckCircle2 className="h-3 w-3" /> : <XCircle className="h-3 w-3" />}
                              {r.status}{r.httpCode ? ` (${r.httpCode})` : ''}
                            </span>
                            {r.error ? <div className="text-[10px] text-destructive">{r.error}</div> : null}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
