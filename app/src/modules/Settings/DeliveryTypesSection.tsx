/**
 * DeliveryTypesSection — central management of delivery-type presets.
 *
 * Each delivery type (SEO, Google Ads, Meta Ads, …) maps to the set of
 * modules a delivery of that type needs; picking a type in the Delivery
 * dialog pre-fills its module grants. The mapping is stored in
 * pcm_settings.delivery_type_presets (POST /settings, admin-only) and read
 * back through GET /deliveries/type-presets, which falls back to the
 * built-in defaults when the setting is empty — so "Reset to defaults"
 * simply clears the override.
 */

import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Plus, Save, Trash2, Undo2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { apiFetch } from '@/lib/trpc';
import { GRANTABLE_MODULES } from '@/modules/Deliveries/types';
import {
  TYPE_PRESETS_QUERY_KEY,
  useTypePresets,
} from '@/modules/Deliveries/hooks/useTypePresets';

interface TypeRow {
  key: string;
  label: string;
  modules: string[];
}

/** Stable key for a new type, derived from its label (mirrors sanitize_key). */
function slugify(label: string): string {
  return label
    .toLowerCase()
    .replace(/[^a-z0-9_\s-]/g, '')
    .trim()
    .replace(/[\s-]+/g, '_');
}

export function DeliveryTypesSection() {
  const { presets, isLoading } = useTypePresets();
  const queryClient = useQueryClient();

  const [rows, setRows] = useState<TypeRow[]>([]);
  const [newLabel, setNewLabel] = useState('');
  const [dirty, setDirty] = useState(false);
  const [saving, setSaving] = useState(false);

  // Seed the editor from the server once per load; afterwards local edits
  // own the state until saved (don't clobber in-progress edits on refetch).
  useEffect(() => {
    if (isLoading || dirty) return;
    setRows(
      Object.entries(presets).map(([key, p]) => ({
        key,
        label: p.label,
        modules: [...p.modules],
      }))
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isLoading, presets]);

  const mutateRows = (updater: (prev: TypeRow[]) => TypeRow[]) => {
    setRows(updater);
    setDirty(true);
  };

  const toggleModule = (key: string, moduleId: string) =>
    mutateRows((prev) =>
      prev.map((row) =>
        row.key === key
          ? {
              ...row,
              modules: row.modules.includes(moduleId)
                ? row.modules.filter((m) => m !== moduleId)
                : [...row.modules, moduleId],
            }
          : row
      )
    );

  const renameRow = (key: string, label: string) =>
    mutateRows((prev) => prev.map((row) => (row.key === key ? { ...row, label } : row)));

  const removeRow = (key: string) =>
    mutateRows((prev) => prev.filter((row) => row.key !== key));

  const addRow = () => {
    const label = newLabel.trim();
    if (!label) return;
    const key = slugify(label);
    if (!key) {
      toast.error('Type name must contain letters or numbers');
      return;
    }
    if (rows.some((row) => row.key === key)) {
      toast.error('A type with this name already exists');
      return;
    }
    mutateRows((prev) => [...prev, { key, label, modules: [] }]);
    setNewLabel('');
  };

  const persist = async (value: Record<string, { label: string; modules: string[] }> | null) => {
    setSaving(true);
    try {
      await apiFetch('settings', {
        method: 'POST',
        body: JSON.stringify({ delivery_type_presets: value }),
      });
      await queryClient.invalidateQueries({ queryKey: TYPE_PRESETS_QUERY_KEY });
      setDirty(false);
      toast.success(value === null ? 'Delivery types reset to defaults' : 'Delivery types saved');
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to save delivery types');
    } finally {
      setSaving(false);
    }
  };

  const handleSave = () => {
    const invalid = rows.find((row) => !row.label.trim());
    if (invalid) {
      toast.error('Every delivery type needs a name');
      return;
    }
    void persist(
      Object.fromEntries(
        rows.map((row) => [row.key, { label: row.label.trim(), modules: row.modules }])
      )
    );
  };

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <h3 className="text-lg font-medium">Delivery types</h3>
        <p className="text-sm text-muted-foreground">
          Central mapping of delivery type → modules needed. Picking a type
          when creating a delivery pre-fills its module grants for assignees.
        </p>
      </div>

      {isLoading && rows.length === 0 ? (
        <p className="text-sm text-muted-foreground">Loading…</p>
      ) : (
        <div className="space-y-4">
          {rows.map((row) => (
            <div key={row.key} className="rounded-lg border p-4 space-y-3">
              <div className="flex items-center gap-2">
                <Input
                  value={row.label}
                  onChange={(e) => renameRow(row.key, e.target.value)}
                  maxLength={64}
                  className="max-w-xs"
                  aria-label="Delivery type name"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  onClick={() => removeRow(row.key)}
                  aria-label={`Remove ${row.label}`}
                >
                  <Trash2 className="w-4 h-4 text-destructive" />
                </Button>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                {GRANTABLE_MODULES.map((m) => (
                  <div key={m.id} className="flex items-center gap-2">
                    <Checkbox
                      id={`type-${row.key}-${m.id}`}
                      checked={row.modules.includes(m.id)}
                      onCheckedChange={() => toggleModule(row.key, m.id)}
                    />
                    <Label
                      htmlFor={`type-${row.key}-${m.id}`}
                      className="cursor-pointer font-normal"
                    >
                      {m.label}
                    </Label>
                  </div>
                ))}
              </div>
            </div>
          ))}

          <div className="flex items-center gap-2">
            <Input
              value={newLabel}
              onChange={(e) => setNewLabel(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') {
                  e.preventDefault();
                  addRow();
                }
              }}
              placeholder="New type name (e.g. LinkedIn Ads)"
              maxLength={64}
              className="max-w-xs"
            />
            <Button type="button" variant="outline" onClick={addRow} className="gap-2">
              <Plus className="w-4 h-4" />
              Add type
            </Button>
          </div>

          <div className="flex items-center gap-2 pt-2">
            <Button type="button" onClick={handleSave} disabled={saving || !dirty} className="gap-2">
              <Save className="w-4 h-4" />
              {saving ? 'Saving…' : 'Save delivery types'}
            </Button>
            <Button
              type="button"
              variant="ghost"
              disabled={saving}
              onClick={() => void persist(null)}
              className="gap-2"
            >
              <Undo2 className="w-4 h-4" />
              Reset to defaults
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
