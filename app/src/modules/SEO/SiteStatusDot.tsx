/**
 * THE SITE STATUS DOT (gap 89ef71a) — the SEO module's ONE shared site
 * indicator. A ~6px dot that inherits its parent's color (active tab
 * stays blue, inactive stays muted); it turns RED only when the
 * connector health check FAILED — there is no green state by design
 * (owner ruling: healthy is unremarkable). The failure's title names
 * the real error, never a generic "offline".
 */

export function SiteStatusDot({ ok, error }: { ok: boolean | null; error?: string | null }) {
  if (ok === false) {
    return (
      <span
        title={error || 'The connection test failed'}
        className="h-1.5 w-1.5 shrink-0 rounded-full bg-red-500"
      />
    );
  }
  // Healthy or not-yet-known: the quiet current-color dot.
  return <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-current opacity-70" />;
}
