/**
 * tRPC → WordPress REST API Adapter
 *
 * Proxy-based compatibility layer that translates tRPC-style hook calls
 * (trpc.xxx.yyy.useQuery() / .useMutation()) into @tanstack/react-query
 * hooks backed by fetch() to the WordPress REST API.
 *
 * Architecture:
 * - ES Proxy intercepts property access to build tRPC-like paths
 * - ROUTE_MAP defines exact REST endpoint + HTTP method for each tRPC procedure
 * - apiFetch() handles authentication via X-WP-Nonce header
 * - useQuery/useMutation hooks are created dynamically
 *
 * This means ALL 27 tRPC-consuming files work WITHOUT code changes.
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";

// ============================================
// WordPress Config (injected by PCM_Admin)
// ============================================

declare global {
    interface Window {
        pcmConfig?: {
            restUrl: string;
            nonce: string;
            pluginUrl: string;
            shortcodePageUrl?: string;
            version: string;
            user: {
                id: number;
                name: string;
                email: string;
                role: string;
                avatarUrl: string;
            };
        };
    }
}

/** Get WP REST config with dev-mode fallbacks */
function getConfig() {
    return (
        window.pcmConfig ?? {
            restUrl: "/wp-json/pcm/v1/",
            nonce: "",
            pluginUrl: "",
            shortcodePageUrl: window.location.origin + "/",
            version: "1.0.0",
            user: { id: 0, name: "Dev", email: "", role: "admin", avatarUrl: "" },
        }
    );
}

// ============================================
// Route Map: tRPC path → REST endpoint + method
// ============================================

/**
 * Explicit mapping from tRPC procedure path to WP REST endpoint.
 * Format: "router.procedure" → { endpoint, method, paramMap? }
 *
 * `paramMap` is a function that transforms tRPC input into URL path segments.
 * Default method for queries is GET, for mutations is POST.
 */
interface RouteConfig {
    /** REST path relative to pcm/v1/ */
    endpoint: string;
    /** HTTP method */
    method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
    /** Transform input to build the final URL and body */
    transform?: (input: any) => { url: string; body?: any };
}

