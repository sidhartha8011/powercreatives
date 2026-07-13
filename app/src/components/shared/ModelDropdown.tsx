/**
 * ModelDropdown — THE model picker (extracted from Copy's ResultsPanel by
 * owner order 2026-07-13: one dropdown, shared everywhere, never welded into
 * a module again). Pill trigger with the selected model's name; floating
 * panel grouped by pricing tier (uppercase headers), selected row bold.
 * Feed it `groups` from useTextModels (or any {label, models} grouping).
 */

import { useState, useEffect, useRef } from 'react';
import { ChevronDown } from 'lucide-react';

import { colors, typography } from './design-tokens';

export interface ModelDropdownGroup {
  label: string;
  models: { id: string; name: string }[];
}

interface ModelDropdownProps {
  modelGroups: ModelDropdownGroup[];
  selectedModel: string;
  onModelChange: (id: string) => void;
  disabled?: boolean;
}

// Thin pill on a SOLID white surface (owner 2026-07-13: transparent triggers
// leaked the background through).
const TRIGGER_STYLE = {
  padding: '3px 10px',
  borderRadius: '9999px',
  fontSize: typography.xs,
  fontWeight: typography.medium,
  color: colors.textMuted,
  background: colors.bgSurface,
  border: `1px solid ${colors.border}`,
  cursor: 'pointer',
} as const;

export function ModelDropdown({ modelGroups, selectedModel, onModelChange, disabled }: ModelDropdownProps) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    if (open) document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [open]);

  const allModels = modelGroups.flatMap((g) => g.models);
  const selectedName = allModels.find((m) => m.id === selectedModel)?.name ?? 'Select model';

  return (
    <div className="relative" ref={ref}>
      <button type="button" disabled={disabled} onClick={() => setOpen(!open)} style={TRIGGER_STYLE} className="flex items-center gap-1.5 disabled:opacity-60">
        <span className="truncate max-w-[120px]">{selectedName}</span>
        <ChevronDown className="w-3 h-3 shrink-0" />
      </button>
      {open && (
        <div
          className="absolute right-0 top-full mt-1 z-50 rounded-lg py-1 max-h-60 overflow-y-auto"
          style={{
            background: colors.bgSurface,
            border: `1px solid ${colors.border}`,
            minWidth: '200px',
          }}
        >
          {modelGroups.map((group) => (
            <div key={group.label}>
              <div
                className="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider"
                style={{ color: colors.textFaint }}
              >
                {group.label}
              </div>
              {group.models.map((model) => (
                <button
                  type="button"
                  key={model.id}
                  onClick={() => {
                    onModelChange(model.id);
                    setOpen(false);
                  }}
                  className="w-full text-left px-3 py-1.5 text-xs transition-colors hover:bg-gray-50"
                  style={{
                    color: model.id === selectedModel ? colors.text : colors.textSecondary,
                    fontWeight: model.id === selectedModel ? '600' : '400',
                  }}
                >
                  {model.name}
                </button>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
