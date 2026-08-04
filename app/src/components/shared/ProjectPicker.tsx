/**
 * ProjectPicker — the mapping row: Project · Delivery · Brand.
 *
 * An approval set is mapped to a PROJECT and nothing else (owner ruling
 * 2026-08-04). The project carries the delivery, the delivery carries the brand
 * — derived live by PCM_Hierarchy, never stored on the thing attached to the
 * project. So all three are shown, but only the project is the mapping; delivery
 * and brand are the chain around it, editable because editing them re-links the
 * PROJECT, not the set.
 *
 * Any one of the three narrows the other two to what actually exists:
 *
 *   pick Brand    → deliveries narrow to that brand → projects narrow to those
 *   pick Delivery → projects narrow to that delivery → brand fills in from it
 *   pick Project  → delivery + brand fill in from its own chain (visibly mapped)
 *
 * The project field also creates: type a name that is not in the list and it is
 * created on submit, with whatever delivery is selected beside it. Choosing a
 * delivery for an existing project that has none links it — and leaving it on
 * "No delivery" is a first-class answer that saves fine.
 *
 * Nothing is written until the caller runs `resolveProjectId()` on submit.
 */

import { useMemo } from 'react';

import { CreatableCombobox } from '@/components/ui/creatable-combobox';
import { Label } from '@/components/ui/label';
import { trpc } from '@/lib/trpc';

import { SearchableSelect, type SearchableSelectOption } from './SearchableSelect';

export interface ProjectOption {
  id: number;
  name: string;
  /** The delivery this project belongs to, or null when it has none. */
  deliveryId?: number | null;
}

export interface DeliveryOption {
  id: number;
  name: string;
  /** The brand this delivery belongs to, or null. */
  brandId?: number | null;
}

export interface BrandOption {
  id: number;
  name: string;
}

/** What the picker holds. Exactly one of projectId / newProjectName is set. */
export interface ProjectPickerValue {
  /** An existing project's id. */
  projectId: number | null;
  /** Name of a project to create on submit. */
  newProjectName: string | null;
  /**
   * The delivery beside the project: the one a new project is created with, the
   * one an unlinked existing project gets linked to, or simply the chain value
   * of a project that already has one. `null` = no delivery, a valid answer.
   */
  deliveryId: number | null;
  /** The brand — derived from the delivery, or chosen to narrow the others. */
  brandId: number | null;
}

export const EMPTY_PROJECT_PICK: ProjectPickerValue = {
  projectId: null,
  newProjectName: null,
  deliveryId: null,
  brandId: null,
};

/**
 * The three lists this row runs on, normalised once. Every create dialog needed
 * the identical `Array.isArray(raw) ? raw.map(...) : []` boundary code — three
 * copies, three chances to forget a field (deliveryId was exactly that field).
 *
 * wpdb serialises BIGINT columns as strings, hence the Number() coercion.
 */
export function useProjectPickerData(): {
  projects: ProjectOption[];
  deliveries: DeliveryOption[];
  brands: BrandOption[];
} {
  // basic=1 — names + links only; skips the asset/copy aggregation this view never reads.
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery({ basic: 1 });
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();
  const { data: brandsRaw } = trpc.brands.list.useQuery();

  const projects = useMemo<ProjectOption[]>(
    () =>
      (Array.isArray(projectsRaw) ? (projectsRaw as any[]) : []).map((p) => ({
        id: Number(p.id),
        name: String(p.name ?? ''),
        deliveryId: p.deliveryId != null ? Number(p.deliveryId) : null,
      })),
    [projectsRaw]
  );

  const deliveries = useMemo<DeliveryOption[]>(
    () =>
      (Array.isArray(deliveriesRaw) ? (deliveriesRaw as any[]) : []).map((d) => ({
        id: Number(d.id),
        name: String(d.name ?? ''),
        brandId: d.brandId != null ? Number(d.brandId) : null,
      })),
    [deliveriesRaw]
  );

  const brands = useMemo<BrandOption[]>(
    () =>
      (Array.isArray(brandsRaw) ? (brandsRaw as any[]) : []).map((b) => ({
        id: Number(b.id),
        name: String(b.name ?? ''),
      })),
    [brandsRaw]
  );

  return { projects, deliveries, brands };
}

/**
 * Build unique combobox labels. Two projects may legitimately share a name, so a
 * duplicated name is disambiguated with its id rather than silently resolving to
 * whichever row happened to come first.
 */
