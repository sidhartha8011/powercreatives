/**
 * BRANDS MODULE — Fetch & Auto-Create Hook
 *
 * Manages the "Fetch Brand" flow: URL scraping, form population,
 * auto-create in create mode.
 *
 * Extracted from BrandDialog.tsx for separation of concerns.
 *
 * Responsibilities:
 *   - handleFetchBrand (unified scrape → fill → persist)
 *   - Auto-create brand in create mode (ContextPanel pattern)
 *   - Returns FetchResult so the wizard orchestrator drives step transitions
 *
 * NOTE: This hook does NOT manage LogoSelectionDialog or wizard state.
 * The wizard orchestrator (BrandDialog) receives FetchResult and owns transitions.
 */

import { useState, useRef } from "react";
import { toast } from "sonner";
import { trpc } from "@/lib/trpc";
import { useSettings } from "@/contexts/AppContext";
import { mapScrapedToFormValues, mapFormValuesToBrandKeys, normalizeLanguage } from "@shared/brandTypes";
import { MAX_COLORS, MAX_FETCHED_IMAGES } from "../types";
import type { BrandFormData, Brand, SavedScrapedAssets } from "../types";
import type { ImageInfo } from "@/components/shared/LogoSelectionDialog";

/** Data returned by handleFetchBrand — wizard uses this to drive step transitions */
export interface FetchResult {
  /** All images found on the page (sorted by resolution, largest first) */
  images: ImageInfo[];
  /** Colors scraped from CSS on the website */
  colors: string[];
  /** Number of form fields that were auto-filled */
  filledCount: number;
  /** Brand ID if one was created during this fetch (create mode only) */
  createdBrandId: number | null;
  /** Scraped images stored on the brand, keyed by source URL */
  savedAssets: SavedScrapedAssets;
}

interface UseBrandFetchOptions {
  /** Current form state */
  form: BrandFormData;
  /** Setter for form state */
  setForm: React.Dispatch<React.SetStateAction<BrandFormData>>;
  /** Brand being edited (null = create mode) */
  editBrand?: Brand | null;
  /** Callback when brand is auto-created during fetch */
  onBrandCreated?: (brand: Brand) => void;
}

