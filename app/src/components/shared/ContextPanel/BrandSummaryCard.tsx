/**
 * BrandSummaryCard — Read-only business context summary
 *
 * Renders a compact, collapsible card below the Brand dropdown showing
 * the brand's business summary when a brand is selected.
 *
 * ONLY shows business summary — niche/location already visible in
 * brand dropdown subtitle, colors/logo already in Brand Assets section.
 * This avoids information duplication across the sidebar.
 */

import { Building2, ChevronDown } from 'lucide-react';
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from '@/components/ui/collapsible';

interface BrandSummaryCardProps {
    /** The full brand record (null = no brand selected → don't render) */
    brand: Record<string, any> | null;
}

/**
 * Compact business context card for ContextPanel.
 * Only renders when a brand is selected and has a business summary.
 */
export function BrandSummaryCard({ brand }: BrandSummaryCardProps) {
    if (!brand) return null;

    const summary = brand.businessSummary as string | undefined;
    if (!summary) return null;

    return (
        <Collapsible defaultOpen={true}>
            <CollapsibleTrigger className="flex items-center gap-2 w-full text-left group">
                <Building2 className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                <span className="text-xs font-medium text-muted-foreground">Business Context</span>
                <ChevronDown className="w-3 h-3 text-muted-foreground ml-auto transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <p className="text-xs text-muted-foreground leading-relaxed mt-1.5 pl-5.5">
                    {summary}
                </p>
            </CollapsibleContent>
        </Collapsible>
    );
}
