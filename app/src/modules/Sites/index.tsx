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

import { useState, useCallback, useMemo, useEffect, type ChangeEvent } from 'react';
import {
  Globe, Plus, Trash2, RefreshCw, ExternalLink, Loader2, ShieldCheck,
  KeyRound, Puzzle, Download, Search, X, ChevronDown, CalendarClock,
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
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuCheckboxItem, DropdownMenuLabel,
  DropdownMenuSeparator, DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

/** Minimal project shape for the Project(s) connection column. The link itself lives on
 *  the projects table (`projects.siteId`, N:1 — several projects may connect to one site);
 *  this module only renders it and calls the assets endpoint that owns it. */
interface ProjectRef {
  id: number;
  name: string;
  siteId: number | null;
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

  // Projects, for the Project(s) connection column. Ids are Number()-normalized (wpdb
  // returns strings); the connection is read from each project's siteId.
  const { data: projectsRaw, refetch: refetchProjects } = trpc.assets.getProjects.useQuery() as any;
  const projects: ProjectRef[] = useMemo(
    () => (Array.isArray(projectsRaw) ? projectsRaw : []).map((p: any) => ({
      id: Number(p.id),
      name: String(p.name ?? ''),
      siteId: p.siteId != null ? Number(p.siteId) : null,
    })),
    [projectsRaw],
  );

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

  // ── "Auto content" recurring-schedule dialog (Task I2) ──
  // The rule lives server-side (pcm_site_schedules option); the daily strategy
  // scan turns it into "suggest N topics → create a strategy" per cadence.
  const [scheduleSite, setScheduleSite] = useState<Site | null>(null);
  const [schedEnabled, setSchedEnabled] = useState(false);
  const [schedFrequency, setSchedFrequency] = useState('weekly');
  const [schedCount, setSchedCount] = useState(5);
  const [schedTemplateId, setSchedTemplateId] = useState<string>('');
  const [schedMode, setSchedMode] = useState('draft');
  const [schedNiche, setSchedNiche] = useState('');

  const { data: templatesRaw } = trpc.templates.list.useQuery(undefined, { enabled: !!scheduleSite }) as any;
  const templates: { id: number; name: string }[] = useMemo(
    () => (Array.isArray(templatesRaw) ? templatesRaw : []).map((t: any) => ({ id: Number(t.id), name: String(t.name ?? `Template ${t.id}`) })),
    [templatesRaw],
  );
  const { data: scheduleData } = trpc.sites.getSchedule.useQuery(
    { id: scheduleSite?.id ?? 0 },
    { enabled: !!scheduleSite },
  ) as any;
  // Hydrate the form from the stored rule each time the dialog opens.
  useEffect(() => {
    if (!scheduleSite) return;
    const r = scheduleData?.schedule;
    setSchedEnabled(!!r?.enabled);
    setSchedFrequency(r?.frequency ?? 'weekly');
    setSchedCount(Number(r?.count ?? 5));
    setSchedTemplateId(r?.templateId ? String(r.templateId) : '');
    setSchedMode(r?.publishingMode ?? 'draft');
    setSchedNiche(r?.niche ?? '');
  }, [scheduleSite, scheduleData]);

  const setScheduleMutation = trpc.sites.setSchedule.useMutation({
    onSuccess: () => { toast.success('Schedule saved'); setScheduleSite(null); },
    onError: (e: any) => toast.error(e.message ?? 'Failed to save the schedule'),
  }) as any;
  const handleSaveSchedule = useCallback(() => {
    if (!scheduleSite) return;
    if (schedEnabled && !schedTemplateId) { toast.error('Pick a template for the generated strategies.'); return; }
    setScheduleMutation.mutate({
      id: scheduleSite.id,
      enabled: schedEnabled,
      frequency: schedFrequency,
      count: schedCount,
      templateId: schedTemplateId ? Number(schedTemplateId) : 0,
      publishingMode: schedMode,
      niche: schedNiche,
    });
  }, [scheduleSite, schedEnabled, schedFrequency, schedCount, schedTemplateId, schedMode, schedNiche, setScheduleMutation]);

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
  // Connect/disconnect a project ↔ this site. Same endpoint as the Projects and Deliveries
  // controls — the link lives only in projects.siteId, so all surfaces stay in sync.
  const setProjectSiteMutation = trpc.assets.setProjectSite.useMutation({
    onError: (e: any) => toast.error(e.message ?? 'Could not update the connection'),
  }) as any;
  const toggleProjectSite = useCallback((project: ProjectRef, site: Site, connect: boolean) => {
    setProjectSiteMutation.mutate(
      { id: project.id, siteId: connect ? Number(site.id) : null },
      {
        onSuccess: () => {
          toast.success(connect ? `“${project.name}” connected to ${site.name}` : `“${project.name}” disconnected`);
          refetchProjects();
        },
      },
    );
  }, [setProjectSiteMutation, refetchProjects]);

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
      key: 'name', header: 'Name', width: '16%', sortAccessor: (s) => s.name.toLowerCase(),
      cell: (site) => (
        <div className="flex items-center gap-2 min-w-0">
          <Globe className="w-4 h-4 shrink-0" style={{ color: colors.primary }} />
          <span className="truncate font-medium" style={{ color: colors.text }}>{site.name}</span>
        </div>
      ),
    },
    {
      key: 'url', header: 'URL', width: '18%', sortAccessor: (s) => s.url.toLowerCase(),
      cell: (site) => (
        <a href={site.url} target="_blank" rel="noopener noreferrer" className="inline-flex max-w-[260px] items-center gap-1 text-primary hover:underline">
          <span className="truncate">{site.url}</span><ExternalLink className="w-3 h-3 shrink-0" />
        </a>
      ),
    },
    {
      // Connect/disconnect projects right from the row. Checked = connected to THIS site;
      // N:1, so several projects can be checked and toggling one never touches the others.
      key: 'projects', header: 'Project(s)', width: '13%',
      // Number() both sides — wpdb returns ids as strings; a strict === on mixed types
      // would never match and the checkmarks would never render.
      sortAccessor: (s) => projects.filter((p) => p.siteId === Number(s.id)).map((p) => p.name.toLowerCase()).join(', '),
      cell: (site) => {
        const siteId = Number(site.id);
        const connected = projects.filter((p) => p.siteId === siteId);
        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" size="sm" className="h-7 max-w-full gap-1 text-xs font-normal">
                <span className="truncate">
                  {connected.length === 0 ? 'Not connected' : connected.map((p) => p.name).join(', ')}
                </span>
                <ChevronDown className="w-3 h-3 shrink-0 text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-64">
              <DropdownMenuLabel className="text-xs">Connected projects</DropdownMenuLabel>
              <DropdownMenuSeparator />
              {projects.length === 0 && (
                <div className="px-2 py-1.5 text-xs text-muted-foreground">No projects yet</div>
              )}
              {projects.map((p) => (
                <DropdownMenuCheckboxItem
                  key={p.id}
                  className="text-xs"
                  checked={p.siteId === siteId}
                  disabled={setProjectSiteMutation.isPending}
                  onCheckedChange={(checked) => toggleProjectSite(p, site, checked === true)}
                  onSelect={(e) => e.preventDefault()}
                >
                  <span className="truncate">{p.name}</span>
                  {p.siteId !== null && p.siteId !== siteId && (
                    <span className="ml-auto pl-2 text-[10px] text-muted-foreground shrink-0">connected to another site</span>
                  )}
                </DropdownMenuCheckboxItem>
              ))}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
    {
      key: 'username', header: 'User', width: '8%', sortAccessor: (s) => s.username.toLowerCase(),
      className: 'text-muted-foreground', cell: (site) => site.username,
    },
    {
      key: 'method', header: 'Method', width: '9%',
      sortAccessor: (s) => (s.connectMethod === 'connector' ? 'plugin' : 'password'),
      cell: (site) => (
        <Badge variant="outline" className="gap-1 text-[10px]">
          {site.connectMethod === 'connector' ? <><Puzzle className="w-3 h-3" /> Plugin</> : <><KeyRound className="w-3 h-3" /> Password</>}
        </Badge>
      ),
    },
    {
      // Installed connector version, read live from the site. When the site is behind
      // the hub's latest, the version turns amber and an inline update button appears
      // right beside it (both the version text and the button trigger the update).
      key: 'connector', header: 'Connector', width: '11%',
      cell: (site) => <ConnectorCell site={site} />,
    },
    {
      key: 'status', header: 'Status', width: '7%', sortAccessor: (s) => s.status,
      cell: (site) => (
        <span className={`capitalize ${site.status === 'active' ? 'text-muted-foreground' : 'font-medium text-destructive'}`}>{site.status}</span>
      ),
    },
    {
      key: 'createdAt', header: 'Added', width: '8%', sortAccessor: (s) => new Date(s.createdAt).getTime(),
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
          <Button variant="outline" size="sm" className="h-7 gap-1 text-xs" title="Recurring auto-content schedule: suggest topics and create a strategy for this site on a cadence" onClick={() => setScheduleSite(site)}>
            <CalendarClock className="w-3.5 h-3.5" /> Auto
          </Button>
          <Button variant="ghost" size="sm" className="h-7 text-muted-foreground hover:text-destructive" onClick={() => deleteMutation.mutate({ id: site.id })}>
            <Trash2 className="w-3.5 h-3.5" />
          </Button>
        </div>
      ),
    },
  ], [colors, testingId, testMutation, deleteMutation, openGsc, gscPreviewMutation.isPending, gscSite, projects, toggleProjectSite, setProjectSiteMutation.isPending]);
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
      <div className="flex items-center gap-2 mb-6 shrink-0">
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
          <div className="flex flex-wrap items-center gap-3 mb-6 bg-white p-2 rounded-lg border border-slate-200/80">
            <div className="relative flex-1 min-w-[200px] max-w-[220px]">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground" />
              <Input value={search} onChange={onSearch} placeholder="Search sites…" className="h-9 pl-9 text-xs bg-slate-50" />
            </div>
            <Select value={statusValue} onValueChange={onStatus}>
              <SelectTrigger className="h-9 w-[150px] text-xs bg-slate-50"><SelectValue placeholder="Status" /></SelectTrigger>
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
            wrapperClassName="shadow-none"
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

      {/* ── Recurring auto-content schedule (Task I2) ── */}
      <Dialog open={!!scheduleSite} onOpenChange={(o) => { if (!o) setScheduleSite(null); }}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Auto content for {scheduleSite?.name}</DialogTitle>
            <DialogDescription>
              On the chosen cadence, AI suggests fresh topics for this site and creates a content
              strategy from them automatically. Runs with the daily scheduler.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3 py-2">
            <label className="flex items-center gap-2 text-sm" style={{ color: colors.text }}>
              <input type="checkbox" checked={schedEnabled} onChange={(e) => setSchedEnabled(e.target.checked)} />
              Enable recurring content for this site
            </label>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>Frequency</label>
                <Select value={schedFrequency} onValueChange={setSchedFrequency}>
                  <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="daily" className="text-xs">Daily</SelectItem>
                    <SelectItem value="weekly" className="text-xs">Weekly</SelectItem>
                    <SelectItem value="monthly" className="text-xs">Monthly</SelectItem>
                  </SelectContent>
                </Select>
              </div>
              <LabeledInput label="Articles per run (1–10)" type="number" value={String(schedCount)} onChange={(v) => setSchedCount(Math.min(10, Math.max(1, Number(v) || 1)))} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>Template</label>
                <Select value={schedTemplateId} onValueChange={setSchedTemplateId}>
                  <SelectTrigger className="h-9 text-xs"><SelectValue placeholder="Pick a template…" /></SelectTrigger>
                  <SelectContent>
                    {templates.map((t) => <SelectItem key={t.id} value={String(t.id)} className="text-xs">{t.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>Publishing</label>
                <Select value={schedMode} onValueChange={setSchedMode}>
                  <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="draft" className="text-xs">Save as drafts</SelectItem>
                    <SelectItem value="publish" className="text-xs">Publish immediately</SelectItem>
                    <SelectItem value="schedule" className="text-xs">Spread on a schedule</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
            <LabeledInput label="Niche / topic focus (optional)" placeholder="e.g. residential solar installation" value={schedNiche} onChange={setSchedNiche} />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setScheduleSite(null)}>Cancel</Button>
            <Button onClick={handleSaveSchedule} disabled={setScheduleMutation.isPending}>
              {setScheduleMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : <CalendarClock className="w-4 h-4" />} Save schedule
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/**
 * Connector column cell: the site's INSTALLED connector version, read live from the
 * site (cached 5 min). Up to date → plain muted version. Behind the hub's latest →
 * amber version + inline update button right beside it (BOTH trigger the per-site
 * self-update). Unreadable → an honest "—" with the reason in the tooltip.
 */
function ConnectorCell({ site }: { site: Site }) {
  const versionQuery = trpc.sites.connectorVersion.useQuery(
    { id: site.id },
    { staleTime: 5 * 60_000 },
  ) as any;
  const updateMutation = trpc.sites.updateConnector.useMutation({
    onSuccess: (r: any) => {
      if (r?.status === 'up-to-date') {
        toast.success(`${site.name}: connector already up to date${r?.version ? ` (v${r.version})` : ''}.`);
      } else {
        toast.success(`${site.name}: connector updated${r?.from ? ` v${r.from} → v${r.to || r.version}` : ''}.`);
      }
      versionQuery.refetch();
    },
    onError: (e: any) => toast.error(`${site.name}: ${e?.message ?? 'Connector update failed'}`),
  }) as any;

  if (versionQuery.isLoading) {
    return <Loader2 className="w-3.5 h-3.5 animate-spin text-muted-foreground/60" />;
  }
  const d: any = versionQuery.data ?? {};
  const version = String(d.version ?? '');
  const latest = String(d.latest ?? '');
  const upToDate = !!d.upToDate;

  if (!version) {
    return (
      <span
        className="text-xs text-muted-foreground/60"
        title="Could not read the connector version — no connector installed, or the site's plugin list isn't readable by the connection user."
      >
        —
      </span>
    );
  }
  if (upToDate || !latest) {
    return (
      <span
        className="text-xs text-muted-foreground tabular-nums"
        title={latest ? `Up to date (latest is v${latest})` : `Installed: v${version} — could not read the hub's latest version`}
      >
        v{version}
      </span>
    );
  }
  const busy = updateMutation.isPending;
  const run = () => { if (!busy) updateMutation.mutate({ id: site.id }); };
  return (
    <span className="inline-flex items-center gap-1">
      <button
        type="button"
        onClick={run}
        disabled={busy}
        title={`v${version} installed — click to update to v${latest}`}
        className="text-xs font-medium tabular-nums text-amber-600 hover:underline decoration-dotted disabled:opacity-60"
      >
        v{version}
      </button>
      <button
        type="button"
        onClick={run}
        disabled={busy}
        title={`Update connector to v${latest}`}
        className="shrink-0 rounded p-0.5 text-amber-600 transition-colors hover:bg-muted hover:text-primary disabled:opacity-60"
      >
        {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <RefreshCw className="w-3.5 h-3.5" />}
      </button>
    </span>
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
