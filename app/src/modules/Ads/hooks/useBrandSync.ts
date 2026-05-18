import { useCallback } from 'react';
import type { ContextData, ScrapedBusinessData } from '@/components/shared';
import { mapBrandToFormValues, mapScrapedToFormValues } from '@shared/brandTypes';

export function useBrandSync(
  setFormValues: React.Dispatch<React.SetStateAction<Record<string, string | number | undefined>>>,
  setContextData: React.Dispatch<React.SetStateAction<ContextData>>
) {
  // Sync Brand Data to Form Values
  const handleContextChange = useCallback(
    (newCtx: ContextData) => {
      setContextData((prevCtx) => {
        // Check if brand changed
        const brandChanged = newCtx.brandId !== prevCtx.brandId;
        const brandDataUpdated = newCtx.brand !== prevCtx.brand;

        if ((brandChanged || brandDataUpdated) && newCtx.brand) {
          const mapped = mapBrandToFormValues(newCtx.brand as Record<string, any>);
          if (Object.keys(mapped).length > 0) {
            setFormValues((prevForm) => ({ ...prevForm, ...mapped }));
          }
        }
        return newCtx;
      });
    },
    [setContextData, setFormValues]
  );

  // Sync Scraped URL Data to Form Values
  const handleUrlFetched = useCallback(
    (scraped: ScrapedBusinessData) => {
      const mapped = mapScrapedToFormValues(scraped);
      if (Object.keys(mapped).length > 0) {
        setFormValues((prev) => ({ ...prev, ...mapped }));
      }
    },
    [setFormValues]
  );

  return {
    handleContextChange,
    handleUrlFetched,
  };
}