function buildLabels(projects: ReadonlyArray<ProjectOption>): {
  labels: string[];
  byLabel: Map<string, number>;
  byId: Map<number, string>;
} {
  const counts = new Map<string, number>();
  for (const p of projects) counts.set(p.name, (counts.get(p.name) ?? 0) + 1);

  const byLabel = new Map<string, number>();
  const byId = new Map<number, string>();
  const labels: string[] = [];

  for (const p of projects) {
    const label = (counts.get(p.name) ?? 0) > 1 ? `${p.name} (#${p.id})` : p.name;
    labels.push(label);
    byLabel.set(label, p.id);
    byId.set(p.id, label);
  }
  return { labels, byLabel, byId };
}

export interface ProjectPickerProps {
  projects: ReadonlyArray<ProjectOption>;
  deliveries: ReadonlyArray<DeliveryOption>;
  brands?: ReadonlyArray<BrandOption>;
  value: ProjectPickerValue;
  onChange: (value: ProjectPickerValue) => void;
  disabled?: boolean;
}

export function ProjectPicker({
  projects,
  deliveries,
  brands = [],
  value,
  onChange,
  disabled = false,
}: ProjectPickerProps) {
  // ── Narrowing, in one direction each ──────────────────────────
  // Brand narrows deliveries; brand+delivery narrow projects. A picked project
  // is always kept in its own list even if the current filters would exclude it,
  // so the field can never display a value its dropdown doesn't contain.
  const visibleDeliveries = useMemo(
    () =>
      value.brandId == null
        ? deliveries
        : deliveries.filter((d) => d.brandId === value.brandId),
    [deliveries, value.brandId]
  );

  const visibleProjects = useMemo(() => {
    let list = projects;
    if (value.deliveryId != null) {
      list = list.filter((p) => p.deliveryId === value.deliveryId);
    } else if (value.brandId != null) {
      const ids = new Set(visibleDeliveries.map((d) => d.id));
      list = list.filter((p) => p.deliveryId != null && ids.has(p.deliveryId));
    }
    if (value.projectId != null && !list.some((p) => p.id === value.projectId)) {
      const picked = projects.find((p) => p.id === value.projectId);
      if (picked) list = [picked, ...list];
    }
    return list;
  }, [projects, visibleDeliveries, value.deliveryId, value.brandId, value.projectId]);

  const { labels, byLabel, byId } = useMemo(() => buildLabels(visibleProjects), [visibleProjects]);

  const deliveryOptions = useMemo<SearchableSelectOption[]>(
    () => visibleDeliveries.map((d) => ({ value: String(d.id), label: d.name })),
    [visibleDeliveries]
  );
  const brandOptions = useMemo<SearchableSelectOption[]>(
    () => brands.map((b) => ({ value: String(b.id), label: b.name })),
    [brands]
  );

  const comboValue =
    value.newProjectName ?? (value.projectId != null ? byId.get(value.projectId) ?? null : null);

  /** The delivery a project already belongs to (null when unlinked/new). */
  const linkedDeliveryOf = (projectId: number | null): number | null => {
    if (projectId == null) return null;
    return projects.find((p) => p.id === projectId)?.deliveryId ?? null;
  };

  const brandOfDelivery = (deliveryId: number | null): number | null => {
    if (deliveryId == null) return null;
    return deliveries.find((d) => d.id === deliveryId)?.brandId ?? null;
  };

  // ── Handlers: each one resolves the chain it implies ──────────
  const handleProjectChange = (next: string | null) => {
    if (next === null) {
      onChange({ ...value, projectId: null, newProjectName: null });
      return;
    }
    const existingId = byLabel.get(next);
    if (existingId != null) {
      // Existing project: its own chain wins for display. An unlinked project
      // keeps whatever delivery is currently selected — that becomes its link.
      const linked = linkedDeliveryOf(existingId);
      const deliveryId = linked ?? value.deliveryId;
      onChange({
        projectId: existingId,
        newProjectName: null,
        deliveryId,
        brandId: brandOfDelivery(deliveryId) ?? value.brandId,
      });
      return;
    }
    // Not in the list → a new project, created on submit with the delivery beside it.
    onChange({ ...value, projectId: null, newProjectName: next });
  };

  const handleDeliveryChange = (next: string | null) => {
    const deliveryId = next != null ? Number(next) : null;
    // Drop a picked project that doesn't belong to the new delivery — showing a
    // project under a delivery it isn't in would be a lie.
    const keepProject =
      value.projectId != null &&
      (deliveryId == null || linkedDeliveryOf(value.projectId) === deliveryId ||
        linkedDeliveryOf(value.projectId) == null);
    onChange({
      ...value,
      deliveryId,
      brandId: brandOfDelivery(deliveryId) ?? value.brandId,
      projectId: keepProject ? value.projectId : null,
    });
  };

  const handleBrandChange = (next: string | null) => {
    const brandId = next != null ? Number(next) : null;
    // Drop a delivery (and any project under it) that isn't in the new brand.
    const deliveryStillValid =
      value.deliveryId == null || brandId == null || brandOfDelivery(value.deliveryId) === brandId;
    onChange({
      ...value,
      brandId,
      deliveryId: deliveryStillValid ? value.deliveryId : null,
      projectId: deliveryStillValid ? value.projectId : null,
    });
  };

  const deliveryHint =
    value.newProjectName !== null
      ? `New project “${value.newProjectName}” will be created here`
      : value.projectId != null && linkedDeliveryOf(value.projectId) == null
        ? 'This project has no delivery — pick one to link it (optional)'
        : null;

  return (
    <>
      <div className="space-y-1.5">
        <Label htmlFor="pcm-project-pick">Project</Label>
        <CreatableCombobox
          options={labels}
          value={comboValue}
          onChange={handleProjectChange}
          placeholder="No project"
          emptyLabel="No projects yet"
          disabled={disabled}
          className="w-full bg-card"
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="pcm-project-delivery">Delivery</Label>
        <SearchableSelect
          options={deliveryOptions}
          value={value.deliveryId != null ? String(value.deliveryId) : null}
          onChange={handleDeliveryChange}
          placeholder="No delivery"
          allLabel="No delivery"
          searchPlaceholder="Search deliveries…"
          emptyLabel="No deliveries match"
          disabled={disabled}
          className="w-full"
          ariaLabel="Delivery"
        />
        {deliveryHint && (
          <p className="text-xs text-muted-foreground">{deliveryHint}</p>
        )}
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="pcm-project-brand">Brand</Label>
        <SearchableSelect
          options={brandOptions}
          value={value.brandId != null ? String(value.brandId) : null}
          onChange={handleBrandChange}
          placeholder="No brand"
          allLabel="No brand"
          searchPlaceholder="Search brands…"
          emptyLabel="No brands match"
          disabled={disabled}
          className="w-full"
          ariaLabel="Brand"
        />
      </div>
    </>
  );
}

