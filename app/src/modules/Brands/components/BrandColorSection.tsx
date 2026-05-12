/**
 * BRANDS MODULE — Color Section (Right Panel)
 *
 * Renders the brand color pool with primary/secondary assignment.
 * Click = assign as primary, right-click = assign as secondary.
 *
 * Pure presentational component — receives colors and callbacks via props.
 */

import { Label } from "@/components/ui/label";
import { Plus, X } from "lucide-react";
import type { RefObject } from "react";

interface BrandColorSectionProps {
  /** Array of hex colors */
  colors: string[];
  /** Max number of colors allowed */
  maxColors: number;
  /** Ref for the hidden color picker input */
  additionalColorRef: RefObject<HTMLInputElement | null>;
  /** Handlers for color operations */
  onAssignPrimary: (index: number) => void;
  onAssignSecondary: (index: number) => void;
  onRemove: (index: number) => void;
  onAdd: (hex: string) => void;
}

export function BrandColorSection({
  colors,
  maxColors,
  additionalColorRef,
  onAssignPrimary,
  onAssignSecondary,
  onRemove,
  onAdd,
}: BrandColorSectionProps) {
  return (
    <div className="space-y-2">
      <Label className="text-sm font-medium">Brand Colors</Label>
      <p className="text-xs text-muted-foreground">
        Click a swatch to assign: 1st click = Primary, 2nd = Secondary.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        {colors.map((color, i) => {
          const isPrimary = i === 0;
          const isSecondary = i === 1;
          return (
            <div key={`${color}-${i}`} className="relative group">
              <button
                type="button"
                onClick={() => {
                  if (!isPrimary) onAssignPrimary(i);
                }}
                onContextMenu={(e) => {
                  e.preventDefault();
                  if (!isSecondary) onAssignSecondary(i);
                }}
                className="w-8 h-8 rounded-lg shadow-sm cursor-pointer transition-all hover:scale-110"
                style={{
                  backgroundColor: color,
                  border: isPrimary
                    ? "2.5px solid #3b82f6"
                    : isSecondary
                      ? "2.5px solid #22c55e"
                      : "1px solid var(--border)",
                  outline: isPrimary || isSecondary ? "2px solid white" : "none",
                  outlineOffset: "-3px",
                }}
                title={
                  isPrimary
                    ? `Primary: ${color}`
                    : isSecondary
                      ? `Secondary: ${color} (click to make primary)`
                      : `${color} — click to set as primary, right-click for secondary`
                }
              />
              {/* Role label */}
              {(isPrimary || isSecondary) && (
                <span
                  className="absolute -bottom-3.5 left-1/2 -translate-x-1/2 text-[9px] font-medium whitespace-nowrap"
                  style={{ color: isPrimary ? "#3b82f6" : "#22c55e" }}
                >
                  {isPrimary ? "1st" : "2nd"}
                </span>
              )}
              {/* Remove button */}
              <button
                type="button"
                onClick={(e) => {
                  e.stopPropagation();
                  onRemove(i);
                }}
                className="absolute -top-1 -right-1 w-3.5 h-3.5 rounded-full bg-destructive text-destructive-foreground flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
              >
                <X size={8} />
              </button>
            </div>
          );
        })}
        {colors.length < maxColors && (
          <button
            type="button"
            onClick={() => additionalColorRef.current?.click()}
            className="w-8 h-8 rounded-lg border border-dashed border-border flex items-center justify-center text-muted-foreground hover:bg-muted/50 transition-colors"
            title="Add color"
          >
            <Plus size={14} />
          </button>
        )}
        <input
          ref={additionalColorRef}
          type="color"
          className="sr-only"
          onChange={(e) => onAdd(e.target.value)}
        />
      </div>
    </div>
  );
}
