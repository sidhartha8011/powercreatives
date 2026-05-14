/**
 * BrandSummaryCard — Read-only brand identity summary
 *
 * Renders a compact card below the Brand dropdown showing key brand
 * metadata (business summary, niche, location, colors, logo thumbnail)
 * when a brand is selected. Shared across all modules via ContextPanel.
 *
 * Design: Read-only, non-interactive. Uses existing design tokens
 * and BrandColorSwatches component for consistency.
 */

import { Building2, MapPin, Palette } from 'lucide-react';
import { BrandColorSwatches } from '@/components/shared/BrandColorSwatches';
import type { Brand } from '../../../../drizzle/schema';

interface BrandSummaryCardProps {
    /** The full brand record (null = no brand selected → don't render) */
    brand: Brand | null;
}

/**
 * Compact brand identity card for ContextPanel.
 * Only renders when a brand is selected and has at least some metadata.
 */
export function BrandSummaryCard({ brand }: BrandSummaryCardProps) {
    if (!brand) return null;

    const b = brand as any; // Brand type varies — safe cast for optional fields
    const hasInfo = b.businessSummary || b.niche || b.location;
    const hasColors = Array.isArray(b.colors) && b.colors.length > 0;
    const hasAssets = Array.isArray(b.assets) && b.assets.length > 0;

    // Don't render empty card
    if (!hasInfo && !hasColors && !hasAssets) return null;

    return (
        <div className="rounded-lg border border-border bg-muted/30 p-3 space-y-2 text-xs">
            {/* Business summary — most important context for prompt injection */}
            {b.businessSummary && (
                <div className="flex gap-2">
                    <Building2 className="w-3.5 h-3.5 text-muted-foreground shrink-0 mt-0.5" />
                    <p className="text-muted-foreground leading-relaxed line-clamp-2">
                        {b.businessSummary}
                    </p>
                </div>
            )}

            {/* Niche + Location — inline for density */}
            {(b.niche || b.location) && (
                <div className="flex items-center gap-3 text-muted-foreground">
                    {b.niche && (
                        <span className="inline-flex items-center gap-1 px-1.5 py-0.5 bg-muted rounded text-[10px] font-medium">
                            {b.niche}
                        </span>
                    )}
                    {b.location && (
                        <span className="inline-flex items-center gap-1">
                            <MapPin className="w-3 h-3" />
                            {b.location}
                        </span>
                    )}
                </div>
            )}

            {/* Brand colors — reuses existing BrandColorSwatches component */}
            {hasColors && (
                <div className="flex items-center gap-2">
                    <Palette className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                    <BrandColorSwatches colors={b.colors} size="sm" />
                </div>
            )}

            {/* Logo thumbnail — first asset */}
            {hasAssets && (
                <div className="flex items-center gap-2">
                    <img
                        src={b.assets[0].url}
                        alt="Brand logo"
                        className="w-6 h-6 object-contain rounded border border-border"
                    />
                    <span className="text-muted-foreground">Logo</span>
                </div>
            )}
        </div>
    );
}
