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

import { useState, useCallback, useMemo, type ChangeEvent } from 'react';
import {
  Globe, Plus, Trash2, RefreshCw, ExternalLink, Loader2, ShieldCheck,
  KeyRound, Puzzle, Download, Search, X,
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
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { useListState, textFilter, searchableSelect, type FilterState } from '@/components/shared/Kanban';
import { colors, typography } from '@/components/shared/design-tokens';
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

/** Sentinel for the Status select's "all" option (Radix forbids an empty value). */
const ALL_STATUS = '__all__';

/** Instant search + status select — reuses the shared Kanban filter engine
 *  (the same primitives the Approvals board uses). */
const siteFilters = [
  textFilter<Site>('search', 'Search', (s) => [s.name, s.url, s.username].join(' '), { placeholder: 'Search sites…' }),
  searchableSelect<Site>('status', 'Status', (s) => s.status || null),
];

function readSearch(state: FilterState): string {
  const v = state['search'];
  return v?.kind === 'text' ? v.query : '';
}
function readStatus(state: FilterState): string {
  const v = state['status'];
  return v?.kind === 'searchableSelect' && v.selected.length > 0 ? v.selected[0] : ALL_STATUS;
}

export function SitesModule() {
  const isAdmin = getIsAdmin();

  // ── Data ──
  const { data: sitesRaw, isLoading, refetch } = trpc.sites.list.useQuery() as any;
  const sites: Site[] = Array.isArray(sitesRaw) ? sitesRaw : [];

  // ── Dialog + form state ──
  const [addStep, setAddStep] = useState<AddStep>(null);
  const [testingId, setTestingId] = useState<number | null>(null);
  // "Verify in GSC" dialog: the site being verified + the editable indexed-domain + the preview data.
  const [gscSite, setGscSite] = useState<Site | null>(null);
  const [gscTargetUrl, setGscTargetUrl] = useState('');
  const [gscPreview, setGscPreview] = useState<any>(null);

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

  // Surface the automatic Search Console provisioning outcome (runs on add + on "GSC" retry).
  // hasData === false means the property is connected but Google has no stats for it yet (new
  // properties take a few days) — say so explicitly, or the user sees "✓" then an empty table.
  const reportGsc = (gsc: any) => {
    if (!gsc) return;
    const noDataYet = gsc.hasData === false ? ' Site is added — no data yet (new properties can take a few days to show stats).' : '';
    if (gsc.alreadyExists) {
      toast.success(`Already in Search Console — connected to ${gsc.property} ✓ (no duplicate created).${noDataYet}`);
    } else if (gsc.verified) {
      toast.success(`Verified in Google Search Console ✓.${noDataYet}`);
    } else if (gsc.error) {
      (gsc.attempted ? toast.warning : toast.info)(`Search Console: ${gsc.error}`);
    }
  };
  const closeGsc = useCallback(() => { setGscSite(null); setGscPreview(null); setGscTargetUrl(''); }, []);
  const createMutation = trpc.sites.create.useMutation({
    onSuccess: (r: any) => { toast.success('Site added'); reportGsc(r?.gsc); closeAll(); refetch(); },
    onError: (e: any) => toast.error(e.message ?? 'Failed to add site'),
  }) as any;
  // Step 1: pull the GSC properties + auto-detect the indexed domain, then open the dialog.
  const gscPreviewMutation = trpc.sites.gscPreview.useMutation({
    onSuccess: (r: any) => { setGscPreview(r); if (r?.suggested) setGscTargetUrl(r.suggested); },
    onError: () => setGscPreview({ error: 'Could not reach Search Console — you can still enter the domain manually.' }),
  }) as any;
  const gscVerifyMutation = trpc.sites.gscVerify.useMutation({
    onSuccess: (r: any) => { reportGsc(r); closeGsc(); refetch(); },
    onError: (e: any) => toast.error(e.message ?? 'Search Console verification failed'),
  }) as any;
  const openGsc = useCallback((site: Site) => {
    setGscSite(site); setGscTargetUrl(site.url); setGscPreview(null);
    gscPreviewMutation.mutate({ id: site.id });
  }, [gscPreviewMutation]);
  const updateConnectorsMutation = trpc.sites.updateConnectors.useMutation({
    onSuccess: (r: any) => {
      const rows: any[] = Array.isArray(r?.results) ? r.results : [];
      const updated = rows.filter((x) => x.status === 'updated').length;
      const current = rows.filter((x) => x.status === 'up-to-date').length;
      const failed = rows.filter((x) => x.status === 'error');
      if (updated) toast.success(`Connectors updated on ${updated} site${updated === 1 ? '' : 's'}${current ? `, ${current} already current` : ''}.`);
      else if (current) toast.success(`All ${current} connector${current === 1 ? ' is' : 's are'} already up to date.`);
      failed.forEach((x) => toast.warning(`${x.name}: ${x.message}`));
      refetch();
    },
    onError: (e: any) => toast.error(e.message ?? 'Could not push connector updates'),
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

  // ── Instant filter (shared Kanban engine); sorting handled by <DataTable> ──
  const list = useListState<Site>(sites, siteFilters, [], {});

  // Column config for the global <DataTable>.
  const columns = useMemo<DataTableColumn<Site>[]>(() => [
    {
      key: 'name', header: 'Name', width: '20%', sortAccessor: (s) => s.name.toLowerCase(),
      cell: (site) => (
        <div className="flex items-center gap-2 min-w-0">
          <Globe className="w-4 h-4 shrink-0" style={{ color: colors.primary }} />
          <span className="truncate font-medium" style={{ color: colors.text }}>{site.name}</span>
        </div>
      ),
    },
    {
      key: 'url', header: 'URL', width: '24%', sortAccessor: (s) => s.url.toLowerCase(),
      cell: (site) => (
        <a href={site.url} target="_blank" rel="noopener noreferrer" className="inline-flex max-w-[260px] items-center gap-1 text-primary hover:underline">
          <span className="truncate">{site.url}</span><ExternalLink className="w-3 h-3 shrink-0" />
        </a>
      ),
    },
    {
      key: 'username', header: 'User', width: '13%', sortAccessor: (s) => s.username.toLowerCase(),
      className: 'text-muted-foreground', cell: (site) => site.username,
    },
    {
      key: 'method', header: 'Method', width: '13%',
      sortAccessor: (s) => (s.connectMethod === 'connector' ? 'plugin' : 'password'),
      cell: (site) => (
        <Badge variant="outline" className="gap-1 text-[10px]">
          {site.connectMethod === 'connector' ? <><Puzzle className="w-3 h-3" /> Plugin</> : <><KeyRound className="w-3 h-3" /> Password</>}
        </Badge>
      ),
    },
    {
      key: 'status', header: 'Status', width: '10%', sortAccessor: (s) => s.status,
      cell: (site) => (
        <span className={`capitalize ${site.status === 'active' ? 'text-muted-foreground' : 'font-medium text-destructive'}`}>{site.status}</span>
      ),
    },
    {
      key: 'createdAt', header: 'Added', width: '10%', sortAccessor: (s) => new Date(s.createdAt).getTime(),
      className: 'text-muted-foreground',
      cell: (site) => (site.createdAt ? new Date(site.createdAt).toLocaleDateString() : '—'),
    },
    {
      key: 'actions', header: 'Actions', width: '10%', className: 'text-center',
      cell: (site) => (
        <div className="flex items-center justify-center gap-1">
          <Button variant="outline" size="sm" className="h-7 gap-1 text-xs" disabled={testingId === site.id} onClick={() => { setTestingId(site.id); testMutation.mutate({ id: site.id }); }}>
            {testingId === site.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />} Test
          </Button>
          {/* Open the "Verify in GSC" dialog: confirm the indexed domain, reuse existing property. */}
          <Button variant="outline" size="sm" className="h-7 gap-1 text-xs" title="Register + verify this site in Google Search Console" disabled={gscPreviewMutation.isPending && gscSite?.id === site.id} onClick={() => openGsc(site)}>
            {gscPreviewMutation.isPending && gscSite?.id === site.id ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <ShieldCheck className="w-3.5 h-3.5" />} GSC
          </Button>
          <Button variant="ghost" size="sm" className="h-7 text-muted-foreground hover:text-destructive" onClick={() => deleteMutation.mutate({ id: site.id })}>
            <Trash2 className="w-3.5 h-3.5" />
          </Button>
        </div>
      ),
    },
  ], [colors, testingId, testMutation, deleteMutation, openGsc, gscPreviewMutation.isPending, gscSite]);
  const search = readSearch(list.filterState);
  const statusValue = readStatus(list.filterState);
  const statusOptions = useMemo(
    () => Array.from(new Set(sites.map((s) => s.status).filter(Boolean))),
    [sites],
  );
  const onSearch = useCallback((e: ChangeEvent<HTMLInputElement>) => {
    const next = e.target.value;
    list.setFilterValue('search', next.trim() ? { kind: 'text', query: next } : undefined);
  }, [list]);
  const onStatus = useCallback((value: string) => {
    list.setFilterValue('status', value === ALL_STATUS ? undefined : { kind: 'searchableSelect', selected: [value] });
  }, [list]);

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
        {isAdmin && (
          <Button
            variant="outline"
            onClick={downloadGenericConnector}
            title="Download the latest connector plugin. To UPDATE an already-connected site, install this over the old one: on the site → Plugins → Add New → Upload Plugin → Replace current with uploaded → Activate. (Connectors v2.4.0+ then self-update automatically.)"
          >
            <Download className="w-4 h-4" /> Download connector
          </Button>
        )}
        {isAdmin && (
          <Button
            variant="outline"
            onClick={() => updateConnectorsMutation.mutate({})}
            disabled={updateConnectorsMutation.isPending}
            title="Force every connected site's connector to self-update to the latest version now (v2.4.0+ connectors only)."
          >
            {updateConnectorsMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />} Update connectors
          </Button>
        )}
        <Button onClick={openAdd}><Plus className="w-4 h-4" /> Add Site</Button>
      </div>
      <p className="text-xs text-muted-foreground mb-4 max-w-3xl">
        Connect your other WordPress sites to publish to them.{' '}
        {isAdmin
          ? 'Install our connector plugin and paste the code it shows, or add one with an Application Password. '
            + 'Already connected? Page-builder link editing needs connector v2.0.0+. The connector is a SEPARATE plugin that lives ON the connected site — reinstalling Power Creatives here will NOT update it. Use “Download connector”, then install the zip ON that site (its wp-admin → Plugins → Add New → Upload Plugin → “Replace current with uploaded” → Activate); its version should then read 2.0.0 under Plugins.'
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
        <>
          {/* Filter bar — instant search + status (shared Kanban filter engine) */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <div className="relative">
              <Search className="absolute left-2 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground" />
              <Input value={search} onChange={onSearch} placeholder="Search sites…" className="h-9 w-[220px] pl-8 text-xs" />
            </div>
            <Select value={statusValue} onValueChange={onStatus}>
              <SelectTrigger className="h-9 w-[150px] text-xs"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value={ALL_STATUS} className="text-xs">All statuses</SelectItem>
                {statusOptions.map((s) => <SelectItem key={s} value={s} className="text-xs capitalize">{s}</SelectItem>)}
              </SelectContent>
            </Select>
            {list.activeFilterCount > 0 && (
              <Button variant="ghost" size="sm" onClick={list.clearAll} className="h-9 gap-1 text-xs">
                <X className="w-3.5 h-3.5" /> Clear
              </Button>
            )}
            <span className="ml-auto text-xs text-muted-foreground">{list.filteredItems.length} of {sites.length}</span>
          </div>

          {/* Sortable table via the shared global <DataTable>. */}
          <DataTable
            columns={columns}
            data={list.filteredItems}
            rowKey={(s) => s.id}
            defaultSortKey="name"
            emptyMessage="No sites match your filters."
          />
        </>
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

      {/* Step 0: confirm the indexed domain before we register/verify in Search Console. */}
      <Dialog open={!!gscSite} onOpenChange={(o) => { if (!o) closeGsc(); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Verify in Google Search Console</DialogTitle>
            <DialogDescription>
              Confirm the domain Google actually indexed. If it’s wrong, change it to the indexed variant.
              Tip: search the domain on Google and hover a result to see which variant (www or non-www) it serves.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            {gscPreviewMutation.isPending ? (
              <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="w-4 h-4 animate-spin" /> Checking Search Console…
              </div>
            ) : (
              <>
                {Array.isArray(gscPreview?.existing) && gscPreview.existing.length > 0 && (
                  <div className="rounded-md border px-3 py-2 text-xs" style={{ borderColor: '#16a34a', color: '#15803d', background: '#f0fdf4' }}>
                    Already in Search Console: <strong>{gscPreview.existing.join(', ')}</strong>. Verifying connects to it — no duplicate is created.
                  </div>
                )}
                {gscPreview?.canonical && (
                  <div className="text-xs text-muted-foreground">
                    Detected indexed domain (auto): <strong>{gscPreview.canonical}</strong>
                  </div>
                )}
                <LabeledInput label="Indexed domain" placeholder="https://www.example.com" value={gscTargetUrl} onChange={setGscTargetUrl} className="bg-white" />
                {gscPreview?.error && <div className="text-xs" style={{ color: '#dc2626' }}>{gscPreview.error}</div>}
              </>
            )}
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={closeGsc}>Cancel</Button>
            <Button
              onClick={() => gscSite && gscVerifyMutation.mutate({ id: gscSite.id, targetUrl: gscTargetUrl })}
              disabled={gscVerifyMutation.isPending || gscPreviewMutation.isPending || !gscTargetUrl.trim()}
            >
              {gscVerifyMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : <ShieldCheck className="w-4 h-4" />} Verify in GSC
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/** Small labeled input to keep the connection dialogs tidy. */
function LabeledInput({ label, value, onChange, placeholder, type, className }: { label: string; value: string; onChange: (v: string) => void; placeholder?: string; type?: string; className?: string }) {
  return (
    <div>
      <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>{label}</label>
      <Input type={type} placeholder={placeholder} value={value} onChange={(e) => onChange(e.target.value)} className={className} />
    </div>
  );
}
