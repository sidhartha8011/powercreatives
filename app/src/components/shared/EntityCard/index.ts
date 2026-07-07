/**
 * EntityCard — shared Notion-style detail-card primitive.
 *
 * Domain-agnostic (Kanban-primitive philosophy): modules compose the shell,
 * title, declarative property table and sections; data + domain sections
 * stay module-owned. Reference consumer: Deliveries (DeliveryDialog).
 */

export { EntityCard } from './EntityCard';
export { EntityCardTitle } from './EntityCardTitle';
export { EntityCardSection } from './EntityCardSection';
export { PropertyTable, type PropertyDef, type SelectOption, type ToggleOption } from './PropertyTable';
export { CARD_TABLE_WRAPPER, CARD_TABLE_HEAD, CARD_TABLE_ROW, CARD_TABLE_CELL } from './tableRecipe';
export { relTime } from './relTime';
