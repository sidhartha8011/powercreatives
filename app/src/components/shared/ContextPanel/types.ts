import type { Brand } from "../../../../../drizzle/schema";

/** Shape returned by the URL scraper endpoint */
export interface ScrapedBusinessData {
  business_name?: string;
  niche?: string;
  location?: string;
  phone?: string;
  business_summary?: string;
  website?: string;
  language?: string;
  /** Brand colors extracted from CSS + optional LLM (hex codes) */
  brand_colors?: string[];
  /** All images found on the page */
  page_images?: string[];
}

/** The data this panel manages. Modules consume this via onChange. */
export interface ContextData {
  /** Selected brand ID (undefined = no brand selected) */
  brandId: number | undefined;
  /** Full brand record when selected (for modules that need all fields) */
  brand: Brand | null;
  /** URL entered by the user */
  url: string;
  /** Scraped business data from URL fetch (raw, module maps as needed) */
  scrapedData: ScrapedBusinessData | null;
  /** Selected season/event value from SEASON_OPTIONS */
  seasonEvent: string;
  /** Free-text campaign theme */
  campaignTheme: string;
}

/** Props for the ContextPanel orchestrator */
export interface ContextPanelProps {
  /** Current context state */
  value: ContextData;
  /** Called whenever any field changes */
  onChange: (data: ContextData) => void;
  /** Called with scraped data after a successful URL fetch */
  onUrlFetched?: (data: ScrapedBusinessData) => void;
  /** Hide specific sections if not needed */
  hideUrl?: boolean;
  hideTheme?: boolean;
}
