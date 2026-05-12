/**
 * TEMPLATE DROPDOWN — Searchable
 *
 * DB-backed template selector used by Copy, Writer, and any future module.
 * Fetches templates from the Templates module (filtered by moduleName).
 *
 * API contract: emits a single `onTemplateApply` callback with both the
 * templateId and entries, so consumers can update their state atomically.
 * This prevents race conditions in single-state-object consumers (Writer).
 */

import { cn } from "@/lib/utils";
import { trpc } from "@/lib/trpc";
import { colors, typography } from "@/components/shared";
import { Check, ChevronsUpDown, FileText, X } from "lucide-react";
import { PanelHeader } from "@/components/shared/PanelHeader";
import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from "@/components/ui/command";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";

/** Payload emitted when a template is selected or cleared. */
export interface TemplateApplyPayload {
  templateId: number | undefined;
  entries: TemplateEntryData[] | null;
}

interface Props {
  selectedTemplateId: number | undefined;
  /** Single callback for template selection (select or clear). */
  onTemplateApply: (payload: TemplateApplyPayload) => void;
  /** Which module to fetch templates for (default: 'copy') */
  moduleName?: "copy" | "writer";
}

/** Minimal template entry shape for population (avoids importing server types) */
export interface TemplateEntryData {
  key: string;
  category: string;
  label: string;
  value: string;
}

