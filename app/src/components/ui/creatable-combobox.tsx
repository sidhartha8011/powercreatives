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
import { useEffect, useRef, useState } from "react";
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

  // Reset search when popover closes
  useEffect(() => {
    if (!open) setSearch("");
  }, [open]);

  // Determine if the typed text is a new value (not in options)
  const trimmedSearch = search.trim();
  const exactMatch = options.some(
    (opt) => opt.toLowerCase() === trimmedSearch.toLowerCase()
  );
  const showCreate = trimmedSearch.length > 0 && !exactMatch;

  const handleSelect = (selected: string) => {
    onChange(selected);
    setOpen(false);
  };

  const handleCreate = () => {
    onChange(trimmedSearch);
    setOpen(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onChange(null);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          ref={triggerRef}
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled}
          className={cn(
            "justify-between font-normal bg-transparent",
            compact ? "h-8 text-xs px-2 gap-1" : "h-9 text-sm px-3 gap-2",
            !value && "text-muted-foreground",
            className
          )}
        >
          <span className="truncate flex-1 text-left">
            {value || placeholder}
          </span>
          <span className="flex items-center gap-0.5 shrink-0">
            {value && !disabled && (
              <X
                className={cn(
                  "opacity-50 hover:opacity-100 transition-opacity cursor-pointer",
                  compact ? "h-3 w-3" : "h-3.5 w-3.5"
                )}
                onClick={handleClear}
              />
            )}
            <ChevronsUpDown
              className={cn(
                "opacity-50 shrink-0",
                compact ? "h-3 w-3" : "h-3.5 w-3.5"
              )}
            />
          </span>
        </Button>
      </PopoverTrigger>
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