const ROUTE_MAP: Record<string, RouteConfig> = {
    // ── Integrations ──
    "integrations.list": { endpoint: "integrations", method: "GET" },
    "integrations.listProviders": { endpoint: "integrations/providers", method: "GET" },
    "integrations.providerDetails": { endpoint: "integrations/providers/details", method: "GET" },
    "integrations.validateApiKey": { endpoint: "integrations/validate", method: "POST" },
    "integrations.create": { endpoint: "integrations", method: "POST" },
    "integrations.update": {
        endpoint: "integrations",
        method: "PATCH",
        transform: (input: any) => ({ url: `integrations/${input.id}`, body: input }),
    },
    "integrations.toggleActive": {
        endpoint: "integrations",
        method: "POST",
        transform: (input: any) => ({ url: `integrations/${input.id}/toggle`, body: input }),
    },
    "integrations.deleteByProvider": {
        endpoint: "integrations",
        method: "DELETE",
        transform: (input: any) => ({ url: `integrations/provider/${input.provider}` }),
    },
    "integrations.syncToDatabase": { endpoint: "integrations/sync", method: "POST" },

    // ── Models ──
    "models.getAll": { endpoint: "models", method: "GET" },
    "models.list": { endpoint: "models", method: "GET" },
    "models.getById": {
        endpoint: "models",
        method: "GET",
        transform: (input: any) => ({ url: `models/${input.id}` }),
    },
    "models.getByProvider": {
        endpoint: "models",
        method: "GET",
        transform: (input: any) => ({ url: `models/provider/${input.provider}` }),
    },
    // Alias: TS original uses listByProvider (unfiltered, admin visibility)
    // Used by AllModelsSection in Integrations module
    "models.listByProvider": {
        endpoint: "models",
        method: "GET",
        transform: (input: any) => ({ url: `models/provider/${input.provider}` }),
    },

    "models.getForGeneration": {
        endpoint: "models",
        method: "GET",
        transform: (input: any) => ({ url: `models/generation/${input.type}` }),
    },
    "models.getForEditing": {
        endpoint: "models",
        method: "GET",
        transform: (input: any) => ({ url: `models/editing/${input.type}` }),
    },
    "models.create": { endpoint: "models", method: "POST" },
    "models.update": {
        endpoint: "models",
        method: "PATCH",
        transform: (input: any) => ({ url: `models/${input.id}`, body: input }),
    },
    "models.delete": {
        endpoint: "models",
        method: "DELETE",
        transform: (input: any) => ({ url: `models/${input.id}` }),
    },
    "models.updateCapability": {
        endpoint: "models",
        method: "POST",
        transform: (input: any) => ({ url: `models/${input.id}/capability`, body: input }),
    },
    "models.confirmStatus": {
        endpoint: "models",
        method: "POST",
        transform: (input: any) => ({ url: `models/${input.id}/confirm`, body: input }),
    },
    "models.toggleModule": {
        endpoint: "models",
        method: "POST",
        transform: (input: any) => ({ url: `models/${input.id}/toggle-module`, body: input }),
    },
    "models.syncFromIntegrations": { endpoint: "models/sync", method: "POST" },
    "models.bulkDelete": { endpoint: "models/bulk/delete", method: "POST" },
    "models.bulkChangeTier": { endpoint: "models/bulk/tier", method: "POST" },
    "models.bulkToggleModule": { endpoint: "models/bulk/toggle-module", method: "POST" },
    "models.bulkToggleEnabled": { endpoint: "models/bulk/toggle-enabled", method: "POST" },
    "models.autoDetectCapabilities": { endpoint: "models/auto-detect", method: "POST" },
    "models.confirmAllSuggested": { endpoint: "models/confirm-all-suggested", method: "POST" },

    // ── Brands ──
    "brands.list": { endpoint: "brands", method: "GET" },
    "brands.getAll": { endpoint: "brands", method: "GET" },
    "brands.getById": {
        endpoint: "brands",
        method: "GET",
        transform: (input: any) => ({ url: `brands/${input.id}` }),
    },
    "brands.findByWebsite": { endpoint: "brands/by-website", method: "GET" },
    "brands.create": { endpoint: "brands", method: "POST" },
    "brands.update": {
        endpoint: "brands",
        method: "PATCH",
        transform: (input: any) => ({ url: `brands/${input.id}`, body: input }),
    },
    "brands.delete": {
        endpoint: "brands",
        method: "DELETE",
        transform: (input: any) => ({ url: `brands/${input.id}` }),
    },
    "brands.bulkDelete": { endpoint: "brands/bulk/delete", method: "POST" },
    "brands.bulkDuplicate": { endpoint: "brands/bulk/duplicate", method: "POST" },
    "brands.fetchAssets": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.id}/fetch-assets`, body: input }),
    },
    "brands.addAsset": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.brandId}/assets`, body: input }),
    },
    "brands.addAssetFromUrl": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.brandId}/assets/from-url`, body: input }),
    },
    "brands.removeAsset": {
        endpoint: "brands",
        method: "DELETE",
        transform: (input: any) => ({ url: `brands/${input.brandId}/assets`, body: input }),
    },
    "brands.reorderAssets": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.brandId}/assets/reorder`, body: input }),
    },
    "brands.updateColors": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.brandId}/colors`, body: input }),
    },
    "brands.scrapeUrl": { endpoint: "brands/scrape-url", method: "POST" },

    // ── Strategy ──
    "strategy.create": { endpoint: "strategies", method: "POST" },
    "strategy.list": { endpoint: "strategies", method: "GET" },
    "strategy.get": {
        endpoint: "strategies",
        method: "GET",
        transform: (input: any) => ({ url: `strategies/${input.id}` }),
    },
    "strategy.update": {
        endpoint: "strategies",
        method: "PATCH",
        transform: (input: any) => ({ url: `strategies/${input.id}`, body: input }),
    },
    "strategy.delete": {
        endpoint: "strategies",
        method: "DELETE",
        transform: (input: any) => ({ url: `strategies/${input.id}` }),
    },
    "strategy.generate": {
        endpoint: "strategies",
        method: "POST",
        transform: (input: any) => ({ url: `strategies/${input.id}/generate` }),
    },

    // ── Writer ──
    "writer.list": { endpoint: "articles", method: "GET" },
    "writer.get": {
        endpoint: "articles",
        method: "GET",
        transform: (input: any) => ({ url: `articles/${input.id}` }),
    },
    "writer.update": {
        endpoint: "articles",
        method: "PATCH",
        transform: (input: any) => ({ url: `articles/${input.id}`, body: input }),
    },
    "writer.delete": {
        endpoint: "articles",
        method: "DELETE",
        transform: (input: any) => ({ url: `articles/${input.id}` }),
    },
    "writer.generate": { endpoint: "articles/generate", method: "POST" },
    "writer.uploadImage": { endpoint: "articles/upload-image", method: "POST" },

    // ── Articles (legacy aliases for Approvals module) ──
    "articles.list": { endpoint: "articles", method: "GET" },
    "articles.get": {
        endpoint: "articles",
        method: "GET",
        transform: (input: any) => ({ url: `articles/${input.id}` }),
    },
    "articles.update": {
        endpoint: "articles",
        method: "PATCH",
        transform: (input: any) => ({ url: `articles/${input.id}`, body: input }),
    },
    "articles.delete": {
        endpoint: "articles",
        method: "DELETE",
        transform: (input: any) => ({ url: `articles/${input.id}` }),
    },

    // ── Sites ──
    "sites.list": { endpoint: "sites", method: "GET" },
    "sites.create": { endpoint: "sites", method: "POST" },
    "sites.get": {
        endpoint: "sites",
        method: "GET",
        transform: (input: any) => ({ url: `sites/${input.id}` }),
    },
    "sites.update": {
        endpoint: "sites",
        method: "PATCH",
        transform: (input: any) => ({ url: `sites/${input.id}`, body: input }),
    },
    "sites.delete": {
        endpoint: "sites",
        method: "DELETE",
        transform: (input: any) => ({ url: `sites/${input.id}` }),
    },
    "sites.test": {
        endpoint: "sites",
        method: "POST",
        transform: (input: any) => ({ url: `sites/${input.id}/test` }),
    },
    "sites.publish": {
        endpoint: "sites",
        method: "POST",
        transform: (input: any) => ({ url: `sites/${input.id}/publish`, body: input }),
    },

    // ── Prompt Overrides ──
    "prompts.listSections": {
        endpoint: "prompts",
        method: "GET",
        transform: (input: any) => ({ url: `prompts/${input.module}` }),
    },
    "prompts.listVariants": {
        endpoint: "prompts",
        method: "GET",
        transform: (input: any) => ({ url: `prompts/${input.module}/${input.section}/variants` }),
    },
    "prompts.getPrompt": {
        endpoint: "prompts",
        method: "GET",
        transform: (input: any) => ({ url: `prompts/${input.module}/${input.section}` }),
    },
    "prompts.createVariant": { endpoint: "prompts", method: "POST" },
    "prompts.duplicateVariant": {
        endpoint: "prompts",
        method: "POST",
        transform: (input: any) => ({ url: `prompts/${input.id}/duplicate`, body: input }),
    },
    "prompts.updateVariant": {
        endpoint: "prompts",
        method: "PATCH",
        transform: (input: any) => ({ url: `prompts/${input.id}`, body: input }),
    },
    "prompts.deleteVariant": {
        endpoint: "prompts",
        method: "DELETE",
        transform: (input: any) => ({ url: `prompts/${input.id}` }),
    },
    "prompts.setDefault": {
        endpoint: "prompts",
        method: "POST",
        transform: (input: any) => ({ url: `prompts/${input.id}/default`, body: input }),
    },

    // ── Templates (Steg 5) ──
    "templates.list": { endpoint: "templates", method: "GET" },
    "templates.getById": {
        endpoint: "templates",
        method: "GET",
        transform: (input: any) => ({ url: `templates/${input.id}` }),
    },
    "templates.create": { endpoint: "templates", method: "POST" },
    "templates.update": {
        endpoint: "templates",
        method: "PATCH",
        transform: (input: any) => ({ url: `templates/${input.id}`, body: input }),
    },
    "templates.delete": {
        endpoint: "templates",
        method: "DELETE",
        transform: (input: any) => ({ url: `templates/${input.id}` }),
    },
    "templates.bulkDelete": { endpoint: "templates/bulk/delete", method: "POST" },
    "templates.bulkDuplicate": { endpoint: "templates/bulk/duplicate", method: "POST" },
    "templates.setDefault": {
        endpoint: "templates",
        method: "POST",
        transform: (input: any) => ({ url: `templates/${input.id}/default`, body: input }),
    },
    "templates.reseed": { endpoint: "templates/reseed", method: "POST" },

    // ── Assets (Steg 6) ──
    "assets.getAll": { endpoint: "assets", method: "GET" },
    "assets.getById": {
        endpoint: "assets",
        method: "GET",
        transform: (input: any) => ({ url: `assets/${input.id}` }),
    },
    "assets.refine": { endpoint: "assets/refine", method: "POST" },
    "assets.createVariations": { endpoint: "assets/variations", method: "POST" },
    "assets.export": { endpoint: "assets/export", method: "POST" },
    "assets.saveToProject": { endpoint: "assets/save-to-project", method: "POST" },
    "assets.getProjects": { endpoint: "assets/projects", method: "GET" },
    "assets.createProject": { endpoint: "assets/projects", method: "POST" },
    "assets.renameProject": {
        endpoint: "assets/projects",
        method: "PATCH",
        transform: (input: any) => ({ url: `assets/projects/${input.id}`, body: input }),
    },
    "assets.deleteProject": {
        endpoint: "assets/projects",
        method: "DELETE",
        transform: (input: any) => ({ url: `assets/projects/${input.id}` }),
    },
    "assets.duplicateProject": {
        endpoint: "assets/projects",
        method: "POST",
        transform: (input: any) => ({ url: `assets/projects/${input.id}/duplicate`, body: input }),
    },

    // ── Copy (Steg 7) ──
    "copy.generate": { endpoint: "copy/generate", method: "POST" },
    "copy.regenerateCard": { endpoint: "copy/regenerate", method: "POST" },
    "copy.regenerateBatch": { endpoint: "copy/regenerate-batch", method: "POST" },
    "copy.suggest": { endpoint: "copy/suggest", method: "POST" },
    "copy.duplicateAudience": { endpoint: "copy/duplicate-audience", method: "POST" },
    "copy.renameAudience": { endpoint: "copy/rename-audience", method: "POST" },
    "copy.updateResult": { endpoint: "copy/update-result", method: "POST" },
    "copy.getJobResults": {
        endpoint: "copy",
        method: "GET",
        transform: (input: any) => ({ url: `copy/jobs/${input.jobId}` }),
    },
    "copy.saveToProject": { endpoint: "copy/save-to-project", method: "POST" },
    "copy.getProjectResults": {
        endpoint: "copy",
        method: "GET",
        transform: (input: any) => ({ url: `copy/project/${input.projectId}` }),
    },

    // ── Copy URL Scraper ──
    "copyUrlScraper.scrapeBusinessInfo": { endpoint: "copy/scrape-url", method: "POST" },

    // ── Image (Steg 8) ──
    // Aliases used internally by AssetDetailView (backward-compat)
    "image.suggestConcepts": { endpoint: "image/concepts", method: "POST" },
    "image.generateSingle": { endpoint: "image/generate", method: "POST" },
    "image.generateBatch": { endpoint: "image/generate-batch", method: "POST" },
    "image.editImage": { endpoint: "image/edit", method: "POST" },
    "image.upscale": { endpoint: "image/upscale", method: "POST" },
    // Primary paths used by ImageModule (index.tsx)
    "image.generateConcepts": { endpoint: "image/concepts", method: "POST" },
    "image.generate": { endpoint: "image/generate", method: "POST" },
    "image.generateSuggestions": { endpoint: "image/suggestions", method: "POST" },
    "image.generateContextSuggestions": { endpoint: "image/context-suggestions", method: "POST" },
    "image.optimizeBrief": { endpoint: "image/optimize-brief", method: "POST" },

    // ── Video (Steg 9) ──
    "video.suggestConcepts": { endpoint: "video/concepts", method: "POST" },
    "video.generateConcepts": { endpoint: "video/concepts", method: "POST" },
    "video.enhancePrompt": { endpoint: "video/enhance-prompt", method: "POST" },
    "video.composePrompt": { endpoint: "video/compose-prompt", method: "POST" },
    "video.generate": { endpoint: "video/generate", method: "POST" },
    "video.checkStatus": { endpoint: "video/status", method: "POST" },
    "video.getCapabilities": { endpoint: "video/capabilities", method: "GET" },

    // ── Asset Scraper (Steg 10) ──
    "assetScraper.listCollections": { endpoint: "scraper/collections", method: "GET" },
    "assetScraper.getCollection": {
        endpoint: "scraper",
        method: "GET",
        transform: (input: any) => ({ url: `scraper/collections/${input.collectionId}` }),
    },
    "assetScraper.scrapeUrl": { endpoint: "scraper/scrape", method: "POST" },
    "assetScraper.analyzeCollection": { endpoint: "scraper/analyze", method: "POST" },
    "assetScraper.smartSelect": { endpoint: "scraper/smart-select", method: "POST" },
    "assetScraper.deleteCollection": {
        endpoint: "scraper",
        method: "DELETE",
        transform: (input: any) => ({ url: `scraper/collections/${input.collectionId}` }),
    },
    "assetScraper.toggleImageExclusion": {
        endpoint: "scraper",
        method: "PATCH",
        transform: (input: any) => ({ url: `scraper/images/${input.imageId}`, body: { excluded: input.excluded } }),
    },

    // ── Auth (handled by WP nonce — no backend needed) ──
    "auth.getUser": {
        endpoint: "__local__",
        method: "GET",
        transform: () => ({ url: "__local__" }),
    },

    // ── Prompt Overrides (Prompt Editor in Settings) ──
    // Route names and transforms must match what PromptEditorSection.tsx calls exactly.
    "promptOverrides.list": {
        endpoint: "prompts",
        method: "GET",
        transform: (input: any) => ({ url: `prompts/${input.module}` }),
    },
    "promptOverrides.variants": {
        endpoint: "prompts",
        method: "GET",
        transform: (input: any) => ({ url: `prompts/${input.module}/${input.section}/variants` }),
    },
    "promptOverrides.getPrompt": {
        endpoint: "prompts",
        method: "GET",
        transform: (input: any) => ({ url: `prompts/${input.module}/${input.section}` }),
    },
    "promptOverrides.create": { endpoint: "prompts", method: "POST" },
    "promptOverrides.duplicate": {
        endpoint: "prompts",
        method: "POST",
        transform: (input: any) => ({ url: `prompts/${input.variantId}/duplicate` }),
    },
    "promptOverrides.update": {
        endpoint: "prompts",
        method: "PATCH",
        transform: (input: any) => ({
            url: `prompts/${input.variantId}`,
            body: { name: input.name, content: input.content },
        }),
    },
    "promptOverrides.delete": {
        endpoint: "prompts",
        method: "DELETE",
        transform: (input: any) => ({ url: `prompts/${input.variantId}` }),
    },
    "promptOverrides.setDefault": {
        endpoint: "prompts",
        method: "POST",
        transform: (input: any) => ({ url: `prompts/${input.variantId}/default` }),
    },

    // ── Keywords (Keyword Explorer) ──
    "keywords.prefixes": { endpoint: "keywords/prefixes", method: "GET" },
    "keywords.search": { endpoint: "keywords/search", method: "POST" },
    "keywords.enrich": { endpoint: "keywords/enrich", method: "POST" },
    "keywords.listSaved": { endpoint: "keywords/lists", method: "GET" },
    "keywords.saveList": { endpoint: "keywords/lists", method: "POST" },
    "keywords.loadList": {
        endpoint: "keywords/lists",
        method: "GET",
        transform: (input: any) => ({ url: `keywords/lists/${input.id}` }),
    },
    "keywords.deleteList": {
        endpoint: "keywords/lists",
        method: "DELETE",
        transform: (input: any) => ({ url: `keywords/lists/${input.id}` }),
    },

    // ── Approvals (Client Sharing & Webhooks) ──
    "approvals.listSets": { endpoint: "approvals/sets", method: "GET" },
    "approvals.createSet": { endpoint: "approvals/sets", method: "POST" },
    "approvals.getPublicSet": {
        endpoint: "approvals/sets",
        method: "GET",
        transform: (input: any) => ({ url: `approvals/sets/${input.token}` }),
    },
    "approvals.submitReview": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.token}/review`, body: input }),
    },
};

