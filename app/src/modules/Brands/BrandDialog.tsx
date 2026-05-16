/**
 * CREATIVE MACHINE - Brand Dialog (Wizard Orchestrator)
 *
 * Single Radix Dialog that handles both create and edit modes.
 *
 * CREATE MODE (wizard):
 *   Step 'url':    UrlStep — URL input + "Continue without URL"
 *   Step 'logo':   LogoSelectionContent (REUSED from shared component, rendered INLINE)
 *   Step 'colors': LogoSelectionContent color step (automatic after logo confirm)
 *   Step 'form':   Full brand form with all fields pre-filled
 *
 * EDIT MODE:
 *   Opens directly at 'form' step with data pre-filled.
 *   "Fetch Brand" button re-runs fetch → logo/colors steps.
 *
 * ARCHITECTURE:
 *   - ONE Dialog wrapping all steps (solves Radix dual-Dialog conflict)
 *   - Wizard transitions via currentStep state
 *   - LogoSelectionContent imported from shared — ZERO duplication
 *   - useBrandFetch returns FetchResult — this component drives transitions
 *
 * @package PowerCreatives
 */

import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Separator } from "@/components/ui/separator";
import { Globe, Loader2, ImageIcon } from "lucide-react";
import { useState, useCallback, useEffect, useRef } from "react";
import { toast } from "sonner";
import { trpc, apiFetch } from "@/lib/trpc";
import { ReferenceImageSelector } from "@/components/shared/ReferenceImageSelector";
import { LogoSelectionContent } from "@/components/shared/LogoSelectionDialog";
import { Label } from "@/components/ui/label";
import type { BrandAsset } from "@shared/brandTypes";

// Sub-components & hooks
import { useBrandForm } from "./hooks/useBrandForm";
import { useBrandFetch } from "./hooks/useBrandFetch";
import { BrandFormFields } from "./components/BrandFormFields";
import { BrandLogoSection } from "@/components/shared/BrandLogoSection";
import { BrandColorSection } from "@/components/shared/BrandColorSection";
import { UrlStep } from "./steps/UrlStep";
import { MAX_COLORS, EMPTY_WIZARD_DATA } from "./types";
import type { BrandFormData, BrandDialogProps, WizardStep, WizardData } from "./types";

// Re-export types for consumers (index.tsx)
export type { BrandFormData } from "./types";

// ============================================
// Component
// ============================================

