/**
 * SHARED COMPONENTS — Barrel Export
 *
 * Import everything from '@/components/shared' for convenience:
 *   import { PillButton, SectionCard, SectionLabel, colors } from '@/components/shared';
 */

export { PillButton, PillSplitButton } from './PillButton';
export type { PillButtonVariant } from './PillButton';
export { PillTabBar } from './PillTabBar';
export type { PillTabItem, PillTabBarProps } from './PillTabBar';
export { ModeToggle } from './ModeToggle';
export type { GenerationMode } from './ModeToggle';
export { ModeListBox } from './ModeListBox';
export type { ListItem } from './ModeListBox';
export { GroupedAnglesList } from './GroupedAnglesList';
export type { AngleItem } from './GroupedAnglesList';
export { SectionCard } from './SectionCard';
export { SectionLabel } from './SectionLabel';
export { ModuleHeader } from './ModuleHeader';
export { EmptyState } from './EmptyState';
export { ContextPanel, createEmptyContextData, DEFAULT_BRAND_TOGGLES } from './ContextPanel';
export type { ContextData, ScrapedBusinessData } from './ContextPanel';
export { EnhancedBrandSection } from './EnhancedBrandSection';
export { ThemeSelector } from './ThemeSelector';
export type { ThemeSelectorProps } from './ThemeSelector';
export { BrandColorSwatches } from './BrandColorSwatches';
export { ReferenceImageSelector } from './ReferenceImageSelector';
export { StatusBadge } from './StatusBadge';
export { CharacterCounter } from './CharacterCounter';
export { colors, typography, spacing, shadows, statusColors } from './design-tokens';
export type { StatusKey } from './design-tokens';
export { default as tokens } from './design-tokens';
export { KeywordPicker } from './KeywordPicker';
export { ModelDropdown } from './ModelDropdown';
export type { ModelDropdownGroup } from './ModelDropdown';
export { AsyncSelectField, UNSELECTED } from './AsyncSelectField';
export * from './BrandAssetGrid';
export * from './ThemeSelector';
export * from './GlobalEngineSelector';
export * from './GlobalProductionParameters';
export * from './copySettingsConfig';
export { DynamicSection } from './DynamicSection';
export { ReferenceAdsSection, getReferenceAdEntries, countFilledReferenceAds, REFERENCE_AD_PREFIX } from './ReferenceAdsSection';
export { CopyTypeSelector } from './CopyTypeSelector';
export { AccordionSection } from './AccordionSection';
export { SearchableSelect } from './SearchableSelect';
export type { SearchableSelectOption, SearchableSelectProps } from './SearchableSelect';
export { ProjectPicker, resolveProjectId, EMPTY_PROJECT_PICK } from './ProjectPicker';
export type { ProjectPickerValue, ProjectPickerProps, ProjectOption } from './ProjectPicker';

