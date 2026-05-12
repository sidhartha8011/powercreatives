/**
 * Shared helpers for mapping DB records → SelectOption[].
 *
 * Used by any dialog/panel that needs brand, site, or template dropdowns
 * via AsyncSelectField. Single source of truth for the mapping logic.
 */

import type { SelectOption } from '@/components/shared';
import { UNSELECTED } from '@/components/shared';

// ── Record types ──

export interface NamedRecord {
  id: number;
  name: string;
}

export interface SiteRecord extends NamedRecord {
  url: string;
}

// ── Mappers ──

export function toOptions(items: NamedRecord[] | undefined): SelectOption[] {
  return (items ?? []).map((item) => ({
    value: String(item.id),
    label: item.name,
  }));
}

export function toSiteOptions(sites: SiteRecord[]): SelectOption[] {
  return sites.map((s) => ({
    value: String(s.id),
    label: `${s.name} — ${s.url}`,
  }));
}

// ── Value parsing ──

export function parseId(value: string): number | undefined {
  return value !== UNSELECTED ? parseInt(value, 10) : undefined;
}
