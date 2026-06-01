/**
 * TEMPLATE TYPES
 *
 * Shared type definitions for the Templates module.
 * Used by both server (validation, LLM injection) and client (UI rendering).
 *
 * TemplateEntry is the core unit — each entry is one row in the template editor.
 * Entries are grouped by `category` for display and LLM prompt injection.
 */

// ============================================
// Template Entry Categories
// ============================================

/**
 * Predefined categories for template entries.
 * New categories can be added here without schema changes.
 */
export const TEMPLATE_CATEGORIES = [
  "reference_ad",
  "tonality",
  "prompt",
  "preset",
  "brief",
  "framework",
] as const;

export type TemplateCategory = (typeof TEMPLATE_CATEGORIES)[number];

/**
 * Human-readable labels for each category.
 */
export const CATEGORY_LABELS: Record<TemplateCategory, string> = {
  reference_ad: "Reference",
  tonality: "Tonality",
  prompt: "Prompt",
  preset: "Preset",
  brief: "Brief",
  framework: "Framework",
};

/**
 * Descriptions for each category — shown in the UI to help users understand
 * what each category is for.
 */
export const CATEGORY_DESCRIPTIONS: Record<TemplateCategory, string> = {
  reference_ad:
    "Example ads that define the desired structure, language, and style. Sent to AI as few-shot examples.",
  tonality:
    "Tone of voice instructions: description, what to include, what to avoid.",
  prompt:
    "Custom prompt for the AI — style notes, formatting rules, constraints.",
  preset:
    "Pre-filled form values (business name, language, etc.) applied when the template is selected.",
  brief:
    "Creative brief instructions: campaign goals, target audience, key messages, and offers.",
  framework:
    "A named copywriting framework/structure (e.g. Hook-Story-Offer, AIDA, PAS). Label = the name shown in the Copy module's Framework dropdown; value = the structure the AI follows. Injected as {{copyFramework}}.",
};

// ============================================
// Template Entry Type
// ============================================

/**
 * A single entry in a template. Each entry is one row in the template editor
 * and maps to one piece of information sent to the AI or applied to the form.
 */
export interface TemplateEntry {
  /** Unique key within this template (e.g. "ref_ad_1", "tone_desc", "business_name") */
  key: string;
  /** Category grouping — determines how the entry is used */
  category: TemplateCategory;
  /** Human-readable label shown in the UI (e.g. "Free Consultation - Swedish") */
  label: string;
  /** The actual content — can be short (preset value) or long (full reference ad) */
  value: string;
}

// ============================================
// Module & Type Constants — SINGLE SOURCE OF TRUTH
//
// To add a new template type:
//   1. Add the type string to the relevant module array in TEMPLATE_TYPES
//   2. Add a human-readable label in TYPE_LABELS
//   That's it — TemplateDialog dropdown and VideoTemplateDropdown
//   both derive their options from these constants automatically.
// ============================================

export const TEMPLATE_MODULES = ["copy", "image", "video", "writer"] as const;
export type TemplateModule = (typeof TEMPLATE_MODULES)[number];

export const TEMPLATE_TYPES: Record<TemplateModule, string[]> = {
  copy: ["social_ads", "social_organic"],
  image: ["generation", "editing"],
  video: ["scene", "recipe", "enhance"],
  writer: ["generation", "research", "outline"],
};

export const MODULE_LABELS: Record<TemplateModule, string> = {
  copy: "Copy",
  image: "Image",
  video: "Video",
  writer: "Writer",
};

export const TYPE_LABELS: Record<string, string> = {
  social_ads: "Social Ads",
  social_organic: "Social Organic",
  generation: "Generation",
  editing: "Editing",
  scene: "Scene Framework",
  recipe: "Content Recipe",
  enhance: "Enhancement",
  research: "Research Pattern",
  outline: "Outline Structure",
};

// ============================================
// Type Colors — shared pill colors per type
//
// Uses the same { bg, text, border } shape as NicheColor
// from nicheColors.ts for consistency.
// ============================================

export interface PillColor {
  bg: string;
  text: string;
  border: string;
}

/** Fixed color per template type — deterministic, no hashing needed. */
export const TYPE_COLORS: Record<string, PillColor> = {
  social_ads: { bg: '#dbeafe', text: '#1e40af', border: '#93c5fd' },
  social_organic: { bg: '#dcfce7', text: '#166534', border: '#86efac' },
  generation: { bg: '#fef3c7', text: '#92400e', border: '#fcd34d' },
  editing: { bg: '#fce7f3', text: '#9d174d', border: '#f9a8d4' },
  scene: { bg: '#e0e7ff', text: '#3730a3', border: '#a5b4fc' },
  recipe: { bg: '#fef9c3', text: '#854d0e', border: '#fde047' },
  enhance: { bg: '#f3e8ff', text: '#6b21a8', border: '#c084fc' },
  research: { bg: '#e0f2fe', text: '#0369a1', border: '#7dd3fc' },
  outline: { bg: '#ffedd5', text: '#c2410c', border: '#fdba74' },
};

/** Fallback for unknown types. */
export const TYPE_COLOR_FALLBACK: PillColor = { bg: '#f3f4f6', text: '#374151', border: '#d1d5db' };
