/**
 * KanbanToolbar — generic filter + sort UI.
 *
 * Reads FilterDefinitions and SortDefinitions and renders the appropriate
 * control for each. Knows nothing about the underlying domain.
 *
 * The toolbar is decoupled from the board — consumers may render it
 * anywhere in their layout. The shared `useListState` hook produces the
 * state object both consume.
 */

import { useMemo, type ReactNode } from 'react';

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import {
  BooleanControl,
  DateRangeControl,
  SearchableSelect,
  TextControl,
} from './filters/controls';
import filterStyles from './filters/filters.module.css';
import type {
  FilterDefinition,
  FilterOption,
  FilterValue,
  SortDefinition,
} from './filters/types';
import type { UseListStateResult } from './filters/useListState';

export interface KanbanToolbarProps<T extends { id: string | number }> {
  /** Source items used to derive option counts for searchableSelect. */
  items: ReadonlyArray<T>;
  filters: ReadonlyArray<FilterDefinition<T>>;
  sorts: ReadonlyArray<SortDefinition<T>>;
  state: UseListStateResult<T>;
  /** Optional ARIA label for the toolbar landmark. */
  ariaLabel?: string;
  /**
   * Optional content rendered at the start of the toolbar row (left of
   * the filter pills). Typically a view-switcher (e.g. PillTabBar) when
   * the consumer wants one unified bar instead of two stacked rows.
   */
  leadingSlot?: ReactNode;
}

export function KanbanToolbar<T extends { id: string | number }>({
  items,
  filters,
  sorts,
  state,
  ariaLabel = 'Filter and sort',
  leadingSlot,
}: KanbanToolbarProps<T>) {
  return (
    <div className={filterStyles.toolbar} role="toolbar" aria-label={ariaLabel}>
      {leadingSlot}

      <div className={filterStyles.toolbarLeft}>
        {filters.map((filter) => (
          <FilterControl
            key={filter.id}
            filter={filter}
            items={items}
            value={state.filterState[filter.id]}
            onChange={(next) => state.setFilterValue(filter.id, next)}
          />
        ))}

        {state.activeFilterCount > 0 && (
          <button
            type="button"
            onClick={state.clearAll}
            className={filterStyles.clearAll}
          >
            Clear all
          </button>
        )}
      </div>

      {sorts.length > 0 && (
        <div className={filterStyles.toolbarRight}>
          <span className={filterStyles.sortLabel} id="pck-sort-label">
            Sort
          </span>
          <Select
            value={state.sortId ?? '__none__'}
            onValueChange={(value) =>
              state.setSortId(value === '__none__' ? null : value)
            }
          >
            <SelectTrigger
              size="sm"
              aria-labelledby="pck-sort-label"
              className={filterStyles.trigger}
            >
              <SelectValue placeholder="None" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__none__">None</SelectItem>
              {sorts.map((sort) => (
                <SelectItem key={sort.id} value={sort.id}>
                  {sort.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
    </div>
  );
}

// ─── Per-filter dispatch ─────────────────────────────────────────

interface FilterControlProps<T> {
  filter: FilterDefinition<T>;
  items: ReadonlyArray<T>;
  value: FilterValue | undefined;
  onChange: (next: FilterValue | undefined) => void;
}

function FilterControl<T>({
  filter,
  items,
  value,
  onChange,
}: FilterControlProps<T>) {
  // Hooks called unconditionally regardless of control kind. Cheap when
  // unused (memoized empty result) and keeps Rules-of-Hooks happy.
  const derivedOptions = useDerivedOptions(filter, items);

  switch (filter.control.kind) {
    case 'searchableSelect': {
      const selected =
        value?.kind === 'searchableSelect' ? value.selected : [];
      return (
        <SearchableSelect
          label={filter.label}
          options={derivedOptions}
          selected={selected}
          searchPlaceholder={filter.control.searchPlaceholder}
          maxOptions={filter.control.maxOptions}
          onChange={(next) =>
            onChange(
              next.length === 0
                ? undefined
                : { kind: 'searchableSelect', selected: next }
            )
          }
        />
      );
    }

    case 'boolean': {
      const v = value?.kind === 'boolean' ? value.value : false;
      return (
        <BooleanControl
          label={filter.label}
          toggleLabel={filter.control.toggleLabel}
          value={v}
          onChange={(next) =>
            onChange(next ? { kind: 'boolean', value: true } : undefined)
          }
        />
      );
    }

    case 'dateRange': {
      const from = value?.kind === 'dateRange' ? value.from : undefined;
      const to = value?.kind === 'dateRange' ? value.to : undefined;
      return (
        <DateRangeControl
          label={filter.label}
          from={from}
          to={to}
          onChange={({ from: nextFrom, to: nextTo }) =>
            onChange(
              nextFrom || nextTo
                ? { kind: 'dateRange', from: nextFrom, to: nextTo }
                : undefined
            )
          }
        />
      );
    }

    case 'text': {
      const query = value?.kind === 'text' ? value.query : '';
      return (
        <TextControl
          label={filter.label}
          query={query}
          placeholder={filter.control.placeholder}
          onChange={(next) =>
            onChange(next ? { kind: 'text', query: next } : undefined)
          }
        />
      );
    }
  }
}

// ─── Option derivation for searchableSelect ───────────────────────

function useDerivedOptions<T>(
  filter: FilterDefinition<T>,
  items: ReadonlyArray<T>
): FilterOption[] {
  return useMemo(() => {
    if (filter.getOptions) return filter.getOptions(items);

    const counts = new Map<string, number>();
    for (const item of items) {
      const raw = filter.getValue(item);
      if (raw == null) continue;
      const values = Array.isArray(raw) ? raw : [raw];
      for (const v of values) {
        if (v == null || v === '') continue;
        counts.set(v, (counts.get(v) ?? 0) + 1);
      }
    }

    const options: FilterOption[] = Array.from(counts, ([value, count]) => ({
      value,
      label: value,
      count,
    }));

    options.sort((a, b) => a.label.localeCompare(b.label));
    return options;
  }, [filter, items]);
}
