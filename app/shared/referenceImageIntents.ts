/**
 * REFERENCE IMAGE INTENTS
 *
 * Shared type definitions and prompt-engineering templates for the
 * intent-based reference image system.
 *
 * Used by:
 *   - Server: routing layer injects intent context into prompts before provider dispatch
 *   - Client: SessionReferenceImagePanel renders intent pill selectors per thumbnail
 *
 * Architecture:
 *   The intent system is prompt-engineering driven — each intent modifies the
 *   generation prompt to guide the AI. This works universally across all providers
 *   (Manus, Gemini, Imagen, Kie.ai) without depending on provider-specific parameters.
 *
 *   When a provider supports structured intent parameters (e.g. Imagen's referenceType),
 *   the routing layer can additionally use those for better results.
 */

// ============================================
// Image Generation Mode
// ============================================

/**
 * Distinguishes how images are used in a generation request.
 *
 * This is the single source of truth for the generate-vs-edit distinction.
 * Every provider reads this mode to decide which API path to take.
 *
 * - `generate`: Pure text-to-image generation (no images involved)
 * - `reference`: Generate NEW images using reference images as visual context
 *   (e.g., "create an Easter ad featuring this person")
 * - `edit`: Modify an EXISTING image based on an instruction
 *   (e.g., "make this image brighter", "add a rainbow")
 *
 * The routing layer sets the mode; providers never guess from parameter presence.
 */
export type ImageGenerationMode = 'generate' | 'reference' | 'edit';

// ============================================
// Intent Types
// ============================================

/**
 * How a reference image should be used during generation.
 *
 * - `auto`: No prompt modification — let the AI interpret naturally
 * - `subject_person`: Preserve the person shown in the image
 * - `subject_product`: Feature the product shown in the image
 * - `style_transfer`: Apply the visual style of the image
 * - `environment`: Use the setting/background from the image
 * - `variation`: Create variations of the image
 */
export type ReferenceImageIntent =
  | 'auto'
  | 'subject_person'
  | 'subject_product'
  | 'style_transfer'
  | 'environment'
  | 'variation';

/**
 * All intent values for iteration and validation.
 */
export const ALL_INTENTS: ReferenceImageIntent[] = [
  'auto',
  'subject_person',
  'subject_product',
  'style_transfer',
  'environment',
  'variation',
];

/**
 * Human-readable labels for each intent.
 * Used by the frontend pill selector.
 */
export const INTENT_LABELS: Record<ReferenceImageIntent, string> = {
  auto: 'Auto',
  subject_person: 'Person',
  subject_product: 'Product',
  style_transfer: 'Style',
  environment: 'Environment',
  variation: 'Variation',
};

/**
 * Short descriptions for tooltip/help text.
 */
export const INTENT_DESCRIPTIONS: Record<ReferenceImageIntent, string> = {
  auto: 'Let the AI decide how to use this image',
  subject_person: 'Preserve and feature this person in the output',
  subject_product: 'Feature this product in the generated image',
  style_transfer: 'Apply the visual style of this image',
  environment: 'Use this setting or background',
  variation: 'Create a variation of this image',
};

// ============================================
// Session Reference Image
// ============================================

/**
 * A reference image in the current generation session.
 *
 * Session images are independent of the brand's permanent asset library.
 * They are created by copying brand assets or uploading session-only files.
 */
export interface SessionReferenceImage {
  /** Unique session-level ID (crypto.randomUUID) */
  id: string;
  /** Image URL (S3 URL from brand asset or session upload) */
  url: string;
  /** Display name */
  filename: string;
  /** How this image should be used during generation */
  intent: ReferenceImageIntent;
  /** true = copied from brand assets, false = session-only upload */
  fromBrand: boolean;
  /** Brand asset fileKey (only when fromBrand is true) */
  fileKey?: string;
}

// ============================================
// Prompt Engineering
// ============================================

/**
 * Prompt fragments injected per intent.
 * Each fragment is appended to the base prompt when the intent is active.
 *
 * `auto` has no fragment — the image is passed without prompt modification.
 */
const INTENT_PROMPT_FRAGMENTS: Record<ReferenceImageIntent, string> = {
  auto: '',
  subject_person: 'featuring the person shown in the reference image',
  subject_product: 'featuring the product shown in the reference image',
  style_transfer: 'in the visual style of the reference image',
  environment: 'set in the environment and location shown in the reference image',
  variation: 'create a variation of the reference image while maintaining its core composition',
};

/**
 * Build an enhanced prompt by injecting intent context from session reference images.
 *
 * Rules:
 *   - `auto` intents add nothing (AI interprets naturally)
 *   - Multiple non-auto intents are joined with ". "
 *   - If all intents are `auto`, the base prompt is returned unchanged
 *   - Empty sessionImages returns the base prompt unchanged
 *
 * @param basePrompt - The user's original generation prompt
 * @param sessionImages - Session reference images with their intents
 * @returns Enhanced prompt with intent context appended
 */
export function buildIntentPrompt(
  basePrompt: string,
  sessionImages: Pick<SessionReferenceImage, 'intent'>[],
): string {
  if (sessionImages.length === 0) return basePrompt;

  const fragments = sessionImages
    .map((img) => INTENT_PROMPT_FRAGMENTS[img.intent])
    .filter((f) => f.length > 0);

  if (fragments.length === 0) return basePrompt;

  // Deduplicate identical fragments (e.g. two images both set to "style_transfer")
  const unique = Array.from(new Set(fragments));

  return `${basePrompt}. ${unique.join('. ')}.`;
}
