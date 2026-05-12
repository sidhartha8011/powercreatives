/**
 * IMAGE MODULE — Types
 *
 * Module-specific type definitions.
 * Shared types (ContextData, Brand, etc.) are imported from their source of truth.
 */

export type DetailLevelValue = 1 | 2 | 3;
export type DetailLevelEnum = "brief" | "moderate" | "detailed";

/** Map numeric detail level to enum string for backend */
export function toDetailLevelEnum(value: DetailLevelValue): DetailLevelEnum {
  const map: Record<DetailLevelValue, DetailLevelEnum> = {
    1: "brief",
    2: "moderate",
    3: "detailed",
  };
  return map[value];
}
