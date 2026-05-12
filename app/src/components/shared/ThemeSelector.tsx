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
import { colors, typography } from "@/components/shared/design-tokens";
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
}: ThemeSelectorProps) {
    const [isExpanded, setIsExpanded] = useState(defaultExpanded);

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

    return (
        <div
            className="rounded-lg border p-3 space-y-2"
            style={{ borderColor: colors.border, background: colors.bgMuted }}
        >
            {/* Clickable header — toggles accordion */}
            <button
                type="button"
                onClick={() => setIsExpanded((prev) => !prev)}
                className="flex items-center justify-between w-full text-left"
            >
                <div className="flex items-center gap-1.5">
                    <ChevronRight
                        style={{
                            width: 14,
                            height: 14,
                            color: colors.textMuted,
                            transition: 'transform 150ms ease',
                            transform: isExpanded ? 'rotate(90deg)' : 'rotate(0deg)',
                        }}
                    />
                    <span
                        style={{
                            fontSize: typography.micro,
                            fontWeight: typography.semibold,
                            textTransform: 'uppercase',
                            letterSpacing: '0.05em',
                            color: colors.textSecondary,
                        }}
                    >
                        Theme
                    </span>
                </div>
                {/* Collapsed summary — show active values */}
                {!isExpanded && summaryText && (
                    <span
                        className="truncate ml-2"
                        style={{
                            fontSize: typography.xs,
                            color: colors.textMuted,
                            maxWidth: '60%',
                        }}
                    >
                        {summaryText}
                    </span>
                )}
            </button>

            {/* Expandable content */}
            {isExpanded && (
                <>
                    {/* Season / Event dropdown */}
                    <div className="space-y-1.5">
                        <label
                            className="text-xs"
                            style={{ color: colors.textSecondary }}
                        >
                            Season / Event
                        </label>
                        <Select
                            value={seasonEvent || EMPTY_SENTINEL}
                            onValueChange={handleSeasonChange}
                        >
                            <SelectTrigger
                                className="h-9 text-sm w-full"
                                style={{
                                    borderColor: colors.border,
                                    background: colors.bgSurface,
                                }}
                            >
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
                        <label
                            className="text-xs"
                            style={{ color: colors.textSecondary }}
                        >
                            Campaign Theme
                        </label>
                        <input
                            type="text"
                            value={campaignTheme}
                            onChange={(e) => onCampaignThemeChange(e.target.value)}
                            placeholder='e.g. "New Year, New You" or "Back to School Savings"'
                            className="w-full rounded-md border px-3 py-2 text-sm outline-none"
                            style={{
                                borderColor: colors.border,
                                background: colors.bgSurface,
                            }}
                        />
                    </div>
                </>
            )}
        </div>
    );
}
