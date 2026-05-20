/**
 * REFERENCE ADS SECTION — Shared Component
 *
 * Renders a dynamic list of reference ad textareas with add/remove controls.
 * Form values are stored as indexed keys: reference_ad_0, reference_ad_1, …
 *
 * Features:
 *   - Dynamic add/remove with +/- buttons
 *   - Auto-populated from template entries (via parent)
 *   - Collapsible section header
 *   - Shows count badge when collapsed
 *
 * Originally from Copy module — extracted to shared/ for cross-module reuse.
 */

import { useState, useCallback, useMemo } from "react";
import { ChevronDown, ChevronRight, Plus, X } from "lucide-react";
import { colors } from "./design-tokens";

// ── Constants ──

/** Prefix for reference ad keys in formValues */
export const REFERENCE_AD_PREFIX = "reference_ad";

/** Maximum number of reference ads allowed */
const MAX_REFERENCE_ADS = 20;

// ── Props ──

interface Props {
  /** Current form values (flat record) */
  values: Record<string, string | number | undefined>;
  /** Callback to update a single field value */
  onChange: (fieldId: string, value: string | number) => void;
  /** Callback to batch-update multiple fields at once (for add/remove) */
  onBatchChange: (updates: Record<string, string | undefined>) => void;
  /** Whether the section starts collapsed */
  defaultCollapsed?: boolean;
}

// ── Helpers ──

/**
 * Extract the current reference ad entries from formValues.
 * Returns an ordered array of { key, value } pairs.
 */
export function getReferenceAdEntries(
  values: Record<string, string | number | undefined>
): { key: string; value: string }[] {
  const entries: { key: string; index: number; value: string }[] = [];

  for (const [key, val] of Object.entries(values)) {
    if (key.startsWith(`${REFERENCE_AD_PREFIX}_`) && typeof val === "string") {
      const indexStr = key.slice(REFERENCE_AD_PREFIX.length + 1);
      const index = parseInt(indexStr, 10);
      if (!isNaN(index)) {
        entries.push({ key, index, value: val });
      }
    }
  }

  // Sort by index to maintain order
  entries.sort((a, b) => a.index - b.index);
  return entries.map(({ key, value }) => ({ key, value }));
}

/**
 * Count how many reference ads have non-empty content.
 */
export function countFilledReferenceAds(values: Record<string, string | number | undefined>): number {
  return getReferenceAdEntries(values).filter((e) => e.value.trim().length > 0)
    .length;
}

// ── Component ──

export function ReferenceAdsSection({
  values,
  onChange,
  onBatchChange,
  defaultCollapsed = true,
}: Props) {
  const [isCollapsed, setIsCollapsed] = useState(defaultCollapsed);

  // Get current reference ad entries from formValues
  const entries = useMemo(() => getReferenceAdEntries(values), [values]);

  // Count of filled entries (for badge display)
  const filledCount = useMemo(
    () => entries.filter((e) => e.value.trim().length > 0).length,
    [entries]
  );

  // ── Add a new empty reference ad ──
  const handleAdd = useCallback(() => {
    if (entries.length >= MAX_REFERENCE_ADS) return;

    // Find the next available index
    const existingIndices = entries.map((e) => {
      const idx = parseInt(e.key.slice(REFERENCE_AD_PREFIX.length + 1), 10);
      return isNaN(idx) ? -1 : idx;
    });
    const nextIndex =
      existingIndices.length > 0 ? Math.max(...existingIndices) + 1 : 0;

    onChange(`${REFERENCE_AD_PREFIX}_${nextIndex}`, "");
  }, [entries, onChange]);

  // ── Remove a reference ad by key ──
  const handleRemove = useCallback(
    (keyToRemove: string) => {
      // Build a batch update: remove the key and re-index remaining entries
      const remaining = entries.filter((e) => e.key !== keyToRemove);
      const updates: Record<string, string | undefined> = {};

      // Clear all existing keys
      for (const entry of entries) {
        updates[entry.key] = undefined;
      }

      // Re-index remaining entries as reference_ad_0, reference_ad_1, …
      remaining.forEach((entry, i) => {
        updates[`${REFERENCE_AD_PREFIX}_${i}`] = entry.value;
      });

      onBatchChange(updates);
    },
    [entries, onBatchChange]
  );

  return (
    <div className="rounded-lg border" style={{ borderColor: "#e5e7eb" }}>
      {/* Section Header */}
      <button
        type="button"
        onClick={() => setIsCollapsed(!isCollapsed)}
        className="flex w-full items-center justify-between px-3 py-2 text-left"
        style={{
          background: "#fff",
          borderRadius: isCollapsed ? "0.5rem" : "0.5rem 0.5rem 0 0",
        }}
      >
        <span
          className="text-xs font-semibold uppercase tracking-wide"
          style={{ color: "#555" }}
        >
          Reference Ads
        </span>
        <div className="flex items-center gap-2">
          {/* Show count badge */}
          <span className="text-[10px]" style={{ color: "#999" }}>
            {entries.length > 0
              ? `${filledCount}/${entries.length} filled`
              : "0 ads"}
          </span>
          {isCollapsed ? (
            <ChevronRight className="w-4 h-4" style={{ color: "#999" }} />
          ) : (
            <ChevronDown className="w-4 h-4" style={{ color: "#999" }} />
          )}
        </div>
      </button>

      {/* Expanded content */}
      {!isCollapsed && (
        <div className="p-3 pt-2 space-y-2">
          {/* Help text */}
          <p className="text-[10px]" style={{ color: "#999" }}>
            Add reference ads to guide AI style, structure, and tone.
          </p>

          {/* Reference ad textareas */}
          {entries.map((entry, index) => (
            <div key={entry.key} className="relative group">
              <label
                className="block text-xs font-medium mb-1"
                style={{ color: "#555" }}
              >
                Reference Ad {index + 1}
              </label>
              <div className="relative">
                <textarea
                  value={entry.value}
                  onChange={(e) => onChange(entry.key, e.target.value)}
                  placeholder="Paste reference ad copy here…"
                  rows={4}
                  className="w-full rounded-md border px-3 py-2 text-sm outline-none resize-y pr-8"
                  style={{
                    borderColor: "#e5e7eb",
                    background: "#fff",
                    color: "#1a1a1a",
                  }}
                />
                {/* Remove button — always visible */}
                <button
                  type="button"
                  onClick={() => handleRemove(entry.key)}
                  className="absolute top-2 right-2 p-0.5 rounded-md hover:bg-red-50 transition-colors"
                  style={{ color: "#999" }}
                  title="Remove this reference ad"
                >
                  <X className="w-3.5 h-3.5 hover:text-red-500" />
                </button>
              </div>
            </div>
          ))}

          {/* Empty state when no entries */}
          {entries.length === 0 && (
            <p
              className="text-xs text-center py-3"
              style={{ color: "#999" }}
            >
              No reference ads yet. Click + to add one.
            </p>
          )}

          {/* Add button */}
          {entries.length < MAX_REFERENCE_ADS && (
            <button
              type="button"
              onClick={handleAdd}
              className="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-md border border-dashed transition-colors hover:border-solid w-full justify-center"
              style={{
                borderColor: colors.primary,
                color: colors.primary,
                background: "transparent",
              }}
            >
              <Plus className="w-3.5 h-3.5" />
              Add Reference Ad
            </button>
          )}
        </div>
      )}
    </div>
  );
}
