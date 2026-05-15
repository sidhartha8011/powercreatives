/**
 * useBrandContext — Toggle-controlled brand context state for Image module
 *
 * Manages per-field toggles that control which brand data points
 * are injected into the AI generation prompt. All fields default to ON
 * when a brand is selected, giving users explicit opt-out control.
 *
 * Architecture note: This hook owns the *view-layer* of brand data for
 * the Image module. The source-of-truth for brand records remains in
 * ContextPanel / useContextPanel. This hook reads from contextData.brand
 * and layers toggle + override semantics on top.
 */

import { useState, useCallback, useMemo, useEffect } from 'react';
import type { ContextData } from '@/components/shared/ContextPanel';
import type { BrandAsset } from '@shared/brandTypes';

// ============================================================================
// Types
// ============================================================================

/** All toggle-able brand context fields */
export const BRAND_CONTEXT_FIELDS = [
    'business_name',
    'niche',
    'business_summary',
    'location',
    'phone',
    'website',
    'language',
    'colors',
    'logo',
    'subject',
    'badges',
] as const;

export type BrandContextField = (typeof BRAND_CONTEXT_FIELDS)[number];

/** Toggle state: true = inject into prompt, false = exclude */
export type BrandContextToggles = Record<BrandContextField, boolean>;

/** The derived data object with only toggled-ON fields */
export interface InjectedBrandData {
    business_name?: string;
    niche?: string;
    business_summary?: string;
    location?: string;
    phone?: string;
    website?: string;
    language?: string;
    colors?: string[];
    logoBase64?: string;
    subjectBase64?: string;
    badges?: string[];  // base64 array
}

export interface UseBrandContextReturn {
    /** Per-field toggle state */
    toggles: BrandContextToggles;
    /** Master toggle: are ANY fields on? */
    masterToggle: boolean;
    /** Toggle a single field */
    setFieldToggle: (field: BrandContextField, value: boolean) => void;
    /** Toggle all fields on/off */
    setMasterToggle: (value: boolean) => void;
    /** Brand field values (populated from brand, editable) */
    brandFields: Record<string, string>;
    /** Update a single brand field value */
    updateBrandField: (key: string, value: string) => void;
    /** Colors array (editable) */
    brandColors: string[];
    /** Update colors */
    setBrandColors: (colors: string[]) => void;
    /** Logo base64 */
    logoBase64: string | undefined;
    /** Set logo from base64 */
    setLogoBase64: (b64: string | undefined) => void;
    /** Subject base64 */
    subjectBase64: string | undefined;
    /** Set subject from base64 */
    setSubjectBase64: (b64: string | undefined) => void;
    /** Certifications / badges (base64 array) */
    badgeImages: { id: string; base64: string }[];
    /** Add a badge */
    addBadge: (base64: string) => void;
    /** Remove a badge by id */
    removeBadge: (id: string) => void;
    /** The final derived data object — only ON-fields included */
    injectedBrandData: InjectedBrandData;
    /** Whether a brand is currently loaded */
    hasBrand: boolean;
    /** Brand name for display */
    brandName: string;
    /** Brand assets (for BrandAssetPicker) */
    brandAssets: BrandAsset[];
}

// ============================================================================
// Default toggles — all ON
// ============================================================================

function createDefaultToggles(): BrandContextToggles {
    const t = {} as BrandContextToggles;
    for (const field of BRAND_CONTEXT_FIELDS) {
        t[field] = true;
    }
    return t;
}

// ============================================================================
// Hook
// ============================================================================

