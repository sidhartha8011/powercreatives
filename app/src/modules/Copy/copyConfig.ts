/**
 * COPY MODULE - Configuration (Single Source of Truth)
 *
 * Every field, section, and option is defined here.
 * Components read this config and render dynamically — no hardcoded UI logic.
 *
 * To add a new field:  add an entry to the relevant section's `fields` array.
 * To add a new section: add an entry to `COPY_SECTIONS`.
 * To add a new copy type: extend CopyType union in types.ts and add applicableTo rules here.
 */

import type {
  CopySectionConfig,
  CopyType,
  OfferTemplate,
  SelectOption,
} from './types';
import { SEASON_OPTIONS as SHARED_SEASON_OPTIONS } from '@shared/seasonOptions';

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

/**
 * Season options derived from the shared single source of truth.
 * Cast to SelectOption[] for compatibility with the config-driven UI.
 */
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
// Section & Field Definitions
// ============================================

export const COPY_SECTIONS: CopySectionConfig[] = [
  // ─── Business Info (always visible) ───
  {
    id: 'business_info',
    title: 'Business Info',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'business_name',
        label: 'Business Name',
        inputType: 'text',
        placeholder: 'e.g. Acme Corp',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'required' },
        width: 'half',
      },
      {
        id: 'niche',
        label: 'Niche / Industry',
        inputType: 'text',
        placeholder: 'e.g. SaaS, E-commerce, Fitness',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'required' },
        width: 'half',
      },
      {
        id: 'location',
        label: 'Location',
        inputType: 'text',
        placeholder: 'e.g. Stockholm, Sweden',
        applicableTo: 'always',
        width: 'half',
      },
      {
        id: 'phone',
        label: 'Phone',
        inputType: 'text',
        placeholder: '+46 70 123 4567',
        applicableTo: 'always',
        width: 'half',
      },
      {
        id: 'website',
        label: 'Website',
        inputType: 'url',
        placeholder: 'https://example.com',
        applicableTo: 'always',
        width: 'full',
      },
      {
        id: 'business_summary',
        label: 'Business Summary',
        inputType: 'textarea',
        placeholder: 'Brief description of what the business does, its USP, and target audience…',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'required' },
        helpText: 'Auto-generated from URL fetch. Edit freely.',
        width: 'full',
      },
      {
        id: 'language',
        label: 'Language',
        inputType: 'select',
        options: LANGUAGE_OPTIONS,
        defaultValue: 'en',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'required' },
        width: 'half',
      },
    ],
  },

  // ─── Destination (always visible) ───
  {
    id: 'destination',
    title: 'Destination',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'landing_page_url',
        label: 'Landing Page URL',
        inputType: 'url',
        placeholder: 'https://example.com/offer',
        applicableTo: 'always',
        width: 'full',
      },
      {
        id: 'shortlink',
        label: 'Shortlink (optional)',
        inputType: 'text',
        placeholder: 'e.g. bit.ly/my-offer',
        applicableTo: 'always',
        width: 'half',
      },
    ],
  },

  // ─── Pricing & Offer ───
  {
    id: 'pricing_offer',
    title: 'Offer & Pricing',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'offer_name',
        label: 'Offer Name',
        inputType: 'text',
        placeholder: 'e.g. Summer Launch Special',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'optional' },
        width: 'full',
      },
      {
        id: 'standard_price',
        label: 'Standard Price',
        inputType: 'text',
        placeholder: 'e.g. $99 / 999 SEK',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'optional' },
        width: 'half',
      },
      {
        id: 'offer_price',
        label: 'Offer Price',
        inputType: 'text',
        placeholder: 'e.g. $49 / 499 SEK',
        applicableTo: 'always',
        requirements: { social_ads: 'required', social_organic: 'optional' },
        width: 'half',
      },
      {
        id: 'total_discount',
        label: 'Total Discount',
        inputType: 'text',
        placeholder: 'e.g. 50% off, Save $50',
        applicableTo: 'always',
        requirements: { social_ads: 'optional', social_organic: 'optional' },
        width: 'half',
      },
      {
        id: 'extra_bonuses',
        label: 'Extra Bonuses',
        inputType: 'textarea',
        placeholder: 'e.g. Free shipping, bonus e-book, 30-day trial…',
        applicableTo: 'always',
        requirements: { social_ads: 'optional', social_organic: 'optional' },
        width: 'full',
      },
    ],
  },

  // ─── Reference Ads (dynamic list, visible for both ads and organic) ───
  // Uses inputType 'dynamic_list' rendered by ReferenceAdsSection component.
  // Form values stored as reference_ad_0, reference_ad_1, etc.
  {
    id: 'reference_ads',
    title: 'Reference Ads',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'reference_ads_list',
        label: 'Reference Ads',
        inputType: 'dynamic_list',
        placeholder: 'Paste reference ad copy here\u2026',
        applicableTo: 'always',
        requirements: { social_ads: 'optional', social_organic: 'optional' },
        helpText: 'Add reference ads to guide AI style, structure, and tone. Auto-populated when a template is selected.',
        width: 'full',
        dynamicListPrefix: 'reference_ad',
      },
    ],
  },

  // ─── Template Tonality (auto-populated from template, editable) ───
  {
    id: 'template_context',
    title: 'Template Tonality',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'template_tonality',
        label: 'Tonality (from template)',
        inputType: 'textarea',
        placeholder: 'Auto-populated when a template with tonality rules is selected…',
        applicableTo: 'always',
        requirements: { social_ads: 'optional', social_organic: 'optional' },
        helpText: 'Tone of voice rules from the selected template. Overrides the tone dropdown. Edit freely.',
        width: 'full',
      },
    ],
  },

  // ─── Customer Reviews (always visible) ───
  {
    id: 'customer_reviews',
    title: 'Customer Reviews',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'reviews',
        label: 'Reviews',
        inputType: 'textarea',
        placeholder: 'Paste anonymized customer reviews here…\n\nExample:\n"I was terrified of the dentist until I found this clinic. Now I actually look forward to my visits."\n\n"Best purchase I ever made. My back pain is completely gone after 2 weeks."',
        applicableTo: 'always',
        requirements: { social_ads: 'optional', social_organic: 'optional' },
        helpText: 'Real customer quotes help the AI write more authentic, emotionally resonant copy. Remove names for privacy.',
        width: 'full',
      },
    ],
  },

  // ─── Theme ───
  {
    id: 'seasonal_campaign',
    title: 'Theme',
    applicableTo: 'always',
    defaultCollapsed: true,
    fields: [
      {
        id: 'season_event',
        label: 'Season / Event',
        inputType: 'select',
        options: SEASON_OPTIONS,
        defaultValue: '',
        applicableTo: 'always',
        width: 'half',
      },
      {
        id: 'campaign_theme',
        label: 'Campaign Theme',
        inputType: 'text',
        placeholder: 'e.g. "New Year, New You" or "Back to School Savings"',
        applicableTo: 'always',
        width: 'half',
      },
    ],
  },

  // ─── Organic-Specific Fields ───
  {
    id: 'organic_specifics',
    title: 'Organic Post Options',
    applicableTo: 'social_organic',
    defaultCollapsed: true,
    fields: [
      {
        id: 'content_pillar',
        label: 'Content Pillar',
        inputType: 'text',
        placeholder: 'e.g. Education, Behind the Scenes, Testimonials',
        applicableTo: 'social_organic',
        requirements: { social_organic: 'optional' },
        width: 'half',
      },
      {
        id: 'hashtag_suggestions',
        label: 'Hashtag Suggestions',
        inputType: 'text',
        placeholder: '#marketing #growth (AI will also suggest)',
        applicableTo: 'social_organic',
        width: 'half',
      },
      {
        id: 'post_format',
        label: 'Post Format',
        inputType: 'select',
        options: POST_FORMAT_OPTIONS,
        defaultValue: 'auto',
        applicableTo: 'social_organic',
        width: 'half',
      },
    ],
  },

  // ─── Advanced Options (shared) ───
  {
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
  },
];

