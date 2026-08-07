/**
 * THEME SELECTOR — Season/Event + Campaign Theme Input
 *
 * Extracted from ContextPanel for standalone use. Allows modules
 * to position the theme section anywhere in their layout.
 * Collapsible accordion — collapsed by default to save sidebar space.
 *
 * Used by:
 *   - ContextPanel (internal, when hideTheme is false)
 *   - Copy module (standalone, for custom ordering)
 *
 * @package PowerCreatives
 */

import { useState, useCallback } from "react";
import { ChevronRight } from "lucide-react";
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { SEASON_OPTIONS } from "@shared/seasonOptions";

// ============================================
// Constants
// ============================================

/** Sentinel value for "None" in shadcn Select (which doesn't support empty string) */
const EMPTY_SENTINEL = "__none__";

// ============================================
// Types
// ============================================

export interface ThemeSelectorProps {
    /** Selected season/event value (empty string = none) */
    seasonEvent: string;
    /** Free-text campaign theme */
    campaignTheme: string;
    /** Called when season/event changes */
    onSeasonChange: (value: string) => void;
    /** Called when campaign theme changes */
    onCampaignThemeChange: (value: string) => void;
    /** Whether the accordion starts expanded (default: false) */
    defaultExpanded?: boolean;
    /** When true, renders only the form fields without accordion wrapper.
     *  Use when ThemeSelector is placed inside an external AccordionSection. */
    bare?: boolean;
}

// ============================================
// Component
// ============================================

export function ThemeSelector({
    seasonEvent,
    campaignTheme,
    onSeasonChange,
    onCampaignThemeChange,
    defaultExpanded = false,
    bare = false,
}: ThemeSelectorProps) {
    const [isExpanded, setIsExpanded] = useState(bare || defaultExpanded);

    /** Map EMPTY_SENTINEL back to empty string for parent state */
    const handleSeasonChange = useCallback(
        (val: string) => {
            onSeasonChange(val === EMPTY_SENTINEL ? "" : val);
        },
        [onSeasonChange]
    );

    /** Summary text when collapsed — shows active theme info */
    const summaryText =
        seasonEvent && campaignTheme
            ? `${seasonEvent} · ${campaignTheme}`
            : seasonEvent || campaignTheme || undefined;

    /* ── Bare mode: just the form fields, no wrapper ── */
    const fields = (
        <>
            {/* Season / Event dropdown */}
            <div className="space-y-1.5">
                <label className="text-xs text-muted-foreground">
                    Season / Event
                </label>
                <Select
                    value={seasonEvent || EMPTY_SENTINEL}
                    onValueChange={handleSeasonChange}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Select…" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={EMPTY_SENTINEL}>None</SelectItem>
                        {SEASON_OPTIONS.filter((o) => o.value !== "").map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {/* Campaign Theme free-text */}
            <div className="space-y-1.5">
                <label className="text-xs text-muted-foreground">
                    Campaign Theme
                </label>
                <input
                    type="text"
                    value={campaignTheme}
                    onChange={(e) => onCampaignThemeChange(e.target.value)}
                    placeholder='e.g. "New Year, New You" or "Back to School Savings"'
                    className="w-full rounded-md border border-border px-3 py-2 text-sm outline-none bg-background"
                />
            </div>
        </>
    );

    /* Bare mode — parent provides the wrapper/accordion */
    if (bare) {
        return <div className="space-y-3">{fields}</div>;
    }

    /* Full mode — self-contained accordion */

    return (
        <div className="rounded-lg border border-border p-3 space-y-2 bg-muted">
            {/* Clickable header — toggles accordion */}
            <button
                type="button"
                onClick={() => setIsExpanded((prev) => !prev)}
                className="flex items-center justify-between w-full text-left"
            >
                <div className="flex items-center gap-1.5">
                    <ChevronRight
                        className={`w-3.5 h-3.5 text-muted-foreground transition-transform duration-150 ${
                            isExpanded ? 'rotate-90' : 'rotate-0'
                        }`}
                    />
                    <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Theme
                    </span>
                </div>
                {/* Collapsed summary — show active values */}
                {!isExpanded && summaryText && (
                    <span className="truncate ml-2 text-xs text-muted-foreground max-w-[60%]">
                        {summaryText}
                    </span>
                )}
            </button>

            {/* Expandable content */}
            {isExpanded && fields}
        </div>
    );
}