export function useBrandFetch({
  form,
  setForm,
  editBrand,
  onBrandCreated,
}: UseBrandFetchOptions) {
  const [isFetching, setIsFetching] = useState(false);
  const isFetchingRef = useRef(false);

  // Brand ID of last created brand (for wizard logo-save step)
  const [lastCreatedBrandId, setLastCreatedBrandId] = useState<number | null>(null);

  // ── tRPC mutations ──
  const scrapeMutation = trpc.brands.scrapeUrl.useMutation();
  const addAssetFromUrlMutation = trpc.brands.addAssetFromUrl.useMutation();
  const createBrandMutation = trpc.brands.create.useMutation();
  const updateBrandColorsMutation = trpc.brands.updateColors.useMutation();
  const utils = trpc.useUtils();

  // Read the user's preferred text model for scraping
  const { settings } = useSettings();

  // ── Brand query for edit mode (used to refetch after image/logo save) ──
  const brandQuery = trpc.brands.getById.useQuery(
    { id: editBrand?.id ?? 0 },
    { enabled: !!editBrand?.id }
  );

  /**
   * Download the scraped images onto the brand as reusable 'reference' assets.
   *
   * The whole point of the fetch is to pull the brand's imagery ONCE so Ads /
   * Image can reuse it later — so this runs in BOTH modes. It used to run only
   * in edit mode, which is why creating a brand from a URL kept the logo and
   * silently discarded every other image it had just found.
   *
   * Returns sourceUrl → stored asset so the wizard's logo step can promote one
   * of these rather than downloading the same URL again.
   */
  const saveScrapedImages = async (
    brandId: number,
    images: ImageInfo[]
  ): Promise<SavedScrapedAssets> => {
    const saved: SavedScrapedAssets = {};

    for (const img of images.slice(0, MAX_FETCHED_IMAGES)) {
      const imageUrl = (img as any)?.url ?? (img as unknown as string);
      if (!imageUrl || typeof imageUrl !== "string") continue;

      try {
        const asset: any = await addAssetFromUrlMutation.mutateAsync({
          brandId,
          imageUrl,
          role: "reference",
        });
        if (asset?.fileKey) {
          saved[imageUrl] = { fileKey: asset.fileKey, colors: asset.extractedColors ?? [] };
        }
      } catch {
        // Skip individual image failures — one dead URL must not cost the rest.
      }
    }

    return saved;
  };

  // ── Main fetch handler ──
  // Returns FetchResult so the wizard orchestrator can drive step transitions.
  // Does NOT open any dialogs — caller decides what to do with the result.
  const handleFetchBrand = async (): Promise<FetchResult | null> => {
    const url = form.website.trim();
    if (!url) {
      toast.error("Enter a website URL first");
      return null;
    }

    setIsFetching(true);
    isFetchingRef.current = true;
    try {
      // 1. Unified scrape — text + colors + images + logo candidates
      const scraped = await scrapeMutation.mutateAsync({
        url,
        ...(settings.defaultTextModel ? { model: settings.defaultTextModel } : {}),
      });

      const info = scraped.businessInfo ?? {};
      let filledCount = 0;
      const updated = { ...form };

      // niche / location / phone can only come from a model. Without one the
      // scrape still returns a name, summary, colours and images, so the form
      // looks half-filled for no visible reason — say why instead.
      const enrichmentNotice = (scraped as any)?.enrichmentNotice as string | undefined;
      if (enrichmentNotice) {
        toast.warning(enrichmentNotice, { duration: 8000 });
      }

      // Map unified businessInfo fields to form fields using centralized utilities
      const mappedFields = mapScrapedToFormValues(info);
      const brandKeys = mapFormValuesToBrandKeys(mappedFields);
      for (const [formKey, val] of Object.entries(brandKeys)) {
        if (val && val.trim()) {
          const currentVal = (updated as any)[formKey];
          if (!currentVal || (typeof currentVal === "string" && !currentVal.trim())) {
            (updated as any)[formKey] = formKey === "language" ? normalizeLanguage(val) : val;
            filledCount++;
          }
        }
      }

      // Merge colors from unified response (CSS-parsed + optional LLM)
      const scrapedColors: string[] = [];
      if (scraped.colors && scraped.colors.length > 0) {
        const existingSet = new Set(updated.colors.map((c) => c.toLowerCase()));
        const newColors = scraped.colors
          .map((c: string) => c.toLowerCase())
          .filter((c: string) => !existingSet.has(c));

        if (newColors.length > 0) {
          updated.colors = [...updated.colors, ...newColors].slice(0, MAX_COLORS);
          filledCount++;
        }
        scrapedColors.push(...scraped.colors.map((c: string) => c.toLowerCase()));
      }

      if (info.website && !updated.website.trim()) {
        updated.website = info.website;
      }

      setForm(updated);

      // Collect all scraped images (sorted by resolution from backend)
      const allImages: ImageInfo[] = scraped.images ?? [];
      let createdBrandId: number | null = null;
      let savedAssets: SavedScrapedAssets = {};

      // 2. Handle edit mode — save images + colors to existing brand
      if (editBrand) {
        try {
          // Persist scraped colors to DB immediately
          if (updated.colors.length > 0) {
            await updateBrandColorsMutation.mutateAsync({
              brandId: editBrand.id,
              colors: updated.colors,
            });
          }

          // Add scraped images as brand assets
          savedAssets = await saveScrapedImages(editBrand.id, allImages);
          const addedCount = Object.keys(savedAssets).length;

          brandQuery.refetch();
          utils.brands.list.invalidate();

          if (addedCount > 0) {
            filledCount++;
            toast.success(
              `Fetched ${filledCount} field${filledCount > 1 ? "s" : ""} + ${addedCount} image${addedCount > 1 ? "s" : ""}`
            );
          } else if (filledCount > 0) {
            toast.success(`Filled ${filledCount} field${filledCount > 1 ? "s" : ""} from website`);
          } else {
            toast.info("No new information found");
          }
        } catch {
          if (filledCount > 0) {
            toast.success(`Filled ${filledCount} field${filledCount > 1 ? "s" : ""} (image fetch failed)`);
          } else {
            toast.info("No new information found");
          }
        }
      } else {
        // 3. Create mode — auto-create brand
        try {
          const newBrand = await createBrandMutation.mutateAsync({
            name: updated.name || url,
            website: updated.website || undefined,
            niche: updated.niche || undefined,
            location: updated.location || undefined,
            phone: updated.phone || undefined,
            businessSummary: updated.businessSummary || undefined,
            language: updated.language || undefined,
            colors: updated.colors.length > 0 ? updated.colors : undefined,
          });

          const brandId = (newBrand as any)?.id;

          // Save colors to DB
          if (brandId && updated.colors.length > 0) {
            try {
              await updateBrandColorsMutation.mutateAsync({
                brandId,
                colors: updated.colors,
              });
            } catch {
              // Colors save is best-effort
            }
          }

          // Notify parent so it can switch to edit mode
          if (brandId) {
            createdBrandId = brandId;
            setLastCreatedBrandId(brandId);
            onBrandCreated?.(newBrand as Brand);

            // Store the images we just found. Without this the brand keeps only
            // whichever image the user later picks as the logo, and everything
            // else the scrape turned up is lost the moment the wizard advances.
            savedAssets = await saveScrapedImages(brandId, allImages);
            const addedCount = Object.keys(savedAssets).length;

            utils.brands.list.invalidate();
            toast.success(
              addedCount > 0
                ? `Brand created with ${filledCount} field${filledCount > 1 ? "s" : ""} + ${addedCount} image${addedCount > 1 ? "s" : ""}`
                : `Brand created with ${filledCount} field${filledCount > 1 ? "s" : ""}`
            );
          }
        } catch (createErr: any) {
          toast.error(createErr?.message || "Failed to create brand");
        }
      }

      // Return result — wizard orchestrator decides what step to show next
      return {
        images: allImages,
        colors: scrapedColors,
        filledCount,
        createdBrandId,
        savedAssets,
      };
    } catch (err: any) {
      toast.error(err?.message || "Failed to fetch brand info");
      return null;
    } finally {
      setIsFetching(false);
      isFetchingRef.current = false;
    }
  };

  return {
    handleFetchBrand,
    isFetching,
    isFetchingRef,
    lastCreatedBrandId,
    brandQuery,
  };
}
