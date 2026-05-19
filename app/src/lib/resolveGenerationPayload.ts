import { DEFAULT_BRAND_TOGGLES } from '@/components/shared/ContextPanel';
import type { ContextData } from '@/components/shared/ContextPanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { getBrandLogo } from '@shared/brandAssetResolver';

export interface GenerationPayloadContext {
    contextData: ContextData;
    formValues: Record<string, string | number | undefined>;
    sessionReferenceImages: SessionReferenceImage[];
}

export interface ResolvedGenerationPayload {
    brandContext: {
        brandName?: string;
        brandSummary?: string;
        brandColors?: string[];
        niche?: string;
        location?: string;
        phone?: string;
        website?: string;
        language?: string;
        seasonEvent?: string;
        campaignTheme?: string;
        url?: string;
    };
    inputUrls: string[];
    referenceImageIntents: string[];
    hasBrandCtx: boolean;
}

/**
 * Resolves the generation payload (brand text context + image references).
 * This acts as the Single Source of Truth for both Image and Ads modules
 * to ensure that logos and reference images are universally forwarded to AI providers.
 */
export function resolveGenerationPayload({
    contextData,
    formValues,
    sessionReferenceImages,
}: GenerationPayloadContext): ResolvedGenerationPayload {
    const toggles = { ...DEFAULT_BRAND_TOGGLES, ...contextData.brandToggles };
    
    const brandCtx = {
        brandName: formValues.business_name as string | undefined,
        brandSummary: toggles.useSummary ? (formValues.business_summary as string | undefined) : undefined,
        brandColors: toggles.useColors ? (contextData.brand as any)?.colors : undefined,
        niche: formValues.niche as string | undefined,
        location: formValues.location as string | undefined,
        phone: formValues.phone as string | undefined,
        website: formValues.website as string | undefined,
        language: formValues.language as string | undefined,
        seasonEvent: contextData.seasonEvent,
        campaignTheme: contextData.campaignTheme,
        url: contextData.url,
    };
    
    const hasBrandCtx = !!contextData.brand || Object.keys(formValues).length > 0;

    const logo = getBrandLogo(contextData.brand as any);

    const refUrls = [
        ...(toggles.useLogo && logo ? [logo.url] : []),
        ...(toggles.useReferenceSubjects ? sessionReferenceImages.filter((img) => img.fileKey !== logo?.fileKey).map((img) => img.url) : [])
    ];
    
    const refIntents = [
        ...(toggles.useLogo && logo ? ['auto'] : []),
        ...(toggles.useReferenceSubjects ? sessionReferenceImages.filter((img) => img.fileKey !== logo?.fileKey).map((img) => img.intent) : [])
    ];

    return {
        brandContext: brandCtx,
        inputUrls: refUrls,
        referenceImageIntents: refIntents,
        hasBrandCtx,
    };
}
