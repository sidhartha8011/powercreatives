/**
 * useImageSuggestions — AI prompt suggestions hook
 *
 * Owns all prompt suggestion state and API calls:
 * - Brief-based suggestions ("Get AI Suggestions" button)
 * - Context-based suggestions ("Generate Suggestions" from brand/URL/theme)
 *
 * Single responsibility: fetch and manage suggestion lists.
 * Generation orchestration (execute + produce images) lives in useImageGeneration.
 */

import { useState, useCallback } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { useSettings } from '@/contexts/AppContext';
import { DEFAULT_DETAIL_LEVEL, DEFAULT_SUGGESTION_COUNT, SUGGESTION_CONFIG } from '../imageConfig';
import { toDetailLevelEnum } from '../types';
import type { DetailLevelValue } from '../types';
import { DEFAULT_BRAND_TOGGLES } from '@/components/shared/ContextPanel';
import type { ContextData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';

// ============================================================================
// Return type
// ============================================================================

export interface UseImageSuggestionsReturn {
    // Suggestions from product brief
    suggestions: string[];
    setSuggestions: React.Dispatch<React.SetStateAction<string[]>>;
    suggestionCount: number;
    setSuggestionCount: (n: number) => void;
    detailLevel: DetailLevelValue;
    setDetailLevel: (v: DetailLevelValue) => void;
    isLoadingSuggestions: boolean;
    // Context-based suggestions
    contextSuggestions: string[];
    setContextSuggestions: React.Dispatch<React.SetStateAction<string[]>>;
    isLoadingContextSuggestions: boolean;
    // Handlers
    handleGenerateSuggestions: (
        productBrief: string,
        contextData: ContextData,
        formValues: Record<string, string | number | undefined>,
        sessionReferenceImages: SessionReferenceImage[]
    ) => Promise<void>;
    handleGenerateContextSuggestions: (
        contextData: ContextData,
        formValues: Record<string, string | number | undefined>,
        sessionReferenceImages: SessionReferenceImage[]
    ) => Promise<void>;
    applySuggestion: (
        suggestion: string,
        setProductBrief: (v: string) => void
    ) => void;
    applyContextSuggestion: (
        suggestion: string,
        setProductBrief: (v: string) => void
    ) => void;
}

// ============================================================================
// Hook
// ============================================================================

export function useImageSuggestions(): UseImageSuggestionsReturn {
    // ── Settings (for Menu Intelligence model selection) ──
    const { settings } = useSettings();

    // ── tRPC mutations ──
    const generateSuggestionsMutation = trpc.image.generateSuggestions.useMutation();
    const generateContextSuggestionsMutation = trpc.image.generateContextSuggestions.useMutation();

    // ── Suggestion controls ──
    const [suggestions, setSuggestions] = useState<string[]>([]);
    const [suggestionCount, setSuggestionCount] = useState(DEFAULT_SUGGESTION_COUNT);
    const [detailLevel, setDetailLevel] = useState<DetailLevelValue>(DEFAULT_DETAIL_LEVEL);
    const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);

    // ── Context-based suggestions ──
    const [contextSuggestions, setContextSuggestions] = useState<string[]>([]);
    const [isLoadingContextSuggestions, setIsLoadingContextSuggestions] = useState(false);

    // ── Brief-based suggestions ──
    const handleGenerateSuggestions = useCallback(async (
        productBrief: string,
        contextData: ContextData,
        formValues: Record<string, string | number | undefined>,
        sessionReferenceImages: SessionReferenceImage[],
    ) => {
        if (!productBrief.trim() || productBrief.length < SUGGESTION_CONFIG.minInputLength) {
            toast.error(`Brief must be at least ${SUGGESTION_CONFIG.minInputLength} characters`);
            return;
        }

        const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };

        setIsLoadingSuggestions(true);
        try {
            const result = await generateSuggestionsMutation.mutateAsync({
                brief: productBrief,
                count: suggestionCount,
                detailLevel: toDetailLevelEnum(detailLevel),
                modelId: settings.defaultImageTextModel || undefined,
                brandName: (contextData.brand as any)?.name,
                brandSummary: toggles.useSummary ? ((contextData.brand as any)?.businessSummary || formValues.business_summary) : undefined,
                brandColors: toggles.useColors ? (contextData.brand as any)?.colors : undefined,
                niche: formValues.niche as string,
                location: formValues.location as string,
                phone: formValues.phone as string,
                website: formValues.website as string,
                language: formValues.language as string,
                seasonEvent: contextData.seasonEvent || undefined,
                campaignTheme: contextData.campaignTheme || undefined,
                url: contextData.url || undefined,
                referenceImages: toggles.useReferenceSubjects && sessionReferenceImages.length > 0
                    ? sessionReferenceImages.map((img) => ({ url: img.url, intent: img.intent }))
                    : undefined,
            });

            const list = (result as any).suggestions ?? result;
            setSuggestions(Array.isArray(list) ? list : []);
        } catch (error) {
            console.error('[useImageSuggestions] generateSuggestions failed:', error);
            const msg = error instanceof Error ? error.message : 'Failed to generate suggestions';
            toast.error(msg);
        } finally {
            setIsLoadingSuggestions(false);
        }
    }, [generateSuggestionsMutation, suggestionCount, detailLevel, settings.defaultImageTextModel]);

    // ── Context-based suggestions ──
    const handleGenerateContextSuggestions = useCallback(async (
        contextData: ContextData,
        formValues: Record<string, string | number | undefined>,
        sessionReferenceImages: SessionReferenceImage[],
    ) => {
        const hasContext = contextData.brand || contextData.url || contextData.seasonEvent || contextData.campaignTheme || Object.keys(formValues).length > 0;
        if (!hasContext) {
            toast.error('Please select a brand, fill out brand fields, or pick a season/theme first');
            return;
        }

        const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };

        setIsLoadingContextSuggestions(true);
        try {
            const result = await generateContextSuggestionsMutation.mutateAsync({
                count: 4,
                modelId: settings.defaultImageTextModel || undefined,
                brandId: (contextData.brand as any)?.id,
                brandName: (contextData.brand as any)?.name,
                brandSummary: toggles.useSummary ? ((contextData.brand as any)?.businessSummary || formValues.business_summary) : undefined,
                brandColors: toggles.useColors ? (contextData.brand as any)?.colors : undefined,
                niche: formValues.niche as string,
                location: formValues.location as string,
                phone: formValues.phone as string,
                website: formValues.website as string,
                language: formValues.language as string,
                seasonEvent: contextData.seasonEvent || undefined,
                campaignTheme: contextData.campaignTheme || undefined,
                url: contextData.url || undefined,
                referenceImages: toggles.useReferenceSubjects && sessionReferenceImages.length > 0
                    ? sessionReferenceImages.map((img) => ({ url: img.url, intent: img.intent }))
                    : undefined,
            });

            const list = (result as any).suggestions ?? result;
            setContextSuggestions(Array.isArray(list) ? list : []);
        } catch (error) {
            console.error('[useImageSuggestions] generateContextSuggestions failed:', error);
            const msg = error instanceof Error ? error.message : 'Failed to generate context suggestions';
            toast.error(msg);
        } finally {
            setIsLoadingContextSuggestions(false);
        }
    }, [generateContextSuggestionsMutation, settings.defaultImageTextModel]);

    // ── Apply suggestion to the product brief ──
    const applySuggestion = useCallback((
        suggestion: string,
        setProductBrief: (v: string) => void,
    ) => {
        setProductBrief(suggestion);
        setSuggestions([]); // Clear after applying
    }, []);

    const applyContextSuggestion = useCallback((
        suggestion: string,
        setProductBrief: (v: string) => void,
    ) => {
        setProductBrief(suggestion);
        setContextSuggestions([]); // Clear after applying
    }, []);

    return {
        suggestions,
        setSuggestions,
        suggestionCount,
        setSuggestionCount,
        detailLevel,
        setDetailLevel,
        isLoadingSuggestions,
        contextSuggestions,
        setContextSuggestions,
        isLoadingContextSuggestions,
        handleGenerateSuggestions,
        handleGenerateContextSuggestions,
        applySuggestion,
        applyContextSuggestion,
    };
}
