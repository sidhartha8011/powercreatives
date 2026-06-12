/**
 * CREATIVE MACHINE - Brands Module
 *
 * Simple list of saved brand/business profiles.
 * Reuses the same table pattern as Templates module.
 * Airtable-style minimalistic table with inline actions.
 *
 * Brands are reusable across modules (Copy, Image, Video).
 * This page is the management view — the BrandDropdown in
 * each module sidebar is the consumption view.
 *
 * NEW BRAND FLOW (two-step):
 *   1. BrandUrlStep: URL input + Fetch → auto-creates brand with all data
 *   2. BrandDialog: opens in edit mode with everything pre-filled
 *   OR: "Continue without URL" → opens BrandDialog in create mode
 *
 * Supports bulk selection with Duplicate + Delete actions
 * via the shared useRowSelection hook and BulkActionBar component.
 */

import { ModuleHeader } from "@/components/shared/ModuleHeader";
import { BulkActionBar } from "@/components/shared/BulkActionBar";
import { getIsAdmin } from "@/lib/pcmConfig";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Empty,
  EmptyDescription,
  EmptyHeader,
  EmptyMedia,
  EmptyTitle,
} from "@/components/ui/empty";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { trpc } from "@/lib/trpc";
import { useRowSelection } from "@/hooks/useRowSelection";
import {
  Building2,
  Copy,
  ExternalLink,
  MoreHorizontal,
  Pencil,
  Plus,
  Trash2,
} from "lucide-react";
import { useMemo, useState } from "react";
import { toast } from "sonner";
import { BrandDialog, type BrandFormData } from "./BrandDialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
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
import type { Brand } from "../../../../drizzle/schema";

// ============================================
// Component
// ============================================

