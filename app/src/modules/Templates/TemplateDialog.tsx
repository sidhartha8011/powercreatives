/**
 * TEMPLATES MODULE - Create/Edit Dialog
 *
 * Batch-create mode:
 *   - 3 dropdowns at top: Module, Type, Category (retain state between adds)
 *   - Always-visible Label input (= template name) + Value textarea
 *   - "Add" button pushes a complete pending template to the list
 *   - Each entry in the list becomes its OWN unique template at save time
 *   - Label/Value clear after Add; dropdowns retain state for quick successive adds
 *
 * Edit mode:
 *   - Single template loaded into inline-form for editing
 *   - Save updates that one template
 *
 * Module change cascades: selecting a new module resets Type to first available.
 */

import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  CATEGORY_LABELS,
  TEMPLATE_CATEGORIES,
  TEMPLATE_TYPES,
  TYPE_LABELS,
  type TemplateCategory,
  type TemplateEntry,
  type TemplateModule,
} from "@shared/templateTypes";
import {
  ChevronDown,
  ChevronRight,
  Pencil,
  Plus,
  Trash2,
} from "lucide-react";
import { useEffect, useState } from "react";
import { CreatableCombobox } from "@/components/ui/creatable-combobox";
import { SourceVarsHint } from "./SourceVarsHint";

// ============================================
// Types
// ============================================

/**
 * A single pending template in the batch list.
 * Each one will become its own template in the database.
 */
export interface PendingTemplate {
  /** Template name (= label from the inline form) */
  name: string;
  module: TemplateModule;
  type: string;
  /** Optional niche/industry category */
  niche?: string | null;
  /** Optional group name for bundling templates */
  groupName?: string | null;
  /** Single entry for this template */
  entry: TemplateEntry;
}

/**
 * Data shape for editing an existing template (single template mode).
 */
export interface TemplateEditData {
  name: string;
  module: TemplateModule;
  type: string;
  entries: TemplateEntry[];
}

interface TemplateDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Called with array of pending templates to create (batch mode) */
  onBatchCreate?: (templates: PendingTemplate[]) => void;
  /** Called with updated data for a single template (edit mode) */
  onUpdate?: (data: TemplateEditData) => void;
  /** If provided, we're editing a single template */
  initialData?: TemplateEditData;
  /** Existing niche names for the CreatableCombobox options */
  nicheOptions?: string[];
  /** Existing group names for the CreatableCombobox options */
  groupOptions?: string[];
  isLoading?: boolean;
}

// Derive Module → Type dropdown options from the shared TEMPLATE_TYPES + TYPE_LABELS.
// Single source of truth: adding a new type in templateTypes.ts automatically appears here.
const MODULE_TYPE_OPTIONS: Record<TemplateModule, { value: string; label: string }[]> =
  Object.fromEntries(
    Object.entries(TEMPLATE_TYPES).map(([mod, types]) => [
      mod,
      types.map((t) => ({ value: t, label: TYPE_LABELS[t] ?? t })),
    ])
  ) as Record<TemplateModule, { value: string; label: string }[]>;

// ============================================
// Category badge colors
// ============================================

const CATEGORY_BADGE_COLORS: Record<TemplateCategory, string> = {
  reference_ad: "bg-emerald-50 text-emerald-700 border-emerald-200",
  tonality: "bg-orange-50 text-orange-700 border-orange-200",
  prompt: "bg-sky-50 text-sky-700 border-sky-200",
  preset: "bg-slate-50 text-slate-600 border-slate-200",
  brief: "bg-yellow-50 text-yellow-700 border-yellow-200",
  framework: "bg-indigo-50 text-indigo-700 border-indigo-200",
};

const MODULE_BADGE_COLORS: Record<TemplateModule, string> = {
  copy: "bg-blue-50 text-blue-700 border-blue-200",
  image: "bg-purple-50 text-purple-700 border-purple-200",
  video: "bg-amber-50 text-amber-700 border-amber-200",
  writer: "bg-teal-50 text-teal-700 border-teal-200",
  seo: "bg-sky-50 text-sky-700 border-sky-200",
};

// ============================================
// Placeholder helpers
// ============================================

function getLabelPlaceholder(category: TemplateCategory, module?: string, type?: string): string {
  // Video-specific placeholders — no category concept
  if (module === "video") {
    if (type === "enhance") return "e.g. UGC / TikTok Style";
    return type === "scene"
      ? "e.g. Product Demo - 3 Act Structure"
      : "e.g. Before/After Comparison";
  }
  switch (category) {
    case "reference_ad":
      return "e.g. Dental Clinic - Social Ads";
    case "tonality":
      return "e.g. Professional Warm Tone";
    case "prompt":
      return "e.g. CTA Style Rules";
    case "preset":
      return "e.g. Business Name Preset";
    case "brief":
      return "e.g. Campaign Creative Brief";
    case "framework":
      return "e.g. Hook-Story-Offer";
    default:
      return "Template name...";
  }
}

