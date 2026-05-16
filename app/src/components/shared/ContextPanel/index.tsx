import { useContextPanel } from "./useContextPanel";
import { BrandDropdown } from "./BrandDropdown";
import { UrlInput } from "./UrlInput";
import { BrandConflictDialog } from "./BrandConflictDialog";
import { ThemeSelector } from "@/components/shared/ThemeSelector";
import { BrandColorSwatches } from "@/components/shared/BrandColorSwatches";
import { LogoSelectionDialog } from "@/components/shared/LogoSelectionDialog";
import { toast } from "sonner";
import { apiFetch } from "@/lib/trpc";
import type { ContextPanelProps } from "./types";

export function ContextPanel(props: ContextPanelProps) {
  const { value, hideUrl = false, hideTheme = false } = props;
  const { state, actions, mutations } = useContextPanel(props);

  // Extract brand identity fields (safe access — brand shape varies)
  const brand = value.brand as Record<string, any> | null;
  const brandSummary = brand?.businessSummary as string | undefined;
  const brandColors = (Array.isArray(brand?.colors) && brand.colors.length > 0) ? brand.colors as string[] : null;
  const brandLogo = (Array.isArray(brand?.assets) && brand.assets.length > 0) ? brand.assets[0] : null;

  return (
    <>
      <div className="space-y-3">
        <BrandDropdown
          brandOpen={state.brandOpen}
          setBrandOpen={actions.setBrandOpen}
          brandSearch={state.brandSearch}
          setBrandSearch={actions.setBrandSearch}
          brandsLoading={state.brandsLoading}
          brandList={state.brandList}
          selectedBrandId={value.brandId}
          handleBrandSelect={actions.handleBrandSelect}
          handleBrandClear={actions.handleBrandClear}
        />



        {!hideUrl && (
          <UrlInput
            url={value.url}
            handleUrlChange={actions.handleUrlChange}
            handleUrlFetch={actions.handleUrlFetch}
            fetchStatus={state.fetchStatus}
            fetchError={state.fetchError}
            isFetching={state.isFetching}
          />
        )}

        {!hideTheme && (
          <ThemeSelector
            seasonEvent={value.seasonEvent}
            campaignTheme={value.campaignTheme}
            onSeasonChange={actions.handleSeasonChange}
            onCampaignThemeChange={actions.handleCampaignThemeChange}
            defaultExpanded={false}
          />
        )}
      </div>

      <BrandConflictDialog
        pendingBrandData={state.pendingBrandData}
        setPendingBrandData={actions.setPendingBrandData}
        handleCreateNew={actions.handleCreateNew}
        handleUpdateExisting={actions.handleUpdateExisting}
      />

      <LogoSelectionDialog
        open={state.showLogoDialog}
        onOpenChange={actions.setShowLogoDialog}
        images={state.logoScrapeImages}
        isConfirming={state.isConfirmingLogo}
        onLogoSelected={async (logoUrl): Promise<string[]> => {
          const brandId = state.lastSavedBrandId ?? value.brandId;
          if (brandId && logoUrl) {
            actions.setIsConfirmingLogo(true);
            try {
              const result = await mutations.addAssetFromUrl.mutateAsync({
                brandId,
                imageUrl: logoUrl,
                role: 'logo',
              });
              try {
                const brand = await apiFetch<any>(`brands/${brandId}`);
                const assets = brand?.assets ?? [];
                const logoAsset = assets.find((a: any) => a.url === logoUrl || a.originalUrl === logoUrl);
                if (logoAsset) {
                  const newOrder = assets.map((a: any) => a.fileKey);
                  const idx = newOrder.indexOf(logoAsset.fileKey);
                  if (idx > 0) {
                    newOrder.splice(idx, 1);
                    newOrder.unshift(logoAsset.fileKey);
                    await apiFetch(`brands/${brandId}/assets/reorder`, {
                      method: 'POST',
                      body: JSON.stringify({ brandId, fileKeys: newOrder }),
                    });
                  }
                }
              } catch (err) { }
              const extracted = (result as any)?.extractedColors as string[] | undefined;
              if (extracted && extracted.length > 0) {
                try {
                  await mutations.updateBrandColors.mutateAsync({ brandId, colors: extracted });
                } catch (err) { }
                return extracted;
              }
              await mutations.refreshBrand(brandId);
            } catch (err) {
              toast.error("Failed to save logo.");
            } finally {
              actions.setIsConfirmingLogo(false);
            }
          }
          return [];
        }}
        onUploadFile={async (file) => {
          const brandId = state.lastSavedBrandId ?? value.brandId;
          if (brandId) {
            actions.setIsConfirmingLogo(true);
            try {
              const buffer = await file.arrayBuffer();
              const base64 = btoa(
                new Uint8Array(buffer).reduce((data, byte) => data + String.fromCharCode(byte), '')
              );
              const result = await mutations.addAssetMutation.mutateAsync({
                brandId,
                fileData: base64,
                filename: file.name,
                mimeType: file.type,
                role: 'logo',
              });
              const extracted = (result as any)?.extractedColors as string[] | undefined;
              if (extracted && extracted.length > 0) {
                try {
                  await mutations.updateBrandColors.mutateAsync({ brandId, colors: extracted });
                } catch (err) { }
              }
              await mutations.refreshBrand(brandId);
            } catch (err) {
              toast.error("Failed to upload logo.");
            } finally {
              actions.setIsConfirmingLogo(false);
            }
          }
        }}
        onUrlPaste={async (logoUrl) => {
          const brandId = state.lastSavedBrandId ?? value.brandId;
          if (brandId && logoUrl) {
            actions.setIsConfirmingLogo(true);
            try {
              const result = await mutations.addAssetFromUrl.mutateAsync({
                brandId,
                imageUrl: logoUrl,
                role: 'logo',
              });
              const extracted = (result as any)?.extractedColors as string[] | undefined;
              if (extracted && extracted.length > 0) {
                try {
                  await mutations.updateBrandColors.mutateAsync({ brandId, colors: extracted });
                } catch (err) { }
              }
              await mutations.refreshBrand(brandId);
            } catch (err) {
              toast.error("Failed to fetch logo from URL.");
            } finally {
              actions.setIsConfirmingLogo(false);
            }
          }
        }}
        onSkip={() => { }}
      />
    </>
  );
}

export { createEmptyContextData } from "./utils";
export { DEFAULT_BRAND_TOGGLES } from "./types";
export type { ContextData, ScrapedBusinessData, ContextPanelProps, BrandToggles } from "./types";
