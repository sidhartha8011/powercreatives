/**
 * TEMPLATE ROW - Flat Inline-Editable Row
 *
 * Column order: Niche | Group | Name | Module | Type | Subtype | Value | Date Added | Default | Actions
 *
 * Every field is visible and editable directly in the table row:
 *   - Niche: colored pill badge + CreatableCombobox on click
 *   - Group: CreatableCombobox (search existing groups or create new)
 *   - Name: click-to-edit inline Input
 *   - Module: visible inline Select dropdown
 *   - Type: visible inline Select dropdown (options depend on Module)
 *   - Subtype: visible inline Select dropdown (Reference/Tonality/Prompt/Preset)
 *   - Value: visible text, click to open inline Textarea
 *   - Default: Switch toggle
 *   - Actions: inline icon buttons (Duplicate + Delete)
 */

import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { CreatableCombobox } from "@/components/ui/creatable-combobox";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import { Textarea } from "@/components/ui/textarea";
import { templateVarsFor, isPromptOnlyModule } from "./templateVars";
import { SlashVariableMenu, useSlashVariables } from "./SlashVariableMenu";
import { TableRow, TableCell } from "@/components/ui/table";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  CATEGORY_LABELS,
  MODULE_LABELS,
  TEMPLATE_CATEGORIES,
  TEMPLATE_MODULES,
  TEMPLATE_TYPES,
  TYPE_COLORS,
  TYPE_COLOR_FALLBACK,
  TYPE_LABELS,
  type TemplateCategory,
  type TemplateModule,
} from "@shared/templateTypes";
import { Check, Copy, Pencil, Trash2, X } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";
import { getNicheColor } from "./nicheColors";

// ============================================
// Helpers
// ============================================

/**
 * Format a date into a human-readable relative string.
 */
function formatRelativeDate(date: Date | string): string {
  const d = typeof date === "string" ? new Date(date) : date;
  const now = new Date();
  const diffMs = now.getTime() - d.getTime();
  const diffMin = Math.floor(diffMs / 60_000);
  const diffHr = Math.floor(diffMs / 3_600_000);
  const diffDay = Math.floor(diffMs / 86_400_000);

  if (diffMin < 1) return "Just now";
  if (diffMin < 60) return `${diffMin}m ago`;
  if (diffHr < 24) return `${diffHr}h ago`;
  if (diffDay < 7) return `${diffDay}d ago`;

  return d.toLocaleDateString(undefined, {
    month: "short",
    day: "numeric",
    year: d.getFullYear() !== now.getFullYear() ? "numeric" : undefined,
  });
}

// ============================================
// Niche Pill Component
// ============================================

function NichePill({ niche }: { niche: string | null }) {
  if (!niche) {
    return (
      <span className="text-xs text-muted-foreground/50 italic">—</span>
    );
  }

  const color = getNicheColor(niche);
  if (!color) return <span className="text-xs">{niche}</span>;

  return (
    <span
      className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-medium whitespace-nowrap leading-tight"
      style={{
        backgroundColor: color.bg,
        color: color.text,
        border: `1px solid ${color.border}`,
      }}
    >
      {niche}
    </span>
  );
}

// ============================================
// Type Pill Component — colored badges per template type
// Uses shared TYPE_COLORS from templateTypes.ts
// ============================================

function TypePill({ type }: { type: string }) {
  const colors = TYPE_COLORS[type] ?? TYPE_COLOR_FALLBACK;
  const label = TYPE_LABELS[type] ?? type;

  return (
    <span
      className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold whitespace-nowrap leading-tight"
      style={{
        backgroundColor: colors.bg,
        color: colors.text,
        border: `1px solid ${colors.border}`,
      }}
    >
      {label}
    </span>
  );
}

// ============================================
// Props
// ============================================

export interface TemplateRowData {
  id: number;
  name: string;
  module: string;
  type: string;
  niche: string | null;
  groupName: string | null;
  isDefault: boolean;
  createdAt: Date | string;
}

