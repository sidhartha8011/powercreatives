/**
 * BRAND TYPES
 *
 * Shared type definitions for the Brands module.
 * Used by both server (validation, DB operations) and client (form population).
 *
 * The BRAND_TO_FORM_MAP is the single source of truth for mapping
 * brand DB columns → CopyFormValues keys. Used by BrandDropdown and
 * UrlFetcher auto-save to ensure consistent field population.
 */

// ============================================
// Brand Asset Types
// ============================================

/** How a brand asset was added */
export type BrandAssetSource = "url_fetch" | "manual_upload";

/** A single brand asset (reference image, logo, etc.) stored in S3 */
export interface BrandAsset {
  /** S3 URL (permanent, owned copy) */
  url: string;
  /** S3 key for deletion/reference */
  fileKey: string;
  /** Original filename for display */
  filename: string;
  /** MIME type, e.g. "image/png", "image/jpeg" */
  mimeType: string;
  /** How this asset was added */
  source: BrandAssetSource;
  /** ISO 8601 timestamp when added */
  addedAt: string;
}

// ============================================
// Brand → Form Field Mapping
// ============================================

/**
 * Maps brand DB column names to Copy module form field IDs.
 * Key = brand column, Value = CopyFormValues key.
 *
 * This is the ONLY place where this mapping is defined.
 * Both BrandDropdown (populate form) and UrlFetcher (auto-save) use it.
 */
export const BRAND_TO_FORM_MAP = {
  name: "business_name",
  website: "website",
  niche: "niche",
  location: "location",
  phone: "phone",
  businessSummary: "business_summary",
  language: "language",
} as const;

export type BrandColumn = keyof typeof BRAND_TO_FORM_MAP;
export type BrandFormKey = (typeof BRAND_TO_FORM_MAP)[BrandColumn];

/**
 * Reverse map: CopyFormValues key → brand column name.
 * Used when saving form data back to a brand.
 */
export const FORM_TO_BRAND_MAP = Object.fromEntries(
  Object.entries(BRAND_TO_FORM_MAP).map(([col, formKey]) => [formKey, col])
) as Record<BrandFormKey, BrandColumn>;

/**
 * All form keys that a brand populates.
 * Used to clear brand-populated fields when a brand is deselected.
 */
export const BRAND_FORM_KEYS = Object.values(BRAND_TO_FORM_MAP);

// ============================================
// Centralized Mapper Functions
// ============================================

/**
 * Map a brand DB object → form values (Copy-format keys).
 *
 * Uses BRAND_TO_FORM_MAP to translate brand column names (e.g. `name`)
 * to form field IDs (e.g. `business_name`). Returns only non-empty string fields.
 *
 * Consumers: Copy handleContextChange, any future module needing brand→form mapping.
 *
 * @param brand - The brand object from DB (e.g. ContextData.brand)
 * @returns Record with form-field keys and string values
 */
export function mapBrandToFormValues(
  brand: Record<string, any> | null | undefined
): Record<string, string> {
  if (!brand) return {};
  const result: Record<string, string> = {};
  for (const [col, formKey] of Object.entries(BRAND_TO_FORM_MAP)) {
    const value = brand[col];
    if (value != null && typeof value === 'string' && value.trim()) {
      result[formKey] = value;
    }
  }
  return result;
}

/**
 * Map scraped business data → form values (Copy-format keys).
 *
 * Scraped data already uses Copy-format keys (business_name, business_summary, etc.)
 * so this is a direct passthrough with null/empty filtering.
 *
 * Consumers: Copy handleUrlFetched, Brands handleFetchBrand (via adapter).
 *
 * @param scraped - The ScrapedBusinessData object from URL fetch
 * @returns Record with form-field keys and string values
 */
export function mapScrapedToFormValues(
  scraped: Record<string, any> | null | undefined
): Record<string, string> {
  if (!scraped) return {};
  const result: Record<string, string> = {};
  for (const formKey of BRAND_FORM_KEYS) {
    const value = scraped[formKey];
    if (value != null && typeof value === 'string' && value.trim()) {
      result[formKey] = value;
    }
  }
  return result;
}

/**
 * Map Copy-format form values → brand DB column keys.
 *
 * Uses FORM_TO_BRAND_MAP (auto-generated reverse of BRAND_TO_FORM_MAP)
 * so adding a new field to BRAND_TO_FORM_MAP automatically updates this.
 *
 * Used by Brands module (BrandDialog) to convert mapScrapedToFormValues output
 * into BrandFormData-compatible keys (which match DB columns).
 *
 * @param formValues - Values in Copy-format (from mapScrapedToFormValues or mapBrandToFormValues)
 * @returns Object with brand DB column keys
 */
export function mapFormValuesToBrandKeys(
  formValues: Record<string, string>
): Record<string, string> {
  const result: Record<string, string> = {};
  for (const [key, value] of Object.entries(formValues)) {
    // Lookup reverse map: e.g. business_name → name, business_summary → businessSummary
    const brandCol = (FORM_TO_BRAND_MAP as Record<string, string>)[key];
    result[brandCol ?? key] = value;
  }
  return result;
}

// ============================================
// Language Normalization (shared utility)
// ============================================

/** Full language names supported by the platform */
export const LANGUAGES = [
  "Arabic", "Bengali", "Chinese", "Croatian", "Czech", "Danish", "Dutch",
  "English", "Estonian", "Finnish", "French", "German", "Greek", "Hebrew",
  "Hindi", "Hungarian", "Icelandic", "Indonesian", "Italian", "Japanese",
  "Korean", "Latvian", "Lithuanian", "Malay", "Norwegian", "Persian",
  "Polish", "Portuguese", "Romanian", "Russian", "Serbian", "Slovak",
  "Slovenian", "Spanish", "Swedish", "Thai", "Turkish", "Ukrainian",
  "Vietnamese",
] as const;

/** Map common ISO 639-1 codes to full language names */
export const ISO_TO_FULL: Record<string, string> = {
  ar: "Arabic", bn: "Bengali", zh: "Chinese", hr: "Croatian", cs: "Czech",
  da: "Danish", nl: "Dutch", en: "English", et: "Estonian", fi: "Finnish",
  fr: "French", de: "German", el: "Greek", he: "Hebrew", hi: "Hindi",
  hu: "Hungarian", is: "Icelandic", id: "Indonesian", it: "Italian",
  ja: "Japanese", ko: "Korean", lv: "Latvian", lt: "Lithuanian",
  ms: "Malay", no: "Norwegian", nb: "Norwegian", nn: "Norwegian",
  fa: "Persian", pl: "Polish", pt: "Portuguese", ro: "Romanian",
  ru: "Russian", sr: "Serbian", sk: "Slovak", sl: "Slovenian",
  es: "Spanish", sv: "Swedish", th: "Thai", tr: "Turkish",
  uk: "Ukrainian", vi: "Vietnamese",
};

/**
 * Normalize language value: convert ISO codes to full names.
 * Falls back to the trimmed input if no match found.
 */
export function normalizeLanguage(val: string): string {
  if (!val) return "";
  const trimmed = val.trim();
  if ((LANGUAGES as readonly string[]).includes(trimmed)) return trimmed;
  const mapped = ISO_TO_FULL[trimmed.toLowerCase()];
  return mapped || trimmed;
}

