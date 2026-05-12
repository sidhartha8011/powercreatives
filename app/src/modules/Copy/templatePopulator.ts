/**
 * TEMPLATE POPULATOR
 *
 * Client-side logic for populating visible form fields from template entries.
 * When a user selects a template:
 *   1. reference_ad entries → populate Reference Ads list (reference_ad_0, reference_ad_1, …)
 *   2. tonality entries → populate the "Template Tonality" field
 *   3. preset entries → populate existing form fields (business_name, language, etc.)
 *
 * All populated content is visible and editable before generation.
 */

import type { CopyFormValues } from "./types";
import { REFERENCE_AD_PREFIX } from "./components/ReferenceAdsSection";

// ── Types ──

/** Mirrors the shared TemplateEntry type for client-side use */
interface TemplateEntry {
  key: string;
  category: string;
  label: string;
  value: string;
}

/** Result of populating form fields from a template */
export interface TemplatePopulationResult {
  /** New form values to merge into state */
  formUpdates: Record<string, string | undefined>;
  /** Summary of what was populated (for UI feedback) */
  summary: {
    referenceAdsCount: number;
    tonalityPopulated: boolean;
    briefPopulated: boolean;
    presetsApplied: string[];
  };
}

// ── Core Function ──

/**
 * Build form field updates from template entries.
 * Returns a flat Record of field updates to merge into formValues.
 */
export function buildTemplatePopulation(
  entries: TemplateEntry[],
  currentValues: CopyFormValues
): TemplatePopulationResult {
  const formUpdates: Record<string, string | undefined> = {};
  const summary = {
    referenceAdsCount: 0,
    tonalityPopulated: false,
    briefPopulated: false,
    presetsApplied: [] as string[],
  };

  // Clear existing reference ads
  for (const key of Object.keys(currentValues)) {
    if (key.startsWith(`${REFERENCE_AD_PREFIX}_`)) {
      formUpdates[key] = undefined;
    }
  }

  // Group entries by category
  const referenceAds: TemplateEntry[] = [];
  const tonalityEntries: TemplateEntry[] = [];
  const briefEntries: TemplateEntry[] = [];
  const presetEntries: TemplateEntry[] = [];

  for (const entry of entries) {
    switch (entry.category) {
      case "reference_ad":
        referenceAds.push(entry);
        break;
      case "tonality":
        tonalityEntries.push(entry);
        break;
      case "brief":
        briefEntries.push(entry);
        break;
      case "preset":
        presetEntries.push(entry);
        break;
      // "prompt" entries are intentionally ignored — no template_instructions field
    }
  }

  // Populate reference ads as indexed fields
  referenceAds.forEach((entry, index) => {
    formUpdates[`${REFERENCE_AD_PREFIX}_${index}`] = entry.value;
  });
  summary.referenceAdsCount = referenceAds.length;

  // Populate tonality as a combined visible field
  if (tonalityEntries.length > 0) {
    const tonalityText = tonalityEntries
      .map((e) => `${e.label}: ${e.value}`)
      .join("\n\n");
    formUpdates["template_tonality"] = tonalityText;
    summary.tonalityPopulated = true;
  }

  // Populate brief as a combined visible field
  if (briefEntries.length > 0) {
    const briefText = briefEntries
      .map((e) => `${e.label}: ${e.value}`)
      .join("\n\n");
    formUpdates["creativeBrief"] = briefText;
    summary.briefPopulated = true;
  }

  // Apply preset entries to existing form fields
  for (const entry of presetEntries) {
    formUpdates[entry.key] = entry.value;
    summary.presetsApplied.push(entry.key);
  }

  return { formUpdates, summary };
}

/**
 * Build form field updates to clear all template-populated fields.
 * Called when the user clears the template selection (X button).
 */
export function buildTemplateClearUpdates(
  currentValues: CopyFormValues
): Record<string, string | undefined> {
  const updates: Record<string, string | undefined> = {};

  // Clear all reference ads
  for (const key of Object.keys(currentValues)) {
    if (key.startsWith(`${REFERENCE_AD_PREFIX}_`)) {
      updates[key] = undefined;
    }
  }

  // Clear template tonality and brief
  updates["template_tonality"] = undefined;
  updates["creativeBrief"] = undefined;

  // Note: we do NOT clear preset fields (business_name, language, etc.)
  // because the user may have edited them and clearing would lose their changes.

  return updates;
}