interface TemplateRowProps {
  template: TemplateRowData;
  /** First entry's value (since each template = 1 entry) */
  value: string;
  /** First entry's category (subtype) */
  subtype: TemplateCategory;
  /** All unique niche names across templates (for combobox options) */
  nicheOptions: string[];
  /** All unique group names across templates (for combobox options) */
  groupOptions: string[];
  /** Whether this row is selected for bulk actions */
  isSelected?: boolean;
  /** Toggle selection for this row */
  onToggleSelect?: (id: number) => void;
  onUpdateName: (id: number, name: string) => void;
  onUpdateModule: (id: number, module: string) => void;
  onUpdateType: (id: number, type: string) => void;
  onUpdateSubtype: (id: number, subtype: TemplateCategory) => void;
  onUpdateNiche: (id: number, niche: string | null) => void;
  onUpdateGroup: (id: number, groupName: string | null) => void;
  onUpdateValue: (id: number, value: string) => void;
  onDelete: (id: number) => void;
  onDuplicate: (id: number) => void;
  onToggleDefault: (id: number, currentDefault: boolean) => void;
}

// ============================================
// Main Component
// ============================================

export function TemplateRow({
  template,
  value,
  subtype,
  nicheOptions,
  groupOptions,
  isSelected = false,
  onToggleSelect,
  onUpdateName,
  onUpdateModule,
  onUpdateType,
  onUpdateSubtype,
  onUpdateNiche,
  onUpdateGroup,
  onUpdateValue,
  onDelete,
  onDuplicate,
  onToggleDefault,
}: TemplateRowProps) {
  // ---- Name inline editing ----
  const [isEditingName, setIsEditingName] = useState(false);
  const [editNameValue, setEditNameValue] = useState(template.name);
  const nameInputRef = useRef<HTMLInputElement>(null);

  // ---- Value inline editing ----
  const [isEditingValue, setIsEditingValue] = useState(false);
  const [editValueText, setEditValueText] = useState(value);
  const valueTextareaRef = useRef<HTMLTextAreaElement>(null);

  // "/" typeahead — the only variable affordance now that the chip strip is gone.
  // Empty vars (any module/category without a substituting builder) disables it.
  const slashVars = useSlashVariables({
    vars: templateVarsFor(template.module, subtype),
    value: editValueText,
    setValue: setEditValueText,
    textareaRef: valueTextareaRef,
  });

  // Sync local state when props change
  useEffect(() => {
    setEditNameValue(template.name);
  }, [template.name]);

  useEffect(() => {
    setEditValueText(value);
  }, [value]);

  // Focus name input when editing starts
  useEffect(() => {
    if (isEditingName && nameInputRef.current) {
      nameInputRef.current.focus();
      nameInputRef.current.select();
    }
  }, [isEditingName]);

  // Focus value textarea when editing starts
  useEffect(() => {
    if (isEditingValue && valueTextareaRef.current) {
      valueTextareaRef.current.focus();
    }
  }, [isEditingValue]);

  // ---- Name handlers ----
  const handleSaveName = useCallback(() => {
    const trimmed = editNameValue.trim();
    if (trimmed && trimmed !== template.name) {
      onUpdateName(template.id, trimmed);
    } else {
      setEditNameValue(template.name);
    }
    setIsEditingName(false);
  }, [editNameValue, template.name, template.id, onUpdateName]);

  const handleCancelName = useCallback(() => {
    setEditNameValue(template.name);
    setIsEditingName(false);
  }, [template.name]);

  // ---- Value handlers ----
  const handleSaveValue = useCallback(() => {
    const trimmed = editValueText.trim();
    if (trimmed && trimmed !== value) {
      onUpdateValue(template.id, trimmed);
    } else {
      setEditValueText(value);
    }
    setIsEditingValue(false);
  }, [editValueText, value, template.id, onUpdateValue]);

  const handleCancelValue = useCallback(() => {
    setEditValueText(value);
    setIsEditingValue(false);
  }, [value]);

  // Get available types for current module
  const availableTypes =
    TEMPLATE_TYPES[template.module as TemplateModule] ?? [];

  return (
    <TableRow className={`group ${isSelected ? "bg-primary/5" : ""}`}>
      {/* 0. Checkbox — bulk selection */}
      {onToggleSelect && (
        <TableCell className="w-10">
          <Checkbox
            checked={isSelected}
            onCheckedChange={() => onToggleSelect(template.id)}
            aria-label={`Select ${template.name}`}
          />
        </TableCell>
      )}

      {/* 1. Niche — colored pill badge + CreatableCombobox */}
      <TableCell>
        <div className="flex items-center gap-1">
          <NichePill niche={template.niche} />
          <div className="opacity-0 group-hover:opacity-100 transition-opacity">
            <CreatableCombobox
              options={nicheOptions}
              value={template.niche}
              onChange={(v) => onUpdateNiche(template.id, v)}
              placeholder="Set niche"
              emptyLabel="No niches yet"
              compact
              className="w-6 h-6 p-0 border-transparent hover:border-border"
            />
          </div>
        </div>
      </TableCell>

      {/* 2. Group — CreatableCombobox (search/create) */}
      <TableCell>
        <CreatableCombobox
          options={groupOptions}
          value={template.groupName}
          onChange={(v) => onUpdateGroup(template.id, v)}
          placeholder="No group"
          emptyLabel="No groups yet"
          compact
          className="w-full border-transparent hover:border-border transition-colors"
        />
      </TableCell>

      {/* 3. Name — click to inline edit */}
      <TableCell>
        {isEditingName ? (
          <div className="flex items-center gap-1">
            <Input
              ref={nameInputRef}
              value={editNameValue}
              onChange={(e) => setEditNameValue(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") handleSaveName();
                else if (e.key === "Escape") handleCancelName();
              }}
              onBlur={handleSaveName}
              className="h-8 text-sm"
            />
            <Button
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 shrink-0"
              onMouseDown={(e) => e.preventDefault()}
              onClick={handleSaveName}
            >
              <Check className="w-4 h-4 text-green-600" />
            </Button>
            <Button
              variant="ghost"
              size="sm"
              className="h-8 w-8 p-0 shrink-0"
              onMouseDown={(e) => e.preventDefault()}
              onClick={handleCancelName}
            >
              <X className="w-4 h-4 text-red-600" />
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-1 min-w-0">
            <span
              className="text-sm font-medium truncate cursor-pointer hover:text-primary transition-colors"
              onClick={() => setIsEditingName(true)}
              title={template.name}
            >
              {template.name}
            </span>
            <Button
              variant="ghost"
              size="sm"
              className="h-6 w-6 p-0 opacity-0 group-hover:opacity-100 transition-opacity shrink-0"
              onClick={() => setIsEditingName(true)}
            >
              <Pencil className="w-3 h-3 text-muted-foreground" />
            </Button>
          </div>
        )}
      </TableCell>

      {/* 4. Module — inline visible Select */}
      <TableCell>
        <Select
          value={template.module}
          onValueChange={(v) => onUpdateModule(template.id, v)}
        >
          <SelectTrigger className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {TEMPLATE_MODULES.map((mod) => (
              <SelectItem key={mod} value={mod}>
                {MODULE_LABELS[mod]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </TableCell>

      {/* 5. Type — colored pill with Select on click */}
      <TableCell>
        <Select
          value={template.type}
          onValueChange={(v) => onUpdateType(template.id, v)}
        >
          <SelectTrigger variant="ghost" size="auto" className="h-auto w-auto">
            <TypePill type={template.type} />
          </SelectTrigger>
          <SelectContent>
            {availableTypes.map((t) => (
              <SelectItem key={t} value={t}>
                <TypePill type={t} />
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </TableCell>


      {/* 6. Subtype — inline visible Select (Reference/Tonality/Prompt/Preset) */}
      {/* Hidden for prompt-only modules (writer/video/seo/optimizer) — every entry there is the prompt */}
      <TableCell>
        {isPromptOnlyModule(template.module) ? (
          <span className="text-xs text-muted-foreground/50 italic" title="Every entry in this module is a prompt">{template.module === "video" ? "—" : "Prompt"}</span>
        ) : (
          <Select
            value={subtype}
            onValueChange={(v) =>
              onUpdateSubtype(template.id, v as TemplateCategory)
            }
          >
            <SelectTrigger className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TEMPLATE_CATEGORIES.map((cat) => (
                <SelectItem key={cat} value={cat}>
                  {CATEGORY_LABELS[cat]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </TableCell>

      {/* 7. Value — single-line truncated, click to edit with Textarea */}
      <TableCell className="max-w-0 overflow-hidden">
        {isEditingValue ? (
          <div className="space-y-1.5 whitespace-normal">
            <Textarea
              ref={valueTextareaRef}
              value={editValueText}
              onChange={slashVars.onChange}
              className="text-sm min-h-[80px] resize-y"
              rows={4}
              onKeyDown={(e) => {
                // The menu claims ↑/↓/Enter/Tab/Esc while it is open. Escape in
                // particular must dismiss the list WITHOUT cancelling the edit.
                if (slashVars.onKeyDown(e)) return;
                if (e.key === "Escape") handleCancelValue();
              }}
            />
            <SlashVariableMenu state={slashVars} />
            <div className="flex items-center gap-1">
              <Button
                variant="default"
                size="sm"
                onClick={handleSaveValue}
                disabled={!editValueText.trim()}
                className="h-7 text-xs gap-1"
              >
                <Check className="w-3 h-3" />
                Save
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={handleCancelValue}
                className="h-7 text-xs gap-1"
              >
                <X className="w-3 h-3" />
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <div
            className="cursor-pointer group/value truncate"
            onClick={() => setIsEditingValue(true)}
            title={value || "Click to edit"}
          >
            <span className="text-xs text-muted-foreground hover:text-foreground transition-colors">
              {value || (
                <span className="italic text-muted-foreground/60">
                  No value — click to add
                </span>
              )}
            </span>
          </div>
        )}
      </TableCell>

      {/* 8. Date Added — read-only formatted date */}
      <TableCell>
        <span className="text-xs text-muted-foreground whitespace-nowrap">
          {formatRelativeDate(template.createdAt)}
        </span>
      </TableCell>

      {/* 9. Default toggle */}
      <TableCell>
        <Switch
          checked={template.isDefault}
          onCheckedChange={() =>
            onToggleDefault(template.id, template.isDefault)
          }
          aria-label={`Set ${template.name} as default`}
        />
      </TableCell>

      {/* 10. Actions — inline icon buttons */}
      <TableCell>
        <div className="flex items-center gap-0.5">
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0 opacity-0 group-hover:opacity-100 transition-opacity"
                onClick={() => onDuplicate(template.id)}
              >
                <Copy className="w-3.5 h-3.5 text-muted-foreground" />
              </Button>
            </TooltipTrigger>
            <TooltipContent side="top">
              <p>Duplicate</p>
            </TooltipContent>
          </Tooltip>

          <AlertDialog>
            <Tooltip>
              <TooltipTrigger asChild>
                <AlertDialogTrigger asChild>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 w-7 p-0 opacity-0 group-hover:opacity-100 transition-opacity"
                  >
                    <Trash2 className="w-3.5 h-3.5 text-muted-foreground hover:text-destructive transition-colors" />
                  </Button>
                </AlertDialogTrigger>
              </TooltipTrigger>
              <TooltipContent side="top">
                <p>Delete</p>
              </TooltipContent>
            </Tooltip>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Delete template?</AlertDialogTitle>
                <AlertDialogDescription>
                  This will permanently delete{" "}
                  <span className="font-medium text-foreground">
                    {template.name}
                  </span>
                  . This action cannot be undone.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                  onClick={() => onDelete(template.id)}
                  className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                >
                  Delete
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </TableCell>
    </TableRow>
  );
}
