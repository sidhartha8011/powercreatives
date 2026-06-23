/**
 * AIReadinessPanel — manage the site's LLM-facing surface.
 *
 * Build per-page Markdown + llms.txt / llms-full.txt, publish the virtual
 * routes (/llms.txt, /{slug}.md), and review per-post readiness. Backed by
 * the `seo/ai-readiness` REST endpoints (admin-only).
 */

import { useMemo, useState, useCallback } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Loader2, ExternalLink, RefreshCw, FileText } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { trpc } from '@/lib/trpc';
import { LlmInfoSection } from './LlmInfoEditor';

interface AirPost { id: number; title: string; type: string; status: string; mdUrl: string }
interface AirStatus {
  published: boolean;
  settings: { max_posts: number; site_description: string; post_types: string[] };
  llmsUrl: string;
  llmsFullUrl: string;
  posts: AirPost[];
}

const STATUS_STYLES: Record<string, string> = {
  ready: 'bg-green-100 text-green-800',
  stale: 'bg-amber-100 text-amber-800',
  none: 'bg-muted text-muted-foreground',
};

const AIR_KEY = ['seo', 'airStatus'] as const;

export function AIReadinessPanel() {
  const queryClient = useQueryClient();
  const { data, isLoading } = trpc.seo.airStatus.useQuery() as { data?: unknown; isLoading: boolean };
  const status = useMemo<AirStatus | null>(() => (data ? (data as AirStatus) : null), [data]);

  const buildMutation = trpc.seo.airBuild.useMutation();
  const publishMutation = trpc.seo.airPublish.useMutation();
  const [busy, setBusy] = useState(false);

  const refresh = useCallback(() => queryClient.invalidateQueries({ queryKey: AIR_KEY }), [queryClient]);

  const handleBuild = useCallback(async () => {
    setBusy(true);
    try {
      const res: any = await buildMutation.mutateAsync({});
      toast.success(`Built llms.txt from ${res?.posts ?? 0} page(s)`);
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Build failed');
    } finally {
      setBusy(false);
    }
  }, [buildMutation, refresh]);

  const handlePublish = useCallback(async (next: boolean) => {
    setBusy(true);
    try {
      await publishMutation.mutateAsync({ published: next });
      toast.success(next ? 'Virtual routes published' : 'Virtual routes unpublished');
      await refresh();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to update');
    } finally {
      setBusy(false);
    }
  }, [publishMutation, refresh]);

  if (isLoading) {
    return <div className="flex items-center justify-center py-16"><Loader2 className="w-6 h-6 animate-spin text-primary" /></div>;
  }
  if (!status) {
    return <div className="text-sm text-muted-foreground py-8">Couldn't load AI Readiness status.</div>;
  }

  const counts = status.posts.reduce(
    (acc, p) => { acc[p.status] = (acc[p.status] ?? 0) + 1; return acc; },
    {} as Record<string, number>,
  );

  return (
    <div className="space-y-6 max-w-3xl">
      {/* Publish + build controls */}
      <div className="rounded-xl border border-border bg-card p-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <div>
            <Label className="text-sm font-medium">Publish virtual routes</Label>
            <p className="text-xs text-muted-foreground">
              Serves <code>/llms.txt</code>, <code>/llms-full.txt</code>, and <code>/&#123;slug&#125;.md</code> to LLM crawlers.
            </p>
          </div>
          <Switch checked={status.published} disabled={busy} onCheckedChange={handlePublish} />
        </div>
        <div className="flex items-center gap-2">
          <Button onClick={handleBuild} disabled={busy} className="gap-2">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
            Build / Regenerate
          </Button>
          {status.published && (
            <>
              <Button asChild variant="outline" size="sm" className="gap-1.5">
                <a href={status.llmsUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="w-3.5 h-3.5" /> llms.txt</a>
              </Button>
              <Button asChild variant="outline" size="sm" className="gap-1.5">
                <a href={status.llmsFullUrl} target="_blank" rel="noopener noreferrer"><ExternalLink className="w-3.5 h-3.5" /> llms-full.txt</a>
              </Button>
            </>
          )}
        </div>
        <div className="flex gap-3 text-xs text-muted-foreground">
          <span><span className="font-medium text-foreground">{counts.ready ?? 0}</span> ready</span>
          <span><span className="font-medium text-amber-700">{counts.stale ?? 0}</span> stale</span>
          <span><span className="font-medium">{counts.none ?? 0}</span> not generated</span>
        </div>
      </div>

      {/* Per-post status */}
      <div className="rounded-xl border border-border bg-card overflow-hidden">
        <div className="grid grid-cols-[1fr_5rem_6rem_3rem] gap-2 bg-muted/60 px-4 py-2 text-xs font-medium text-muted-foreground">
          <span>Page</span><span>Type</span><span>Status</span><span className="text-center">.md</span>
        </div>
        {status.posts.length === 0 ? (
          <div className="px-4 py-6 text-sm text-muted-foreground text-center">No published content in the configured types.</div>
        ) : (
          status.posts.map((p) => (
            <div key={p.id} className="grid grid-cols-[1fr_5rem_6rem_3rem] items-center gap-2 px-4 py-2 border-t border-border text-xs">
              <span className="truncate" title={p.title}>{p.title || '(untitled)'}</span>
              <span className="capitalize text-muted-foreground">{p.type}</span>
              <span><span className={`inline-block rounded px-1.5 py-0.5 capitalize ${STATUS_STYLES[p.status] ?? STATUS_STYLES.none}`}>{p.status}</span></span>
              <span className="text-center">
                <a href={p.mdUrl} target="_blank" rel="noopener noreferrer" className="inline-flex text-muted-foreground hover:text-foreground" title="View .md">
                  <FileText className="w-3.5 h-3.5" />
                </a>
              </span>
            </div>
          ))
        )}
      </div>

      <div className="pt-4 mt-2 border-t border-border">
        <h3 className="text-sm font-semibold">AI Search Optimization</h3>
        <p className="text-xs text-muted-foreground mb-4">A persuasive, keyword-optimized overview served at <code>/llm-info/</code>.</p>
        <LlmInfoSection />
      </div>
    </div>
  );
}
