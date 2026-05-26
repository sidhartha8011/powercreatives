/**
 * SearchableSelect — multi-select combobox.
 *
 * Built on the exact shadcn pattern already used in the codebase by
 * Copy/TemplateDropdown:  Popover + Command + CommandInput + CommandList +
 * CommandEmpty + CommandGroup + CommandItem.
 *
 * `cmdk` handles the instant search filtering automatically when
 * `shouldFilter` is true — no custom filter loop needed. The popover
 * background, border, and shadow come from the shared shadcn theme; we
 * don't override them here.
 */

import { useMemo, useRef, useState } from 'react';
import { Check, ChevronsUpDown } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
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
  const triggerRef = useRef<HTMLButtonElement>(null);

  const selectedSet = useMemo(() => new Set(selected), [selected]);

  const visibleOptions = useMemo(() => {
    if (!maxOptions || options.length <= maxOptions) return options;
    return options.slice(0, maxOptions);
  }, [options, maxOptions]);

  const toggle = (value: string) => {
    const next = selectedSet.has(value)
      ? selected.filter((v) => v !== value)
      : [...selected, value];
    onChange(next);
  };

  const clearAll = () => {
    onChange([]);
  };

  // Trigger summary mirrors the Notion/Linear pill convention.
  const summary =
    selected.length === 0
      ? label
      : selected.length === 1
        ? `${label}: ${optionLabel(options, selected[0])}`
        : `${label}: ${selected.length}`;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          ref={triggerRef}
          type="button"
          variant="ghost"
          size="sm"
          role="combobox"
          aria-expanded={open}
          aria-label={ariaLabel ?? label}
          className={cn(
            styles.trigger,
            selected.length > 0 && styles.triggerActive
          )}
        >
          <span className={styles.triggerLabel}>{summary}</span>
          <ChevronsUpDown className="h-3 w-3 opacity-60 shrink-0" aria-hidden="true" />
        </Button>
      </PopoverTrigger>

      <PopoverContent className="p-0 w-[260px]" align="start">
        <Command shouldFilter>
          <CommandInput
            placeholder={searchPlaceholder ?? `Search ${label.toLowerCase()}…`}
            className="h-9 text-sm"
          />
          <CommandList>
            <CommandEmpty>No matches.</CommandEmpty>
            <CommandGroup>
              {visibleOptions.map((option) => {
                const isSelected = selectedSet.has(option.value);
                return (
                  <CommandItem
                    key={option.value}
                    value={option.label}
                    onSelect={() => toggle(option.value)}
                    className="text-sm"
                  >
                    <Check
                      className={cn(
                        'mr-2 h-3.5 w-3.5 shrink-0',
                        isSelected ? 'opacity-100' : 'opacity-0'
                      )}
                      aria-hidden="true"
                    />
                    <span className="truncate flex-1">{option.label}</span>
                    {typeof option.count === 'number' && (
                      <span className="text-[10px] text-muted-foreground ml-2 shrink-0 tabular-nums">
                        {option.count}
                      </span>
                    )}
                  </CommandItem>
                );
              })}
            </CommandGroup>
          </CommandList>

          {selected.length > 0 && (
            <div className="border-t px-2 py-1.5 text-right">
              <button
                type="button"
                onClick={clearAll}
                className="text-[11px] font-medium text-muted-foreground hover:text-foreground transition-colors px-1.5 py-0.5 rounded"
              >
                Clear selection
              </button>
            </div>
          )}
        </Command>
      </PopoverContent>
    </Popover>
  );
}

function optionLabel(
  options: ReadonlyArray<FilterOption>,
  value: string
): string {
  return options.find((o) => o.value === value)?.label ?? value;
}
