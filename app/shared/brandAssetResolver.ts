/**
 * BRAND ASSET RESOLVER
 *
 * Single source of truth for resolving brand assets by their role.
 *
 * Contract: every brand asset is created with an explicit `role` field.
 * Producers (upload mutations) set it. The v1.4.0 schema migration
 * backfills legacy assets so this assumption holds for all data.
 *
 * Consumers MUST use these resolvers — do not duplicate the lookup logic.
 * If an asset is missing here, the right fix is to ensure the producer
 * sets the role, NOT to add a fallback in the consumer.
 */
import type { BrandAsset } from './brandTypes';

/** Minimal brand shape this module needs. Avoid coupling to the full Brand type. */
type BrandLike = { assets?: BrandAsset[] | null } | null | undefined;

/**
 * Get the brand's logo asset, or undefined if none is set.
 *
 * Caller MUST handle the undefined case explicitly (e.g. UI prompt to upload a logo).
 * Do not fall back to assets[0] — that masks missing data.
 */
export function getBrandLogo(brand: BrandLike): BrandAsset | undefined {
  return brand?.assets?.find((a) => a.role === 'logo');
}

/**
 * Get all certification assets for the brand (empty array if none).
 */
export function getBrandCertifications(brand: BrandLike): BrandAsset[] {
  return brand?.assets?.filter((a) => a.role === 'certification') ?? [];
}

/**
 * Get all reference image assets for the brand (empty array if none).
 */
export function getBrandReferences(brand: BrandLike): BrandAsset[] {
  return brand?.assets?.filter((a) => a.role === 'reference') ?? [];
}
