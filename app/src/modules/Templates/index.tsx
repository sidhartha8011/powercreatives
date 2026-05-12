/**
 * CREATIVE MACHINE - Templates Module
 *
 * Flat table where every field is visible and editable inline:
 *   Niche | Group | Name | Module | Type | Subtype | Value | Date Added | Default | Actions
 *
 * Sortable column headers (click to cycle asc → desc → default).
 * Niche filter dropdown above table for quick filtering.
 * Create dialog uses batch-create mode: each entry becomes its own template.
 * All editing happens directly in the table — no edit dialogs.
 */

import { ModuleHeader } from "@/components/shared/ModuleHeader";
import { Button } from "@/components/ui/button";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { SortableTableHead } from "@/components/ui/sortable-table-head";
import {
  Table,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { useSortableTable } from "@/hooks/useSortableTable";
import { trpc } from "@/lib/trpc";
import { exportToCsv } from "@/lib/exportCsv";
import { parseCsvFile, validateCsvHeaders } from "@/lib/importCsv";
import {
  TEMPLATE_TYPES,
  type TemplateCategory,
  type TemplateEntry,
  type TemplateModule,
} from "@shared/templateTypes";
import { useRowSelection } from "@/hooks/useRowSelection";
import { BulkActionBar } from "@/components/shared/BulkActionBar";
import { Copy, Download, FileText, Plus, RotateCcw, Trash2, Upload } from "lucide-react";
import { useMemo, useRef, useState } from "react";
import { toast } from "sonner";
import { TemplateDialog, type PendingTemplate } from "./TemplateDialog";
import { TemplateRow } from "./TemplateRow";
import { Checkbox } from "@/components/ui/checkbox";
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

// ============================================
// Filter tabs
// ============================================

type FilterTab = "all" | "copy" | "image" | "video" | "writer";

const FILTER_TABS: { value: FilterTab; label: string }[] = [
  { value: "all", label: "All" },
  { value: "copy", label: "Copy" },
  { value: "image", label: "Image" },
  { value: "video", label: "Video" },
  { value: "writer", label: "Writer" },
];

// ============================================
// Sort column keys
// ============================================

type SortKey =
  | "name"
  | "module"
  | "type"
  | "subtype"
  | "niche"
  | "groupName"
  | "createdAt";

// ============================================
// Helpers
// ============================================

/**
 * Safely parse the `fields` column into TemplateEntry[].
 * Handles both new TemplateEntry[] format and legacy Record<string,string>.
 */
function parseEntries(fields: unknown): TemplateEntry[] {
  if (Array.isArray(fields)) {
    return fields as TemplateEntry[];
  }
  if (fields && typeof fields === "object" && !Array.isArray(fields)) {
    return Object.entries(fields as Record<string, string>).map(
      ([key, value]) => ({
        key,
        category: "preset" as TemplateCategory,
        label: key,
        value: String(value),
      })
    );
  }
  return [];
}

/**
 * Extract the first entry's value from a template.
 * Since batch-create makes each template = 1 entry, this is the primary value.
 */
function getFirstEntryValue(fields: unknown): string {
  const entries = parseEntries(fields);
  return entries.length > 0 ? entries[0].value : "";
}

/**
 * Extract the first entry's category (subtype) from a template.
 */
function getFirstEntrySubtype(fields: unknown): TemplateCategory {
  const entries = parseEntries(fields);
  return entries.length > 0 ? entries[0].category : "reference_ad";
}

// ============================================
// Component
// ============================================

export function TemplatesModule() {
  const [activeFilter, setActiveFilter] = useState<FilterTab>("all");
  const [nicheFilter, setNicheFilter] = useState<string>("__all__");
  const [dialogOpen, setDialogOpen] = useState(false);
  const [isBatchCreating, setIsBatchCreating] = useState(false);
  const [bulkDeleteConfirmOpen, setBulkDeleteConfirmOpen] = useState(false);

  // ---- Row selection ----
  const selection = useRowSelection();

  // ---- Data fetching ----
  const utils = trpc.useUtils();
  const { data: templates = [], isLoading } = trpc.templates.list.useQuery(
    activeFilter === "all" ? undefined : { module: activeFilter }
  );

  // ---- Derived data ----

  /** Unique niche names across all templates (for CreatableCombobox + filter options). */
  const nicheOptions = useMemo(
    () =>
      Array.from(
        new Set(
          templates
            .map((t) => t.niche)
            .filter((n): n is string => n != null && n.length > 0)
        )
      ).sort(),
    [templates]
  );

  /** Unique group names across all templates (for CreatableCombobox options). */
  const groupOptions = useMemo(
    () =>
      Array.from(
        new Set(
          templates
            .map((t) => t.groupName)
            .filter((g): g is string => g != null && g.length > 0)
        )
      ).sort(),
    [templates]
  );

  /** Apply niche filter to templates. */
  const filteredTemplates = useMemo(() => {
    if (nicheFilter === "__all__") return templates;
    if (nicheFilter === "__none__")
      return templates.filter((t) => !t.niche || t.niche.length === 0);
    return templates.filter((t) => t.niche === nicheFilter);
  }, [templates, nicheFilter]);

  // ---- Sorting ----
  const sortAccessors = useMemo(
    () => ({
      name: (row: (typeof templates)[number]) => row.name.toLowerCase(),
      module: (row: (typeof templates)[number]) => row.module,
      type: (row: (typeof templates)[number]) => row.type,
      subtype: (row: (typeof templates)[number]) =>
        getFirstEntrySubtype(row.entries),
      niche: (row: (typeof templates)[number]) =>
        row.niche?.toLowerCase() ?? null,
      groupName: (row: (typeof templates)[number]) =>
        row.groupName?.toLowerCase() ?? null,
      createdAt: (row: (typeof templates)[number]) =>
        new Date(row.createdAt).getTime(),
    }),
    []
  );

  const { sortKey, sortDir, toggleSort, sortedData } = useSortableTable<
    (typeof templates)[number],
    SortKey
  >(filteredTemplates, {
    defaultKey: "createdAt",
    defaultDir: "desc",
    accessors: sortAccessors,
  });

  // ---- Mutations ----
  const createMutation = trpc.templates.create.useMutation({
    onError: (err) => toast.error(err.message),
  });

  const updateMutation = trpc.templates.update.useMutation({
    onSuccess: () => {
      utils.templates.list.invalidate();
    },
    onError: (err) => toast.error(err.message),
  });

  const deleteMutation = trpc.templates.delete.useMutation({
    onSuccess: () => {
      utils.templates.list.invalidate();
      toast.success("Template deleted");
    },
    onError: (err) => toast.error(err.message),
  });

  const setDefaultMutation = trpc.templates.setDefault.useMutation({
    onSuccess: () => {
      utils.templates.list.invalidate();
    },
    onError: (err) => toast.error(err.message),
  });

  const bulkDeleteMutation = trpc.templates.bulkDelete.useMutation({
    onSuccess: (result) => {
      utils.templates.list.invalidate();
      selection.clearAll();
      toast.success(`${result.deleted} template${result.deleted !== 1 ? "s" : ""} deleted`);
    },
    onError: (err) => toast.error(err.message),
  });

  const bulkDuplicateMutation = trpc.templates.bulkDuplicate.useMutation({
    onSuccess: (result) => {
      utils.templates.list.invalidate();
      selection.clearAll();
      toast.success(`${result.created} template${result.created !== 1 ? "s" : ""} duplicated`);
    },
    onError: (err) => toast.error(err.message),
  });

  const reseedMutation = trpc.templates.reseed.useMutation({
    onSuccess: () => {
      utils.templates.list.invalidate();
      toast.success("Default templates restored");
    },
    onError: (err) => toast.error(err.message),
  });

  // ---- Handlers ----

  /** Batch create: each PendingTemplate becomes its own template. */
  const handleBatchCreate = async (pendingTemplates: PendingTemplate[]) => {
    if (pendingTemplates.length === 0) return;

    setIsBatchCreating(true);
    let successCount = 0;
    let errorCount = 0;

    for (const pending of pendingTemplates) {
      try {
        await createMutation.mutateAsync({
          name: pending.name,
          module: pending.module,
          type: pending.type,
          niche: pending.niche,
          groupName: pending.groupName,
          entries: [pending.entry],
        });
        successCount++;
      } catch {
        errorCount++;
      }
    }

    setIsBatchCreating(false);
    utils.templates.list.invalidate();
    setDialogOpen(false);

    if (errorCount === 0) {
      toast.success(
        `${successCount} template${successCount !== 1 ? "s" : ""} created`
      );
    } else {
      toast.warning(`${successCount} created, ${errorCount} failed`);
    }
  };

  /** Inline update: name only. */
  const handleUpdateName = (id: number, name: string) => {
    updateMutation.mutate({ id, name });
  };

  /** Inline update: module (and reset type to first available). */
  const handleUpdateModule = (id: number, module: string) => {
    const types = TEMPLATE_TYPES[module as TemplateModule] ?? [];
    const newType = types[0] ?? "generation";
    updateMutation.mutate({
      id,
      module: module as "copy" | "image" | "video",
      type: newType,
    });
  };

  /** Inline update: type only. */
  const handleUpdateType = (id: number, type: string) => {
    updateMutation.mutate({ id, type });
  };

  /** Inline update: subtype (updates the first entry's category). */
  const handleUpdateSubtype = (id: number, newSubtype: TemplateCategory) => {
    const template = templates.find((t) => t.id === id);
    if (!template) return;

    const entries = parseEntries(template.entries);
    if (entries.length > 0) {
      entries[0].category = newSubtype;
    } else {
      entries.push({
        key: `entry_${Date.now()}`,
        category: newSubtype,
        label: template.name,
        value: "",
      });
    }
    updateMutation.mutate({ id, entries });
  };

  /** Inline update: niche (set or clear). */
  const handleUpdateNiche = (id: number, niche: string | null) => {
    updateMutation.mutate({ id, niche });
  };

  /** Inline update: group name (set or clear). */
  const handleUpdateGroup = (id: number, groupName: string | null) => {
    updateMutation.mutate({ id, groupName });
  };

  /** Inline update: value (updates the first entry's value). */
  const handleUpdateValue = (id: number, newValue: string) => {
    const template = templates.find((t) => t.id === id);
    if (!template) return;

    const entries = parseEntries(template.entries);
    if (entries.length > 0) {
      entries[0].value = newValue;
    } else {
      entries.push({
        key: `entry_${Date.now()}`,
        category: "reference_ad",
        label: template.name,
        value: newValue,
      });
    }
    updateMutation.mutate({ id, entries });
  };

  /** Duplicate: create a copy with " (copy)" suffix. */
  const handleDuplicate = async (id: number) => {
    const template = templates.find((t) => t.id === id);
    if (!template) return;

    const entries = parseEntries(template.entries);
    try {
      await createMutation.mutateAsync({
        name: `${template.name} (copy)`,
        module: template.module,
        type: template.type,
        niche: template.niche,
        groupName: template.groupName,
        entries:
          entries.length > 0
            ? entries
            : [
              {
                key: "entry_1",
                category: "reference_ad",
                label: template.name,
                value: "",
              },
            ],
      });
      utils.templates.list.invalidate();
      toast.success("Template duplicated");
    } catch {
      // Error handled by mutation onError
    }
  };

  const handleDelete = (id: number) => {
    deleteMutation.mutate({ id });
  };

  const handleToggleDefault = (id: number, currentDefault: boolean) => {
    setDefaultMutation.mutate({ id, isDefault: !currentDefault });
  };

  /** Bulk duplicate selected templates. */
  const handleBulkDuplicate = () => {
    const ids = Array.from(selection.selectedIds);
    if (ids.length === 0) return;
    bulkDuplicateMutation.mutate({ ids });
  };

  /** Bulk delete selected templates (after confirmation). */
  const handleBulkDelete = () => {
    const ids = Array.from(selection.selectedIds);
    if (ids.length === 0) return;
    bulkDeleteMutation.mutate({ ids });
    setBulkDeleteConfirmOpen(false);
  };

  /** Export all visible templates as CSV. */
  const handleExportCsv = () => {
    if (sortedData.length === 0) {
      toast.error("No templates to export");
      return;
    }

    const headers = ["Name", "Module", "Type", "Niche", "Group", "Subtype", "Value", "Default", "Created"];
    const rows = sortedData.map((t) => [
      t.name,
      t.module,
      t.type ?? "",
      t.niche ?? "",
      t.groupName ?? "",
      getFirstEntrySubtype(t.entries),
      getFirstEntryValue(t.entries),
      t.isDefault ? "Yes" : "No",
      t.createdAt ? new Date(t.createdAt).toISOString().slice(0, 10) : "",
    ]);
    const date = new Date().toISOString().slice(0, 10);
    exportToCsv(`templates-export-${date}.csv`, headers, rows);
    toast.success(`Exported ${rows.length} template${rows.length !== 1 ? "s" : ""}`);
  };

  /** Hidden file input ref for CSV import. */
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [isImporting, setIsImporting] = useState(false);

  /** Import templates from a CSV file. */
  const handleImportCsv = async (file: File) => {
    setIsImporting(true);
    try {
      const rows = await parseCsvFile(file);
      if (rows.length < 2) {
        toast.error("CSV file is empty or has no data rows");
        return;
      }

      // Validate headers match export format
      const expectedHeaders = ["Name", "Module", "Type", "Niche", "Group", "Subtype", "Value"];
      const { valid, missing } = validateCsvHeaders(rows[0], expectedHeaders);
      if (!valid) {
        toast.error(`Missing CSV columns: ${missing.join(", ")}`);
        return;
      }

      // Map header indices (case-insensitive)
      const headerMap = new Map(rows[0].map((h, i) => [h.trim().toLowerCase(), i]));
      const dataRows = rows.slice(1).filter((r) => r.some((cell) => cell.trim()));

      let successCount = 0;
      let errorCount = 0;

      for (const row of dataRows) {
        const name = row[headerMap.get("name") ?? 0]?.trim();
        const module = row[headerMap.get("module") ?? 1]?.trim().toLowerCase();
        const type = row[headerMap.get("type") ?? 2]?.trim() || undefined;
        const niche = row[headerMap.get("niche") ?? 3]?.trim() || undefined;
        const groupName = row[headerMap.get("group") ?? 4]?.trim() || undefined;
        const subtype = row[headerMap.get("subtype") ?? 5]?.trim() || undefined;
        const value = row[headerMap.get("value") ?? 6]?.trim() || "";

        if (!name || !module) {
          errorCount++;
          continue;
        }

        try {
          await createMutation.mutateAsync({
            name,
            module,
            type,
            niche,
            groupName,
            entries: [{ category: subtype ?? "default", value }],
          });
          successCount++;
        } catch {
          errorCount++;
        }
      }

      utils.templates.list.invalidate();
      if (errorCount === 0) {
        toast.success(`Imported ${successCount} template${successCount !== 1 ? "s" : ""}`);
      } else {
        toast.warning(`Imported ${successCount}, failed ${errorCount}`);
      }
    } catch {
      toast.error("Failed to parse CSV file");
    } finally {
      setIsImporting(false);
      // Reset file input so same file can be re-selected
      if (fileInputRef.current) fileInputRef.current.value = "";
    }
  };

  /** Visible row IDs for select-all. */
  const visibleIds = useMemo(() => sortedData.map((t) => t.id), [sortedData]);

  return (
    <div className="module-container animate-fade-in">
      <ModuleHeader
        title="Templates"
        description="Reference ads, tonality rules, prompts, and presets for your AI-generated content"
        action={
          <div className="flex gap-2">
            <Button variant="outline" size="icon" onClick={handleExportCsv} title="Export CSV">
              <Download className="w-4 h-4" />
            </Button>
            <Button
              variant="outline"
              size="icon"
              onClick={() => fileInputRef.current?.click()}
              disabled={isImporting}
              title="Import CSV"
            >
              <Upload className="w-4 h-4" />
            </Button>
            <input
              ref={fileInputRef}
              type="file"
              accept=".csv"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) handleImportCsv(file);
              }}
            />
            <Button
              variant="outline"
              size="icon"
              onClick={() => reseedMutation.mutate({})}
              disabled={reseedMutation.isPending}
              title="Restore default templates"
            >
              <RotateCcw className={`w-4 h-4 ${reseedMutation.isPending ? "animate-spin" : ""}`} />
            </Button>
            <Button onClick={() => setDialogOpen(true)} className="gap-2">
              <Plus className="w-4 h-4" />
              New Template
            </Button>
          </div>
        }
      />

      {/* Filter row: Module tabs + Niche dropdown */}
      <div
        className="flex items-center gap-4 mb-4"
        style={{
          borderBottom: "1px solid #ededed",
          paddingBottom: "0.5rem",
        }}
      >
        {/* Module filter tabs */}
        <div className="flex gap-1">
          {FILTER_TABS.map((tab) => (
            <button
              key={tab.value}
              onClick={() => setActiveFilter(tab.value)}
              className="transition-all"
              style={{
                padding: "4px 12px",
                borderRadius: "999px",
                fontSize: "0.8rem",
                fontWeight: activeFilter === tab.value ? 600 : 500,
                background:
                  activeFilter === tab.value ? "#e7f5ff" : "transparent",
                color: activeFilter === tab.value ? "#007bff" : "#666",
                border: "1px solid transparent",
              }}
              onMouseEnter={(e) => {
                if (activeFilter !== tab.value) {
                  e.currentTarget.style.background = "#f1f3f5";
                }
              }}
              onMouseLeave={(e) => {
                if (activeFilter !== tab.value) {
                  e.currentTarget.style.background = "transparent";
                }
              }}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {/* Niche filter dropdown */}
        {nicheOptions.length > 0 && (
          <Select value={nicheFilter} onValueChange={setNicheFilter}>
            <SelectTrigger className="h-8 w-[180px] text-xs">
              <SelectValue placeholder="All Niches" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="__all__">All Niches</SelectItem>
              <SelectItem value="__none__">No Niche</SelectItem>
              {nicheOptions.map((niche) => (
                <SelectItem key={niche} value={niche}>
                  {niche}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        )}
      </div>

      {/* Table or Empty State */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <div className="animate-spin rounded-full h-6 w-6 border-2 border-primary border-t-transparent" />
        </div>
      ) : templates.length === 0 ? (
        <Empty className="py-16">
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <FileText className="w-5 h-5" />
            </EmptyMedia>
            <EmptyTitle>No templates yet</EmptyTitle>
            <EmptyDescription>
              Create your first template with reference ads, tonality rules, and
              presets.
            </EmptyDescription>
          </EmptyHeader>
          <Button onClick={() => setDialogOpen(true)} className="gap-2">
            <Plus className="w-4 h-4" />
            Create Template
          </Button>
        </Empty>
      ) : (
        <div className="card-powerkeys overflow-hidden">
          <Table>
            <TableHeader>
              <TableRow className="bg-muted/60">
                <TableHead style={{ width: "3%" }} className="px-2">
                  <Checkbox
                    checked={selection.isAllSelected(visibleIds)}
                    onCheckedChange={() => selection.toggleAll(visibleIds)}
                    aria-label="Select all templates"
                    {...(selection.isIndeterminate(visibleIds) ? { "data-state": "indeterminate" } : {})}
                  />
                </TableHead>
                <SortableTableHead
                  columnKey="niche"
                  label="Niche"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "9%" }}
                />
                <SortableTableHead
                  columnKey="groupName"
                  label="Group"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "9%" }}
                />
                <SortableTableHead
                  columnKey="name"
                  label="Name"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "12%" }}
                />
                <SortableTableHead
                  columnKey="module"
                  label="Module"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "7%" }}
                />
                <SortableTableHead
                  columnKey="type"
                  label="Type"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "8%" }}
                />
                <SortableTableHead
                  columnKey="subtype"
                  label="Subtype"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "8%" }}
                />
                <TableHead style={{ width: "24%" }}>Value</TableHead>
                <SortableTableHead
                  columnKey="createdAt"
                  label="Date Added"
                  currentSortKey={sortKey}
                  currentSortDir={sortDir}
                  onToggle={toggleSort}
                  style={{ width: "7%" }}
                />
                <TableHead style={{ width: "5%" }}>Default</TableHead>
                <TableHead style={{ width: "5%" }}>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <tbody>
              {sortedData.map((template) => (
                <TemplateRow
                  key={template.id}
                  template={template}
                  value={getFirstEntryValue(template.entries)}
                  subtype={getFirstEntrySubtype(template.entries)}
                  nicheOptions={nicheOptions}
                  groupOptions={groupOptions}
                  isSelected={selection.isSelected(template.id)}
                  onToggleSelect={selection.toggle}
                  onUpdateName={handleUpdateName}
                  onUpdateModule={handleUpdateModule}
                  onUpdateType={handleUpdateType}
                  onUpdateSubtype={handleUpdateSubtype}
                  onUpdateNiche={handleUpdateNiche}
                  onUpdateGroup={handleUpdateGroup}
                  onUpdateValue={handleUpdateValue}
                  onDelete={handleDelete}
                  onDuplicate={handleDuplicate}
                  onToggleDefault={handleToggleDefault}
                />
              ))}
            </tbody>
          </Table>
        </div>
      )}

      {/* Bulk Action Bar */}
      <BulkActionBar count={selection.count} onClear={selection.clearAll}>
        <BulkActionBar.Action
          icon={Copy}
          label="Duplicate"
          onClick={handleBulkDuplicate}
          loading={bulkDuplicateMutation.isPending}
        />
        <BulkActionBar.Action
          icon={Trash2}
          label="Delete"
          onClick={() => setBulkDeleteConfirmOpen(true)}
          variant="destructive"
        />
      </BulkActionBar>

      {/* Bulk Delete Confirmation */}
      <AlertDialog open={bulkDeleteConfirmOpen} onOpenChange={setBulkDeleteConfirmOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {selection.count} template{selection.count !== 1 ? "s" : ""}?</AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. All selected templates will be permanently deleted.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleBulkDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Scroll padding — ensures last row is comfortably reachable */}
      <div className="h-24" aria-hidden />

      {/* Create Dialog (batch mode) */}
      <TemplateDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        onBatchCreate={handleBatchCreate}
        nicheOptions={nicheOptions}
        groupOptions={groupOptions}
        isLoading={isBatchCreating}
      />
    </div>
  );
}
