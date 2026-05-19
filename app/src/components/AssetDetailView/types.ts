/**
 * ASSET DETAIL VIEW - Types
 * 
 * Centralized type definitions for asset detail view components.
 * Designed to work with both image and video assets.
 */

// Asset types
export type AssetType = "image" | "video";

// Export formats
export type ExportFormat = "png" | "jpeg" | "webp" | "mp4";

// Asset data structure (matches database schema)
export interface Asset {
  id: number;
  userId: number;
  type: AssetType;
  url: string;
  thumbnailUrl?: string | null;
  prompt: string;
  model: string;
  projectId?: number | null;
  metadata?: string | null;
  createdAt: Date;
  updatedAt: Date;
}

// Project data structure
export interface Project {
  id: number;
  name: string;
  type: AssetType;
}

// Props for the main AssetDetailView component
export interface AssetDetailViewProps {
  asset: Asset;
  isOpen: boolean;
  onClose: () => void;
  onVariationsCreated?: (assets: Asset[]) => void;
  onAssetRefined?: (asset: Asset) => void;
  onNavigateToVideo?: (prompt: string, originalAssetUrl: string) => void;
  /** Brand logo URL for logo overlay feature (position 0 asset from brand) */
  brandLogoUrl?: string | null;
}

// Props for action panels
export interface ActionPanelProps {
  asset: Asset;
  isLoading?: boolean;
}

// Refine panel specific props
export interface RefinePanelProps extends ActionPanelProps {
  /** Callback when user applies refinement. Receives instruction, selected model IDs, and optional reference image. */
  onRefine: (instruction: string, modelIds?: string[], referenceImageUrl?: string) => Promise<void>;
}

// Export panel specific props
export interface ExportPanelProps extends ActionPanelProps {
  onExport: (format: ExportFormat) => Promise<void>;
}

// Save to project panel specific props
export interface SaveToProjectPanelProps extends ActionPanelProps {
  projects: Project[];
  onSave: (projectId: number) => Promise<void>;
  onCreateProject: (name: string) => Promise<number | void>;
}

// Variations panel specific props
export interface VariationsPanelProps extends ActionPanelProps {
  onCreateVariations: () => Promise<void>;
}

// Make into video panel specific props
export interface MakeVideoProps extends ActionPanelProps {
  onMakeVideo: () => void;
}

// Variation result (shared between VariationsPanel and VariationsTab)
export interface VariationResult {
  id: string;
  modelId: string;
  modelName: string;
  provider: string;
  status: "pending" | "loading" | "success" | "error";
  imageUrl?: string | null;
  error?: string;
}

// Tab types for the detail view
export type DetailViewTab = "detail" | "variations";

// Edit history item — represents one successful refine/edit operation
export interface EditHistoryItem {
  id: string;
  url: string;
  modelName: string;
  createdAt: Date;
}
