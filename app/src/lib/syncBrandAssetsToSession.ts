/**
 * SYNC BRAND ASSETS TO SESSION
 *
 * Reusable utility for syncing brand assets into session reference images.
 * Used by module-level onChange handlers (Image, Video) to keep
 * session reference images in sync with the active brand's assets.
 *
 * Sync triggers:
 *   - Brand selection changes (brandId differs)
 *   - Same brand but assets updated (e.g. logo saved via URL-fetch dialog)
 *
 * Design:
 *   - Pure function (no side effects, no hooks)
 *   - Returns null if no sync needed → caller skips update
 *   - Preserves user-uploaded session images (fromBrand: false)
 *   - Uses deterministic IDs (brand-{fileKey}) to avoid DOM re-mounts
 *
 * @module syncBrandAssetsToSession
 */

import type { ContextData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import type { BrandAsset } from '@shared/brandTypes';

/**
 * Compare previous and new ContextData to determine if brand assets changed.
 * If changed, returns a new merged array of [brand images, ...user images].
 * If no change, returns null (caller should skip the state update).
 *
 * @param prevData - Previous ContextData (from current render)
 * @param newData  - New ContextData (from onChange callback)
 * @param currentSession - Current session reference images
 * @returns Merged array if sync needed, null otherwise
 */
export function syncBrandAssetsToSession(
  prevData: ContextData,
  newData: ContextData,
  currentSession: SessionReferenceImage[],
): SessionReferenceImage[] | null {
  // Detect what changed
  const brandChanged = newData.brandId !== prevData.brandId;
  const prevAssets = ((prevData.brand as any)?.assets as BrandAsset[] | null) ?? [];
  const newAssets = ((newData.brand as any)?.assets as BrandAsset[] | null) ?? [];
  const assetsChanged = newAssets.length !== prevAssets.length;

  // No sync needed — brandId same AND asset count same
  if (!brandChanged && !assetsChanged) return null;

  // Map brand assets → SessionReferenceImage format
  const fromBrand: SessionReferenceImage[] = newAssets.map((a) => ({
    id: `brand-${a.fileKey}`,
    url: a.url,
    filename: a.filename ?? 'Brand asset',
    intent: 'auto' as const,
    fromBrand: true,
    fileKey: a.fileKey,
  }));

  // Preserve user-uploaded images (not from brand)
  const userOnly = currentSession.filter((img) => !img.fromBrand);

  return [...fromBrand, ...userOnly];
}
