/**
 * SHARED COPY SETTINGS CONFIG
 *
 * Reusable option sets and config types for copy generation settings.
 * Used by Copy, Ads, and any future module that needs tone/emoji/CTA controls.
 *
 * Extracted from Copy/copyConfig.ts to enable cross-module reuse.
 */

import { SEASON_OPTIONS as SHARED_SEASON_OPTIONS } from '@shared/seasonOptions';

// ============================================
// Generic Types (module-agnostic)
// ============================================

/** A single option for select/dropdown fields */
export interface SelectOption {
  value: string;
  label: string;
}

/** Supported input types for dynamic field rendering */
export type FieldInputType =
  | 'text'
  | 'textarea'
  | 'number'
  | 'select'
  | 'url'
  | 'slider'
  | 'dynamic_list';

/** Visibility rule — 'always' or a specific copy type string */
export type FieldVisibility = 'always' | string;

/** Whether a field is required, optional, or hidden */
export type FieldRequirement = 'required' | 'optional' | 'hidden';

/** Full field definition — the building block of a dynamic section */
export interface FieldConfig {
  /** Unique key used as form state key */
  id: string;
  /** Display label */
  label: string;
  /** Input type to render */
  inputType: FieldInputType;
  /** Placeholder text */
  placeholder?: string;
  /** Options for select fields */
  options?: SelectOption[];
  /** Default value */
  defaultValue?: string | number;
  /** Min/max for number/slider fields */
  min?: number;
  max?: number;
  step?: number;
  /** Which types this field applies to */
  applicableTo: FieldVisibility;
  /** Requirement level per type (keys are type strings, e.g. 'social_ads') */
  requirements?: Record<string, FieldRequirement>;
  /** Help text shown below the field */
  helpText?: string;
  /** Width hint: 'full' = 100%, 'half' = 50% */
  width?: 'full' | 'half';
  /** For dynamic_list fields: the prefix used for indexed keys */
  dynamicListPrefix?: string;
}

/** A collapsible section that groups related fields */
export interface SectionConfig {
  /** Unique section key */
  id: string;
  /** Display title */
  title: string;
  /** Which types this section applies to */
  applicableTo: FieldVisibility;
  /** Whether the section starts collapsed */
  defaultCollapsed: boolean;
  /** Fields inside this section */
  fields: FieldConfig[];
}

// ============================================
// Reusable Option Sets
// ============================================

export const LANGUAGE_OPTIONS: SelectOption[] = [
  { value: 'en', label: 'English' },
  { value: 'sv', label: 'Swedish' },
  { value: 'no', label: 'Norwegian' },
  { value: 'da', label: 'Danish' },
  { value: 'fi', label: 'Finnish' },
  { value: 'de', label: 'German' },
  { value: 'fr', label: 'French' },
  { value: 'es', label: 'Spanish' },
  { value: 'pt', label: 'Portuguese' },
  { value: 'it', label: 'Italian' },
  { value: 'nl', label: 'Dutch' },
  { value: 'ar', label: 'Arabic' },
];

/** Season options derived from the shared single source of truth */
export const SEASON_OPTIONS: SelectOption[] = SHARED_SEASON_OPTIONS;

export const EMOJI_OPTIONS: SelectOption[] = [
  { value: 'auto', label: 'Auto (AI decides)' },
  { value: 'none', label: 'None' },
  { value: 'few', label: 'Few (1-2)' },
  { value: 'some', label: 'Some (3-5)' },
  { value: 'many', label: 'Many (6+)' },
];

export const CTA_STYLE_OPTIONS: SelectOption[] = [
  { value: 'auto', label: 'Auto (AI decides)' },
  { value: 'direct', label: 'Direct ("Buy Now", "Sign Up")' },
  { value: 'soft', label: 'Soft ("Learn More", "See How")' },
  { value: 'urgency', label: 'Urgency ("Limited Time", "Act Now")' },
  { value: 'question', label: 'Question ("Ready to…?")' },
];

export const TONE_OPTIONS: SelectOption[] = [
  { value: 'auto', label: 'Auto (AI decides)' },
  { value: 'professional', label: 'Professional' },
  { value: 'casual', label: 'Casual / Friendly' },
  { value: 'humorous', label: 'Humorous' },
  { value: 'urgent', label: 'Urgent / FOMO' },
  { value: 'luxurious', label: 'Luxurious / Premium' },
  { value: 'empathetic', label: 'Empathetic / Caring' },
  { value: 'bold', label: 'Bold / Provocative' },
];

export const POST_FORMAT_OPTIONS: SelectOption[] = [
  { value: 'auto', label: 'Auto (AI decides)' },
  { value: 'short', label: 'Short Post (1-3 lines)' },
  { value: 'story', label: 'Story / Narrative' },
  { value: 'listicle', label: 'Listicle (numbered tips)' },
  { value: 'question', label: 'Question / Poll' },
  { value: 'carousel', label: 'Carousel Captions' },
  { value: 'thread', label: 'Thread / Multi-part' },
];

// ============================================
// Shared Section Definitions (reusable across modules)
// ============================================

/**
 * Advanced copy style options — tone, emoji, CTA.
 * Can be rendered by DynamicSection in any module.
 */
export const ADVANCED_COPY_OPTIONS: SectionConfig = {
  id: 'advanced_options',
  title: 'Advanced Options',
  applicableTo: 'always',
  defaultCollapsed: true,
  fields: [
    {
      id: 'tone_override',
      label: 'Tone',
      inputType: 'select',
      options: TONE_OPTIONS,
      defaultValue: 'auto',
      applicableTo: 'always',
      width: 'half',
    },
    {
      id: 'emoji_level',
      label: 'Emojis',
      inputType: 'select',
      options: EMOJI_OPTIONS,
      defaultValue: 'auto',
      applicableTo: 'always',
      width: 'half',
    },
    {
      id: 'cta_style',
      label: 'CTA Style',
      inputType: 'select',
      options: CTA_STYLE_OPTIONS,
      defaultValue: 'auto',
      applicableTo: 'always',
      width: 'half',
    },
  ],
};

// ============================================
// Helpers
// ============================================

/**
 * Determine whether a section should be visible given the currently selected types.
 */
export function isSectionVisible(
  section: SectionConfig,
  selectedTypes: Record<string, boolean>,
): boolean {
  if (section.applicableTo === 'always') return true;
  return !!selectedTypes[section.applicableTo];
}

/**
 * Determine whether a field should be visible given the currently selected types.
 */
export function isFieldVisible(
  field: { applicableTo: string },
  selectedTypes: Record<string, boolean>,
): boolean {
  if (field.applicableTo === 'always') return true;
  return !!selectedTypes[field.applicableTo];
}

/**
 * Build initial form values from config defaults for a set of sections.
 */
export function buildDefaultFormValues(sections: SectionConfig[]): Record<string, string | number | undefined> {
  const values: Record<string, string | number | undefined> = {};
  for (const section of sections) {
    for (const field of section.fields) {
      if (field.defaultValue !== undefined) {
        values[field.id] = field.defaultValue;
      }
    }
  }
  return values;
}
