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

import { SearchableSelect, type SearchableSelectOption } from './SearchableSelect';

export interface ProjectOption {
  id: number;
  name: string;
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
  /** Delivery for the project being created (null = deliberately no delivery). */
  newProjectDeliveryId: number | null;
}

export const EMPTY_PROJECT_PICK: ProjectPickerValue = {
  projectId: null,
  newProjectName: null,
  newProjectDeliveryId: null,
};

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

      {value.newProjectName !== null && (
        <div className="space-y-1.5 pt-1">
          <Label htmlFor="pcm-project-delivery">Delivery for “{value.newProjectName}”</Label>
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
 * Turn a picker value into the projectId to store on the set, creating the
 * project first when the user typed a new name. Throws on failure so the caller
 * aborts instead of quietly saving a set with no project.
 *
 * @param createProject `trpc.assets.createProject.mutateAsync`
 */
export async function resolveProjectId(
  value: ProjectPickerValue,
  createProject: (input: {
    name: string;
    deliveryId: number | null;
  }) => Promise<unknown>
): Promise<number | null> {
  if (value.newProjectName === null) return value.projectId;

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
