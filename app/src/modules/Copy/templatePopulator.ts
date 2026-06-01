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
import type { FrameworkOption } from "./frameworks";

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
  /** Frameworks the template carries (name + instruction text) for the Framework dropdown */
  frameworks: FrameworkOption[];
  /** Summary of what was populated (for UI feedback) */
  summary: {
    referenceAdsCount: number;
    tonalityPopulated: boolean;
    briefPopulated: boolean;
    frameworkPopulated: boolean;
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
    frameworkPopulated: false,
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
  const frameworkEntries: TemplateEntry[] = [];

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
      case "framework":
        frameworkEntries.push(entry);
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

  // Collect frameworks (name = label, text = value). Default-select the first
  // so the Framework dropdown reflects the template immediately. The resolved
  // text is written into copyFramework so it reaches the backend like creativeBrief.
  const frameworks: FrameworkOption[] = frameworkEntries.map((e) => ({
    name: e.label,
    text: e.value,
  }));
  if (frameworks.length > 0) {
    formUpdates["copyFrameworkName"] = frameworks[0].name;
    formUpdates["copyFramework"] = frameworks[0].text;
    summary.frameworkPopulated = true;
  }

  return { formUpdates, frameworks, summary };
}

/**
 * Build form field updates to clear all template-populated fields.
 * Called when the user clears the template selection (X button).
 *
 * `briefWasModified` / `tonalityWasModified` / `frameworkWasModified` signal that
 * the user manually edited the Creative Brief / Template Tonality / Framework
 * AFTER the template populated them. When true, those fields are left untouched
 * on clear so the user's manual edits are never silently discarded — matching the
 * existing preset-field policy below.
 */
export function buildTemplateClearUpdates(
  currentValues: CopyFormValues,
  briefWasModified = false,
  tonalityWasModified = false,
  frameworkWasModified = false
): Record<string, string | undefined> {
  const updates: Record<string, string | undefined> = {};

  // Clear all reference ads
  for (const key of Object.keys(currentValues)) {
    if (key.startsWith(`${REFERENCE_AD_PREFIX}_`)) {
      updates[key] = undefined;
    }
  }

  // Clear template tonality and brief — unless the user edited them by hand
  // after the template applied them (see param docs above).
  if (!tonalityWasModified) {
    updates["template_tonality"] = undefined;
  }
  if (!briefWasModified) {
    updates["creativeBrief"] = undefined;
  }

  // Clear the framework (name + resolved text) — unless the user changed the
  // dropdown by hand after the template applied it.
  if (!frameworkWasModified) {
    updates["copyFrameworkName"] = undefined;
    updates["copyFramework"] = undefined;
  }

  // Note: we do NOT clear preset fields (business_name, language, etc.)
  // because the user may have edited them and clearing would lose their changes.

  return updates;
}
