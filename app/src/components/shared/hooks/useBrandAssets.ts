import { useState, useCallback, useRef, useEffect } from "react";
import { trpc, apiFetch } from "@/lib/trpc";
import { toast } from "sonner";
import type { ContextData } from "@/components/shared/ContextPanel";

export function useBrandAssets(
  contextData: ContextData,
  onContextChange: (data: ContextData) => void
) {
  // Keep latest references for async callbacks to avoid closure staleness
  const contextDataRef = useRef(contextData);
  useEffect(() => {
    contextDataRef.current = contextData;
  }, [contextData]);

  // Local loading states
  const [isUploadingLogo, setIsUploadingLogo] = useState(false);
  const [isRemovingLogo, setIsRemovingLogo] = useState(false);
  const [isUpdatingColors, setIsUpdatingColors] = useState(false);

  const utils = trpc.useUtils();
  const removeAssetMutation = trpc.brands.removeAsset.useMutation();
  const updateColorsMutation = trpc.brands.updateColors.useMutation();
  
  // Expose certifications by filtering the current brand assets
  const certifications = ((contextData.brand as any)?.assets as any[] | null)?.filter(a => a.role === 'certification') || [];

  const refreshBrand = useCallback(async (brandId: number) => {
    try {
      await utils.brands.list.invalidate();
      const fresh = await apiFetch<any>(`brands/${brandId}`);
      if (fresh) {
        onContextChange({ ...contextDataRef.current, brand: fresh });
      }
    } catch {
      // Silent ignore
    }
  }, [utils, onContextChange]);

  const addColor = useCallback(async (hex: string) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    const currentColors = ((currentContext.brand as any)?.colors as string[] | null) ?? [];
    if (currentColors.includes(hex)) return;

    const newColors = [...currentColors, hex];
    
    // Optimistic UI update
    onContextChange({
      ...currentContext,
      brand: { ...currentContext.brand, colors: newColors } as any
    });

    setIsUpdatingColors(true);
    try {
      await updateColorsMutation.mutateAsync({ brandId: currentContext.brandId, colors: newColors });
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to add color.");
      // Rollback
      onContextChange(currentContext);
    } finally {
      setIsUpdatingColors(false);
    }
  }, [onContextChange, updateColorsMutation, refreshBrand]);

  const removeColor = useCallback(async (indexToRemove: number) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    const currentColors = ((currentContext.brand as any)?.colors as string[] | null) ?? [];
    const newColors = currentColors.filter((_, idx) => idx !== indexToRemove);

    // Optimistic UI update
    onContextChange({
      ...currentContext,
      brand: { ...currentContext.brand, colors: newColors } as any
    });

    setIsUpdatingColors(true);
    try {
      await updateColorsMutation.mutateAsync({ brandId: currentContext.brandId, colors: newColors });
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to remove color.");
      // Rollback
      onContextChange(currentContext);
    } finally {
      setIsUpdatingColors(false);
    }
  }, [onContextChange, updateColorsMutation, refreshBrand]);

  const assignAsPrimary = useCallback(async (index: number) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    const currentColors = ((currentContext.brand as any)?.colors as string[] | null) ?? [];
    if (index <= 0 || index >= currentColors.length) return;

    const newColors = [...currentColors];
    const [color] = newColors.splice(index, 1);
    newColors.unshift(color);

    onContextChange({
      ...currentContext,
      brand: { ...currentContext.brand, colors: newColors } as any
    });

    setIsUpdatingColors(true);
    try {
      await updateColorsMutation.mutateAsync({ brandId: currentContext.brandId, colors: newColors });
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to update colors.");
      onContextChange(currentContext);
    } finally {
      setIsUpdatingColors(false);
    }
  }, [onContextChange, updateColorsMutation, refreshBrand]);

  const assignAsSecondary = useCallback(async (index: number) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    const currentColors = ((currentContext.brand as any)?.colors as string[] | null) ?? [];
    if (index === 1 || index >= currentColors.length) return;

    const newColors = [...currentColors];
    const [color] = newColors.splice(index, 1);
    newColors.splice(1, 0, color);

    onContextChange({
      ...currentContext,
      brand: { ...currentContext.brand, colors: newColors } as any
    });

    setIsUpdatingColors(true);
    try {
      await updateColorsMutation.mutateAsync({ brandId: currentContext.brandId, colors: newColors });
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to update colors.");
      onContextChange(currentContext);
    } finally {
      setIsUpdatingColors(false);
    }
  }, [onContextChange, updateColorsMutation, refreshBrand]);

  const uploadLogo = useCallback(async (file: File) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    setIsUploadingLogo(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("role", "logo");

      const response = await fetch(`${(window as any).pcmConfig.restUrl}brands/${currentContext.brandId}/assets`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': (window as any).pcmConfig.nonce
        },
        body: formData
      });

      if (!response.ok) {
        throw new Error("Upload failed");
      }
      
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to upload logo.");
    } finally {
      setIsUploadingLogo(false);
    }
  }, [refreshBrand]);

  const [isUploadingCertification, setIsUploadingCertification] = useState(false);
  const uploadCertification = useCallback(async (file: File) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    setIsUploadingCertification(true);
    try {
      const formData = new FormData();
      formData.append("file", file);
      formData.append("role", "certification");

      const response = await fetch(`${(window as any).pcmConfig.restUrl}brands/${currentContext.brandId}/assets`, {
        method: 'POST',
        headers: {
          'X-WP-Nonce': (window as any).pcmConfig.nonce
        },
        body: formData
      });

      if (!response.ok) {
        throw new Error("Upload failed");
      }
      
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to upload certification.");
    } finally {
      setIsUploadingCertification(false);
    }
  }, [refreshBrand]);

  const removeCertification = useCallback(async (fileKey: string) => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;
    
    try {
      await removeAssetMutation.mutateAsync({
        brandId: currentContext.brandId,
        fileKey: fileKey
      });
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to remove certification.");
    }
  }, [removeAssetMutation, refreshBrand]);

  const removeLogo = useCallback(async () => {
    const currentContext = contextDataRef.current;
    if (!currentContext.brandId) return;

    const currentAssets = ((currentContext.brand as any)?.assets as any[] | null) ?? [];
    if (currentAssets.length === 0) return;
    
    // We assume index 0 is the logo per architecture
    const logoAsset = currentAssets[0];
    
    setIsRemovingLogo(true);
    try {
      await removeAssetMutation.mutateAsync({
        brandId: currentContext.brandId,
        fileKey: logoAsset.fileKey
      });
      await refreshBrand(currentContext.brandId);
    } catch (err) {
      toast.error("Failed to remove logo.");
    } finally {
      setIsRemovingLogo(false);
    }
  }, [removeAssetMutation, refreshBrand]);

  return {
    isUploadingLogo,
    isRemovingLogo,
    isUpdatingColors,
    addColor,
    removeColor,
    assignAsPrimary,
    assignAsSecondary,
    uploadLogo,
    removeLogo,
    uploadCertification,
    removeCertification,
    certifications,
    isUploadingCertification,
    refreshBrand,
  };
}
