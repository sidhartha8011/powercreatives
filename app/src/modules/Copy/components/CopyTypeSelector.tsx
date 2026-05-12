/**
 * COPY TYPE SELECTOR
 *
 * Compact inline checkboxes for each copy type (Social Ads, Social Organic).
 * Both can be selected simultaneously.
 * Minimalistic: no icons, no descriptions, single row.
 */

import type { CopyType, CopyTypeSelection } from '../types';
import { COPY_TYPE_LABELS } from '../types';

interface Props {
  selection: CopyTypeSelection;
  onChange: (selection: CopyTypeSelection) => void;
}

export function CopyTypeSelector({ selection, onChange }: Props) {
  const toggle = (type: CopyType) => {
    onChange({ ...selection, [type]: !selection[type] });
  };

  const types: CopyType[] = ['social_ads', 'social_organic'];

  return (
    <div className="flex gap-2">
      {types.map((type) => {
        const isActive = selection[type];
        return (
          <button
            key={type}
            type="button"
            onClick={() => toggle(type)}
            className="flex items-center gap-1.5 rounded-lg border px-3 py-2 text-left transition-all flex-1"
            style={{
              borderColor: isActive ? '#007bff' : '#e5e7eb',
              background: isActive ? '#f0f7ff' : '#fff',
            }}
          >
            {/* Checkbox */}
            <div
              className="flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border"
              style={{
                borderColor: isActive ? '#007bff' : '#d1d5db',
                background: isActive ? '#007bff' : '#fff',
              }}
            >
              {isActive && (
                <svg className="h-2.5 w-2.5 text-white" viewBox="0 0 12 12" fill="none">
                  <path d="M2 6l3 3 5-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              )}
            </div>

            {/* Label only — no icon, no description */}
            <span
              className="text-xs font-semibold whitespace-nowrap"
              style={{ color: isActive ? '#007bff' : '#333' }}
            >
              {COPY_TYPE_LABELS[type]}
            </span>
          </button>
        );
      })}
    </div>
  );
}
