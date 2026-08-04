/**
 * ProjectPicker — the ONE control for "which project does this belong to?".
 *
 * An approval set is mapped to a PROJECT and nothing else (owner ruling
 * 2026-08-04). The project carries the delivery, and the delivery carries the
 * brand — derived live by PCM_Hierarchy, never stored on the thing attached to
 * the project. So this control asks for a project, and asks for a delivery ONLY
 * while creating a new project (that maps the PROJECT, not the set).
 *
 * Search + "Create «typed name»" come from the shared CreatableCombobox; the
 * delivery row is the shared SearchableSelect. "No delivery" is a real, supported
 * answer — a project without a delivery simply has no brand either.
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
  /**
   * The delivery this project already belongs to, or null when it has none.
   * `GET /assets/projects` returns it, so "does this project need linking?" is
   * answerable without another call.
   */
  deliveryId?: number | null;
}

export interface DeliveryOption {
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
   * Delivery to attach to the project on submit — for one being CREATED, or for
   * a chosen existing project that has none. `null` = no delivery, which is a
   * first-class answer: the set saves fine without it.
   */
  newProjectDeliveryId: number | null;
}

export const EMPTY_PROJECT_PICK: ProjectPickerValue = {
  projectId: null,
  newProjectName: null,
  newProjectDeliveryId: null,
};

/**
 * The two lists this picker runs on, normalised once. Every create dialog needed
 * the identical `Array.isArray(raw) ? raw.map(Number(id), String(name)) : []`
 * pair — three copies of the same boundary code, which is three chances for one
 * of them to forget a field (deliveryId was exactly that field).
 *
 * wpdb serialises BIGINT columns as strings, hence the Number() coercion.
 */
export function useProjectPickerData(): {
  projects: ProjectOption[];
  deliveries: DeliveryOption[];
} {
  const { data: projectsRaw } = trpc.assets.getProjects.useQuery();
  const { data: deliveriesRaw } = trpc.deliveries.list.useQuery();

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
      })),
    [deliveriesRaw]
  );

  return { projects, deliveries };
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
  value: ProjectPickerValue;
  onChange: (value: ProjectPickerValue) => void;
  disabled?: boolean;
  /** Label above the project field. */
  label?: string;
}

export function ProjectPicker({
  projects,
  deliveries,
  value,
  onChange,
  disabled = false,
  label = 'Project',
}: ProjectPickerProps) {
  const { labels, byLabel, byId } = useMemo(() => buildLabels(projects), [projects]);

  const deliveryOptions = useMemo<SearchableSelectOption[]>(
    () => deliveries.map((d) => ({ value: String(d.id), label: d.name })),
    [deliveries]
  );

  const comboValue =
    value.newProjectName ?? (value.projectId != null ? byId.get(value.projectId) ?? null : null);

  /** The picked existing project, when one is picked. */
  const pickedProject =
    value.projectId != null ? projects.find((p) => p.id === value.projectId) ?? null : null;

  /**
   * Offer the delivery row when the project is being CREATED, or when the picked
   * one has no delivery yet. A project that already has one is left alone —
   * re-pointing it belongs to the Projects registry, not to a create dialog.
   */
  const offerDeliveryLink =
    value.newProjectName !== null ||
    (pickedProject !== null && pickedProject.deliveryId == null);

  const deliveryRowLabel =
    value.newProjectName !== null
      ? `Delivery for “${value.newProjectName}”`
      : `Link “${pickedProject?.name ?? ''}” to a delivery (optional)`;

  const handleComboChange = (next: string | null) => {
    if (next === null) {
      onChange(EMPTY_PROJECT_PICK);
      return;
    }
    const existingId = byLabel.get(next);
    if (existingId != null) {
      onChange({ projectId: existingId, newProjectName: null, newProjectDeliveryId: null });
      return;
    }
    // Not in the list → the user typed a new project name.
    onChange({ projectId: null, newProjectName: next, newProjectDeliveryId: null });
  };

  return (
    <div className="space-y-1.5">
      <Label htmlFor="pcm-project-pick">{label}</Label>
      <CreatableCombobox
        options={labels}
        value={comboValue}
        onChange={handleComboChange}
        placeholder="No project"
        emptyLabel="No projects yet"
        disabled={disabled}
        className="w-full bg-card"
      />

      {offerDeliveryLink && (
        <div className="space-y-1.5 pt-1">
          <Label htmlFor="pcm-project-delivery">{deliveryRowLabel}</Label>
          <SearchableSelect
            options={deliveryOptions}
            value={
              value.newProjectDeliveryId != null ? String(value.newProjectDeliveryId) : null
            }
            onChange={(next) =>
              onChange({ ...value, newProjectDeliveryId: next != null ? Number(next) : null })
            }
            placeholder="No delivery"
            allLabel="No delivery"
            searchPlaceholder="Search deliveries…"
            emptyLabel="No deliveries match"
            disabled={disabled}
            className="w-full"
            ariaLabel="Delivery for the new project"
          />
        </div>
      )}
    </div>
  );
}

/**
 * Turn a picker value into the projectId to store on the set — the ONE
 * choke-point every create dialog goes through, so none of them learns the rules:
 *
 *   - typed a new name  → create the project (with its delivery, if chosen);
 *   - picked an existing project + chose a delivery → attach it to that project;
 *   - chose no delivery → nothing happens, and that is a valid outcome.
 *
 * Creation failure throws, so the caller aborts rather than quietly saving a set
 * with no project. Attaching a delivery to an EXISTING project does not throw:
 * the project is real and the set is about to be valid either way, so a failed
 * link is surfaced to the caller as a rejected promise ONLY for the create case.
 *
 * @param createProject `trpc.assets.createProject.mutateAsync`
 * @param setProjectDelivery `trpc.assets.setProjectDelivery.mutateAsync`
 */
export async function resolveProjectId(
  value: ProjectPickerValue,
  createProject: (input: {
    name: string;
    deliveryId: number | null;
  }) => Promise<unknown>,
  setProjectDelivery?: (input: { id: number; deliveryId: number | null }) => Promise<unknown>
): Promise<number | null> {
  if (value.newProjectName === null) {
    // Existing project: attach the delivery the user picked for it, if any.
    if (value.projectId != null && value.newProjectDeliveryId != null && setProjectDelivery) {
      await setProjectDelivery({
        id: value.projectId,
        deliveryId: value.newProjectDeliveryId,
      });
    }
    return value.projectId;
  }

  const created = (await createProject({
    name: value.newProjectName,
    deliveryId: value.newProjectDeliveryId,
  })) as { id?: unknown } | null;

  const id = Number(created?.id);
  if (!Number.isFinite(id) || id <= 0) {
    throw new Error('The project could not be created, so the set was not saved.');
  }
  return id;
}
