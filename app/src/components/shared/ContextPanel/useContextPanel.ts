import { useState, useCallback, useRef, useEffect } from "react";
import { trpc, apiFetch } from "@/lib/trpc";
import { useSettings } from "@/contexts/AppContext";
import { normalizeUrl } from "./utils";
import { getBrandsForModule } from "@/lib/pcmConfig";
import type { Brand } from "../../../../../drizzle/schema";
import type { ContextData, ScrapedBusinessData, ContextPanelProps } from "./types";
import type { ImageInfo } from "@/components/shared/LogoSelectionDialog";

export function useContextPanel({ value, onChange, onUrlFetched, moduleId }: ContextPanelProps) {
  const [brandOpen, setBrandOpen] = useState(false);
  const [brandSearch, setBrandSearch] = useState("");

  const valueRef = useRef(value);
  useEffect(() => {
    valueRef.current = value;
  }, [value]);

  const [fetchStatus, setFetchStatus] = useState<"idle" | "success" | "error">("idle");
  const [fetchError, setFetchError] = useState("");
  const { settings } = useSettings();

  const [pendingBrandData, setPendingBrandData] = useState<{
    scrapedData: ScrapedBusinessData;
    existingBrandId: number;
    existingBrandName: string;
    normalizedUrl: string;
  } | null>(null);

  const { data: brands, isLoading: brandsLoading } = trpc.brands.list.useQuery();
  // Per-module brand scoping (UX — the REST layer enforces it anyway):
  // restricted users only see brands usable in the hosting module. The map
  // is built server-side and already includes the user's own brands.
  const allowedBrandIds = moduleId ? getBrandsForModule(moduleId) : null;
  const brandList = (brands ?? []).filter(
    (b) => allowedBrandIds === null || allowedBrandIds.includes(Number(b.id))
  );
  const selectedBrand = brandList.find((b) => b.id === value.brandId);
  const utils = trpc.useUtils();

  const refreshBrand = useCallback(
    async (brandId: number) => {
      try {
        await utils.brands.list.invalidate();
        const fresh = await apiFetch<any>(`brands/${brandId}`);
        if (fresh) {
          onChange({ ...valueRef.current, brandId, brand: fresh as Brand });
          return fresh;
        }
      } catch {
        // Refetch failed
      }
      return null;
    },
    [utils, onChange]
  );

  const createBrand = trpc.brands.create.useMutation({
    onSuccess: async (brand) => {
      if (brand) {
        onChange({ ...valueRef.current, brandId: brand.id, brand: brand as Brand });
        await refreshBrand(brand.id);
      }
    },
  });

  const updateBrand = trpc.brands.update.useMutation({
    onSuccess: () => {
      utils.brands.list.invalidate();
    },
  });

  const scrapeUrl = trpc.brands.scrapeUrl.useMutation();
  const addAssetFromUrl = trpc.brands.addAssetFromUrl.useMutation();
  const addAssetMutation = trpc.brands.addAsset.useMutation();

  const [showLogoDialog, setShowLogoDialog] = useState(false);
  const [logoScrapeImages, setLogoScrapeImages] = useState<ImageInfo[]>([]);
  const [lastSavedBrandId, setLastSavedBrandId] = useState<number | null>(null);
  const [isConfirmingLogo, setIsConfirmingLogo] = useState(false);

  const handleBrandSelect = useCallback(
    (brandId: number) => {
      if (brandId === value.brandId) {
        onChange({ ...value, brandId: undefined, brand: null });
      } else {
        const brand = brandList.find((b) => b.id === brandId) ?? null;
        onChange({ ...value, brandId, brand });
      }
      setBrandOpen(false);
    },
    [value, onChange, brandList]
  );

  const handleBrandClear = useCallback(
    (e: React.MouseEvent) => {
      e.stopPropagation();
      onChange({ ...value, brandId: undefined, brand: null });
    },
    [value, onChange]
  );

  const updateBrandColors = trpc.brands.updateColors.useMutation({
    onSuccess: () => {
      utils.brands.list.invalidate();
    },
  });

  const autoSaveNewBrand = useCallback(
    async (scrapedData: ScrapedBusinessData, normalizedUrl: string): Promise<number | null> => {
      const name = scrapedData.business_name || normalizedUrl;
      try {
        const newBrand = await createBrand.mutateAsync({
          name,
          website: normalizedUrl,
          niche: scrapedData.niche || undefined,
          location: scrapedData.location || undefined,
          phone: scrapedData.phone || undefined,
          businessSummary: scrapedData.business_summary || undefined,
          language: scrapedData.language || undefined,
        });
        if (newBrand && scrapedData.brand_colors && scrapedData.brand_colors.length > 0) {
          try {
            await updateBrandColors.mutateAsync({ brandId: newBrand.id, colors: scrapedData.brand_colors });
          } catch {}
        }
        return newBrand?.id ?? null;
      } catch {
        return null;
      }
    },
    [createBrand, updateBrandColors]
  );

  const autoUpdateBrand = useCallback(
    async (brandId: number, scrapedData: ScrapedBusinessData) => {
      try {
        await updateBrand.mutateAsync({
          id: brandId,
          name: scrapedData.business_name || undefined,
          niche: scrapedData.niche || undefined,
          location: scrapedData.location || undefined,
          phone: scrapedData.phone || undefined,
          businessSummary: scrapedData.business_summary || undefined,
          language: scrapedData.language || undefined,
          scrapedAt: new Date(),
        });
        if (scrapedData.brand_colors && scrapedData.brand_colors.length > 0) {
          try {
            await updateBrandColors.mutateAsync({ brandId, colors: scrapedData.brand_colors });
          } catch {}
        }
        await refreshBrand(brandId);
      } catch {}
    },
    [updateBrand, updateBrandColors, refreshBrand]
  );

  const handleUrlFetch = useCallback(async () => {
    if (!value.url.trim()) return;
    setFetchStatus("idle");
    setFetchError("");

    try {
      const data = await scrapeUrl.mutateAsync({
        url: value.url.trim(),
        ...(settings.defaultTextModel ? { model: settings.defaultTextModel } : {}),
      });

      const info = data.businessInfo ?? {};
      const scrapedData: ScrapedBusinessData = {
        business_name: info.business_name || undefined,
        niche: info.niche || undefined,
        location: info.location || undefined,
        phone: info.phone || undefined,
        business_summary: info.business_summary || undefined,
        website: info.website || undefined,
        language: info.language || undefined,
        brand_colors: data.colors?.length ? data.colors : undefined,
        page_images: data.images?.length ? data.images.map((img: any) => img.url || img) : undefined,
      };

      onChange({ ...value, scrapedData });
      onUrlFetched?.(scrapedData);
      setFetchStatus("success");
      setTimeout(() => setFetchStatus("idle"), 3000);

      const normalizedUrl = normalizeUrl(value.url);
      let savedBrandId: number | null = null;
      try {
        const existing = await utils.brands.findByWebsite.fetch({ website: normalizedUrl });
        if (existing) {
          setPendingBrandData({ scrapedData, existingBrandId: existing.id, existingBrandName: existing.name, normalizedUrl });
          savedBrandId = existing.id;
        } else {
          savedBrandId = await autoSaveNewBrand(scrapedData, normalizedUrl);
        }
      } catch {
        savedBrandId = await autoSaveNewBrand(scrapedData, normalizedUrl);
      }

      const allImages = data.images ?? [];
      if (allImages.length > 0) {
        setLogoScrapeImages(allImages);
        setLastSavedBrandId(savedBrandId ?? value.brandId ?? null);
        setShowLogoDialog(true);
      }
    } catch (error: unknown) {
      setFetchStatus("error");
      setFetchError(error instanceof Error ? error.message : "Failed to fetch business info");
    }
  }, [value, onChange, onUrlFetched, scrapeUrl, settings.defaultTextModel, utils, autoSaveNewBrand]);

  const handleUpdateExisting = useCallback(async () => {
    if (!pendingBrandData) return;
    await autoUpdateBrand(pendingBrandData.existingBrandId, pendingBrandData.scrapedData);
    setPendingBrandData(null);
  }, [pendingBrandData, autoUpdateBrand]);

  const handleCreateNew = useCallback(async () => {
    if (!pendingBrandData) return;
    await autoSaveNewBrand(pendingBrandData.scrapedData, pendingBrandData.normalizedUrl);
    setPendingBrandData(null);
  }, [pendingBrandData, autoSaveNewBrand]);

  const handleSeasonChange = useCallback((seasonEvent: string) => onChange({ ...value, seasonEvent }), [value, onChange]);
  const handleCampaignThemeChange = useCallback((campaignTheme: string) => onChange({ ...value, campaignTheme }), [value, onChange]);
  const handleUrlChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      onChange({ ...value, url: e.target.value });
      if (fetchStatus === "error") setFetchStatus("idle");
    },
    [value, onChange, fetchStatus]
  );

  return {
    state: {
      brandOpen,
      brandSearch,
      fetchStatus,
      fetchError,
      isFetching: scrapeUrl.isPending,
      pendingBrandData,
      brandsLoading,
      brandList,
      selectedBrand,
      showLogoDialog,
      logoScrapeImages,
      lastSavedBrandId,
      isConfirmingLogo,
    },
    actions: {
      setBrandOpen,
      setBrandSearch,
      handleBrandSelect,
      handleBrandClear,
      handleUrlFetch,
      handleUpdateExisting,
      handleCreateNew,
      handleSeasonChange,
      handleCampaignThemeChange,
      handleUrlChange,
      setShowLogoDialog,
      setPendingBrandData,
      setIsConfirmingLogo,
    },
    mutations: {
      addAssetFromUrl,
      addAssetMutation,
      updateBrandColors,
      refreshBrand,
    },
  };
}