function getValuePlaceholder(category: TemplateCategory, module?: string, type?: string): string {
  // Video-specific placeholders — no category concept
  if (module === "video") {
    if (type === "enhance") return "System prompt for the AI — defines how the Enhance button rewrites prompts...";
    return type === "scene"
      ? "Describe the scene structure: acts, shots, transitions, pacing..."
      : "Describe the content pattern: hooks, storytelling style, CTA approach...";
  }
  switch (category) {
    case "reference_ad":
      return "Paste a full reference ad here — the AI will use it as a style/structure example...";
    case "tonality":
      return "Describe the tone of voice, what to include, or what to avoid...";
    case "prompt":
      return "Custom prompt for the AI — formatting rules, constraints, style notes...";
    case "preset":
      return "Pre-filled value (e.g. business name, language, target audience)...";
    case "brief":
      return "Enter the creative brief details, goals, and core messages...";
    case "framework":
      return "Describe the copywriting framework/structure the AI must follow (steps, sections, rules)...";
    default:
      return "Enter value...";
  }
}

// ============================================
// Pending Template Row (in the batch list)
// ============================================

interface PendingRowProps {
  template: PendingTemplate;
  index: number;
  onEdit: (index: number) => void;
  onRemove: (index: number) => void;
}

function PendingRow({ template, index, onEdit, onRemove }: PendingRowProps) {
  const [expanded, setExpanded] = useState(false);

  return (
    <div className="border border-border/60 rounded-md p-2.5 bg-muted/20 group">
      {/* Top row: badges + name + actions */}
      <div className="flex items-center gap-2">
        <Badge
          variant="outline"
          className={`text-[11px] shrink-0 ${MODULE_BADGE_COLORS[template.module] ?? ""}`}
        >
          {template.module}
        </Badge>
        <Badge
          variant="outline"
          className={`text-[11px] shrink-0 ${CATEGORY_BADGE_COLORS[template.entry.category] ?? ""}`}
        >
          {CATEGORY_LABELS[template.entry.category] ?? template.entry.category}
        </Badge>

        <span className="text-sm font-medium truncate flex-1">
          {template.name}
        </span>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => setExpanded(!expanded)}
          className="h-7 w-7 p-0 text-muted-foreground"
        >
          {expanded ? (
            <ChevronDown className="w-3.5 h-3.5" />
          ) : (
            <ChevronRight className="w-3.5 h-3.5" />
          )}
        </Button>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => onEdit(index)}
          className="h-7 w-7 p-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity"
        >
          <Pencil className="w-3 h-3" />
        </Button>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => onRemove(index)}
          className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive opacity-0 group-hover:opacity-100 transition-opacity"
        >
          <Trash2 className="w-3 h-3" />
        </Button>
      </div>

      {/* Collapsed: truncated value preview */}
      {!expanded && template.entry.value && (
        <p
          className="text-xs text-muted-foreground mt-1 truncate cursor-pointer"
          onClick={() => setExpanded(true)}
        >
          {template.entry.value}
        </p>
      )}

      {/* Expanded: full value */}
      {expanded && (
        <p className="text-sm text-foreground mt-2 whitespace-pre-wrap break-words">
          {template.entry.value}
        </p>
      )}
    </div>
  );
}

// ============================================
// Entry Row for Edit mode (existing entries within a template)
// ============================================

interface EntryRowProps {
  entry: TemplateEntry;
  index: number;
  onEdit: (index: number) => void;
  onRemove: (index: number) => void;
}

