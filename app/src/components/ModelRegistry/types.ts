/**
 * MODEL REGISTRY - Component Types
 * 
 * Purpose: Type definitions for ModelRegistry UI components
 * Used by: All components in ModelRegistry folder
 * 
 * IMPORTANT: This is the single source of truth for all ModelRegistry props.
 * Do NOT create extended/shadow interfaces in component files.
 */

import type React from "react";
import type { 
  ModelData, 
  ModelCapability, 
  CostTier, 
  ModelStatus,
  ModuleType,
} from "@shared/types/models";

/**
 * Props for the main ModelRegistry table
 * 
 * All props live here — no "ExpandedModelRegistryTableProps" allowed.
 * Optional props represent features that are progressively enabled
 * (e.g., bulk actions are only available when all 5 bulk callbacks are provided).
 */
export interface ModelRegistryTableProps {
  // ── Core (required) ──────────────────────────────────────────────
  /** List of models to display */
  models: ModelData[];
  /** Callback when a capability is toggled */
  onToggleCapability: (modelId: number, capability: ModelCapability, enabled: boolean) => void;
  /** Callback when cost tier is changed */
  onChangeTier: (modelId: number, tier: CostTier) => void;
  /** Callback when status is confirmed (clicking the badge) */
  onConfirmStatus: (modelId: number) => void;
  /** Callback when model is enabled/disabled */
  onToggleEnabled: (modelId: number, enabled: boolean) => void;
  /** Callback to sync models from integrations */
  onSyncModels: () => void;
  /** Callback to run AI auto-detect */
  onAutoDetect: () => void;

  // ── Loading states ───────────────────────────────────────────────
  /** Loading state for initial data fetch */
  isLoading?: boolean;
  /** Whether sync is in progress */
  isSyncing?: boolean;
  /** Whether auto-detect is in progress */
  isAutoDetecting?: boolean;
  /** Whether confirming all suggested models */
  isConfirmingAll?: boolean;
  /** Whether any bulk operation is in progress */
  isBulkProcessing?: boolean;

  // ── Single-model extended actions (optional) ─────────────────────
  /** Callback to update a model's custom name */
  onUpdateName?: (modelId: number, name: string) => void;
  /** Callback to confirm all AI-suggested models at once */
  onConfirmAllSuggested?: () => void;
  /** Callback to delete a single model */
  onDeleteModel?: (modelId: number) => void;
  /** Callback to toggle per-module enablement */
  onToggleModule?: (modelId: number, module: ModuleType, enabled: boolean) => void;

  // ── Bulk actions (all-or-nothing: provide all 5 or none) ─────────
  /** Bulk enable selected models */
  onBulkEnable?: (ids: number[]) => void;
  /** Bulk disable selected models */
  onBulkDisable?: (ids: number[]) => void;
  /** Bulk delete selected models */
  onBulkDelete?: (ids: number[]) => void;
  /** Bulk change tier for selected models */
  onBulkChangeTier?: (ids: number[], tier: CostTier) => void;
  /** Bulk toggle module for selected models */
  onBulkToggleModule?: (ids: number[], module: ModuleType, enabled: boolean) => void;

  // ── Selection control (for parent-driven clear) ──────────────────
  /** Ref that the table populates with its clearSelection function.
   *  Parent can call ref.current() to clear selection after bulk success. */
  onClearSelectionRef?: React.RefObject<(() => void) | null>;
}

/**
 * Props for a single model row
 */
export interface ModelRowProps {
  /** Model data */
  model: ModelData;
  /** Callback when a capability is toggled */
  onToggleCapability: (capability: ModelCapability, enabled: boolean) => void;
  /** Callback when cost tier is changed */
  onChangeTier: (tier: CostTier) => void;
  /** Callback when status badge is clicked to confirm */
  onConfirmStatus: () => void;
  /** Callback when model is enabled/disabled */
  onToggleEnabled: (enabled: boolean) => void;
  /** Callback to update model's custom name */
  onUpdateName?: (name: string) => void;
  /** Callback to delete this model */
  onDeleteModel?: () => void;
  /** Callback to toggle per-module enablement */
  onToggleModule?: (module: ModuleType, enabled: boolean) => void;
  /** Whether this row is selected for bulk action */
  isSelected?: boolean;
  /** Callback when selection state changes */
  onSelect?: (selected: boolean) => void;
}

/**
 * Props for capability toggle checkbox
 */
export interface CapabilityCheckboxProps {
  /** Whether the capability is enabled */
  enabled: boolean;
  /** Callback when toggled */
  onToggle: (enabled: boolean) => void;
  /** Whether the checkbox is disabled */
  disabled?: boolean;
}

/**
 * Props for status badge
 */
export interface StatusBadgeProps {
  /** Current status */
  status: ModelStatus;
  /** Callback when badge is clicked to confirm */
  onClick?: () => void;
}

/**
 * Props for tier selector
 */
export interface TierSelectorProps {
  /** Current tier */
  tier: CostTier;
  /** Callback when tier is changed */
  onChange: (tier: CostTier) => void;
  /** Whether the selector is disabled */
  disabled?: boolean;
}

/**
 * Column definition for the capabilities
 */
export interface CapabilityColumn {
  key: ModelCapability;
  label: string;
  shortLabel: string;
}

/**
 * All capability columns (6 capabilities)
 */
export const CAPABILITY_COLUMNS: CapabilityColumn[] = [
  { key: "can_generate_image", label: "Generate Image", shortLabel: "Gen Img" },
  { key: "can_edit_image", label: "Edit Image", shortLabel: "Edit Img" },
  { key: "can_generate_video", label: "Generate Video", shortLabel: "Gen Vid" },
  { key: "can_edit_video", label: "Edit Video", shortLabel: "Edit Vid" },
  { key: "can_generate_text", label: "Generate Text", shortLabel: "Gen Text" },
  { key: "can_vision", label: "Vision", shortLabel: "Vision" },
];

/**
 * Status badge colors
 */
export const STATUS_COLORS: Record<ModelStatus, { bg: string; text: string; border: string }> = {
  auto: { bg: "bg-gray-100", text: "text-gray-600", border: "border-gray-300" },
  ai_suggested: { bg: "bg-blue-100", text: "text-blue-600", border: "border-blue-300" },
  confirmed: { bg: "bg-green-100", text: "text-green-600", border: "border-green-300" },
};

/**
 * Tier badge colors
 */
export const TIER_COLORS: Record<CostTier, { bg: string; text: string }> = {
  budget: { bg: "bg-green-100", text: "text-green-700" },
  standard: { bg: "bg-yellow-100", text: "text-yellow-700" },
  premium: { bg: "bg-red-100", text: "text-red-700" },
};
