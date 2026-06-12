/**
 * CREATIVE MACHINE - Type Definitions
 * Core types for the modular micro-frontend architecture
 */

// ============================================
// Integration & API Key Types
// ============================================

export type IntegrationType = 'image' | 'video' | 'text' | 'audio';

export type ConnectionStatus = 'connected' | 'error' | 'unknown' | 'checking';

export interface Integration {
  id: string;
  name: string;
  type: IntegrationType;
  provider: string;
  apiKey?: string;
  isActive: boolean;
  enabledModules: ModuleId[];
  // Provider capabilities - what the provider actually supports
  capabilities?: {
    image: boolean;
    video: boolean;
    text: boolean;
    vision: boolean;
  };
  // All models available for this integration (from API validation)
  availableModels?: AIModel[];
  // Which models are enabled by the user (subset of availableModels)
  enabledModelIds?: string[];
  // Connection status tracking
  connectionStatus?: ConnectionStatus;
  lastVerified?: Date;
  lastError?: string;
  config?: Record<string, unknown>;
  createdAt: Date;
  updatedAt: Date;
}

export type CostTier = 'budget' | 'standard' | 'premium';

export interface AIModel {
  id: string;
  name: string;
  provider: string;
  type: IntegrationType;
  integrationId: string;
  description?: string;
  capabilities?: string[];
  isAvailable: boolean;
  costTier?: CostTier;
}

// ============================================
// Module Types
// ============================================

export type ModuleId = 'deliveries' | 'projects' | 'assets' | 'copy' | 'image' | 'video' | 'text' | 'brands' | 'templates' | 'settings' | 'integrations' | 'keywords' | 'writer' | 'strategies' | 'sites' | 'approvals' | 'ads' | 'automations' | 'users';

export interface ModuleConfig {
  id: ModuleId;
  label: string;
  icon: string;
  description: string;
  isEnabled: boolean;
  order: number;
}

export interface ModuleState {
  isLoading: boolean;
  error: string | null;
  lastUpdated: Date | null;
}

// ============================================
// Settings Types
// ============================================

export type ThemeMode = 'light' | 'dark' | 'system';
export type Language = 'en' | 'sv';

export interface AppSettings {
  theme: ThemeMode;
  language: Language;
  defaultImageModels: string[];
  defaultVideoModel: string | null;
  /** Default text model for URL scraping and text analysis */
  defaultTextModel: string | null;
  /** Default text model for Copy module writing (angles, audiences, copy generation) */
  defaultCopyMenuIntelligence: string | null;
  /** Default model for audience research (grounding/search step) */
  defaultCopyResearchModel: string | null;
  /** Default text model for Video module intelligence (concepts, enhance, compose) */
  defaultVideoTextModel: string | null;
  /** Default text model for Image module intelligence (suggestions, concepts, optimize brief) */
  defaultImageTextModel: string | null;
  /** Default text model for Writer module article generation */
  defaultWriterModel: string | null;
  autoSave: boolean;
  notifications: boolean;
  /** Token budget for audience generation (includes thinking tokens) */
  token_budget_audience: number;
  /** Token budget for angle generation (includes thinking tokens) */
  token_budget_angle: number;
  /** Token budget for copy generation (includes thinking tokens) */
  token_budget_copy: number;
  /** Token budget for video prompt enrichment (includes thinking tokens) */
  token_budget_video: number;
  /** Token budget for writer article generation (includes thinking tokens) */
  token_budget_writer: number;
}

// ============================================
// Project Types
// ============================================

export interface Project {
  id: string;
  name: string;
  description?: string;
  thumbnail?: string;
  assets: GeneratedAsset[];
  createdAt: Date;
  updatedAt: Date;
}

// ============================================
// Ad Version Types (Creative Angles/Concepts)
// ============================================

export interface AdVersion {
  id: string;
  name: string;
  description: string;
}

// ============================================
// Generation Types
// ============================================

export type GenerationMode = 'image' | 'video';
export type AssetStatus = 'pending' | 'processing' | 'complete' | 'failed';

export interface GeneratedAsset {
  id: string;
  projectId?: string;
  versionId?: string;
  type: GenerationMode;
  prompt: string;
  modelId: string;
  modelName: string;
  url?: string;
  base64?: string;
  thumbnailUrl?: string;
  status: AssetStatus;
  errorMessage?: string;
  rawVideoData?: unknown;
  metadata?: Record<string, unknown>;
  createdAt: Date;
}

export interface GenerationRequest {
  prompt: string;
  modelId: string;
  type: GenerationMode;
  options?: {
    aspectRatio?: string;
    duration?: string;
    style?: string;
    negativePrompt?: string;
    variationsCount?: number;
  };
}

export interface GenerationStatus {
  isGenerating: boolean;
  progress: number;
  message: string;
  currentModel?: string;
  totalModels?: number;
  completedModels?: number;
}

// ============================================
// Video Production Config
// ============================================

export type VideoDuration = '5s' | '10s' | '15s' | '20s' | 'auto';

export interface VideoProductionConfig {
  duration: VideoDuration;
  generateText: boolean;
  generateSound: boolean;
  script?: string;
}

// ============================================
// Logo & Reference Asset Types
// ============================================

export type LogoPlacement = 'in-image' | 'on-image';
export type ReferenceAssetType = 'product' | 'staff' | 'vehicle' | 'building' | 'interior';

export interface LogoConfig {
  url?: string;
  base64?: string;
  placement: LogoPlacement;
  isActive: boolean;
}

export interface ReferenceAsset {
  id: string;
  type: ReferenceAssetType;
  url?: string;
  base64?: string;
  name?: string;
  isActive: boolean;
}

export interface CertificationConfig {
  id: string;
  base64: string;
  name?: string;
}

// ============================================
// Text Overlay Types
// ============================================

export type TextPlacement = 'optimize' | 'top-left' | 'top-center' | 'top-right' | 'center' | 'bottom-left' | 'bottom-center' | 'bottom-right';

export interface TextOverlayConfig {
  isActive: boolean;
  text: string;
  optimize: boolean; // Allow model to adjust text
  placement: TextPlacement;
}

// ============================================
// Event Types for Module Communication
// ============================================

export type AppEventType =
  | 'settings:updated'
  | 'integration:added'
  | 'integration:removed'
  | 'integration:updated'
  | 'model:available'
  | 'model:unavailable'
  | 'project:created'
  | 'project:updated'
  | 'asset:generated';

export interface AppEvent<T = unknown> {
  type: AppEventType;
  payload: T;
  timestamp: Date;
}
