import type { ModuleId } from '@/types';

/**
 * THE MODULE VERSION REGISTRY (owner order 2026-07-17, gap
 * GAP-ANALYSIS-MODULE-VERSION-STAMP): every module carries its OWN version,
 * shown bottom-left of the active module by the Shell's single stamp mount.
 *
 * RITUAL LAW: any pair that touches a module's frontend BUMPS that module's
 * version HERE in the same commit. The stamp is only worth what this
 * discipline keeps it — the build stamp (__PCM_BUILD__) stays the machine
 * truth either way.
 */
export const MODULE_VERSIONS: Record<ModuleId, string> = {
  brands: '1.0.0',
  deliveries: '1.0.0',
  projects: '1.0.0',
  assets: '1.0.0',
  copy: '1.0.0',
  text: '1.0.0', // alias of copy (Shell registry maps both to CopyModule)
  image: '1.0.0',
  templates: '1.0.0',
  video: '1.0.0',
  settings: '1.0.0',
  integrations: '1.0.0',
  keywords: '1.0.0',
  writer: '1.0.0',
  strategies: '1.0.0',
  sites: '1.0.0',
  approvals: '1.0.0',
  ads: '1.0.0',
  automations: '1.0.0',
  users: '1.0.0',
  seo: '1.0.5', // the business card: per-site resolved record, mapping, overrides
  logs: '1.0.0',
};
