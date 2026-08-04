/**
 * CreatableCombobox — searchable dropdown with create-on-type.
 *
 * Built on shadcn Popover + Command (cmdk). Provides:
 *   - Search/filter through existing options
 *   - "Create: <typed text>" option when no exact match exists
 *   - Clear button to remove selection
 *   - Compact inline variant for table cells
 *
 * Usage:
 *   <CreatableCombobox
 *     options={["Group A", "Group B"]}
 *     value={selectedGroup}
 *     onChange={setSelectedGroup}
 *     placeholder="Select or create group..."
 *     emptyLabel="No groups found"
 *   />
 */

import { cn } from "@/lib/utils";
import { Check, ChevronsUpDown, Plus, X } from "lucide-react";
import { useRef, useState } from "react";
import { Button } from "./button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
} from "./command";
import { Popover, PopoverContent, PopoverTrigger } from "./popover";

export interface CreatableComboboxProps {
  /** Available options to choose from */
  options: string[];
  /** Currently selected value (null = no selection) */
  value: string | null;
  /** Called when selection changes */
  onChange: (value: string | null) => void;
  /** Placeholder text when nothing is selected */
  placeholder?: string;
  /** Label shown when search yields no results (before "Create:" option) */
  emptyLabel?: string;
  /** Additional class names for the trigger button */
  className?: string;
  /** Compact mode for table cells */
  compact?: boolean;
  /** Disabled state */
  disabled?: boolean;
}

export function CreatableCombobox({
  options,
  value,
  onChange,
  placeholder = "Select or create...",
  emptyLabel = "No results found",
  className,
  compact = false,
  disabled = false,
}: CreatableComboboxProps) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const triggerRef = useRef<HTMLButtonElement>(null);

  /**
   * Open/close, clearing the query IN THE SAME UPDATE as the close.
   *
   * This was a `useEffect` on [open]. An effect runs after the commit, so a
   * close produced two renders: the first started the exit animation with the
   * list still filtered, the second re-rendered that still-visible list
   * unfiltered — the full option set flashed back before fading. Batching the
   * two state changes gives one render and no flash.
   */
  const setOpenState = (next: boolean) => {
    if (!next) setSearch("");
    setOpen(next);
  };

  // Determine if the typed text is a new value (not in options)
  const trimmedSearch = search.trim();
  const exactMatch = options.some(
    (opt) => opt.toLowerCase() === trimmedSearch.toLowerCase()
  );
  const showCreate = trimmedSearch.length > 0 && !exactMatch;

  const handleSelect = (selected: string) => {
    onChange(selected);
    setOpenState(false);
  };

  const handleCreate = () => {
    onChange(trimmedSearch);
    setOpenState(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange(null);
  };

  return (
    <Popover open={open} onOpenChange={setOpenState}>
      {/* The clear button is a SIBLING of the trigger, not nested inside it.
          Radix toggles the popover on pointerdown, so an X inside the trigger
          opened the list before its own click handler ran — the clear read as
          broken. Same fix as components/shared/SearchableSelect. */}
      <div className={cn("relative inline-flex items-center", className)}>
        <PopoverTrigger asChild>
          <Button
            ref={triggerRef}
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className={cn(
              "w-full justify-between font-normal bg-transparent",
              compact ? "h-8 text-xs px-2 gap-1" : "h-9 text-sm px-3 gap-2",
              !value && "text-muted-foreground",
              // Unconditional: reserves room for the chevron AND the clear
              // button, so picking a value never re-lays-out the label.
              compact ? "pr-11" : "pr-14"
            )}
          >
            <span className="truncate flex-1 text-left">
              {value || placeholder}
            </span>
            <ChevronsUpDown
              className={cn(
                "opacity-50 shrink-0",
                compact ? "h-3 w-3" : "h-3.5 w-3.5"
              )}
            />
          </Button>
        </PopoverTrigger>

        {value && !disabled && (
          <button
            type="button"
            onClick={handleClear}
            aria-label="Clear selection"
            title="Clear"
            className={cn(
              "absolute inline-flex items-center justify-center rounded-sm text-muted-foreground opacity-60 transition-opacity hover:opacity-100 focus-visible:opacity-100",
              compact ? "right-6 h-4 w-4" : "right-8 h-5 w-5"
            )}
          >
            <X className={compact ? "h-3 w-3" : "h-3.5 w-3.5"} />
          </button>
        )}
      </div>
      <PopoverContent
        className="p-0"
        style={{
          width: triggerRef.current
            ? Math.max(triggerRef.current.offsetWidth, 220)
            : 220,
        }}
        align="start"
      >
        <Command shouldFilter={true}>
          <CommandInput
            placeholder="Search or type to create..."
            value={search}
            onValueChange={setSearch}
            className={compact ? "h-8 text-xs" : "h-9 text-sm"}
          />
          <CommandList>
            {/* Existing options */}
            {options.length > 0 && (
              <CommandGroup>
                {options.map((option) => (
                  <CommandItem
                    key={option}
                    value={option}
                    onSelect={() => handleSelect(option)}
                    className={compact ? "text-xs py-1" : "text-sm"}
                  >
                    <Check
                      className={cn(
                        "mr-2 h-3.5 w-3.5 shrink-0",
                        value === option ? "opacity-100" : "opacity-0"
                      )}
                    />
                    <span className="truncate">{option}</span>
                  </CommandItem>
                ))}
              </CommandGroup>
            )}

            {/* Empty state (only when no options match AND no create) */}
            {!showCreate && options.length === 0 && (
              <CommandEmpty>{emptyLabel}</CommandEmpty>
            )}

            {/* Create new option */}
            {showCreate && (
              <>
                {options.length > 0 && <CommandSeparator />}
                <CommandGroup>
                  <CommandItem
                    value={`__create__${trimmedSearch}`}
                    onSelect={handleCreate}
                    className={cn(
                      "text-primary",
                      compact ? "text-xs py-1" : "text-sm"
                    )}
                  >
                    <Plus className="mr-2 h-3.5 w-3.5 shrink-0" />
                    <span className="truncate">
                      Create &ldquo;{trimmedSearch}&rdquo;
                    </span>
                  </CommandItem>
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}