function EntryRow({ entry, index, onEdit, onRemove }: EntryRowProps) {
  const [expanded, setExpanded] = useState(false);

  return (
    <div className="border border-border/60 rounded-md p-2.5 bg-muted/20 group">
      <div className="flex items-center gap-2">
        <Badge
          variant="outline"
          className={`text-[11px] shrink-0 ${CATEGORY_BADGE_COLORS[entry.category] ?? ""}`}
        >
          {CATEGORY_LABELS[entry.category] ?? entry.category}
        </Badge>

        <span className="text-sm font-medium truncate flex-1">
          {entry.label}
        </span>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => setExpanded(!expanded)}
          className="h-7 w-7 p-0 text-muted-foreground"
        >
          {expanded ? (
            <ChevronDown className="w-3.5 h-3.5" />
          ) : (
            <ChevronRight className="w-3.5 h-3.5" />
          )}
        </Button>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => onEdit(index)}
          className="h-7 w-7 p-0 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity"
        >
          <Pencil className="w-3 h-3" />
        </Button>

        <Button
          type="button"
          variant="ghost"
          size="sm"
          onClick={() => onRemove(index)}
          className="h-7 w-7 p-0 text-muted-foreground hover:text-destructive opacity-0 group-hover:opacity-100 transition-opacity"
        >
          <Trash2 className="w-3 h-3" />
        </Button>
      </div>

      {!expanded && entry.value && (
        <p
          className="text-xs text-muted-foreground mt-1 truncate cursor-pointer"
          onClick={() => setExpanded(true)}
        >
          {entry.value}
        </p>
      )}

      {expanded && (
        <p className="text-sm text-foreground mt-2 whitespace-pre-wrap break-words">
          {entry.value}
        </p>
      )}
    </div>
  );
}

// ============================================
// Main Dialog Component
// ============================================

