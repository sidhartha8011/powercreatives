/**
 * BRANDS MODULE — Shared Types & Constants
 *
 * Extracted from BrandDialog.tsx for reuse across:
 *   - BrandDialog (orchestrator)
 *   - useBrandForm hook (form state)
 *   - useBrandFetch hook (fetch + auto-create)
 *   - Sub-components (BrandFormFields, BrandLogoSection, BrandColorSection)
 */

import type { Brand } from "../../../../drizzle/schema";

// Re-export Brand type so hooks/ and components/ can import from ../types
export type { Brand };

// ============================================
// Wizard Steps
// ============================================

/** Steps in the brand creation wizard flow */
export type WizardStep = 'url' | 'logo' | 'colors' | 'form';

/** Data collected during wizard steps, passed between steps */
export interface WizardData {
  /** Scraped images from website (sorted by size, largest first) */
  scrapedImages: Array<{ url: string; width: number; height: number }>;
  /** URL chosen as logo by the user in LogoStep */
  selectedLogoUrl: string | null;
  /** Colors extracted from logo image (from backend extract_dominant_colors) */
  logoColors: string[];
  /** Colors scraped from CSS on the website */
  cssColors: string[];
}

/** Empty wizard data sentinel */
export const EMPTY_WIZARD_DATA: WizardData = {
  scrapedImages: [],
  selectedLogoUrl: null,
  logoColors: [],
  cssColors: [],
};

// ============================================
// Form Data
// ============================================

/** Shape of the brand form (used by BrandDialog + sub-components) */
export interface BrandFormData {
  name: string;
  website: string;
  niche: string;
  location: string;
  phone: string;
  businessSummary: string;
  language: string;
  colors: string[];
  /** Logo URL discovered during website fetch — caller handles saving as brand asset */
  logoUrl?: string;
}

/** Empty form sentinel — used for create mode and reset */
export const EMPTY_FORM: BrandFormData = {
  name: "",
  website: "",
  niche: "",
  location: "",
  phone: "",
  businessSummary: "",
  language: "",
  colors: [],
};

/** Maximum number of brand colors allowed */
export const MAX_COLORS = 10;

// ============================================
// Component Props
// ============================================

export interface BrandDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSubmit: (data: BrandFormData) => void;
  /** If provided, dialog is in edit mode. */
  editBrand?: Brand | null;
  isLoading?: boolean;
  /** Called when a brand is auto-created during fetch (so parent can update editBrand) */
  onBrandCreated?: (brand: Brand) => void;
}