// ============================================
// Offer Templates (preset configurations for quick-fill)
// ============================================

export const OFFER_TEMPLATES: OfferTemplate[] = [
  {
    id: 'summer_sale',
    name: 'Summer Sale',
    applicableTo: 'social_ads',
    fields: {
      offer_name: 'Summer Sale 2026',
      total_discount: '30% off everything',
      season_event: 'summer',
    },
  },
  {
    id: 'launch_special',
    name: 'Product Launch',
    applicableTo: 'social_ads',
    fields: {
      offer_name: 'Launch Special',
      total_discount: 'Early-bird 25% off',
      cta_style: 'urgency',
    },
  },
  {
    id: 'educational_post',
    name: 'Educational Post',
    applicableTo: 'social_organic',
    fields: {
      content_pillar: 'Education',
      post_format: 'listicle',
      tone_override: 'professional',
    },
  },
  {
    id: 'behind_scenes',
    name: 'Behind the Scenes',
    applicableTo: 'social_organic',
    fields: {
      content_pillar: 'Behind the Scenes',
      post_format: 'story',
      tone_override: 'casual',
    },
  },
];

// ============================================
// Helpers
// ============================================

/**
 * Determine whether a section should be visible given the currently selected copy types.
 */
export function isSectionVisible(
  section: CopySectionConfig,
  selectedTypes: Record<string, boolean>,
): boolean {
  if (section.applicableTo === 'always') return true;
  return !!selectedTypes[section.applicableTo];
}

/**
 * Determine whether a field should be visible given the currently selected copy types.
 */
export function isFieldVisible(
  field: { applicableTo: string },
  selectedTypes: Record<string, boolean>,
): boolean {
  if (field.applicableTo === 'always') return true;
  return !!selectedTypes[field.applicableTo];
}

/**
 * Build initial form values from config defaults.
 */
export function buildDefaultFormValues(): Record<string, string | number | undefined> {
  const values: Record<string, string | number | undefined> = {};
  for (const section of COPY_SECTIONS) {
    for (const field of section.fields) {
      if (field.defaultValue !== undefined) {
        values[field.id] = field.defaultValue;
      }
    }
  }
  return values;
}
