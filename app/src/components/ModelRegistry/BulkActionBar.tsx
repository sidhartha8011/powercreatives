/**
 * BULK ACTION BAR
 * 
 * Floating bar that appears when models are selected.
 * Shows selection count and provides bulk actions:
 * - Enable / Disable
 * - Delete
 * - Change Tier (budget/standard/premium)
 * - Toggle Module (copy/image/video)
 */

import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { useState } from "react";
import {
  Power,
  PowerOff,
  Trash2,
  X,
  Layers,
  ToggleLeft,
} from "lucide-react";
import type { CostTier, ModuleType } from "@shared/types/models";
import { MODULE_TYPE_LABELS, ALL_MODULE_TYPES } from "@shared/types/models";

export interface BulkActionBarProps {
  /** Number of selected models */
  selectedCount: number;
  /** Total number of models visible (for "select all" context) */
  totalCount: number;
  /** Clear selection */
  onClearSelection: () => void;
  /** Bulk enable */
  onBulkEnable: () => void;
  /** Bulk disable */
  onBulkDisable: () => void;
  /** Bulk delete */
  onBulkDelete: () => void;
  /** Bulk change tier */
  onBulkChangeTier: (tier: CostTier) => void;
  /** Bulk toggle module */
  onBulkToggleModule: (module: ModuleType, enabled: boolean) => void;
  /** Whether any bulk operation is in progress */
  isProcessing?: boolean;
}

export function BulkActionBar({
  selectedCount,
  totalCount,
  onClearSelection,
  onBulkEnable,
  onBulkDisable,
  onBulkDelete,
  onBulkChangeTier,
  onBulkToggleModule,
  isProcessing = false,
}: BulkActionBarProps) {
  const [showDeleteConfirm, setShowDeleteConfirm] = useState(false);

  if (selectedCount === 0) return null;

  return (
    <>
      <div className="sticky bottom-4 z-50 mx-auto w-fit">
        <div className="flex items-center gap-2 bg-background border border-border rounded-lg shadow-lg px-4 py-2.5 animate-in slide-in-from-bottom-4 duration-200">
          {/* Selection count & clear */}
          <div className="flex items-center gap-2 pr-3 border-r border-border">
            <span className="text-sm font-medium">
              {selectedCount} of {totalCount} selected
            </span>
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={onClearSelection}
              disabled={isProcessing}
            >
              <X className="w-4 h-4" />
            </Button>
          </div>

          {/* Enable / Disable */}
          <Button
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-green-600 border-green-200 hover:bg-green-50"
            onClick={onBulkEnable}
            disabled={isProcessing}
          >
            <Power className="w-3.5 h-3.5" />
            Enable
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 text-orange-600 border-orange-200 hover:bg-orange-50"
            onClick={onBulkDisable}
            disabled={isProcessing}
          >
            <PowerOff className="w-3.5 h-3.5" />
            Disable
          </Button>

          {/* Tier selector */}
          <div className="flex items-center gap-1 pl-2 border-l border-border">
            <Layers className="w-3.5 h-3.5 text-muted-foreground" />
            <Select
              onValueChange={(val) => onBulkChangeTier(val as CostTier)}
              disabled={isProcessing}
            >
              <SelectTrigger className="w-[110px]">
                <SelectValue placeholder="Set Tier" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="budget">Budget</SelectItem>
                <SelectItem value="standard">Standard</SelectItem>
                <SelectItem value="premium">Premium</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Module toggle */}
          <div className="flex items-center gap-1 pl-2 border-l border-border">
            <ToggleLeft className="w-3.5 h-3.5 text-muted-foreground" />
            <Select
              onValueChange={(val) => {
                // Format: "module:enabled" e.g. "copy:true"
                const [mod, en] = val.split(":");
                onBulkToggleModule(mod as ModuleType, en === "true");
              }}
              disabled={isProcessing}
            >
              <SelectTrigger className="w-[140px]">
                <SelectValue placeholder="Module" />
              </SelectTrigger>
              <SelectContent>
                {ALL_MODULE_TYPES.map((mod) => (
                  <SelectItem key={`${mod}:true`} value={`${mod}:true`}>
                    Enable {MODULE_TYPE_LABELS[mod]}
                  </SelectItem>
                ))}
                {ALL_MODULE_TYPES.map((mod) => (
                  <SelectItem key={`${mod}:false`} value={`${mod}:false`}>
                    Disable {MODULE_TYPE_LABELS[mod]}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          {/* Delete */}
          <div className="pl-2 border-l border-border">
            <Button
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 text-red-600 border-red-200 hover:bg-red-50"
              onClick={() => setShowDeleteConfirm(true)}
              disabled={isProcessing}
            >
              <Trash2 className="w-3.5 h-3.5" />
              Delete
            </Button>
          </div>
        </div>
      </div>

      {/* Delete confirmation dialog */}
      <AlertDialog open={showDeleteConfirm} onOpenChange={setShowDeleteConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {selectedCount} Models</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete {selectedCount} selected model{selectedCount > 1 ? "s" : ""}?
              This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                onBulkDelete();
                setShowDeleteConfirm(false);
              }}
              className="bg-red-600 hover:bg-red-700"
            >
              Delete {selectedCount} Model{selectedCount > 1 ? "s" : ""}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
