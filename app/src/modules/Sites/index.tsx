/**
 * SITES MODULE — Connected WordPress Sites Manager
 *
 * CRUD for managing remote WP sites connected via Application Passwords.
 * Allows adding, testing, editing, and removing site connections.
 *
 * Data source: trpc.sites.list / trpc.sites.create
 */

import { useState, useCallback } from 'react';
import {
  Globe, Plus, Trash2, RefreshCw,
  ExternalLink, Loader2,
} from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle,
  DialogDescription, DialogFooter, DialogTrigger,
} from '@/components/ui/dialog';
import { colors, typography, shadows } from '@/components/shared/design-tokens';
import { trpc } from '@/lib/trpc';

// ── Types ──
interface Site {
  id: number;
  name: string;
  url: string;
  username: string;
  appPassword: string; // Always masked in API responses
  status: string;
  lastSyncAt?: string;
  createdAt: string;
}

// ── Main Component ──
export function SitesModule() {
  const [dialogOpen, setDialogOpen] = useState(false);
  const [testingId, setTestingId] = useState<number | null>(null);

  // Form state
  const [formName, setFormName] = useState('');
  const [formUrl, setFormUrl] = useState('');
  const [formUsername, setFormUsername] = useState('');
  const [formPassword, setFormPassword] = useState('');

  // Data fetching via tRPC proxy
  const { data: sitesRaw, isLoading, refetch } = trpc.sites.list.useQuery() as any;
  const sites: Site[] = Array.isArray(sitesRaw) ? sitesRaw : [];

  // Mutations via tRPC proxy
  const createMutation = trpc.sites.create.useMutation({
    onSuccess: () => {
      toast.success('Site added successfully');
      setDialogOpen(false);
      resetForm();
      refetch();
    },
    onError: (err: any) => toast.error(err.message ?? 'Failed to add site'),
  }) as any;

  const deleteMutation = trpc.sites.delete.useMutation({
    onSuccess: () => { toast.success('Site removed'); refetch(); },
    onError: (err: any) => toast.error(err.message ?? 'Failed to delete site'),
  }) as any;

  const testMutation = trpc.sites.test.useMutation({
    onSuccess: (result: any) => {
      if (result?.success) {
        toast.success(`Connected to ${result.siteName} as ${result.roles?.join(', ')}`);
      }
    },
    onError: (err: any) => toast.error(err.message ?? 'Connection test failed'),
    onSettled: () => setTestingId(null),
  }) as any;

  // Reset form fields
  const resetForm = useCallback(() => {
    setFormName(''); setFormUrl(''); setFormUsername(''); setFormPassword('');
  }, []);

  // Create site
  const handleCreate = useCallback(() => {
    if (!formName || !formUrl || !formUsername || !formPassword) {
      toast.error('All fields are required');
      return;
    }
    createMutation.mutate({
      name: formName,
      url: formUrl,
      username: formUsername,
      appPassword: formPassword,
    });
  }, [formName, formUrl, formUsername, formPassword, createMutation]);

  // Test connection
  const handleTest = useCallback((siteId: number) => {
    setTestingId(siteId);
    testMutation.mutate({ id: siteId });
  }, [testMutation]);

  // Delete site
  const handleDelete = useCallback((siteId: number) => {
    deleteMutation.mutate({ id: siteId });
  }, [deleteMutation]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-full">
        <Spinner className="w-6 h-6" />
      </div>
    );
  }

  return (
    <div className="h-full flex flex-col">
      {/* Header */}
      <div className="flex items-center gap-2 mb-4 shrink-0">
        <Globe className="w-5 h-5" style={{ color: colors.primary }} />
        <h1 style={{ fontSize: typography.title, fontWeight: typography.bold, color: colors.text }}>
          Sites
        </h1>
        <Badge variant="secondary" className="ml-2">
          {sites.length} connected
        </Badge>
        <div className="flex-1" />

        {/* Add Site dialog */}
        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogTrigger asChild>
            <Button>
              <Plus className="w-4 h-4" />
              Add Site
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Connect WordPress Site</DialogTitle>
              <DialogDescription>
                Add a WordPress site for publishing. You'll need an Application Password
                created in the target site's User Profile → Application Passwords.
              </DialogDescription>
            </DialogHeader>
            <div className="space-y-3 py-2">
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                  Site Name
                </label>
                <Input
                  placeholder="My Blog"
                  value={formName}
                  onChange={(e) => setFormName(e.target.value)}
                />
              </div>
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                  Site URL
                </label>
                <Input
                  placeholder="https://example.com"
                  value={formUrl}
                  onChange={(e) => setFormUrl(e.target.value)}
                />
              </div>
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                  WordPress Username
                </label>
                <Input
                  placeholder="admin"
                  value={formUsername}
                  onChange={(e) => setFormUsername(e.target.value)}
                />
              </div>
              <div>
                <label style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                  Application Password
                </label>
                <Input
                  type="password"
                  placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"
                  value={formPassword}
                  onChange={(e) => setFormPassword(e.target.value)}
                />
                <p style={{ fontSize: typography.xs, color: colors.textMuted, marginTop: '4px' }}>
                  Generate this in your target site: Users → Profile → Application Passwords
                </p>
              </div>
            </div>
            <DialogFooter>
              <Button variant="outline" onClick={() => setDialogOpen(false)}>Cancel</Button>
              <Button onClick={handleCreate} disabled={createMutation.isPending}>
                {createMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin" /> : <Plus className="w-4 h-4" />}
                Add Site
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      {/* Empty state */}
      {sites.length === 0 ? (
        <div
          className="flex flex-col items-center justify-center flex-1 rounded-lg"
          style={{ border: `1px dashed ${colors.border}`, background: colors.bgSurface }}
        >
          <Globe className="w-12 h-12 mb-3" style={{ color: colors.textMuted }} />
          <p style={{ color: colors.textSecondary, fontSize: typography.body, fontWeight: typography.medium }}>
            No sites connected
          </p>
          <p style={{ color: colors.textMuted, fontSize: typography.sm, marginTop: '4px' }}>
            Add a WordPress site to start publishing content
          </p>
        </div>
      ) : (
        /* Sites grid */
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {sites.map((site) => (
            <div
              key={site.id}
              className="rounded-lg p-4 flex flex-col gap-3"
              style={{ border: `1px solid ${colors.border}`, background: colors.bgSurface, boxShadow: shadows.card }}
            >
              {/* Site info */}
              <div className="flex items-start gap-3">
                <div
                  className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                  style={{ background: colors.primaryLight }}
                >
                  <Globe className="w-4 h-4" style={{ color: colors.primary }} />
                </div>
                <div className="flex-1 min-w-0">
                  <h3
                    className="truncate"
                    style={{ fontSize: typography.body, fontWeight: typography.semibold, color: colors.text }}
                  >
                    {site.name}
                  </h3>
                  <a
                    href={site.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="flex items-center gap-1 truncate"
                    style={{ fontSize: typography.xs, color: colors.primary, textDecoration: 'none' }}
                  >
                    {site.url}
                    <ExternalLink className="w-3 h-3 shrink-0" />
                  </a>
                </div>
              </div>

              {/* Metadata */}
              <div style={{ fontSize: typography.xs, color: colors.textMuted }}>
                <span>User: {site.username}</span>
                {site.lastSyncAt && (
                  <span> · Last sync: {new Date(site.lastSyncAt).toLocaleDateString()}</span>
                )}
              </div>

              {/* Actions */}
              <div className="flex items-center gap-2 pt-1" style={{ borderTop: `1px solid ${colors.borderLight}` }}>
                <Button
                  variant="outline"
                  size="sm"
                  className="flex-1"
                  disabled={testingId === site.id}
                  onClick={() => handleTest(site.id)}
                >
                  {testingId === site.id ? (
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                  ) : (
                    <RefreshCw className="w-3.5 h-3.5" />
                  )}
                  Test
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => handleDelete(site.id)}
                  className="text-muted-foreground hover:text-destructive"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                </Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
