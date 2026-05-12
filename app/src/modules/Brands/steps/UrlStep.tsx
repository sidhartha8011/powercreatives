/**
 * BRAND WIZARD — Step 1: URL Input
 *
 * First step of the brand creation wizard.
 * Two paths:
 *   1. Enter URL → Fetch → proceeds to logo selection
 *   2. "Continue without URL" → jumps directly to full form
 *
 * Pure presentational — all state and callbacks via props.
 * No existing component does this — genuinely new.
 */

import { Button } from "@/components/ui/button";
import { Globe, Loader2, PenLine } from "lucide-react";
import { colors } from "@/components/shared/design-tokens";

interface UrlStepProps {
  url: string;
  onUrlChange: (url: string) => void;
  onFetch: () => void;
  onSkipToForm: () => void;
  isFetching: boolean;
}

export function UrlStep({ url, onUrlChange, onFetch, onSkipToForm, isFetching }: UrlStepProps) {
  const hasUrl = url.trim().length > 0;

  return (
    <div className="flex flex-col items-center gap-6 py-8 px-4">
      {/* Header */}
      <div className="text-center space-y-2">
        <div
          className="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-2"
          style={{ background: `${colors.primary}15` }}
        >
          <Globe className="w-6 h-6" style={{ color: colors.primary }} />
        </div>
        <h3 className="text-lg font-semibold">Create New Brand</h3>
        <p className="text-sm text-muted-foreground max-w-sm">
          Enter a website URL to automatically extract business info, logo, and
          brand colors. Or continue manually.
        </p>
      </div>

      {/* URL Input + Fetch */}
      <div className="w-full max-w-md space-y-3">
        <div className="flex gap-2">
          <div className="relative flex-1">
            <div className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
              <Globe className="w-4 h-4 text-muted-foreground" />
            </div>
            <input
              type="url"
              placeholder="https://example.com"
              value={url}
              onChange={(e) => onUrlChange(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter" && hasUrl && !isFetching) onFetch();
              }}
              disabled={isFetching}
              className="w-full rounded-md border py-2 pr-3 text-sm outline-none"
              style={{
                paddingLeft: '2.5rem',
                borderColor: colors.border,
                background: colors.bgSurface,
              }}
              autoFocus
            />
          </div>
          <Button onClick={onFetch} disabled={!hasUrl || isFetching} className="shrink-0 gap-1.5">
            {isFetching ? <Loader2 size={14} className="animate-spin" /> : <Globe size={14} />}
            {isFetching ? "Fetching..." : "Fetch"}
          </Button>
        </div>

        {isFetching && (
          <p className="text-xs text-muted-foreground text-center animate-pulse">
            Analyzing website — extracting business info, images, and colors...
          </p>
        )}
      </div>

      {/* Divider */}
      <div className="flex items-center gap-3 w-full max-w-md">
        <div className="flex-1 h-px bg-border" />
        <span className="text-xs text-muted-foreground">or</span>
        <div className="flex-1 h-px bg-border" />
      </div>

      {/* Manual entry */}
      <Button variant="outline" onClick={onSkipToForm} disabled={isFetching} className="gap-2">
        <PenLine size={14} />
        Continue without URL
      </Button>
    </div>
  );
}
