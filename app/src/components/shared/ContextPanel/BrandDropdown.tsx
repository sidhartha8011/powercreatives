import { useRef, useEffect } from "react";
import { cn } from "@/lib/utils";
import { colors } from "@/components/shared/design-tokens";
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
import { Check, ChevronsUpDown, X } from "lucide-react";
import { PanelHeader } from "@/components/shared/PanelHeader";
import type { Brand } from "../../../../../drizzle/schema";

interface Props {
  brandOpen: boolean;
  setBrandOpen: (open: boolean) => void;
  brandSearch: string;
  setBrandSearch: (search: string) => void;
  brandsLoading: boolean;
  brandList: Brand[];
  selectedBrandId: number | undefined;
  handleBrandSelect: (brandId: number) => void;
  handleBrandClear: (e: React.MouseEvent) => void;
  /** When true, hides the PanelHeader and card wrapper.
   *  Use when BrandDropdown is inside an AccordionSection that already provides the title. */
  hideHeader?: boolean;
}

export function BrandDropdown({
  brandOpen,
  setBrandOpen,
  brandSearch,
  setBrandSearch,
  brandsLoading,
  brandList,
  selectedBrandId,
  handleBrandSelect,
  handleBrandClear,
  hideHeader = false,
}: Props) {
  const brandTriggerRef = useRef<HTMLButtonElement>(null);
  const selectedBrand = brandList.find((b) => b.id === selectedBrandId);

  useEffect(() => {
    if (!brandOpen) setBrandSearch("");
  }, [brandOpen, setBrandSearch]);

  const grouped = brandList.reduce(
    (acc, brand) => {
      const group = brand.niche || "__ungrouped__";
      if (!acc[group]) acc[group] = [];
      acc[group].push(brand);
      return acc;
    },
    {} as Record<string, typeof brandList>
  );

  const groupKeys = Object.keys(grouped).sort((a, b) => {
    if (a === "__ungrouped__") return 1;
    if (b === "__ungrouped__") return -1;
    return a.localeCompare(b);
  });

  const hasNiches = groupKeys.some((k) => k !== "__ungrouped__");

  /* Inner content — shared between both modes */
  const content = (
    <div className="space-y-2">
      <Popover open={brandOpen} onOpenChange={setBrandOpen}>
          <div className="flex items-center gap-0">
            <PopoverTrigger asChild>
              <Button
                ref={brandTriggerRef}
                variant="outline"
                role="combobox"
                aria-expanded={brandOpen}
                disabled={brandsLoading}
                className={cn(
                  "w-full justify-between font-normal h-9 text-sm px-3 gap-2",
                  selectedBrand ? "rounded-r-none border-r-0" : "",
                  !selectedBrand && "text-muted-foreground"
                )}
                style={{
                  borderColor: selectedBrand ? colors.primary : undefined,
                }}
              >
                <span className="truncate flex-1 text-left">
                  {brandsLoading
                    ? "Loading brands..."
                    : selectedBrand
                      ? selectedBrand.name
                      : "Select a brand..."}
                </span>
                <span className="flex items-center gap-0.5 shrink-0">
                  <ChevronsUpDown className="h-3.5 w-3.5 opacity-50 shrink-0" />
                </span>
              </Button>
            </PopoverTrigger>
            {selectedBrand && (
              <button
                type="button"
                onClick={handleBrandClear}
                className="inline-flex items-center justify-center h-9 w-8 rounded-r-md border border-l-0 hover:bg-muted transition-colors"
                style={{
                  borderColor: colors.primary,
                }}
                aria-label="Clear brand selection"
              >
                <X className="h-3.5 w-3.5 opacity-50 hover:opacity-100 transition-opacity" />
              </button>
            )}
          </div>
          <PopoverContent
            className="p-0"
            style={{
              width: brandTriggerRef.current
                ? Math.max(brandTriggerRef.current.offsetWidth, 240)
                : 240,
            }}
            align="start"
          >
            <Command shouldFilter={true}>
              <CommandInput
                placeholder="Search brands..."
                value={brandSearch}
                onValueChange={setBrandSearch}
                className="h-9 text-sm"
              />
              <CommandList>
                <CommandEmpty>No brands found.</CommandEmpty>
                {hasNiches
                  ? groupKeys.map((groupKey) => (
                    <CommandGroup
                      key={groupKey}
                      heading={groupKey === "__ungrouped__" ? "Other" : groupKey}
                    >
                      {grouped[groupKey].map((brand) => (
                        <CommandItem
                          key={brand.id}
                          value={`${brand.name} ${brand.niche ?? ""} ${brand.website ?? ""}`}
                          onSelect={() => handleBrandSelect(brand.id)}
                          className="text-sm"
                        >
                          <Check
                            className={cn(
                              "mr-2 h-3.5 w-3.5 shrink-0",
                              selectedBrandId === brand.id ? "opacity-100" : "opacity-0"
                            )}
                          />
                          <span className="truncate flex-1">{brand.name}</span>
                          {brand.website && (
                            <span className="text-[10px] text-muted-foreground ml-1 shrink-0">
                              {brand.website.replace(/^https?:\/\//, "").replace(/\/$/, "")}
                            </span>
                          )}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  ))
                  : brandList.map((brand) => (
                    <CommandItem
                      key={brand.id}
                      value={`${brand.name} ${brand.niche ?? ""} ${brand.website ?? ""}`}
                      onSelect={() => handleBrandSelect(brand.id)}
                      className="text-sm"
                    >
                      <Check
                        className={cn(
                          "mr-2 h-3.5 w-3.5 shrink-0",
                          selectedBrandId === brand.id ? "opacity-100" : "opacity-0"
                        )}
                      />
                      <span className="truncate flex-1">{brand.name}</span>
                      {brand.website && (
                        <span className="text-[10px] text-muted-foreground ml-1 shrink-0">
                          {brand.website.replace(/^https?:\/\//, "").replace(/\/$/, "")}
                        </span>
                      )}
                    </CommandItem>
                  ))}
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>

        {selectedBrand && (
          <p className="text-xs text-muted-foreground">
            {selectedBrand.niche && `${selectedBrand.niche} · `}
            {selectedBrand.location ||
              selectedBrand.website?.replace(/^https?:\/\//, "").replace(/\/$/, "") || ""}
          </p>
        )}

        {!brandsLoading && brandList.length === 0 && (
          <p className="text-xs text-muted-foreground">
            No brands yet. Fetch a URL below to auto-save one.
          </p>
        )}
    </div>
  );

  /* Bare mode — parent provides wrapper */
  if (hideHeader) return content;

  /* Full mode — self-contained card with header */
  return (
    <div className="rounded-lg border border-border bg-card overflow-hidden">
      <PanelHeader title="Brand" />
      <div className="p-3">{content}</div>
    </div>
  );
}
