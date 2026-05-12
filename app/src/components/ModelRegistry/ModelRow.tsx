/**
 * MODEL ROW - WITH PER-MODULE SUB-TOGGLES
 * 
 * Purpose: Single row in the Model Registry table
 * Column order: Model -> Tier -> Status -> On -> [6 capability checkboxes] -> Delete
 * 
 * Features:
 * - Inline editable model name (click to edit)
 * - Delete button with hover reveal
 * - All capability checkboxes
 * - Tier selector
 * - Status badge showing Connected/Disconnected
 * - Enable/disable toggle (main toggle)
 * - Expandable per-module sub-toggles (Copy, Image, Video)
 */

import { useState, useRef, useEffect } from "react";
import { TableCell, TableRow } from "@/components/ui/table";
import { Checkbox } from "@/components/ui/checkbox";
import { Switch } from "@/components/ui/switch";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { TierSelector } from "./TierSelector";
import { CAPABILITY_COLUMNS, type ModelRowProps } from "./types";
import {
  getModelDisplayName,
  modelHasCapability,
  isModelEnabledForModule,
  deriveDefaultEnabledModules,
  ALL_MODULE_TYPES,
  MODULE_TYPE_LABELS,
  type ModuleType,
} from "@shared/types/models";
import { Trash2, Pencil, Check, X, Plug, Unplug, ChevronDown, ChevronRight, Pen, ImageIcon, Video } from "lucide-react";

const MODULE_ICONS: Record<ModuleType, React.ReactNode> = {
  copy: <Pen className="w-3 h-3" />,
  image: <ImageIcon className="w-3 h-3" />,
  video: <Video className="w-3 h-3" />,
};

interface ExtendedModelRowProps extends ModelRowProps {
  /** Callback when model name is updated */
  onUpdateName?: (name: string) => void;
  /** Callback when delete is clicked */
  onDelete?: () => void;
  /** Whether the parent integration is active (connected) */
  isIntegrationActive?: boolean;
  /** Callback when a module toggle is changed */
  onToggleModule?: (module: ModuleType, enabled: boolean) => void;
  /** Whether this row is selected for bulk actions */
  isSelected?: boolean;
  /** Callback when selection checkbox is toggled */
  onSelect?: (selected: boolean) => void;
}

