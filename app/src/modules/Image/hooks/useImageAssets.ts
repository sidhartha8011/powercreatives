/**
 * useImageAssets — Asset pipeline state hook
 *
 * Owns file upload state for the Asset Pipeline sidebar section:
 * - Logo branding (single image)
 * - Reference subject (single image)
 *
 * Also owns localStorage persistence for generated assets and ad versions
 * (read-on-mount + write-on-change pattern).
 *
 * Exposes an `assetPipelinePayload` that is passed to useImageGeneration
 * so the generation hook can include logo/subject/text in the API call.
 */

import { useState, useRef, useEffect, useCallback, useMemo } from 'react';
import type { RefObject } from 'react';
import type {
    LogoConfig,
    LogoPlacement,
    ReferenceAsset,
    ReferenceAssetType,
    ReferenceAssetType,
    GeneratedAsset,
    AdVersion,
} from '@/types';
import { STORAGE_KEYS } from '../imageConfig';
import type { AssetPipelinePayload } from './useImageGeneration';

// ============================================================================
// Return type
// ============================================================================

export interface UseImageAssetsReturn {
    // Logo
    logoConfig: LogoConfig;
    setLogoConfig: React.Dispatch<React.SetStateAction<LogoConfig>>;
    logoInputRef: RefObject<HTMLInputElement>;
    clearLogo: () => void;
    // Subject
    subjectConfig: ReferenceAsset;
    setSubjectConfig: React.Dispatch<React.SetStateAction<ReferenceAsset>>;
    subjectInputRef: RefObject<HTMLInputElement>;
    clearSubject: () => void;
    // Unified upload handler
    handleFileUpload: (type: 'logo' | 'subject', e: React.ChangeEvent<HTMLInputElement>) => void;
    // Built payload to pass to generation hook
    assetPipelinePayload: AssetPipelinePayload;
    // localStorage helpers for assets + versions
    persistAssets: (assets: GeneratedAsset[]) => void;
    persistVersions: (versions: AdVersion[]) => void;
    loadPersistedAssets: () => { assets: GeneratedAsset[]; versions: AdVersion[] };
}

// ============================================================================
// Hook
// ============================================================================

export function useImageAssets(): UseImageAssetsReturn {
    // ── Logo ──
    const [logoConfig, setLogoConfig] = useState<LogoConfig>({
        isActive: false,
        placement: 'in-image' as LogoPlacement,
    });
    const logoInputRef = useRef<HTMLInputElement>(null);

    // ── Reference subject ──
    const [subjectConfig, setSubjectConfig] = useState<ReferenceAsset>({
        id: 'subject',
        type: 'product' as ReferenceAssetType,
        isActive: false,
    });
    const subjectInputRef = useRef<HTMLInputElement>(null);

    // ── File upload handler — reads file as base64 data URL ──
    const handleFileUpload = useCallback(
        (type: 'logo' | 'subject', e: React.ChangeEvent<HTMLInputElement>) => {
            const file = e.target.files?.[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onloadend = () => {
                const result = reader.result as string;
                if (type === 'logo') {
                    setLogoConfig((prev) => ({ ...prev, base64: result, isActive: true }));
                } else if (type === 'subject') {
                    setSubjectConfig((prev) => ({ ...prev, base64: result, isActive: true }));
                }
            };
            reader.readAsDataURL(file);
            // Reset input so same file can be re-uploaded
            e.target.value = '';
        },
        [],
    );

    // ── Clear handlers — reset logo/subject to initial empty state ──
    const clearLogo = useCallback(() => {
        setLogoConfig({ isActive: false, placement: 'in-image' as LogoPlacement });
    }, []);

    const clearSubject = useCallback(() => {
        setSubjectConfig({ id: 'subject', type: 'product' as ReferenceAssetType, isActive: false });
    }, []);

    // ── Asset pipeline payload — built from current state for generation hook ──
    // Simplified: if base64 exists, asset is active. No separate isActive flag needed.
    const assetPipelinePayload = useMemo<AssetPipelinePayload>(() => ({
        logoBase64: logoConfig.base64 || undefined,
        subjectBase64: subjectConfig.base64 || undefined,
    }), [logoConfig, subjectConfig]);

    // ── localStorage helpers ──
    const persistAssets = useCallback((assets: GeneratedAsset[]) => {
        if (assets.length === 0) return;
        try {
            localStorage.setItem(STORAGE_KEYS.assets, JSON.stringify(assets));
        } catch (e) {
            console.warn('[useImageAssets] Failed to persist assets:', e);
        }
    }, []);

    const persistVersions = useCallback((versions: AdVersion[]) => {
        if (versions.length === 0) return;
        try {
            localStorage.setItem(STORAGE_KEYS.versions, JSON.stringify(versions));
        } catch (e) {
            console.warn('[useImageAssets] Failed to persist versions:', e);
        }
    }, []);

    const loadPersistedAssets = useCallback((): { assets: GeneratedAsset[]; versions: AdVersion[] } => {
        try {
            const rawAssets = localStorage.getItem(STORAGE_KEYS.assets);
            const rawVersions = localStorage.getItem(STORAGE_KEYS.versions);
            const assets: GeneratedAsset[] = rawAssets
                ? JSON.parse(rawAssets).map((a: GeneratedAsset) => ({ ...a, createdAt: new Date(a.createdAt) }))
                : [];
            const versions: AdVersion[] = rawVersions ? JSON.parse(rawVersions) : [];
            return { assets, versions };
        } catch (e) {
            console.warn('[useImageAssets] Failed to load persisted assets:', e);
            return { assets: [], versions: [] };
        }
    }, []);

    return {
        logoConfig,
        setLogoConfig,
        logoInputRef,
        clearLogo,
        subjectConfig,
        setSubjectConfig,
        subjectInputRef,
        clearSubject,
        handleFileUpload,
        assetPipelinePayload,
        persistAssets,
        persistVersions,
        loadPersistedAssets,
    };
}
