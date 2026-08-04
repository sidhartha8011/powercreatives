/**
 * TemplateVarChips — the "Available variables — click one to copy" strip.
 *
 * Same affordance the Automations webhook editor gives (Automations/index.tsx),
 * brought to the Templates value editors: prose tells you variables exist,
 * clickable chips let you actually use one without retyping it.
 *
 * Pairs with SourceVarsHint, which explains the SEMANTICS (what a variable does
 * to the generated prompt, which ones suppress auto-appended text). That prose
 * now lives only in the Add/Edit dialog — in the list's Value cell it ran several
 * paragraphs and pushed Save/Cancel out of view, so there these chips ARE the
 * discoverability.
 *
 * TWO VOCABULARIES, and they are not interchangeable:
 *
 *  - Writer templates resolve through PCM_Strategy_Service::build_prompt(), which
 *    is documented lowercase + whitespace-tolerant (service.php:3347), so
 *    `{{post_title}}` == `{{ post_title }}`. Spaced form is used here to match
 *    what SourceVarsHint already shows the author.
 *
 *  - SEO templates resolve through PCM_SEO_AI::substitute_vars(), which is a
 *    LITERAL str_replace('{{' . key . '}}') — no whitespace tolerance at all. A
 *    spaced token would silently never resolve, so SEO chips are emitted tight.
 *
 * The SEO list is the key set built by PCM_SEO_AI::build_field_vars(), plus
 * `current_value` which the caller adds before substituting — i.e. what a custom
 * SEO prompt can actually reference, not what the default prompts happen to use.
 */

import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { cn, copyToClipboard } from '@/lib/utils';

/** Writer/strategy prompt variables — build_prompt(). */
const WRITER_VARS = [
  '{{ post_title }}',
  '{{ post_content }}',
  '{{ post_link }}',
  '{{ keyword }}',
  '{{ brand_context }}',
  '{{ research }}',
  '{{ output_format }}',
  '{{ media_instructions }}',
  '{{ brand_language }}',
];

/** SEO prompt variables — PCM_SEO_AI::build_field_vars() + current_value. */
const SEO_VARS = [
  '{{title}}',
  '{{current_value}}',
  '{{primary_keyword}}',
  '{{supporting_keyword}}',
  '{{meta_title}}',
  '{{meta_description}}',
  '{{meta_keywords}}',
  '{{post_type}}',
  '{{site.lang}}',
  '{{today}}',
  '{{website.url}}',
  '{{business.name}}',
  '{{business.category}}',
  '{{business.description}}',
  '{{business.tagline}}',
  '{{business.address}}',
  '{{business.phone}}',
  '{{business.hours}}',
  '{{business.rating}}',
  '{{business.types}}',
  '{{business.website}}',
  '{{business.website|hostname}}',
  '{{business.lat}}',
  '{{business.lng}}',
];

// Deliberately an allow-list, not a default. A module with no known vocabulary
// gets no chips rather than borrowing the Writer set — offering a token that
// stays literal in the generated prompt is worse than offering nothing.
function varsFor(module?: string): string[] {
  if (module === 'writer') return WRITER_VARS;
  if (module === 'seo') return SEO_VARS;
  return [];
}

/**
 * The variables an entry may use — the single source both the chips and the
 * "/" typeahead read, so the two can never offer different vocabularies.
 *
 * Only `prompt` entries run through a substituting builder; anything else would
 * keep the token as literal text.
 */
export function templateVarsFor(module?: string, category?: string): string[] {
  return category === 'prompt' ? varsFor(module) : [];
}

interface TemplateVarChipsProps {
  /** The template's module — "writer", "seo", … */
  module?: string;
  /** The entry's category/subtype. Only "prompt" entries resolve variables. */
  category?: string;
  className?: string;
}

export function TemplateVarChips({ module, category, className }: TemplateVarChipsProps) {
  const [copiedToken, setCopiedToken] = useState<string | null>(null);

  // copyToClipboard falls back to execCommand: the SPA runs inside wp-admin and
  // the async Clipboard API is unavailable on plain-HTTP installs.
  const copyToken = useCallback(async (token: string) => {
    const ok = await copyToClipboard(token);
    if (!ok) {
      toast.error('Could not copy to clipboard');
      return;
    }
    setCopiedToken(token);
    toast.success(`Copied ${token}`);
    setTimeout(() => setCopiedToken((cur) => (cur === token ? null : cur)), 1500);
  }, []);

  const vars = templateVarsFor(module, category);
  if (vars.length === 0) return null;

  return (
    <div className={cn('space-y-1.5', className)}>
      <p className="text-[11px] text-muted-foreground">
        Available variables — click one to copy:
      </p>
      <div className="flex flex-wrap gap-1">
        {vars.map((token) => (
          <button
            key={token}
            type="button"
            onClick={() => copyToken(token)}
            title={`Copy ${token}`}
            className={cn(
              'rounded border px-1.5 py-0.5 font-mono text-[11px] transition-colors',
              copiedToken === token
                ? 'border-emerald-500 bg-emerald-500/10 text-emerald-600'
                : 'border-border bg-muted/40 text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
          >
            {copiedToken === token ? 'Copied!' : token}
          </button>
        ))}
      </div>
    </div>
  );
}