export function BrandDialog({
  open,
  onOpenChange,
  onSubmit,
  editBrand,
  isLoading,
  onBrandCreated,
}: BrandDialogProps) {
  // ── Wizard state ──
  const [currentStep, setCurrentStep] = useState<WizardStep>(
    editBrand ? "form" : "url"
  );
  const [wizardData, setWizardData] = useState<WizardData>(EMPTY_WIZARD_DATA);
  const [isConfirmingLogo, setIsConfirmingLogo] = useState(false);

  // Reset wizard step only when dialog OPENS (false→true), not when editBrand changes mid-session
  const prevOpenRef = useRef(false);
  useEffect(() => {
    if (open && !prevOpenRef.current) {
      // Dialog just opened — set correct step based on mode
      setCurrentStep(editBrand ? "form" : "url");
      setWizardData(EMPTY_WIZARD_DATA);
    }
    prevOpenRef.current = open;
  }, [open]);

  // Shared ref to prevent form reset during fetch
  const isFetchingRef = { current: false };

  // ── Hooks (one call each — no duplicates) ──
  const formHook = useBrandForm({
    editBrand,
    open,
    isFetchingRef,
  });

  const fetchHook = useBrandFetch({
    form: formHook.form,
    setForm: formHook.setForm,
    editBrand,
    onBrandCreated,
  });

  // Sync the shared ref
  isFetchingRef.current = fetchHook.isFetching;

  // ── Brand data for visual identity panel (edit mode) ──
  const liveBrand = fetchHook.brandQuery.data;
  const liveAssets: BrandAsset[] = (liveBrand as any)?.assets ?? [];
  const logoAsset = liveAssets.length > 0 ? liveAssets[0] : null;

  // ── Mutations (for logo save in wizard + asset reorder) ──
  const addAssetFromUrlMutation = trpc.brands.addAssetFromUrl.useMutation();
  const reorderMutation = trpc.brands.reorderAssets.useMutation();
  const utils = trpc.useUtils();

  // ── Reset wizard when dialog closes ──
  const handleOpenChange = useCallback(
    (isOpen: boolean) => {
      if (!isOpen) {
        setCurrentStep(editBrand ? "form" : "url");
        setWizardData(EMPTY_WIZARD_DATA);
      } else {
        setCurrentStep(editBrand ? "form" : "url");
      }
      onOpenChange(isOpen);
    },
    [editBrand, onOpenChange]
  );

  // ── Wizard step transitions ──

  /** Fetch URL → advance to logo step or form (if no images) */
  const handleFetch = useCallback(async () => {
    const result = await fetchHook.handleFetchBrand();
    if (result && result.images.length > 0) {
      setWizardData((prev) => ({
        ...prev,
        scrapedImages: result.images,
        cssColors: result.colors,
      }));
      setCurrentStep("logo");
    } else {
      // No images found — go directly to form
      setCurrentStep("form");
    }
  }, [fetchHook]);

  /** Logo selected → save as asset → return extracted logo colors for pre-selection */
  const handleLogoSelected = useCallback(
    async (logoUrl: string): Promise<string[]> => {
      const brandId = editBrand?.id ?? fetchHook.lastCreatedBrandId;
      if (!brandId) return [];

      setIsConfirmingLogo(true);
      try {
        const result = await addAssetFromUrlMutation.mutateAsync({ brandId, imageUrl: logoUrl, role: 'logo' });
        fetchHook.brandQuery.refetch();
        utils.brands.list.invalidate();
        toast.success("Logo saved");

        // Extract logo colors from backend response
        const logoColors = (result as any)?.extractedColors as string[] | undefined;
        if (logoColors && logoColors.length > 0) {
          // Merge logo colors into form.colors so they appear in the color step
          formHook.setForm((prev) => {
            const existing = new Set(prev.colors.map((c) => c.toLowerCase()));
            const newColors = logoColors.filter((c) => !existing.has(c.toLowerCase()));
            return { ...prev, colors: [...prev.colors, ...newColors].slice(0, MAX_COLORS) };
          });
          return logoColors;
        }
        return [];
      } catch {
        toast.error("Failed to save logo");
        return [];
      } finally {
        setIsConfirmingLogo(false);
      }
    },
    [editBrand, fetchHook.lastCreatedBrandId, addAssetFromUrlMutation, fetchHook.brandQuery, utils, formHook]
  );

  /** Colors assigned in LogoSelectionContent → merge into form */
  const handleColorsAssigned = useCallback(
    (primary: string, secondary: string) => {
      const newColors = [
        primary,
        secondary,
        ...formHook.form.colors.filter(
          (c) => c.toLowerCase() !== primary.toLowerCase() && c.toLowerCase() !== secondary.toLowerCase()
        ),
      ].slice(0, MAX_COLORS);

      formHook.setForm((prev) => ({ ...prev, colors: newColors }));

      // Persist colors to DB if brand exists
      const brandId = editBrand?.id ?? fetchHook.lastCreatedBrandId;
      if (brandId) {
        apiFetch(`brands/${brandId}/colors`, {
          method: "POST",
          body: JSON.stringify({ brandId, colors: newColors }),
        }).catch(() => {});
      }
    },
    [editBrand, fetchHook.lastCreatedBrandId, formHook]
  );

  /** LogoSelectionContent is done (after logo + colors) → advance to form */
  const handleLogoDone = useCallback(() => {
    setCurrentStep("form");
  }, []);

  // ── Form step handlers ──

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!formHook.form.name.trim()) return;
    const cleanedColors = formHook.form.colors.filter((c) => c && c.trim());
    onSubmit({ ...formHook.form, colors: cleanedColors });
  };

  const handleAssetsChanged = useCallback(() => {
    fetchHook.brandQuery.refetch();
    utils.brands.list.invalidate();
  }, [fetchHook.brandQuery, utils]);

  /** Move an asset to position 0 (set as logo) */
  const handleSetAsLogo = useCallback(
    async (index: number) => {
      if (!editBrand) return;
      if (index <= 0 || index >= liveAssets.length) return;

      const newOrder = liveAssets.map((a) => a.fileKey);
      const [moved] = newOrder.splice(index, 1);
      newOrder.unshift(moved);

      try {
        await reorderMutation.mutateAsync({
          brandId: editBrand.id,
          fileKeys: newOrder,
        });
        fetchHook.brandQuery.refetch();
        utils.brands.list.invalidate();
        toast.success("Logo updated");
      } catch {
        toast.error("Failed to set logo");
      }
    },
    [editBrand, liveAssets, reorderMutation, fetchHook.brandQuery, utils]
  );

  // ── Derived state ──
  const isEdit = !!editBrand;
  const hasWebsite = formHook.form.website.trim().length > 0;

  // Dialog title + size adapts to current step
  const dialogTitle = (() => {
    if (isEdit) return "Edit Brand";
    switch (currentStep) {
      case "url": return "New Brand";
      case "logo": return "Select Logo";
      case "form": return "New Brand";
    }
  })();

  const dialogMaxWidth = currentStep === "form"
    ? "sm:max-w-[780px]"
    : "sm:max-w-[560px]";

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className={`${dialogMaxWidth} max-h-[85vh] overflow-y-auto`}>
        <DialogHeader className="flex flex-row items-center justify-between gap-3 space-y-0">
          <div className="flex items-center gap-3">
            <DialogTitle>{dialogTitle}</DialogTitle>
            {/* Step indicator during wizard (not in edit mode) */}
            {!isEdit && currentStep !== "form" && (
              <span className="text-xs text-muted-foreground bg-muted px-2 py-0.5 rounded-full">
                Step {currentStep === "url" ? 1 : 2} of 3
              </span>
            )}
          </div>

          {/* Fetch Brand button — only visible in form step */}
          {currentStep === "form" && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleFetch}
              disabled={!hasWebsite || fetchHook.isFetching || isLoading}
              className="shrink-0 gap-1.5 h-8 mr-6"
              title="Fetch brand details, images, and colors from website"
            >
              {fetchHook.isFetching ? (
                <Loader2 size={14} className="animate-spin" />
              ) : (
                <Globe size={14} />
              )}
              {fetchHook.isFetching ? "Fetching..." : "Fetch Brand"}
            </Button>
          )}
        </DialogHeader>

        {/* ════════════════════════════════════════ */}
        {/* STEP: URL INPUT                         */}
        {/* ════════════════════════════════════════ */}
        {currentStep === "url" && (
          <UrlStep
            url={formHook.form.website}
            onUrlChange={(url) => formHook.setForm((prev) => ({ ...prev, website: url }))}
            onFetch={handleFetch}
            onSkipToForm={() => setCurrentStep("form")}
            isFetching={fetchHook.isFetching}
          />
        )}

        {/* ════════════════════════════════════════ */}
        {/* STEP: LOGO + COLORS (reused component)  */}
        {/* ════════════════════════════════════════ */}
        {currentStep === "logo" && (
          <LogoSelectionContent
            images={wizardData.scrapedImages}
            extractedColors={[...formHook.form.colors]}
            isConfirming={isConfirmingLogo}
            onLogoSelected={handleLogoSelected}
            onColorsAssigned={handleColorsAssigned}
            onSkip={handleLogoDone}
            onDone={handleLogoDone}
          />
        )}

        {/* ════════════════════════════════════════ */}
        {/* STEP: FULL FORM                         */}
        {/* ════════════════════════════════════════ */}
        {currentStep === "form" && (
          <form onSubmit={handleSubmit} className="mt-2">
            <div className="flex gap-6">
              {/* LEFT PANEL — Form Fields */}
              <BrandFormFields
                form={formHook.form}
                onChange={formHook.setForm}
              />

              {/* Vertical separator */}
              <Separator orientation="vertical" className="h-auto" />

              {/* RIGHT PANEL — Visual Identity */}
              <div className="w-[280px] shrink-0 space-y-5">
                {/* Brand Logo */}
                <BrandLogoSection
                  editBrand={editBrand}
                  logoAsset={logoAsset}
                  onLogoChanged={handleAssetsChanged}
                  onColorsExtracted={(colors) => formHook.showColorDiscoveryToast(colors)}
                />

                <Separator />

                {/* Brand Colors */}
                <BrandColorSection
                  colors={formHook.form.colors}
                  maxColors={MAX_COLORS}
                  additionalColorRef={formHook.additionalColorRef}
                  onAssignPrimary={formHook.assignAsPrimary}
                  onAssignSecondary={formHook.assignAsSecondary}
                  onRemove={formHook.removeColor}
                  onAdd={formHook.addColor}
                />

                <Separator />

                {/* Reference Images — self-querying component */}
                {isEdit && editBrand ? (
                  <ReferenceImageSelector
                    brandId={editBrand.id}
                    onAssetsChanged={handleAssetsChanged}
                    onSetAsPrimary={handleSetAsLogo}
                    maxImages={20}
                  />
                ) : (
                  <div className="space-y-1.5">
                    <Label className="text-sm font-medium">Reference Images</Label>
                    <div className="flex flex-col items-center gap-2 py-4 rounded border border-dashed border-border bg-muted/30">
                      <ImageIcon size={18} className="text-muted-foreground" />
                      <p className="text-xs text-muted-foreground text-center px-3">
                        Save the brand first to add reference images.
                      </p>
                    </div>
                  </div>
                )}
              </div>
            </div>

            <DialogFooter className="mt-6">
              <Button type="button" variant="outline" onClick={() => handleOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={!formHook.form.name.trim() || isLoading || fetchHook.isFetching}>
                {isLoading ? "Saving..." : isEdit ? "Update Brand" : "Create Brand"}
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
