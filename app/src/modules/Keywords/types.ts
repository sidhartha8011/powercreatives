/**
 * KEYWORD EXPLORER — Type Definitions
 *
 * Types used exclusively by the Keywords module.
 * No dependencies on other modules.
 */

/** Single keyword result from Google Autocomplete or Ahrefs enrichment */
export interface KeywordResult {
  /** Unique ID for table row tracking (nanoid or index-based) */
  id: string;
  /** The keyword string */
  keyword: string;
  /** Category from Google Autocomplete (if available) */
  category?: string;
  /** Ahrefs: Monthly search volume */
  volume?: number;
  /** Ahrefs: Keyword difficulty (0–100) */
  difficulty?: number;
  /** Ahrefs: Cost per click (USD) */
  cpc?: number;
  /** Whether this keyword has been enriched via Ahrefs */
  enriched: boolean;
  /** SERP: Average Domain Rating of top 5 positions */
  serpAvgDR?: number;
  /** SERP: Lowest Domain Rating in top 5 positions */
  serpLowDR?: number;
  /** SERP: Average URL Rating of top 5 positions */
  serpAvgUR?: number;
  /** SERP: Lowest URL Rating in top 5 positions */
  serpLowUR?: number;
  /** SERP: Top 5 ranking pages with details */
  serpResults?: SerpResult[];
}

/** Single SERP position result from Ahrefs serp-overview */
export interface SerpResult {
  /** SERP position (1-5) */
  position: number;
  /** Page title */
  title: string;
  /** Full URL of the ranking page */
  url: string;
  /** Domain Rating of the ranking domain */
  domain_rating: number;
  /** URL Rating of the specific ranking page */
  url_rating?: number;
  /** Estimated monthly organic traffic */
  traffic: number;
}

/** Saved keyword list (persisted via REST API) */
export interface KeywordList {
  id: string;
  name: string;
  keywords: string[];
  createdAt: string;
}

/** Context passed to keyword actions */
export interface ActionContext {
  /** Whether Ahrefs API key is available */
  ahrefsKey: boolean;
  /** Navigate to editor tab with selected keywords */
  openEditor: (keywords: string[]) => void;
}

/** Pluggable keyword action definition */
export interface KeywordAction {
  id: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  /** Only show if requirements are met */
  isAvailable?: (ctx: ActionContext) => boolean;
  /** Execute the action on selected keywords */
  execute: (keywords: KeywordResult[], ctx: ActionContext) => Promise<void>;
}
