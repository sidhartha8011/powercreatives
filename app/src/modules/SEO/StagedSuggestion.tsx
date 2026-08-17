import { Check, Loader2, RefreshCw, X } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * A staged AI suggestion inside a table cell / heading row: the proposed value plus
 * Accept · Reject · Re-generate.
 *
 * ONE component, shared by the SEO table cells and the Headings panel — the two
 * places used to carry identical hand-rolled chips (`bg-green-600 text-white
 * text-[10px] rounded`), colours and a type size that exist nowhere in the app's
 * tokens (owner card 11: "font does not match the rest of the app… are the colors /
 * borders shared design tokens? … Not look like AI slop"). Everything here is the
 * shared vocabulary: the `Button` component (success / outline, size `xs`), and
 * `accent` / `primary` / `border` / `foreground` tokens for the surface.
 */
export function StagedSuggestion({
  suggestion,
  busy,
  onAccept,
  onReject,
  onRegenerate,
}: {
  suggestion: string;
  busy?: boolean;
  onAccept: () => void;
  onReject: () => void;
  /** Omit to hide Re-generate (cells that can't be generated). */
  onRegenerate?: () => void;
}) {
  return (
    <div className="space-y-1 rounded-md border border-primary/20 bg-accent p-1.5">
      <div className="text-xs text-foreground break-words whitespace-normal" title={suggestion}>{suggestion}</div>
      <div className="flex items-center gap-1">
        <Button type="button" size="xs" variant="success" onClick={onAccept} disabled={busy} title="Accept">
          <Check /> Accept
        </Button>
        <Button type="button" size="xs" variant="outline" onClick={onReject} disabled={busy} title="Reject">
          <X /> Reject
        </Button>
        {onRegenerate && (
          <Button type="button" size="xs" variant="outline" onClick={onRegenerate} disabled={busy} title="Re-generate">
            {busy ? <Loader2 className="animate-spin" /> : <RefreshCw />} Re-generate
          </Button>
        )}
      </div>
    </div>
  );
}
