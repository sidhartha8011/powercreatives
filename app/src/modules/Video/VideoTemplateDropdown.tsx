/**
 * VIDEO TEMPLATE DROPDOWN — Scene, Recipe & Enhancement Selector
 *
 * Reusable dropdown for selecting video templates (scene frameworks or type recipes).
 * Follows the same pattern as Copy's TemplateDropdown but simplified for video:
 * - Fetches from the shared Templates module with module='video' + type filter
 * - Returns the selected template's entries for prompt composition
 *
 * Built on shadcn Popover + Command (cmdk) for keyboard-friendly search.
 */

import { cn } from "@/lib/utils";
import { trpc } from "@/lib/trpc";
import { Check, ChevronsUpDown, X } from "lucide-react";
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

// ── Types ──

interface Props {
    /** Template sub-type to filter by: 'scene' for framework, 'recipe' for content type, 'enhance' for prompt style */
    templateType: "scene" | "recipe" | "enhance";
    /** Currently selected template ID */
    selectedId: number | undefined;
    /** Called when user selects or clears a template */
    onSelect: (templateId: number | undefined) => void;
    /** Called with the raw template content string when selected, or null when cleared */
    onTemplateContent?: (content: string | null) => void;
    /** Label to show above the dropdown */
    label: string;
    /** Placeholder text when nothing is selected */
    placeholder?: string;
}

/** Entry shape stored in template formData.entries[] */
interface VideoTemplateEntry {
    key: string;
    category: string;
    label: string;
    value: string;
}

// ── Component ──

export function VideoTemplateDropdown({
    templateType,
    selectedId,
    onSelect,
    onTemplateContent,
    label,
    placeholder = "Select...",
}: Props) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState("");
    const triggerRef = useRef<HTMLButtonElement>(null);

    // Fetch video templates filtered by type (scene or recipe)
    const { data: templates, isLoading } = trpc.templates.list.useQuery({
        module: "video",
        type: templateType,
    });

    const templateList = templates ?? [];

    // Find the selected template for display
    const selectedTemplate = templateList.find((t: any) => t.id === selectedId);

    // Reset search when popover closes
    useEffect(() => {
        if (!open) setSearch("");
    }, [open]);

    /**
     * Handle template selection/deselection.
     * Extracts the prompt_body entry and passes it up for prompt composition.
     */
    const handleSelect = (templateId: number) => {
        if (templateId === selectedId) {
            // Toggle off
            onSelect(undefined);
            onTemplateContent?.(null);
        } else {
            onSelect(templateId);
            // Find the template and extract prompt content from entries
            const tpl = templateList.find((t: any) => t.id === templateId);
            if (tpl) {
                const entries = (tpl.entries ?? []) as VideoTemplateEntry[];
                // Combine all entry values into a single prompt block
                const content = entries
                    .map((e: VideoTemplateEntry) => e.value)
                    .join("\n\n");
                onTemplateContent?.(content || null);
            }
        }
        setOpen(false);
    };

    const handleClear = (e: React.MouseEvent) => {
        e.stopPropagation();
        onSelect(undefined);
        onTemplateContent?.(null);
    };

    return (
        <div className="space-y-1.5">
            <span className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                {label}
            </span>

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
                                "w-full justify-between font-normal h-8 text-xs px-2.5 gap-1.5",
                                selectedTemplate ? "rounded-r-none border-r-0" : "",
                                !selectedTemplate && "text-muted-foreground"
                            )}
                        >
                            <span className="truncate flex-1 text-left">
                                {isLoading
                                    ? "Loading..."
                                    : selectedTemplate
                                        ? (selectedTemplate as any).name
                                        : placeholder}
                            </span>
                            <ChevronsUpDown className="h-3 w-3 opacity-50 shrink-0" />
                        </Button>
                    </PopoverTrigger>

                    {/* Clear button (shown when a template is selected) */}
                    {selectedTemplate && (
                        <button
                            type="button"
                            onClick={handleClear}
                            className="inline-flex items-center justify-center h-8 w-7 rounded-r-md border border-l-0 hover:bg-muted transition-colors"
                            aria-label={`Clear ${label} selection`}
                        >
                            <X className="h-3 w-3 opacity-50 hover:opacity-100 transition-opacity" />
                        </button>
                    )}
                </div>

                <PopoverContent
                    className="p-0"
                    style={{
                        width: triggerRef.current
                            ? Math.max(triggerRef.current.offsetWidth, 200)
                            : 200,
                    }}
                    align="start"
                >
                    <Command shouldFilter={true}>
                        <CommandInput
                            placeholder={`Search ${label.toLowerCase()}...`}
                            value={search}
                            onValueChange={setSearch}
                            className="h-8 text-xs"
                        />
                        <CommandList>
                            <CommandEmpty>No {label.toLowerCase()} found.</CommandEmpty>
                            <CommandGroup>
                                {templateList.map((tpl: any) => (
                                    <CommandItem
                                        key={tpl.id}
                                        value={tpl.name}
                                        onSelect={() => handleSelect(tpl.id)}
                                        className="text-xs"
                                    >
                                        <Check
                                            className={cn(
                                                "mr-1.5 h-3 w-3 shrink-0",
                                                selectedId === tpl.id ? "opacity-100" : "opacity-0"
                                            )}
                                        />
                                        <span className="truncate flex-1">{tpl.name}</span>
                                        {tpl.isDefault && (
                                            <span className="text-[9px] text-muted-foreground ml-1">
                                                default
                                            </span>
                                        )}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
        </div>
    );
}
