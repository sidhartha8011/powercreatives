/**
 * SearchableSelect — popover with instant-search multiselect.
 *
 * Generic over the underlying option type. Built on shadcn Command + Popover
 * + Checkbox. Renders option counts when supplied. Empty / no-match state
 * built in.
 *
 * Used by the Kanban toolbar — never imported directly by domain modules.
 */

import { useMemo, useState } from 'react';
import { Check, ChevronDown, Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';

import styles from '../filters.module.css';
import type { FilterOption } from '../types';

export interface SearchableSelectProps {
  label: string;
  options: ReadonlyArray<FilterOption>;
  selected: ReadonlyArray<string>;
  onChange: (next: string[]) => void;
  searchPlaceholder?: string;
  maxOptions?: number;
  /** ARIA label fallback when the trigger label is opaque. */
  ariaLabel?: string;
}

export function SearchableSelect({
  label,
  options,
  selected,
  onChange,
  searchPlaceholder,
  maxOptions,
  ariaLabel,
}: SearchableSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');

  const filteredOptions = useMemo(() => {
    const q = query.trim().toLowerCase();
    let list = options;
    if (q) {
      list = options.filter((o) => o.label.toLowerCase().includes(q));
    }
    if (maxOptions && list.length > maxOptions) {
      list = list.slice(0, maxOptions);
    }
    return list;
  }, [options, query, maxOptions]);

  const selectedSet = useMemo(() => new Set(selected), [selected]);

  const toggle = (value: string) => {
    const next = selectedSet.has(value)
      ? selected.filter((v) => v !== value)
      : [...selected, value];
    onChange(next);
  };

  const clear = () => {
    onChange([]);
    setQuery('');
  };

  const summary = selected.length === 0
    ? label
    : selected.length === 1
      ? `${label}: ${selectedLabel(options, selected[0])}`
      : `${label}: ${selected.length}`;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-label={ariaLabel ?? label}
          aria-haspopup="listbox"
          aria-expanded={open}
          className={`${styles.trigger} ${selected.length > 0 ? styles.triggerActive : ''}`}
        >
          <span className={styles.triggerLabel}>{summary}</span>
          <ChevronDown className={styles.triggerChevron} aria-hidden="true" />
        </Button>
      </PopoverTrigger>

      <PopoverContent
        align="start"
        sideOffset={6}
        className={styles.popoverContent}
      >
        <div className={styles.searchRow}>
          <Search className={styles.searchIcon} aria-hidden="true" />
          <input
            type="search"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={searchPlaceholder ?? `Search ${label.toLowerCase()}…`}
            className={styles.searchInput}
            aria-label={`Search ${label.toLowerCase()}`}
            autoFocus
          />
        </div>

        <div className={styles.optionList} role="listbox" aria-multiselectable="true">
          {filteredOptions.length === 0 ? (
            <div className={styles.optionEmpty}>No matches.</div>
          ) : (
            filteredOptions.map((option) => {
              const isSelected = selectedSet.has(option.value);
              return (
                <button
                  type="button"
                  key={option.value}
                  role="option"
                  aria-selected={isSelected}
                  onClick={() => toggle(option.value)}
                  className={`${styles.optionRow} ${isSelected ? styles.optionRowActive : ''}`}
                >
                  <Checkbox
                    checked={isSelected}
                    tabIndex={-1}
                    aria-hidden="true"
                    className={styles.optionCheckbox}
                  />
                  <span className={styles.optionLabel}>{option.label}</span>
                  {typeof option.count === 'number' && (
                    <span className={styles.optionCount}>{option.count}</span>
                  )}
                  {isSelected && (
                    <Check className={styles.optionCheck} aria-hidden="true" />
                  )}
                </button>
              );
            })
          )}
        </div>

        {selected.length > 0 && (
          <div className={styles.popoverFooter}>
            <button
              type="button"
              onClick={clear}
              className={styles.popoverFooterAction}
            >
              Clear selection
            </button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}

function selectedLabel(
  options: ReadonlyArray<FilterOption>,
  value: string
): string {
  return options.find((o) => o.value === value)?.label ?? value;
}