export function ModelRow({
  model,
  onToggleCapability,
  onChangeTier,
  onConfirmStatus,
  onToggleEnabled,
  onUpdateName,
  onDelete,
  isIntegrationActive = true,
  onToggleModule,
  isSelected = false,
  onSelect,
}: ExtendedModelRowProps) {
  const displayName = getModelDisplayName(model);
  
  // Inline editing state
  const [isEditing, setIsEditing] = useState(false);
  const [editValue, setEditValue] = useState(model.customName || model.originalName);
  const inputRef = useRef<HTMLInputElement>(null);
  
  // Expansion state for per-module sub-toggles
  const [isExpanded, setIsExpanded] = useState(false);
  
  // Focus input when editing starts
  useEffect(() => {
    if (isEditing && inputRef.current) {
      inputRef.current.focus();
      inputRef.current.select();
    }
  }, [isEditing]);
  
  // Handle save
  const handleSave = () => {
    if (onUpdateName && editValue.trim()) {
      const newName = editValue.trim();
      if (newName !== model.originalName) {
        onUpdateName(newName);
      } else {
        onUpdateName("");
      }
    }
    setIsEditing(false);
  };
  
  // Handle cancel
  const handleCancel = () => {
    setEditValue(model.customName || model.originalName);
    setIsEditing(false);
  };
  
  // Handle key press
  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter") {
      handleSave();
    } else if (e.key === "Escape") {
      handleCancel();
    }
  };
  
  // Determine which modules this model supports (has relevant capabilities)
  const supportedModules = ALL_MODULE_TYPES.filter((mod) => {
    const defaults = deriveDefaultEnabledModules(model);
    // A module is "supported" if the model has the capability for it
    return defaults[mod] === true;
  });
  
  // Count enabled modules
  const enabledModuleCount = supportedModules.filter((mod) =>
    isModelEnabledForModule(model, mod)
  ).length;
  
  const totalCols = (onSelect ? 1 : 0) + 4 + CAPABILITY_COLUMNS.length + 1; // Checkbox? + Model + Tier + Status + On + capabilities + delete
  
  return (
    <>
      <TableRow className={`group ${!model.isEnabled ? "opacity-50" : ""} ${isSelected ? "bg-primary/5" : ""}`}>
        {/* 0. Selection Checkbox */}
        {onSelect && (
          <TableCell className="py-2 w-[40px]">
            <Checkbox
              checked={isSelected}
              onCheckedChange={(checked) => onSelect(checked === true)}
              className="mx-auto"
            />
          </TableCell>
        )}
        {/* 1. Model Info with Inline Edit */}
        <TableCell className="py-2 overflow-hidden">
          {isEditing ? (
            <div className="flex items-center gap-1">
              <Input
                ref={inputRef}
                value={editValue}
                onChange={(e) => setEditValue(e.target.value)}
                onKeyDown={handleKeyDown}
                onBlur={handleSave}
                className="h-8 text-sm"
              />
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={handleSave}
              >
                <Check className="w-4 h-4 text-green-600" />
              </Button>
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0"
                onClick={handleCancel}
              >
                <X className="w-4 h-4 text-red-600" />
              </Button>
            </div>
          ) : (
            <div className="flex flex-col">
              <div className="flex items-center gap-1">
                {/* Expand/collapse chevron for models with module toggles */}
                {supportedModules.length > 0 && onToggleModule && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-5 w-5 p-0 shrink-0"
                    onClick={() => setIsExpanded(!isExpanded)}
                  >
                    {isExpanded ? (
                      <ChevronDown className="w-3.5 h-3.5 text-muted-foreground" />
                    ) : (
                      <ChevronRight className="w-3.5 h-3.5 text-muted-foreground" />
                    )}
                  </Button>
                )}
                <span 
                  className="text-sm font-medium truncate cursor-pointer hover:text-primary" 
                  title={displayName}
                  onClick={() => onUpdateName && setIsEditing(true)}
                >
                  {displayName}
                </span>
                {onUpdateName && (
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-6 w-6 p-0 opacity-0 group-hover:opacity-100 transition-opacity"
                    onClick={() => setIsEditing(true)}
                  >
                    <Pencil className="w-3 h-3 text-muted-foreground" />
                  </Button>
                )}
              </div>
              <div className="flex items-center gap-1.5">
                <span className="text-xs text-muted-foreground truncate" title={model.provider}>
                  {model.provider}
                </span>
                {/* Module enablement badges */}
                {supportedModules.length > 0 && onToggleModule && (
                  <span className="text-[10px] text-muted-foreground">
                    ({enabledModuleCount}/{supportedModules.length} modules)
                  </span>
                )}
              </div>
            </div>
          )}
        </TableCell>
        
        {/* 2. Cost Tier */}
        <TableCell className="py-2 overflow-hidden">
          <TierSelector
            tier={model.costTier}
            onChange={onChangeTier}
            disabled={!model.isEnabled}
          />
        </TableCell>
        
        {/* 3. Status Badge — Connected / Disconnected */}
        <TableCell className="py-2 overflow-hidden">
          <Badge
            variant="outline"
            className={`
              text-[10px] px-1.5 py-0.5 gap-0.5 whitespace-nowrap
              ${isIntegrationActive
                ? "bg-green-100 text-green-600 border-green-300"
                : "bg-red-100 text-red-600 border-red-300"
              }
            `}
            title={isIntegrationActive ? "Integration is active" : "Integration is disabled"}
          >
            {isIntegrationActive ? (
              <Plug className="w-3 h-3" />
            ) : (
              <Unplug className="w-3 h-3" />
            )}
            {isIntegrationActive ? "Connected" : "Disconnected"}
          </Badge>
        </TableCell>
        
        {/* 4. Enable Toggle */}
        <TableCell className="text-center py-2">
          <Switch
            checked={model.isEnabled}
            onCheckedChange={onToggleEnabled}
            className="mx-auto"
          />
        </TableCell>
        
        {/* 5. Capability Checkboxes */}
        {CAPABILITY_COLUMNS.map((col) => (
          <TableCell key={col.key} className="text-center py-2">
            <Checkbox
              checked={modelHasCapability(model, col.key)}
              onCheckedChange={(checked) => 
                onToggleCapability(col.key, checked === true)
              }
              disabled={!model.isEnabled}
              className="mx-auto"
            />
          </TableCell>
        ))}
        
        {/* 6. Delete Button */}
        <TableCell className="py-2">
          {onDelete && (
            <Button
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 opacity-0 group-hover:opacity-100 transition-opacity text-muted-foreground hover:text-red-600"
              onClick={onDelete}
            >
              <Trash2 className="w-4 h-4" />
            </Button>
          )}
        </TableCell>
      </TableRow>
      
      {/* Per-module sub-toggles (expanded row) */}
      {isExpanded && supportedModules.length > 0 && onToggleModule && (
        <TableRow className="bg-muted/30">
          <TableCell colSpan={totalCols} className="py-1.5 pl-10">
            <div className="flex items-center gap-4">
              <span className="text-xs font-medium text-muted-foreground mr-1">Modules:</span>
              {supportedModules.map((mod) => {
                const enabled = isModelEnabledForModule(model, mod);
                return (
                  <label
                    key={mod}
                    className="flex items-center gap-1.5 cursor-pointer select-none"
                  >
                    <Switch
                      checked={enabled}
                      onCheckedChange={(checked) => onToggleModule(mod, checked)}
                      disabled={!model.isEnabled}
                      className="scale-75"
                    />
                    <span className="flex items-center gap-1 text-xs">
                      {MODULE_ICONS[mod]}
                      {MODULE_TYPE_LABELS[mod]}
                    </span>
                  </label>
                );
              })}
            </div>
          </TableCell>
        </TableRow>
      )}
    </>
  );
}
