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

export const TEMPLATE_MODULES = ["copy", "image", "video", "writer", "seo", "optimizer"] as const;
export type TemplateModule = (typeof TEMPLATE_MODULES)[number];

/** SEO prompt-template sections (each = one generatable field × mode). */
export const SEO_PROMPT_SECTIONS = [
  "page_title_generate", "page_title_optimize",
  "slug_generate", "slug_optimize",
  "meta_title_generate", "meta_title_optimize",
  "meta_description_generate", "meta_description_optimize",
  "meta_keywords_generate",
  "primary_keyword_generate", "primary_keyword_optimize",
  "content_optimize",
  "site_ai_description_generate",
  "llm_info_page_generate",
  "revise_contract_generate",
  "revise_envelope_generate",
  "revise_scope_classifier_generate",
] as const;

/** AI Optimization prompt-template sections — the compiler + one per teacher
 *  that calls an LLM (demand/onpage are fully deterministic, no prompt). */
export const OPTIMIZER_PROMPT_SECTIONS = [
  "compile",
  "teacher_answerability",
  "teacher_facts",
  "teacher_interlink",
  "teacher_mention",
  "teacher_search",
  "teacher_serp",
  "teacher_subtopics",
] as const;

export const TEMPLATE_TYPES: Record<TemplateModule, string[]> = {
  copy: ["social_ads", "social_organic"],
  image: ["generation", "editing"],
  video: ["scene", "recipe", "enhance"],
  writer: ["generation", "research", "outline"],
  seo: [...SEO_PROMPT_SECTIONS],
  optimizer: [...OPTIMIZER_PROMPT_SECTIONS],
};

export const MODULE_LABELS: Record<TemplateModule, string> = {
  copy: "Copy",
  image: "Image",
  video: "Video",
  writer: "Writer",
  seo: "SEO",
  optimizer: "AI Optimization",
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
  page_title_generate: "Page Title — Generate",
  page_title_optimize: "Page Title — Optimize",
  slug_generate: "Slug — Generate",
  slug_optimize: "Slug — Optimize",
  meta_title_generate: "Meta Title — Generate",
  meta_title_optimize: "Meta Title — Optimize",
  meta_description_generate: "Meta Description — Generate",
  meta_description_optimize: "Meta Description — Optimize",
  meta_keywords_generate: "Meta Keywords — Generate",
  primary_keyword_generate: "Primary Keyword — Generate",
  primary_keyword_optimize: "Primary Keyword — Optimize",
  content_optimize: "Content — Optimize",
  site_ai_description_generate: "AI Index Description (llms.txt)",
  llm_info_page_generate: "/llm-info/ Page",
  revise_contract_generate: "Section Revise — Editor Contract",
  revise_envelope_generate: "Section Revise — Change-Card Format",
  revise_scope_classifier_generate: "Section Revise — Scope Classifier",
  compile: "Directive Compiler",
  teacher_answerability: "Direct Answers Auditor",
  teacher_facts: "Business Facts Auditor",
  teacher_interlink: "Internal Linking Strategist",
  teacher_mention: "AI Recommendation Panel",
  teacher_search: "Structure & Language Auditor",
  teacher_serp: "Competitor Gaps (SERP) Analyst",
  teacher_subtopics: "Topic Coverage Auditor",
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
