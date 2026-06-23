/**
 * SITES MODULE — connection manager for remote WordPress sites.
 *
 * Every connected site is a first-class `wp_pcm_sites` row authenticated with an
 * Application Password, so it's usable for publishing (Writer) and remote SEO.
 * Two ways to add one:
 *   • Admins: download the connector plugin, activate it on the target site, and
 *     paste the one-paste connection code it shows (base64 JSON of url/user/pass).
 *   • Non-admins: enter the site URL + WP username + an Application Password.
 */

import { useState, useCallback } from 'react';
import {
  Globe, Plus, Trash2, RefreshCw, ExternalLink, Loader2,
  KeyRound, Puzzle, Download,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
  DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { colors, typography, shadows } from '@/components/shared/design-tokens';
import { trpc, getConfig } from '@/lib/trpc';
import { getIsAdmin } from '@/lib/pcmConfig';

interface Site {
  id: number;
  name: string;
  url: string;
  username: string;
  appPassword: string;
  status: string;
  connectMethod?: string;
  lastSyncAt?: string;
  createdAt: string;
}

type AddStep = null | 'choose' | 'password';

export function SitesModule() {
  const isAdmin = getIsAdmin();

  // ── Data ──
  const { data: sitesRaw, isLoading, refetch } = trpc.sites.list.useQuery() as any;
  const sites: Site[] = Array.isArray(sitesRaw) ? sitesRaw : [];

  // ── Dialog + form state ──
  const [addStep, setAddStep] = useState<AddStep>(null);
  const [testingId, setTestingId] = useState<number | null>(null);

  const [formName, setFormName] = useState('');
  const [formUrl, setFormUrl] = useState('');
  const [formUsername, setFormUsername] = useState('');
  const [formPassword, setFormPassword] = useState('');
  const [pasteCode, setPasteCode] = useState('');

  // ── Mutations ──
  const closeAll = useCallback(() => {
    setAddStep(null);
    setFormName(''); setFormUrl(''); setFormUsername(''); setFormPassword('');
    setPasteCode('');
  }, []);

  const createMutation = trpc.sites.create.useMutation({
    onSuccess: () => { toast.success('Site added'); closeAll(); refetch(); },
    onError: (e: any) => toast.error(e.message ?? 'Failed to add site'),
  }) as any;
  const deleteMutation = trpc.sites.delete.useMutation({
    onSuccess: () => { toast.success('Site removed'); refetch(); },
    onError: (e: any) => toast.error(e.message ?? 'Failed to delete site'),
  }) as any;
  const testMutation = trpc.sites.test.useMutation({
    onSuccess: (r: any) => { if (r?.success) toast.success(`Connected to ${r.siteName}`); },
    onError: (e: any) => toast.error(e.message ?? 'Connection test failed'),
    onSettled: () => setTestingId(null),
  }) as any;

  // ── Handlers ──
  const openAdd = useCallback(() => setAddStep(isAdmin ? 'choose' : 'password'), [isAdmin]);

  const handleCreate = useCallback(() => {
    if (!formName || !formUrl || !formUsername || !formPassword) { toast.error('All fields are required'); return; }
    createMutation.mutate({ name: formName, url: formUrl, username: formUsername, appPassword: formPassword });
  }, [formName, formUrl, formUsername, formPassword, createMutation]);

  // Pairing code = base64(JSON{url,user,pass}) shown by the connector plugin. Decode it
  // and connect via the reliable App-Password path (no handshake).
  const handlePasteCode = useCallback(() => {
    try {
      const j = JSON.parse(atob(pasteCode.trim()));
      const url = String(j.url || '').replace(/\/+$/, '');
      const user = String(j.user || '');
      const pass = String(j.pass || '');
      if (!url || !user || !pass) throw new Error('incomplete');
      createMutation.mutate({ name: url.replace(/^https?:\/\//, ''), url, username: user, appPassword: pass });
    } catch {
      toast.error('Invalid connection code — copy it again from the connector plugin’s page.');
    }
  }, [pasteCode, createMutation]);

  // Generic (tenant-free) connector for the one-paste flow.
  const downloadGenericConnector = useCallback(async () => {
    try {
      const cfg = getConfig();
      const res = await fetch(`${cfg.restUrl}seohub/connector-download`, { headers: { 'X-WP-Nonce': cfg.nonce } });
      if (!res.ok) throw new Error('Download failed');
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url; a.download = 'pcm-connector.zip'; a.click();
      URL.revokeObjectURL(url);
    } catch (e) { toast.error(e instanceof Error ? e.message : 'Download failed'); }
  }, []);

  if (isLoading) {
    return <div className="flex items-center justify-center h-full"><Spinner className="w-6 h-6" /></div>;
  }

  return (
    <div className="h-full flex flex-col overflow-auto">
      {/* Header */}
      <div className="flex items-center gap-2 mb-1 shrink-0">
        <Globe className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>Sites</h1>
        <Badge variant="secondary" className="ml-1">{sites.length} connected</Badge>
        <div className="flex-1" />
        <Button onClick={openAdd}><Plus className="w-4 h-4" /> Add Site</Button>
      </div>
      <p className="text-xs text-muted-foreground mb-4 max-w-3xl">
        Connect your other WordPress sites to publish to them.{' '}
        {isAdmin
          ? 'Install our connector plugin and paste the code it shows, or add one with an Application Password.'
          : 'Add one with its URL and an Application Password.'}
      </p>

      {/* Unified list */}
      {sites.length === 0 ? (
        <div className="flex flex-col items-center justify-center flex-1 rounded-lg" style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}>
          <Globe className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
          <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>No sites connected</p>
          <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>Add a WordPress site to start publishing content</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {sites.map((site) => {
            const isConnector = site.connectMethod === 'connector';
            return (
              <div key={site.id} className="rounded-lg p-4 flex flex-col gap-3" style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface, boxShadow: shadows.card }}>
                <div className="flex items-start gap-3">
                  <div className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style={{ background: colors.primaryLight }}>
                    <Globe className="w-4 h-4" style={{ color: colors.primary }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <h3 className="truncate" style={{ fontSize: typography.body, fontWeight: typography.semibold, color: colors.text }}>{site.name}</h3>
                    <a href={site.url} target="_blank" rel="noopener noreferrer" className="flex items-center gap-1 truncate" style={{ fontSize: typography.xs, color: colors.primary, textDecoration: 'none' }}>
                      {site.url}<ExternalLink className="w-3 h-3 shrink-0" />
                    </a>
                  </div>
                  <Badge variant="outline" className="gap-1 shrink-0 text-[10px]">
                    {isConnector ? <><Puzzle className="w-3 h-3" /> Plugin</> : <><KeyRound className="w-3 h-3" /> Password</>}
                  </Badge>
                </div>
                <div style={{ fontSize: typography.xs, color: colors.textMuted }}>
                  <span>User: {site.username}</span>
                  {site.status !== 'active' && <span> · <span className="capitalize" style={{ color: colors.danger ?? '#dc2626' }}>{site.status}</span></span>}
                </div>
                <div className="flex items-center gap-2 pt-1" style={{ borderTop: `1px solid ${colors.borderLight}` }}>
                  <Button variant="outline" size="sm" className="flex-1" disabled={testingId === site.id} onClick={() => { setTestingId(site.id); testMutation.mutate({ id: site.id }); }}>
                    {testingId === site.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />} Test
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => deleteMutation.mutate({ id: site.id })} className="text-muted-foreground hover:text-destructive">
                    <Trash2 className="w-3.5 h-3.5" />
                  </Button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* ── Add Site: connector + one-paste code (admin) ── */}
      <Dialog open={addStep === 'choose'} onOpenChange={(o) => !o && closeAll()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Connect a site</DialogTitle>
            <DialogDescription>Install our connector plugin on the site, then paste the code it shows.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2 text-sm">
            <ol className="list-decimal pl-5 space-y-1.5 text-xs text-muted-foreground">
              <li>
                <button type="button" onClick={downloadGenericConnector} className="inline-flex items-center gap-1 text-primary hover:underline">
                  <Download className="w-3.5 h-3.5" /> Download the connector plugin
                </button>{' '}— then on the site: Plugins → Add New → Upload → Activate.
              </li>
              <li>Open the new <strong className="text-foreground">“Power Creatives”</strong> menu on that site, then <strong className="text-foreground">Copy code</strong>.</li>
              <li>Paste the code below and click Connect.</li>
            </ol>
            <textarea
              value={pasteCode}
              onChange={(e) => setPasteCode(e.target.value)}
              rows={4}
              placeholder="Paste the connection code here…"
              className="w-full rounded-md border border-border bg-card p-2 font-mono text-xs outline-none focus:border-primary"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={closeAll}>Cancel</Button>
            <Button onClick={handlePasteCode} disabled={createMutation.isPending || !pasteCode.trim()}>
              {createMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />} Connect
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Add Site: manual (Application Password) ── */}
      <Dialog open={addStep === 'password'} onOpenChange={(o) => !o && closeAll()}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Connect with an Application Password</DialogTitle>
            <DialogDescription>Create an Application Password on the target site under Users → Profile → Application Passwords.</DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <LabeledInput label="Site Name" placeholder="My Blog" value={formName} onChange={setFormName} />
            <LabeledInput label="Site URL" placeholder="https://example.com" value={formUrl} onChange={setFormUrl} />
            <LabeledInput label="WordPress Username" placeholder="admin" value={formUsername} onChange={setFormUsername} />
            <LabeledInput label="Application Password" type="password" placeholder="xxxx xxxx xxxx xxxx" value={formPassword} onChange={setFormPassword} />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={closeAll}>Cancel</Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              {createMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />} Add Site
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/** Small labeled input to keep the connection dialogs tidy. */
function LabeledInput({ label, value, onChange, placeholder, type }: { label: string; value: string; onChange: (v: string) => void; placeholder?: string; type?: string }) {
  return (
    <div>
      <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>{label}</label>
      <Input type={type} placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} />
    </div>
  );
}
