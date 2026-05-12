/**
 * BulkActionBar — Reusable floating action bar for bulk table operations.
 *
 * Renders via React Portal to document.body, escaping any parent stacking
 * context (critical for WordPress admin where z-index layers are complex).
 *
 * Slides in from the bottom when `count > 0`. Shows the number of selected
 * rows and renders action buttons provided by the parent.
 *
 * Usage:
 *   <BulkActionBar count={selection.count} onClear={selection.clearAll}>
 *     <BulkActionBar.Action icon={Copy} label="Duplicate" onClick={handleBulkDuplicate} />
 *     <BulkActionBar.Action icon={Trash2} label="Delete" onClick={handleBulkDelete} variant="destructive" />
 *   </BulkActionBar>
 *
 * Designed to be table-agnostic: works with Templates, Brands, or any
 * future table. The parent decides which actions to show.
 */

import { createPortal } from "react-dom";
import { Button } from "@/components/ui/button";
import { X, type LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

// ============================================
// Action sub-component
// ============================================

interface ActionProps {
  icon: LucideIcon;
  label: string;
  onClick: () => void;
  variant?: "default" | "destructive";
  disabled?: boolean;
  loading?: boolean;
}

function Action({
  icon: Icon,
  label,
  onClick,
  variant = "default",
  disabled = false,
  loading = false,
}: ActionProps) {
  return (
    <Button
      size="sm"
      variant={variant === "destructive" ? "destructive" : "secondary"}
      onClick={onClick}
      disabled={disabled || loading}
      className="gap-1.5 text-xs h-8"
    >
      <Icon className="w-3.5 h-3.5" />
      {label}
    </Button>
  );
}

// ============================================
// Main component — rendered via Portal
// ============================================

interface BulkActionBarProps {
  /** Number of selected rows */
  count: number;
  /** Clear all selections */
  onClear: () => void;
  /** Action buttons — use <BulkActionBar.Action /> */
  children: ReactNode;
}

function BulkActionBarRoot({ count, onClear, children }: BulkActionBarProps) {
  if (count === 0) return null;

  // Render via Portal into #pcm-root (not document.body) to:
  // 1. Escape any nested stacking context \u2014 fixes click-blocking in WP admin
  // 2. Inherit all CSS variables, fonts, and design tokens from #pcm-root
  const portalTarget = document.getElementById('pcm-root') ?? document.body;

  return createPortal(
    <div
      className="fixed bottom-6 left-1/2 -translate-x-1/2 flex items-center gap-3 rounded-lg border bg-background px-4 py-2.5 shadow-lg animate-in slide-in-from-bottom-4 fade-in duration-200"
      style={{ minWidth: "280px", zIndex: 99990 }}
    >
      {/* Selection count */}
      <span className="text-sm font-medium whitespace-nowrap">
        {count} selected
      </span>

      {/* Separator */}
      <div className="w-px h-5 bg-border" />

      {/* Action buttons */}
      <div className="flex items-center gap-2">{children}</div>

      {/* Spacer */}
      <div className="flex-1" />

      {/* Clear selection */}
      <Button
        size="icon"
        variant="ghost"
        onClick={onClear}
        className="h-7 w-7 shrink-0"
        title="Clear selection"
      >
        <X className="w-3.5 h-3.5" />
      </Button>
    </div>,
    portalTarget,
  );
}

// Attach Action as a static property for compound component pattern
export const BulkActionBar = Object.assign(BulkActionBarRoot, { Action });
