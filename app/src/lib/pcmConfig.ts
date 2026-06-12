/**
 * Typed readers for the `pcmConfig` global injected by WordPress
 * (wp_localize_script in class-pcm-admin.php / class-pcm-shortcode.php).
 *
 * Everything here is UX sugar — the REST layer enforces module/brand
 * grants server-side regardless of what the frontend shows.
 */

export interface DeliveryTypePreset {
  label: string;
  modules: string[];
}

interface PcmConfigShape {
  user?: {
    /** 'admin' (WP manage_options) or 'user'. */
    role?: string;
    /** Map of granted module id → usable brand ids; null = unrestricted. */
    brandsByModule?: Record<string, number[]> | number[] | null;
  };
  /** Central delivery-type → module presets (PCM_Deliveries_Service). */
  deliveryTypePresets?: Record<string, DeliveryTypePreset> | unknown[];
}

function getConfig(): PcmConfigShape {
  return (window as unknown as { pcmConfig?: PcmConfigShape }).pcmConfig ?? {};
}

/**
 * Whether the current user is an admin. Defaults to true when the config is
 * unavailable (gate visitors / dev) — server capabilities are authoritative,
 * this only drives UI affordances.
 */
export function getIsAdmin(): boolean {
  const role = getConfig().user?.role;
  return role === undefined || role === 'admin';
}

/** Delivery type presets keyed by type id. Empty when unavailable. */
export function getDeliveryTypePresets(): Record<string, DeliveryTypePreset> {
  const presets = getConfig().deliveryTypePresets;
  // PHP serializes an empty assoc array as [] — guard both shapes.
  if (!presets || Array.isArray(presets)) return {};
  return presets;
}

/**
 * Brand ids the current user may use inside the given module, or null when
 * unrestricted (admins, gate visitors, or config unavailable).
 */
export function getBrandsForModule(moduleId: string): number[] | null {
  const map = getConfig().user?.brandsByModule;
  if (map === null || map === undefined) return null;
  if (Array.isArray(map)) return []; // empty PHP map → no grants
  const ids = map[moduleId];
  return Array.isArray(ids) ? ids.map(Number) : [];
}