export function useBrandContext(contextData: ContextData): UseBrandContextReturn {
    // ── Toggle state ──
    const [toggles, setToggles] = useState<BrandContextToggles>(createDefaultToggles);

    // ── Editable brand field values ──
    const [brandFields, setBrandFields] = useState<Record<string, string>>({});
    const [brandColors, setBrandColors] = useState<string[]>([]);
    const [logoBase64, setLogoBase64] = useState<string | undefined>();
    const [subjectBase64, setSubjectBase64] = useState<string | undefined>();
    const [badgeImages, setBadgeImages] = useState<{ id: string; base64: string }[]>([]);

    // ── Derive brand info from contextData ──
    const brand = contextData.brand as Record<string, any> | null;
    const hasBrand = !!brand;
    const brandName = (brand?.name as string) ?? '';
    const brandAssets: BrandAsset[] = (brand?.assets as BrandAsset[] | null) ?? [];

    // ── Auto-populate fields when brand changes ──
    useEffect(() => {
        if (!brand) {
            // Brand deselected → clear all
            setBrandFields({});
            setBrandColors([]);
            setLogoBase64(undefined);
            return;
        }

        // Map brand DB fields → local editable state
        const fields: Record<string, string> = {};
        if (brand.name) fields.business_name = brand.name;
        if (brand.niche) fields.niche = brand.niche;
        if (brand.businessSummary) fields.business_summary = brand.businessSummary;
        if (brand.location) fields.location = brand.location;
        if (brand.phone) fields.phone = brand.phone;
        if (brand.website) fields.website = brand.website;
        if (brand.language) fields.language = brand.language;
        setBrandFields(fields);

        // Colors
        const colors = (Array.isArray(brand.colors) && brand.colors.length > 0) ? brand.colors : [];
        setBrandColors(colors);

        // Logo — fetch first asset URL → base64
        const assets = (brand.assets as BrandAsset[] | null) ?? [];
        if (assets.length > 0) {
            fetch(assets[0].url)
                .then((res) => res.blob())
                .then((blob) => {
                    const reader = new FileReader();
                    reader.onloadend = () => {
                        if (typeof reader.result === 'string') setLogoBase64(reader.result);
                    };
                    reader.readAsDataURL(blob);
                })
                .catch(() => { /* Non-fatal */ });
        } else {
            setLogoBase64(undefined);
        }

        // Reset toggles to all ON on new brand
        setToggles(createDefaultToggles());
    }, [brand]);

    // ── Toggle handlers ──
    const setFieldToggle = useCallback((field: BrandContextField, value: boolean) => {
        setToggles((prev) => ({ ...prev, [field]: value }));
    }, []);

    const masterToggle = useMemo(() => BRAND_CONTEXT_FIELDS.some((f) => toggles[f]), [toggles]);

    const setMasterToggle = useCallback((value: boolean) => {
        const next = {} as BrandContextToggles;
        for (const field of BRAND_CONTEXT_FIELDS) {
            next[field] = value;
        }
        setToggles(next);
    }, []);

    // ── Field update handlers ──
    const updateBrandField = useCallback((key: string, value: string) => {
        setBrandFields((prev) => ({ ...prev, [key]: value }));
    }, []);

    const addBadge = useCallback((base64: string) => {
        setBadgeImages((prev) => [...prev, { id: crypto.randomUUID(), base64 }]);
    }, []);

    const removeBadge = useCallback((id: string) => {
        setBadgeImages((prev) => prev.filter((b) => b.id !== id));
    }, []);

    // ── Derived: only ON-fields ──
    const injectedBrandData = useMemo<InjectedBrandData>(() => {
        const data: InjectedBrandData = {};
        if (toggles.business_name && brandFields.business_name) data.business_name = brandFields.business_name;
        if (toggles.niche && brandFields.niche) data.niche = brandFields.niche;
        if (toggles.business_summary && brandFields.business_summary) data.business_summary = brandFields.business_summary;
        if (toggles.location && brandFields.location) data.location = brandFields.location;
        if (toggles.phone && brandFields.phone) data.phone = brandFields.phone;
        if (toggles.website && brandFields.website) data.website = brandFields.website;
        if (toggles.language && brandFields.language) data.language = brandFields.language;
        if (toggles.colors && brandColors.length > 0) data.colors = brandColors;
        if (toggles.logo && logoBase64) data.logoBase64 = logoBase64;
        if (toggles.subject && subjectBase64) data.subjectBase64 = subjectBase64;
        if (toggles.badges && badgeImages.length > 0) data.badges = badgeImages.map((b) => b.base64);
        return data;
    }, [toggles, brandFields, brandColors, logoBase64, subjectBase64, badgeImages]);

    return {
        toggles,
        masterToggle,
        setFieldToggle,
        setMasterToggle,
        brandFields,
        updateBrandField,
        brandColors,
        setBrandColors,
        logoBase64,
        setLogoBase64,
        subjectBase64,
        setSubjectBase64,
        badgeImages,
        addBadge,
        removeBadge,
        injectedBrandData,
        hasBrand,
        brandName,
        brandAssets,
    };
}