export function TemplateDialog({
  open,
  onOpenChange,
  onBatchCreate,
  onUpdate,
  initialData,
  nicheOptions = [],
  groupOptions = [],
  isLoading = false,
}: TemplateDialogProps) {
  const isEditing = !!initialData;

  // Inline-form fields
  const [module, setModule] = useState<TemplateModule>("copy");
  const [type, setType] = useState("social_ads");
  const [selectedCategory, setSelectedCategory] =
    useState<TemplateCategory>("reference_ad");
  const [niche, setNiche] = useState<string | null>(null);
  const [groupName, setGroupName] = useState<string | null>(null);
  const [entryLabel, setEntryLabel] = useState("");
  const [entryValue, setEntryValue] = useState("");

  // Batch list (create mode): each item becomes a unique template
  const [pendingTemplates, setPendingTemplates] = useState<PendingTemplate[]>(
    []
  );

  // Edit mode: entries within the single template being edited
  const [editEntries, setEditEntries] = useState<TemplateEntry[]>([]);
  const [editingEntryIndex, setEditingEntryIndex] = useState<number | null>(
    null
  );

  // Batch list: editing index for pending templates
  const [editingPendingIndex, setEditingPendingIndex] = useState<number | null>(
    null
  );

  // Reset form when dialog opens/closes or initialData changes
  useEffect(() => {
    if (open) {
      if (initialData) {
        // Edit mode: load the template
        setModule(initialData.module);
        setType(initialData.type);
        setEditEntries(initialData.entries);
      } else {
        // Create mode: clean slate
        setModule("copy");
        setType("social_ads");
        setPendingTemplates([]);
      }
      // Always reset inline-form fields
      setSelectedCategory("reference_ad");
      setNiche(null);
      setGroupName(null);
      setEntryLabel("");
      setEntryValue("");
      setEditingEntryIndex(null);
      setEditingPendingIndex(null);
    }
  }, [open, initialData]);

  // When module changes, cascade: reset type to first available
  // For video module, also auto-set category to 'prompt' (categories are irrelevant)
  useEffect(() => {
    const types = MODULE_TYPE_OPTIONS[module];
    if (types && types.length > 0 && !types.find((t) => t.value === type)) {
      setType(types[0].value);
    }
    if (module === "video" || module === "seo") {
      setSelectedCategory("prompt");
    }
  }, [module]);

  // ---- Inline-form: Add / Update ----

  const canAddEntry =
    entryLabel.trim().length > 0 && entryValue.trim().length > 0;

  const handleAdd = () => {
    if (!canAddEntry) return;

    if (isEditing) {
      // Edit mode: add/update entry within the template
      const newEntry: TemplateEntry = {
        key: `${selectedCategory}_${Date.now()}`,
        category: selectedCategory,
        label: entryLabel.trim(),
        value: entryValue.trim(),
      };

      if (editingEntryIndex !== null) {
        setEditEntries((prev) =>
          prev.map((e, i) =>
            i === editingEntryIndex ? { ...newEntry, key: e.key } : e
          )
        );
        setEditingEntryIndex(null);
      } else {
        setEditEntries((prev) => [...prev, newEntry]);
      }
    } else {
      // Create mode: add/update pending template in batch list
      const pending: PendingTemplate = {
        name: entryLabel.trim(),
        module,
        type,
        niche,
        groupName,
        entry: {
          key: `${selectedCategory}_${Date.now()}`,
          category: selectedCategory,
          label: entryLabel.trim(),
          value: entryValue.trim(),
        },
      };

      if (editingPendingIndex !== null) {
        setPendingTemplates((prev) =>
          prev.map((t, i) => (i === editingPendingIndex ? pending : t))
        );
        setEditingPendingIndex(null);
      } else {
        setPendingTemplates((prev) => [...prev, pending]);
      }
    }

    // Clear inline-form inputs (dropdowns retain state)
    setEntryLabel("");
    setEntryValue("");
  };

  // ---- Edit handlers for batch list ----

  const handleEditPending = (index: number) => {
    const t = pendingTemplates[index];
    setModule(t.module);
    setType(t.type);
    setSelectedCategory(t.entry.category);
    setNiche(t.niche ?? null);
    setGroupName(t.groupName ?? null);
    setEntryLabel(t.name);
    setEntryValue(t.entry.value);
    setEditingPendingIndex(index);
  };

  const handleRemovePending = (index: number) => {
    setPendingTemplates((prev) => prev.filter((_, i) => i !== index));
    if (editingPendingIndex === index) {
      handleCancelEdit();
    } else if (
      editingPendingIndex !== null &&
      index < editingPendingIndex
    ) {
      setEditingPendingIndex(editingPendingIndex - 1);
    }
  };

  // ---- Edit handlers for entry list (edit mode) ----

  const handleEditEntry = (index: number) => {
    const entry = editEntries[index];
    setSelectedCategory(entry.category);
    setEntryLabel(entry.label);
    setEntryValue(entry.value);
    setEditingEntryIndex(index);
  };

  const handleRemoveEntry = (index: number) => {
    setEditEntries((prev) => prev.filter((_, i) => i !== index));
    if (editingEntryIndex === index) {
      handleCancelEdit();
    } else if (editingEntryIndex !== null && index < editingEntryIndex) {
      setEditingEntryIndex(editingEntryIndex - 1);
    }
  };

  const handleCancelEdit = () => {
    setEditingPendingIndex(null);
    setEditingEntryIndex(null);
    setEntryLabel("");
    setEntryValue("");
  };

  // ---- Submit ----

  const handleSubmit = () => {
    if (isEditing && onUpdate) {
      // Edit mode: save the single template with updated entries
      const validEntries = editEntries.map((e, i) => ({
        ...e,
        key: e.key || `${e.category}_${i + 1}`,
      }));
      onUpdate({
        name: initialData!.name,
        module,
        type,
        entries: validEntries,
      });
    } else if (onBatchCreate) {
      // Create mode: each pending template becomes its own template
      onBatchCreate(pendingTemplates);
    }
  };

  const isCurrentlyEditing =
    editingPendingIndex !== null || editingEntryIndex !== null;

  const isValid = isEditing
    ? editEntries.length > 0
    : pendingTemplates.length > 0;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[640px] max-h-[85vh] flex flex-col">
        <DialogHeader>
          <DialogTitle>
            {isEditing ? "Edit Template" : "New Templates"}
          </DialogTitle>
          <DialogDescription>
            {isEditing
              ? "Update entries for this template."
              : "Add templates — each entry becomes its own unique template."}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2 overflow-y-auto flex-1">
          {/* ---- Inline-form: Add Entry Section ---- */}
          <div className="border border-border rounded-lg p-3 space-y-3 bg-muted/10">
            <p className="text-xs font-medium text-muted-foreground uppercase tracking-wide">
              {isCurrentlyEditing ? "Edit Entry" : "Add Template"}
            </p>

            {/* Module + Type dropdowns (+ Category for non-video modules) */}
            <div className={`grid ${module === "video" || module === "seo" ? "grid-cols-2" : "grid-cols-3"} gap-2`}>
              <div className="space-y-1">
                <Label className="text-xs text-muted-foreground">Module</Label>
                <Select
                  value={module}
                  onValueChange={(v: TemplateModule) => setModule(v)}
                  disabled={isEditing}
                >
                  <SelectTrigger className="h-8 text-sm">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="copy">Copy</SelectItem>
                    <SelectItem value="image">Image</SelectItem>
                    <SelectItem value="video">Video</SelectItem>
                    <SelectItem value="writer">Writer</SelectItem>
                    <SelectItem value="seo">SEO</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-1">
                <Label className="text-xs text-muted-foreground">Type</Label>
                <Select
                  value={type}
                  onValueChange={setType}
                  disabled={isEditing}
                >
                  <SelectTrigger className="h-8 text-sm">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {(MODULE_TYPE_OPTIONS[module] ?? []).map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Category dropdown — only for Copy/Image (Video & SEO auto-default to 'prompt') */}
              {module !== "video" && module !== "seo" && (
                <div className="space-y-1">
                  <Label className="text-xs text-muted-foreground">
                    Category
                  </Label>
                  <Select
                    value={selectedCategory}
                    onValueChange={(v: TemplateCategory) =>
                      setSelectedCategory(v)
                    }
                  >
                    <SelectTrigger className="h-8 text-sm">
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
                </div>
              )}
            </div>

            {/* Niche + Group (create mode only) */}
            {!isEditing && (
              <div className="grid grid-cols-2 gap-2">
                <div className="space-y-1">
                  <Label className="text-xs text-muted-foreground">Niche</Label>
                  <CreatableCombobox
                    options={nicheOptions}
                    value={niche}
                    onChange={setNiche}
                    placeholder="No niche (optional)"
                    emptyLabel="No niches yet"
                    className="w-full"
                  />
                </div>
                <div className="space-y-1">
                  <Label className="text-xs text-muted-foreground">Group</Label>
                  <CreatableCombobox
                    options={groupOptions}
                    value={groupName}
                    onChange={setGroupName}
                    placeholder="No group (optional)"
                    emptyLabel="No groups yet"
                    className="w-full"
                  />
                </div>
              </div>
            )}

            {/* Label input (= template name) */}
            <div className="space-y-1">
              <Label className="text-xs text-muted-foreground">
                {isEditing ? "Entry Label" : "Template Name"}
              </Label>
              <Input
                value={entryLabel}
                onChange={(e) => setEntryLabel(e.target.value)}
                placeholder={getLabelPlaceholder(selectedCategory, module, type)}
                className="h-9 text-sm"
              />
            </div>

            {/* Value textarea */}
            <Textarea
              value={entryValue}
              onChange={(e) => setEntryValue(e.target.value)}
              placeholder={getValuePlaceholder(selectedCategory, module, type)}
              className="text-sm min-h-[80px] resize-y"
              rows={selectedCategory === "reference_ad" ? 5 : 3}
            />

            {/* Source variables — shared with the list's inline Value editor
                (TemplateRow) so both paths teach the same thing. */}
            <SourceVarsHint module={module} category={selectedCategory} />

            {/* Add / Update button */}
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant={isCurrentlyEditing ? "default" : "outline"}
                size="sm"
                onClick={handleAdd}
                disabled={!canAddEntry}
                className="h-8 text-xs gap-1.5"
              >
                {isCurrentlyEditing ? (
                  <>
                    <Pencil className="w-3 h-3" />
                    Update
                  </>
                ) : (
                  <>
                    <Plus className="w-3.5 h-3.5" />
                    Add
                  </>
                )}
              </Button>

              {isCurrentlyEditing && (
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={handleCancelEdit}
                  className="h-8 text-xs"
                >
                  Cancel
                </Button>
              )}
            </div>
          </div>

          {/* ---- List ---- */}
          <div className="space-y-2">
            {isEditing ? (
              <>
                <Label className="text-sm font-medium">
                  Entries ({editEntries.length})
                </Label>
                {editEntries.length > 0 ? (
                  <div className="space-y-1.5">
                    {editEntries.map((entry, index) => (
                      <EntryRow
                        key={`${entry.key}-${index}`}
                        entry={entry}
                        index={index}
                        onEdit={handleEditEntry}
                        onRemove={handleRemoveEntry}
                      />
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground text-center py-3">
                    No entries yet. Fill in the fields above and click "Add".
                  </p>
                )}
              </>
            ) : (
              <>
                <Label className="text-sm font-medium">
                  Templates to create ({pendingTemplates.length})
                </Label>
                {pendingTemplates.length > 0 ? (
                  <div className="space-y-1.5">
                    {pendingTemplates.map((template, index) => (
                      <PendingRow
                        key={`pending-${index}`}
                        template={template}
                        index={index}
                        onEdit={handleEditPending}
                        onRemove={handleRemovePending}
                      />
                    ))}
                  </div>
                ) : (
                  <p className="text-xs text-muted-foreground text-center py-3">
                    No templates yet. Fill in the fields above and click "Add"
                    to queue a template.
                  </p>
                )}
              </>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isLoading}
          >
            Cancel
          </Button>
          <Button onClick={handleSubmit} disabled={!isValid || isLoading}>
            {isLoading
              ? "Saving..."
              : isEditing
                ? "Save Changes"
                : `Create ${pendingTemplates.length} Template${pendingTemplates.length !== 1 ? "s" : ""}`}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
