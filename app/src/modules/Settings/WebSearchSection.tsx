/**
 * Settings → Model Registry → "Web-enabled generation" (owner card 14).
 *
 * One global switch for whether the writing model may browse the live web while
 * it writes long-form content (strategy posts, Writer articles, SEO body and
 * section rewrites, the article review). ON by default. Implemented with each
 * provider's OWN web tool — OpenAI web search (Responses API), Anthropic web
 * search, Google grounding — so no model needs a separate "web" registry flag.
 *
 * Server setting `llm_web_search` (PCM_Settings). Every strategy also has its own
 * opt-out (the "Web" tick in the strategy row / "Web access" in its dialog);
 * this switch overrides them all when OFF.
 */
import { Globe, Info, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { trpc } from '@/lib/trpc';
import { HoverCard, HoverCardContent, HoverCardTrigger } from '@/components/ui/hover-card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';

export function WebSearchSection() {
  const utils = trpc.useUtils();
  const settingsQuery = trpc.settings.get.useQuery(undefined) as any;
  const saveMutation = trpc.settings.update.useMutation({
    onSuccess: (_d: unknown, vars: any) => {
      toast.success(vars?.llm_web_search === false ? 'Web-enabled generation turned off.' : 'Web-enabled generation turned on.');
      (utils as any).settings?.get?.invalidate?.();
    },
    onError: (err: any) => toast.error(err.message || 'Failed to save.'),
  });
  const settings = (settingsQuery.data && typeof settingsQuery.data === 'object' ? settingsQuery.data : {}) as Record<string, any>;
  // Absent = on (the server default).
  const enabled = settings.llm_web_search !== false && settings.llm_web_search !== 0 && settings.llm_web_search !== '0';

  return (
    <section className="card-powerkeys p-5" data-testid="web-search-section">
      {/* ONE compact row (owner 2026-08-20: the explanations "grow so much and
          it's hard for the UI"). Everything explanatory lives behind the info
          hover — the shared HoverCard, which also opens on keyboard focus, not
          a hand-rolled hover state. The row keeps only its identity: icon,
          name, info, switch. */}
      <div className="flex items-center gap-3">
        <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center shrink-0">
          <Globe className="w-4 h-4 text-primary" />
        </div>
        <div className="flex min-w-0 flex-1 items-center gap-1.5">
          <h3 className="font-medium text-foreground">Web-enabled generation</h3>
          <HoverCard openDelay={150}>
            <HoverCardTrigger asChild>
              <button
                type="button"
                aria-label="About web-enabled generation"
                className="shrink-0 text-muted-foreground/60 hover:text-foreground focus-visible:text-foreground"
              >
                <Info className="h-3.5 w-3.5" />
              </button>
            </HoverCardTrigger>
            <HoverCardContent align="start" className="w-80 space-y-3 text-xs text-muted-foreground">
              <p>
                The writing model searches and reads the live web while it writes — the source article, your site, current facts — instead of writing from memory.
              </p>
              <div>
                <Label className="text-xs font-medium text-foreground">Where it applies</Label>
                <p>Strategy posts (keyword, RSS, social), Writer articles, SEO body &amp; section rewrites, the article review.</p>
              </div>
              <div>
                <Label className="text-xs font-medium text-foreground">How</Label>
                <p>Each provider's own web tool: OpenAI web search, Anthropic web search, Google grounding. No per-model setup needed.</p>
              </div>
              <p>
                {enabled
                  ? 'On for every call. A single strategy can still opt out with its "Web" tick in the strategy row.'
                  : 'Off everywhere — every call writes from training memory only, whatever the strategies say.'}
              </p>
            </HoverCardContent>
          </HoverCard>
        </div>
        {settingsQuery.isLoading ? (
          <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
        ) : (
          <Switch
            id="llm-web-search"
            aria-label="Web-enabled generation"
            checked={enabled}
            disabled={saveMutation.isPending}
            onCheckedChange={(on) => saveMutation.mutate({ llm_web_search: !!on })}
          />
        )}
      </div>
    </section>
  );
}
