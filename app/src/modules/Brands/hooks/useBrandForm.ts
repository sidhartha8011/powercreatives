/**
 * BRANDS MODULE — Form State Hook
 *
 * Manages brand form state, color helpers, and form population logic.
 * Extracted from BrandDialog.tsx for separation of concerns.
 *
 * Responsibilities:
 *   - Form state (8 text fields + colors array)
 *   - Color pool operations (assign primary/secondary, add, remove)
 *   - Color discovery toast (when logo analysis returns new colors)
 *   - Auto-populate form from editBrand data
 */

import { useState, useEffect, useRef, useCallback } from "react";
import { toast } from "sonner";
import { mapBrandToFormValues, mapFormValuesToBrandKeys, normalizeLanguage } from "@shared/brandTypes";
import { EMPTY_FORM, MAX_COLORS } from "../types";
import type { BrandFormData, Brand } from "../types";

interface UseBrandFormOptions {
  /** Brand being edited (null = create mode) */
  editBrand?: Brand | null;
  /** Whether the dialog is open (used to reset form on open) */
  open: boolean;
  /** External flag to prevent form reset during fetch */
  isFetchingRef: React.MutableRefObject<boolean>;
}

export function useBrandForm({ editBrand, open, isFetchingRef }: UseBrandFormOptions) {
  const [form, setForm] = useState<BrandFormData>(EMPTY_FORM);
  const additionalColorRef = useRef<HTMLInputElement>(null);

  // ── Populate form when editing — but NOT while a fetch is in progress ──
  // Uses centralized mapBrandToFormValues → mapFormValuesToBrandKeys chain
  useEffect(() => {
    if (isFetchingRef.current) return;
    if (editBrand) {
      const mapped = mapFormValuesToBrandKeys(mapBrandToFormValues(editBrand as Record<string, any>));
      setForm({
        name: mapped.name ?? editBrand.name ?? "",
        website: mapped.website ?? editBrand.website ?? "",
        niche: mapped.niche ?? editBrand.niche ?? "",
        location: mapped.location ?? editBrand.location ?? "",
        phone: mapped.phone ?? editBrand.phone ?? "",
        businessSummary: mapped.businessSummary ?? editBrand.businessSummary ?? "",
        language: normalizeLanguage(mapped.language ?? editBrand.language ?? ""),
        colors: (editBrand as any).colors ?? [],
        externalId: (editBrand as any).externalId ?? "",
      });
    } else {
      setForm(EMPTY_FORM);
    }
  }, [editBrand, open]);

  // ── Derived color roles ──
  const primaryColor = form.colors[0] || null;
  const secondaryColor = form.colors[1] || null;

  // ── Color operations ──

  /** Move a color to the primary (index 0) position */
  const assignAsPrimary = useCallback((absIndex: number) => {
    if (absIndex === 0) return;
    setForm((prev) => {
      const updated = [...prev.colors];
      const [color] = updated.splice(absIndex, 1);
      updated.unshift(color);
      return { ...prev, colors: updated };
    });
  }, []);

  /** Move a color to the secondary (index 1) position */
  const assignAsSecondary = useCallback((absIndex: number) => {
    if (absIndex === 1) return;
    setForm((prev) => {
      const updated = [...prev.colors];
      const [color] = updated.splice(absIndex, 1);
      updated.splice(1, 0, color);
      return { ...prev, colors: updated };
    });
  }, []);

  /** Remove a color from the palette */
  const removeColor = useCallback((absIndex: number) => {
    setForm((prev) => ({
      ...prev,
      colors: prev.colors.filter((_, i) => i !== absIndex),
    }));
  }, []);

  /** Add a new hex color to the palette (deduped, max 10) */
  const addColor = useCallback((hex: string) => {
    const normalized = hex.toLowerCase();
    setForm((prev) => {
      if (prev.colors.includes(normalized)) return prev;
      if (prev.colors.length >= MAX_COLORS) return prev;
      return { ...prev, colors: [...prev.colors, normalized] };
    });
  }, []);

  /**
   * Show a persistent toast when new colors are discovered from a logo.
   * User can "Add all" to merge into the palette, or dismiss to ignore.
   */
  const showColorDiscoveryToast = useCallback((newColors: string[]) => {
    if (!newColors || newColors.length === 0) return;

    setForm((prev) => {
      const available = MAX_COLORS - prev.colors.length;
      const toAdd = newColors.slice(0, available);
      if (toAdd.length === 0) return prev;

      toast(`${toAdd.length} new color${toAdd.length > 1 ? "s" : ""} found in logo`, {
        duration: Infinity,
        description: toAdd.map((c) => c.toUpperCase()).join(", "),
        action: {
          label: "Add all",
          onClick: () => {
            setForm((inner) => {
              const existing = new Set(inner.colors.map((c) => c.toLowerCase()));
              const unique = toAdd.filter((c) => !existing.has(c.toLowerCase()));
              return {
                ...inner,
                colors: [...inner.colors, ...unique].slice(0, MAX_COLORS),
              };
            });
          },
        },
        cancel: {
          label: "Dismiss",
          onClick: () => {},
        },
      });

      return prev; // Don't change state here — toast action does it
    });
  }, []);

  return {
    form,
    setForm,
    primaryColor,
    secondaryColor,
    additionalColorRef,
    assignAsPrimary,
    assignAsSecondary,
    removeColor,
    addColor,
    showColorDiscoveryToast,
  };
}
