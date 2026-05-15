/**
 * SHARED COMPONENTS — Barrel Export
 *
 * Import everything from '@/components/shared' for convenience:
 *   import { PillButton, SectionCard, SectionLabel, colors } from '@/components/shared';
 */

export { PillButton } from './PillButton';
export type { PillButtonVariant } from './PillButton';
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
export { ContextPanel, createEmptyContextData } from './ContextPanel';
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
export { AsyncSelectField, UNSELECTED } from './AsyncSelectField';
export type { SelectOption } from './AsyncSelectField';
