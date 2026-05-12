/**
 * BRAND COLOR SWATCHES
 *
 * Displays a brand's color palette as small circular swatches.
 * Read-only display component — editing happens in BrandDialog.
 *
 * Reusable across any module that needs to show brand colors:
 *   - Image module sidebar (reference context)
 *   - Video module sidebar (future)
 *   - Brand detail views
 *
 * Usage:
 *   <BrandColorSwatches colors={brand.colors} />
 *   <BrandColorSwatches colors={brand.colors} size="lg" />
 */

import { cn } from "@/lib/utils";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import { colors as tokens } from "@/components/shared/design-tokens";

// ============================================
// Types
// ============================================

interface BrandColorSwatchesProps {
  /** Array of hex color strings (ordered: index 0 = primary) */
  colors: string[] | null | undefined;
  /** Swatch size variant */
  size?: "sm" | "md" | "lg";
  /** Max swatches to show before "+N" overflow */
  maxVisible?: number;
  /** Additional className for the container */
  className?: string;
}

// ============================================
// Size config
// ============================================

const SIZE_MAP = {
  sm: { swatch: 16, ring: 1, text: "0.6rem" },
  md: { swatch: 22, ring: 1.5, text: "0.65rem" },
  lg: { swatch: 28, ring: 2, text: "0.7rem" },
} as const;

// ============================================
// Helpers
// ============================================

/** Determine if a color is light (needs dark border for visibility) */
function isLightColor(hex: string): boolean {
  const clean = hex.replace("#", "");
  const r = parseInt(clean.substring(0, 2), 16);
  const g = parseInt(clean.substring(2, 4), 16);
  const b = parseInt(clean.substring(4, 6), 16);
  // Relative luminance approximation
  return (r * 299 + g * 587 + b * 114) / 1000 > 200;
}

// ============================================
// Component
// ============================================

export function BrandColorSwatches({
  colors,
  size = "sm",
  maxVisible = 6,
  className,
}: BrandColorSwatchesProps) {
  if (!colors || colors.length === 0) return null;

  const config = SIZE_MAP[size];
  const visible = colors.slice(0, maxVisible);
  const overflow = colors.length - maxVisible;

  return (
    <div className={cn("flex items-center gap-1", className)}>
      {visible.map((color, index) => (
        <Tooltip key={`${color}-${index}`}>
          <TooltipTrigger asChild>
            <span
              className="inline-block rounded-full shrink-0 cursor-default"
              style={{
                width: config.swatch,
                height: config.swatch,
                backgroundColor: color,
                border: `${config.ring}px solid ${isLightColor(color) ? tokens.border : "transparent"}`,
                boxShadow: index === 0 ? `0 0 0 1.5px ${tokens.primary}` : undefined,
              }}
              title={color}
            />
          </TooltipTrigger>
          <TooltipContent side="top" className="text-xs">
            {index === 0 ? `Primary: ${color}` : color}
          </TooltipContent>
        </Tooltip>
      ))}
      {overflow > 0 && (
        <span
          className="inline-flex items-center justify-center rounded-full shrink-0"
          style={{
            width: config.swatch,
            height: config.swatch,
            fontSize: config.text,
            fontWeight: 500,
            color: tokens.textMuted,
            background: tokens.bgHover,
          }}
        >
          +{overflow}
        </span>
      )}
    </div>
  );
}