export function TemplateDropdown({ selectedTemplateId, onTemplateApply, moduleName = "copy" }: Props) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const triggerRef = useRef<HTMLButtonElement>(null);

  // Fetch all templates from the Templates module
  const { data: templates, isLoading } = trpc.templates.list.useQuery({
    module: moduleName,
  });

  const templateList = templates ?? [];

  // Find the selected template for display
  const selectedTemplate = templateList.find(
    (t) => t.id === selectedTemplateId
  );

  // Reset search when popover closes
  useEffect(() => {
    if (!open) setSearch("");
  }, [open]);

  const handleSelect = (templateId: number) => {
    if (templateId === selectedTemplateId) {
      onTemplateApply({ templateId: undefined, entries: null });
    } else {
      const tpl = templateList.find((t) => t.id === templateId);
      const entries = tpl ? (tpl.entries ?? []) as unknown as TemplateEntryData[] : null;
      onTemplateApply({ templateId, entries });
    }
    setOpen(false);
  };

  const handleClear = (e: React.MouseEvent) => {
    e.stopPropagation();
    onTemplateApply({ templateId: undefined, entries: null });
  };

  // Group templates by niche (if available)
  const grouped = templateList.reduce(
    (acc, tpl) => {
      const group = tpl.niche || "__ungrouped__";
      if (!acc[group]) acc[group] = [];
      acc[group].push(tpl);
      return acc;
    },
    {} as Record<string, typeof templateList>
  );

  const groupKeys = Object.keys(grouped).sort((a, b) => {
    if (a === "__ungrouped__") return 1;
    if (b === "__ungrouped__") return -1;
    return a.localeCompare(b);
  });

  // Check if we have any niches at all (to decide whether to show groups)
  const hasNiches = groupKeys.some((k) => k !== "__ungrouped__");

  return (
    <div
      className="rounded-lg border bg-white overflow-hidden"
    >
      <PanelHeader title="Templates" />
      <div className="p-3 space-y-2">

      <Popover open={open} onOpenChange={setOpen}>
        <div className="flex items-center gap-0">
          <PopoverTrigger asChild>
            <Button
              ref={triggerRef}
              variant="outline"
              role="combobox"
              aria-expanded={open}
              disabled={isLoading}
              className={cn(
                "w-full justify-between font-normal h-9 text-sm px-3 gap-2",
                selectedTemplate ? "rounded-r-none border-r-0" : "",
                !selectedTemplate && "text-muted-foreground"
              )}
              style={{
                borderColor: selectedTemplate ? colors.primary : "#e5e7eb",
                background: "#fff",
              }}
            >
              <span className="truncate flex-1 text-left">
                {isLoading
                  ? "Loading templates..."
                  : selectedTemplate
                    ? `${selectedTemplate.name}${selectedTemplate.isDefault ? " (default)" : ""}`
                    : "Select a template..."}
              </span>
              <span className="flex items-center gap-0.5 shrink-0">
                <ChevronsUpDown className="h-3.5 w-3.5 opacity-50 shrink-0" />
              </span>
            </Button>
          </PopoverTrigger>
          {selectedTemplate && (
            <button
              type="button"
              onClick={handleClear}
              className="inline-flex items-center justify-center h-9 w-8 rounded-r-md border border-l-0 hover:bg-muted transition-colors"
              style={{
                borderColor: colors.primary,
                background: "#fff",
              }}
              aria-label="Clear template selection"
            >
              <X className="h-3.5 w-3.5 opacity-50 hover:opacity-100 transition-opacity" />
            </button>
          )}
        </div>
        <PopoverContent
          className="p-0"
          style={{
            width: triggerRef.current
              ? Math.max(triggerRef.current.offsetWidth, 240)
              : 240,
          }}
          align="start"
        >
          <Command shouldFilter={true}>
            <CommandInput
              placeholder="Search templates..."
              value={search}
              onValueChange={setSearch}
              className="h-9 text-sm border-0 focus:ring-0 focus-visible:ring-0 outline-none shadow-none ring-0 placeholder:text-muted-foreground bg-transparent"
              style={{ outline: "none", boxShadow: "none" }}
            />
            <CommandList>
              <CommandEmpty>No templates found.</CommandEmpty>

              {hasNiches ? (
                /* Grouped by niche */
                groupKeys.map((groupKey) => (
                  <CommandGroup
                    key={groupKey}
                    heading={
                      groupKey === "__ungrouped__" ? "Other" : groupKey
                    }
                  >
                    {grouped[groupKey].map((tpl) => (
                      <CommandItem
                        key={tpl.id}
                        value={`${tpl.name} ${tpl.niche ?? ""} ${tpl.groupName ?? ""}`}
                        onSelect={() => handleSelect(tpl.id)}
                        className="text-sm"
                      >
                        <Check
                          className={cn(
                            "mr-2 h-3.5 w-3.5 shrink-0",
                            selectedTemplateId === tpl.id
                              ? "opacity-100"
                              : "opacity-0"
                          )}
                        />
                        <span className="truncate flex-1">
                          {tpl.name}
                          {tpl.isDefault ? " (default)" : ""}
                        </span>
                        {tpl.groupName && (
                          <span className="text-[10px] text-muted-foreground ml-1 shrink-0">
                            {tpl.groupName}
                          </span>
                        )}
                      </CommandItem>
                    ))}
                  </CommandGroup>
                ))
              ) : (
                /* Flat list (no niches) */
                <CommandGroup>
                  {templateList.map((tpl) => (
                    <CommandItem
                      key={tpl.id}
                      value={`${tpl.name} ${tpl.groupName ?? ""}`}
                      onSelect={() => handleSelect(tpl.id)}
                      className="text-sm"
                    >
                      <Check
                        className={cn(
                          "mr-2 h-3.5 w-3.5 shrink-0",
                          selectedTemplateId === tpl.id
                            ? "opacity-100"
                            : "opacity-0"
                        )}
                      />
                      <span className="truncate flex-1">
                        {tpl.name}
                        {tpl.isDefault ? " (default)" : ""}
                      </span>
                      {tpl.groupName && (
                        <span className="text-[10px] text-muted-foreground ml-1 shrink-0">
                          {tpl.groupName}
                        </span>
                      )}
                    </CommandItem>
                  ))}
                </CommandGroup>
              )}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>

      {/* Show entry count when a template is selected */}
      {selectedTemplate && (
        <p className="text-xs" style={{ color: colors.textSecondary }}>
          {(selectedTemplate.entries as unknown[])?.length ?? 0} entries populated
          into form fields
        </p>
      )}

      {/* Empty state */}
      {!isLoading && templateList.length === 0 && (
        <p className="text-xs" style={{ color: colors.textSecondary }}>
          No copy templates yet. Create one in the Templates module.
        </p>
      )}
      </div>
    </div>
  );
}
