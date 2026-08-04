/**
 * SearchableSelect — a single-choice dropdown with a search box inside it.
 *
 * The filter counterpart to `@/components/ui/creatable-combobox`: same Popover +
 * Command (cmdk) primitives, same trigger shape, but it can only ever pick from
 * the options given — there is deliberately NO "Create «typed text»" row, because
 * filtering by a value that does not exist can only ever return nothing.
 *
 * Options are `{ value, label }` so callers filter by a stable id while the user
 * reads a name. Selecting the "all" row (or the trigger's ✕) clears the filter.
 *
 * Usage:
 *   <SearchableSelect
 *     options={[{ value: '11', label: 'Bright Tandhälsa' }]}
 *     value={brandId}
 *     onChange={setBrandId}
 *     placeholder="Brand"
 *     allLabel="All brands"
 *   />
 */

import { useEffect, useRef, useState } from 'react';
import { Check, ChevronsUpDown, X } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';

export interface SearchableSelectOption {
  /** Stable value stored in filter state (e.g. a numeric id as a string). */
  value: string;
  /** Human-readable text shown in the list and on the trigger. */
  label: string;
}

export interface SearchableSelectProps {
  options: ReadonlyArray<SearchableSelectOption>;
  /** Currently selected value, or null for "no filter". */
  value: string | null;
  /** Emitted with the new value, or null when cleared. */
  onChange: (value: string | null) => void;
  /** Trigger text while nothing is selected (e.g. "Brand"). */
  placeholder?: string;
  /** Label of the leading "clear this filter" row (e.g. "All brands"). */
  allLabel?: string;
  /** Placeholder inside the search box. */
  searchPlaceholder?: string;
  /** Text shown when the search matches nothing. */
  emptyLabel?: string;
  className?: string;
  disabled?: boolean;
  /** Accessible name for the trigger; falls back to `placeholder`. */
  ariaLabel?: string;
}

export function SearchableSelect({
  options,
  value,
  onChange,
  placeholder = 'Select…',
  allLabel = 'All',
  searchPlaceholder = 'Search…',
  emptyLabel = 'No matches',
  className,
  disabled = false,
  ariaLabel,
}: SearchableSelectProps) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState('');
  const triggerRef = useRef<HTMLButtonElement>(null);

  // Reset the query when the popover closes, so re-opening starts clean.
  useEffect(() => {
    if (!open) setSearch('');
  }, [open]);

  const selected = value != null ? options.find((o) => o.value === value) ?? null : null;

  const handleSelect = (next: string | null) => {
    onChange(next);
    setOpen(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange(null);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      {/* The clear button is a SIBLING of the trigger, not a child of it.
          Radix toggles the popover on pointerdown, so an X nested inside the
          trigger opened the list before its own click handler ran — the clear
          looked dead. A separate control also matches what it is: clearing the
          value is not "open the list". */}
      <div className={cn('relative inline-flex items-center', className)}>
        <PopoverTrigger asChild>
          <Button
            ref={triggerRef}
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            aria-label={ariaLabel ?? placeholder}
            disabled={disabled}
            className={cn(
              'h-9 w-full justify-between gap-2 bg-card px-3 text-sm font-normal',
              !selected && 'text-muted-foreground',
              selected && !disabled && 'pr-14'
            )}
          >
            <span className="flex-1 truncate text-left">{selected?.label ?? placeholder}</span>
            <ChevronsUpDown className="h-3.5 w-3.5 shrink-0 opacity-50" aria-hidden="true" />
          </Button>
        </PopoverTrigger>

        {selected && !disabled && (
          <button
            type="button"
            onClick={handleClear}
            aria-label={`Clear ${ariaLabel ?? placeholder}`}
            title="Clear"
            className="absolute right-8 inline-flex h-5 w-5 items-center justify-center rounded-sm text-muted-foreground opacity-60 transition-opacity hover:opacity-100 focus-visible:opacity-100"
          >
            <X className="h-3.5 w-3.5" aria-hidden="true" />
          </button>
        )}
      </div>

      <PopoverContent
        className="p-0"
        align="start"
        style={{
          width: triggerRef.current ? Math.max(triggerRef.current.offsetWidth, 220) : 220,
        }}
      >
        <Command shouldFilter>
          <CommandInput
            placeholder={searchPlaceholder}
            value={search}
            onValueChange={setSearch}
            className="h-9 text-sm"
          />
          <CommandList>
            <CommandEmpty>{emptyLabel}</CommandEmpty>

            {/* Clear row — always reachable, never filtered out by the query. */}
            {selected && (
              <>
                <CommandGroup>
                  <CommandItem value={allLabel} onSelect={() => handleSelect(null)} className="text-sm">
                    <Check className="mr-2 h-3.5 w-3.5 shrink-0 opacity-0" aria-hidden="true" />
                    <span className="truncate text-muted-foreground">{allLabel}</span>
                  </CommandItem>
                </CommandGroup>
                <CommandSeparator />
              </>
            )}

            <CommandGroup>
              {options.map((option) => (
                <CommandItem
                  key={option.value}
                  value={option.label}
                  onSelect={() => handleSelect(option.value)}
                  className="text-sm"
                >
                  <Check
                    className={cn(
                      'mr-2 h-3.5 w-3.5 shrink-0',
                      value === option.value ? 'opacity-100' : 'opacity-0'
                    )}
                    aria-hidden="true"
                  />
                  <span className="truncate">{option.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