/**
 * Turn a picker value into the projectId to store on the set — the ONE
 * choke-point every create dialog goes through, so none of them learns the rules:
 *
 *   - typed a new name  → create the project (with the delivery beside it);
 *   - existing project with NO delivery + a delivery chosen → link it;
 *   - existing project that already HAS a delivery → left alone (re-pointing it
 *     belongs to the Projects registry, not to a create dialog);
 *   - no delivery at all → nothing happens, and that is a valid outcome.
 *
 * Creation failure throws, so the caller aborts rather than quietly saving a set
 * with no project.
 *
 * @param createProject `trpc.assets.createProject.mutateAsync`
 * @param setProjectDelivery `trpc.assets.setProjectDelivery.mutateAsync`
 */
export async function resolveProjectId(
  value: ProjectPickerValue,
  createProject: (input: { name: string; deliveryId: number | null }) => Promise<unknown>,
  setProjectDelivery?: (input: { id: number; deliveryId: number | null }) => Promise<unknown>,
  /** The projects list, so an already-linked project is never re-pointed. */
  projects: ReadonlyArray<ProjectOption> = []
): Promise<number | null> {
  if (value.newProjectName === null) {
    const alreadyLinked =
      value.projectId != null
        ? projects.find((p) => p.id === value.projectId)?.deliveryId ?? null
        : null;

    if (
      value.projectId != null &&
      value.deliveryId != null &&
      alreadyLinked == null &&
      setProjectDelivery
    ) {
      await setProjectDelivery({ id: value.projectId, deliveryId: value.deliveryId });
    }
    return value.projectId;
  }

  const created = (await createProject({
    name: value.newProjectName,
    deliveryId: value.deliveryId,
  })) as { id?: unknown } | null;

  const id = Number(created?.id);
  if (!Number.isFinite(id) || id <= 0) {
    throw new Error('The project could not be created, so the set was not saved.');
  }
  return id;
}
