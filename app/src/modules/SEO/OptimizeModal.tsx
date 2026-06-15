/**
 * OptimizeModal — AI-optimize a page's full body (Phase 3b).
 *
 * Fetches the post body, generates an SEO/AEO-optimized version, shows a
 * Before/After view with a live SEO scorecard for each, and lets the user
 * accept (save) or cancel. Scorecard is computed client-side.
 */

import { useEffect, useMemo, useState, useCallback } from 'react';
import { Loader2, Sparkles, Check } from 'lucide-react';
import { toast } from 'sonner';

import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { trpc } from '@/lib/trpc';
import { computeScorecard, scoreVerdict } from './scorecard';

function ScoreList({ html, keyword }: { html: string; keyword: string }) {
  const verdicts = useMemo(() => scoreVerdict(computeScorecard(html, keyword)), [html, keyword]);
  return (
    <div className="space-y-1">
      {verdicts.map((v) => (
        <div key={v.label} className="flex items-center justify-between text-xs">
          <span className="text-muted-foreground">{v.label}</span>
          <span className={`inline-flex items-center gap-1 font-medium ${v.ok ? 'text-green-700' : 'text-amber-700'}`}>
            <span className={`w-1.5 h-1.5 rounded-full ${v.ok ? 'bg-green-600' : 'bg-amber-500'}`} />
            {v.value}
          </span>
        </div>
      ))}
    </div>
  );
}

export function OptimizeModal({
  postId,
  title,
  keyword,
  open,
  onClose,
}: {
  postId: number;
  title: string;
  keyword: string;
  open: boolean;
  onClose: () => void;
}) {
  const [original, setOriginal] = useState('');
  const [optimized, setOptimized] = useState('');
  const [loadingBody, setLoadingBody] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [saving, setSaving] = useState(false);

  const optimizeM = trpc.seo.optimizeBody.useMutation();
  const saveM = trpc.seo.saveBody.useMutation();
  const bodyQuery = trpc.seo.getBody.useQuery(open ? { id: postId } : (undefined as any), { enabled: open }) as { data?: any };

  useEffect(() => {
    if (open) { setOptimized(''); setLoadingBody(true); }
  }, [open, postId]);

  useEffect(() => {
    if (bodyQuery.data) { setOriginal(String(bodyQuery.data.body ?? '')); setLoadingBody(false); }
  }, [bodyQuery.data]);

  const handleGenerate = useCallback(async () => {
    setGenerating(true);
    try {
      const res: any = await optimizeM.mutateAsync({ id: postId });
      setOptimized(String(res?.body ?? ''));
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Optimization failed');
    } finally { setGenerating(false); }
  }, [postId, optimizeM]);

  const handleAccept = useCallback(async () => {
    if (!optimized) return;
    setSaving(true);
    try {
      await saveM.mutateAsync({ id: postId, body: optimized });
      toast.success('Optimized content saved');
      onClose();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Save failed');
    } finally { setSaving(false); }
  }, [postId, optimized, saveM, onClose]);

  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      <DialogContent className="sm:max-w-4xl max-h-[85vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><Sparkles className="w-5 h-5 text-primary" /> Optimize content</DialogTitle>
          <DialogDescription className="truncate">{title} {keyword ? `· keyword: ${keyword}` : ''}</DialogDescription>
        </DialogHeader>

        <div className="grid grid-cols-2 gap-4 flex-1 min-h-0 overflow-y-auto">
          {/* Before */}
          <div className="space-y-2 min-h-0">
            <div className="text-xs font-semibold text-muted-foreground">Before</div>
            <ScoreList html={original} keyword={keyword} />
            <div className="rounded-lg border border-border p-2 text-xs max-h-64 overflow-y-auto prose prose-sm" dangerouslySetInnerHTML={{ __html: loadingBody ? 'Loading…' : original || '(empty)' }} />
          </div>
          {/* After */}
          <div className="space-y-2 min-h-0">
            <div className="text-xs font-semibold text-muted-foreground">After (AI)</div>
            {optimized ? (
              <>
                <ScoreList html={optimized} keyword={keyword} />
                <div className="rounded-lg border border-blue-200 bg-blue-50/40 p-2 text-xs max-h-64 overflow-y-auto prose prose-sm" dangerouslySetInnerHTML={{ __html: optimized }} />
              </>
            ) : (
              <div className="flex items-center justify-center h-40 rounded-lg border border-dashed border-border text-xs text-muted-foreground">
                {generating ? <Loader2 className="w-5 h-5 animate-spin text-primary" /> : 'Click “Optimize with AI”.'}
              </div>
            )}
          </div>
        </div>

        <DialogFooter className="gap-2">
          <Button variant="ghost" onClick={onClose}>Cancel</Button>
          <Button variant="outline" onClick={handleGenerate} disabled={generating || loadingBody} className="gap-2">
            {generating ? <Loader2 className="w-4 h-4 animate-spin" /> : <Sparkles className="w-4 h-4" />} Optimize with AI
          </Button>
          <Button onClick={handleAccept} disabled={!optimized || saving} className="gap-2">
            {saving ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />} Accept &amp; Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
