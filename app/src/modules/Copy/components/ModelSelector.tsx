/**
 * MODEL SELECTOR (Per Copy Type)
 *
 * Dropdown that shows text-capable models grouped by pricing tier.
 * Each copy type gets its own independent selector.
 */

import type { CopyType } from '../types';
import { COPY_TYPE_LABELS } from '../types';
import { useTextModels, type TextModelGroup } from '../useTextModels';

interface Props {
  copyType: CopyType;
  selectedModelId: string | undefined;
  onChange: (modelId: string) => void;
}

const TIER_COLORS: Record<string, string> = {
  budget: '#16a34a',
  standard: '#ca8a04',
  premium: '#ef4444',
};

const TIER_ICONS: Record<string, string> = {
  budget: '$',
  standard: '$$',
  premium: '$$$',
};

export function ModelSelector({ copyType, selectedModelId, onChange }: Props) {
  const { groups, isLoading, hasRegisteredModels } = useTextModels();

  return (
    <div>
      <label
        className="flex items-center gap-1.5 text-xs font-semibold mb-1.5"
        style={{ color: '#555' }}
      >
        Model for {COPY_TYPE_LABELS[copyType]}
        {!hasRegisteredModels && (
          <span
            className="ml-1 px-1.5 py-0.5 rounded text-[10px] font-medium"
            style={{ background: '#dbeafe', color: '#1e40af' }}
          >
            BUILT-IN
          </span>
        )}
      </label>

      <select
        value={selectedModelId || ''}
        onChange={(e) => onChange(e.target.value)}
        disabled={isLoading}
        className="w-full rounded-md border px-3 py-2 text-sm outline-none focus:ring-2"
        style={{
          borderColor: '#e5e7eb',
          background: '#fff',
          color: selectedModelId ? '#1a1a1a' : '#999',
        }}
      >
        <option value="">Select a model…</option>
        {groups.map((group: TextModelGroup) => (
          <optgroup
            key={group.tier}
            label={`${TIER_ICONS[group.tier]} ${group.label}`}
          >
            {group.models.map((model) => (
              <option key={model.id} value={model.id}>
                {model.name} ({model.provider})
              </option>
            ))}
          </optgroup>
        ))}
      </select>
    </div>
  );
}