// ============================================
// Fetch Wrapper
// ============================================

/** Authenticated fetch wrapper for WP REST API */
async function apiFetch<T>(
    endpoint: string,
    options?: RequestInit
): Promise<T> {
    // Special case: local-only data (e.g., auth.getUser → pcmConfig.user)
    if (endpoint === "__local__") {
        return getConfig().user as unknown as T;
    }

    const config = getConfig();
    const url = `${config.restUrl}${endpoint.replace(/^\//, "")}`;

    const response = await fetch(url, {
        ...options,
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": config.nonce,
            ...(options?.headers ?? {}),
        },
    });

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        throw new Error(
            error.message ?? `API error: ${response.status} ${response.statusText}`
        );
    }

    return response.json();
}

// ============================================
// Proxy Factory
// ============================================

/**
 * Create a nested proxy that intercepts property access to build
 * tRPC-compatible hook calls.
 *
 * trpc.integrations.list.useQuery()
 *   → looks up "integrations.list" in ROUTE_MAP
 *   → returns useQuery({ queryFn: () => apiFetch("integrations") })
 */
function createNestedProxy(path: string[] = []): any {
    return new Proxy(
        {},
        {
            get(_target, prop: string) {
                // ── Terminal: useQuery ──
                if (prop === "useQuery") {
                    return (input?: any, options?: any) => {
                        const routeKey = path.join(".");
                        const route = ROUTE_MAP[routeKey];

                        // Determine endpoint URL
                        let endpoint: string;
                        if (route?.transform && input) {
                            endpoint = route.transform(input).url;
                        } else if (route) {
                            endpoint = route.endpoint;
                        } else {
                            // Fallback: use path as endpoint (for unmapped routes)
                            endpoint = path.join("/");
                        }

                        // Build query string from input (for GET requests without transform)
                        let queryString = "";
                        if (input && (!route?.transform)) {
                            const params = new URLSearchParams();
                            for (const [key, value] of Object.entries(input)) {
                                if (value !== undefined && value !== null) {
                                    params.set(key, String(value));
                                }
                            }
                            const str = params.toString();
                            if (str) queryString = "?" + str;
                        }

                        return useQuery({
                            queryKey: [...path, input],
                            queryFn: () => apiFetch(endpoint + queryString),
                            enabled: options?.enabled ?? true,
                            ...options,
                        });
                    };
                }

                // ── Terminal: useMutation ──
                if (prop === "useMutation") {
                    return (options?: any) => {
                        const routeKey = path.join(".");
                        const route = ROUTE_MAP[routeKey];

                        return useMutation({
                            mutationFn: (input: any) => {
                                let endpoint: string;
                                let body: any = input;
                                let method = route?.method ?? "POST";

                                if (route?.transform) {
                                    const transformed = route.transform(input);
                                    endpoint = transformed.url;
                                    body = transformed.body ?? input;
                                } else if (route) {
                                    endpoint = route.endpoint;
                                } else {
                                    endpoint = path.join("/");
                                }

                                // Only send body for methods that support it (POST, PATCH, PUT)
                                const fetchOptions: RequestInit = { method };
                                if (method !== "GET" && method !== "DELETE") {
                                    fetchOptions.body = JSON.stringify(body);
                                }

                                return apiFetch(endpoint, fetchOptions);
                            },
                            ...options,
                        });
                    };
                }

                // ── Terminal: useUtils (for cache invalidation) ──
                if (prop === "useUtils") {
                    return () => {
                        const qc = useQueryClient();
                        return createUtilsProxy(path, qc);
                    };
                }

                // ── Skip Provider/createClient (removed in main.tsx) ──
                if (prop === "Provider" || prop === "createClient") {
                    return () => null;
                }

                // ── Continue building path ──
                return createNestedProxy([...path, prop]);
            },
        }
    );
}

/** Utils proxy for cache invalidation: trpc.xxx.useUtils().yyy.invalidate() */
function createUtilsProxy(basePath: string[], queryClient: any): any {
    return new Proxy(
        {},
        {
            get(_target, prop: string) {
                if (prop === "invalidate") {
                    return () => queryClient.invalidateQueries({ queryKey: basePath });
                }
                if (prop === "refetch") {
                    return () => queryClient.refetchQueries({ queryKey: basePath });
                }
                if (prop === "setData") {
                    return (updater: any) =>
                        queryClient.setQueryData(basePath, updater);
                }
                return createUtilsProxy([...basePath, prop], queryClient);
            },
        }
    );
}

// ============================================
// Exports
// ============================================

/**
 * The tRPC-compatible client.
 * Components use it exactly like the original:
 *
 *   import { trpc } from '@/lib/trpc';
 *   const { data } = trpc.integrations.list.useQuery();
 *   const mutation = trpc.brands.create.useMutation();
 */
export const trpc = createNestedProxy();

/** Direct fetch for custom API calls outside the proxy */
export { apiFetch, getConfig };