export function BrandsModule() {
  // ---- Dialog state ----
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editBrand, setEditBrand] = useState<Brand | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Brand | null>(null);
  const [bulkDeleteConfirmOpen, setBulkDeleteConfirmOpen] = useState(false);

  // ---- Row selection ----
  const selection = useRowSelection();

  // ---- Data fetching ----
  const utils = trpc.useUtils();
  const { data: brands = [], isLoading } = trpc.brands.list.useQuery();

  // ---- Derived data ----
  const visibleIds = useMemo(() => brands.map((b) => b.id), [brands]);

  // ---- Mutations ----
  const createMutation = trpc.brands.create.useMutation({
    onSuccess: (brand) => {
      utils.brands.list.invalidate();
      // Keep dialog open — switch to edit mode so logo + images are available
      setEditBrand(brand as Brand);
      toast.success("Brand created — add logo and images");
    },
    onError: (err) => toast.error(err.message),
  });

  const updateMutation = trpc.brands.update.useMutation({
    onSuccess: () => {
      utils.brands.list.invalidate();
      setDialogOpen(false);
      setEditBrand(null);
      toast.success("Brand updated");
    },
    onError: (err) => toast.error(err.message),
  });

  const deleteMutation = trpc.brands.delete.useMutation({
    onSuccess: () => {
      utils.brands.list.invalidate();
      setDeleteTarget(null);
      toast.success("Brand deleted");
    },
    onError: (err) => toast.error(err.message),
  });

  const bulkDeleteMutation = trpc.brands.bulkDelete.useMutation({
    onSuccess: (result) => {
      utils.brands.list.invalidate();
      selection.clearAll();
      toast.success(
        `${result.deleted} brand${result.deleted !== 1 ? "s" : ""} deleted`
      );
    },
    onError: (err) => toast.error(err.message),
  });

  const bulkDuplicateMutation = trpc.brands.bulkDuplicate.useMutation({
    onSuccess: (result) => {
      utils.brands.list.invalidate();
      selection.clearAll();
      toast.success(
        `${result.created} brand${result.created !== 1 ? "s" : ""} duplicated`
      );
    },
    onError: (err) => toast.error(err.message),
  });

  // ---- Handlers ----

  /** "New Brand" button → opens BrandDialog in create mode */
  const handleCreate = () => {
    setEditBrand(null);
    setDialogOpen(true);
  };

  /** Table row click → open BrandDialog in edit mode */
  const handleEdit = (brand: Brand) => {
    setEditBrand(brand);
    setDialogOpen(true);
  };

  const handleSubmit = (data: BrandFormData) => {
    if (editBrand) {
      updateMutation.mutate({
        id: editBrand.id,
        name: data.name,
        website: data.website || null,
        niche: data.niche || null,
        location: data.location || null,
        phone: data.phone || null,
        businessSummary: data.businessSummary || null,
        language: data.language || null,
        colors: data.colors.length > 0 ? data.colors : null,
      });
    } else {
      createMutation.mutate({
        name: data.name,
        website: data.website || undefined,
        niche: data.niche || undefined,
        location: data.location || undefined,
        phone: data.phone || undefined,
        businessSummary: data.businessSummary || undefined,
        language: data.language || undefined,
        colors: data.colors.length > 0 ? data.colors : undefined,
      });
    }
  };

  const handleDelete = () => {
    if (!deleteTarget) return;
    deleteMutation.mutate({ id: deleteTarget.id });
  };

  const handleBulkDuplicate = () => {
    const ids = Array.from(selection.selectedIds);
    if (ids.length === 0) return;
    bulkDuplicateMutation.mutate({ ids });
  };

  const handleBulkDelete = () => {
    const ids = Array.from(selection.selectedIds);
    if (ids.length === 0) return;
    bulkDeleteMutation.mutate({ ids });
    setBulkDeleteConfirmOpen(false);
  };

  /** Format date for display */
  const formatDate = (date: Date | string | null) => {
    if (!date) return "—";
    const d = new Date(date);
    return d.toLocaleDateString("sv-SE", {
      year: "numeric",
      month: "short",
      day: "numeric",
    });
  };

  return (
    <div className="module-container animate-fade-in">
      <ModuleHeader
        title="Brands"
        description="Saved business profiles reusable across Copy, Image, and Video modules"
        action={
          // Brand creation is admin-only — team members view + use granted
          // brands (server enforces manage_options on the write routes).
          getIsAdmin() ? (
            <Button onClick={handleCreate} className="gap-2">
              <Plus className="w-4 h-4" />
              New Brand
            </Button>
          ) : undefined
        }
      />

      {/* Table or Empty State */}
      {isLoading ? (
        <div className="flex items-center justify-center py-20">
          <div className="animate-spin rounded-full h-6 w-6 border-2 border-primary border-t-transparent" />
        </div>
      ) : brands.length === 0 ? (
        <Empty className="py-16">
          <EmptyHeader>
            <EmptyMedia variant="icon">
              <Building2 className="w-5 h-5" />
            </EmptyMedia>
            <EmptyTitle>No brands yet</EmptyTitle>
            <EmptyDescription>
              Save a brand profile to reuse business info across modules.
              Brands are also auto-saved when you fetch a URL in the Copy module.
            </EmptyDescription>
          </EmptyHeader>
          <Button onClick={handleCreate} className="gap-2">
            <Plus className="w-4 h-4" />
            Create Brand
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
                    aria-label="Select all brands"
                    {...(selection.isIndeterminate(visibleIds)
                      ? { "data-state": "indeterminate" }
                      : {})}
                  />
                </TableHead>
                <TableHead style={{ width: "18%" }}>Name</TableHead>
                <TableHead style={{ width: "18%" }}>Website</TableHead>
                <TableHead style={{ width: "14%" }}>Niche</TableHead>
                <TableHead style={{ width: "14%" }}>Location</TableHead>
                <TableHead style={{ width: "8%" }}>Language</TableHead>
                <TableHead style={{ width: "12%" }}>Last Fetched</TableHead>
                <TableHead style={{ width: "5%" }}>Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {brands.map((brand) => (
                <TableRow
                  key={brand.id}
                  className={`group cursor-pointer hover:bg-muted/30 ${selection.isSelected(brand.id) ? "bg-primary/5" : ""
                    }`}
                  onClick={() => handleEdit(brand)}
                >
                  <TableCell className="px-2" onClick={(e) => e.stopPropagation()}>
                    <Checkbox
                      checked={selection.isSelected(brand.id)}
                      onCheckedChange={() => selection.toggle(brand.id)}
                      aria-label={`Select ${brand.name}`}
                    />
                  </TableCell>
                  <TableCell className="font-medium truncate max-w-0">
                    {brand.name}
                  </TableCell>
                  <TableCell className="truncate max-w-0">
                    {brand.website ? (
                      <span className="flex items-center gap-1 text-muted-foreground">
                        <span className="truncate">
                          {brand.website.replace(/^https?:\/\//, "")}
                        </span>
                        <ExternalLink
                          className="w-3 h-3 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
                          onClick={(e) => {
                            e.stopPropagation();
                            window.open(brand.website!, "_blank");
                          }}
                        />
                      </span>
                    ) : (
                      <span className="text-muted-foreground/50">—</span>
                    )}
                  </TableCell>
                  <TableCell className="truncate max-w-0 text-muted-foreground">
                    {brand.niche || "—"}
                  </TableCell>
                  <TableCell className="truncate max-w-0 text-muted-foreground">
                    {brand.location || "—"}
                  </TableCell>
                  <TableCell className="text-muted-foreground">
                    {brand.language?.toUpperCase() || "—"}
                  </TableCell>
                  <TableCell className="text-muted-foreground text-xs">
                    {formatDate(brand.scrapedAt)}
                  </TableCell>
                  <TableCell>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                          onClick={(e) => e.stopPropagation()}
                        >
                          <MoreHorizontal className="w-4 h-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem
                          onClick={(e) => {
                            e.stopPropagation();
                            handleEdit(brand);
                          }}
                        >
                          <Pencil className="w-3.5 h-3.5 mr-2" />
                          Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          className="text-destructive"
                          onClick={(e) => {
                            e.stopPropagation();
                            setDeleteTarget(brand);
                          }}
                        >
                          <Trash2 className="w-3.5 h-3.5 mr-2" />
                          Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
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
      <AlertDialog
        open={bulkDeleteConfirmOpen}
        onOpenChange={setBulkDeleteConfirmOpen}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              Delete {selection.count} brand{selection.count !== 1 ? "s" : ""}?
            </AlertDialogTitle>
            <AlertDialogDescription>
              This action cannot be undone. All selected brands will be
              permanently deleted.
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

      {/* Scroll padding */}
      <div className="h-24" aria-hidden />

      {/* Brand dialog — wizard (create) or edit mode */}
      <BrandDialog
        open={dialogOpen}
        onOpenChange={(open) => {
          setDialogOpen(open);
          if (!open) setEditBrand(null);
        }}
        onSubmit={handleSubmit}
        editBrand={editBrand}
        isLoading={createMutation.isPending || updateMutation.isPending}
        onBrandCreated={(brand) => setEditBrand(brand)}
      />

      {/* Single Delete Confirmation */}
      <AlertDialog
        open={!!deleteTarget}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Brand</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{deleteTarget?.name}"? This action
              cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
