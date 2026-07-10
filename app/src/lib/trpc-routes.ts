/**
 * tRPC Route Map — All REST endpoint mappings
 *
 * This file contains the route configuration that maps tRPC-style
 * procedure paths to WordPress REST API endpoints.
 *
 * Format: "router.procedure" → { endpoint, method, transform? }
 *
 * The proxy engine in trpc.ts reads this map to route calls.
 * When adding a new endpoint, add it here — NOT in trpc.ts.
 */

// ── Types ──

/** Configuration for a single tRPC → REST route mapping */
export interface RouteConfig {
    /** REST path relative to pcm/v1/ */
    endpoint: string;
    /** HTTP method */
    method: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
    /** Transform input to build the final URL and body */
    transform?: (input: any) => { url: string; body?: any };
}

// ── Route Map ──

export const ROUTE_MAP: Record<string, RouteConfig> = {
    // ── Integrations ──
    "integrations.list": { endpoint: "integrations", method: "GET" },
    "integrations.listProviders": { endpoint: "integrations/providers", method: "GET" },
    "integrations.providerDetails": { endpoint: "integrations/providers/details", method: "GET" },
    "integrations.brevoSenders": { endpoint: "integrations/brevo/senders", method: "GET" },
    "integrations.prtUrls": { endpoint: "integrations/proranktracker/urls", method: "GET" },
    "integrations.prtRanks": { endpoint: "integrations/proranktracker/ranks", method: "GET" },
    "integrations.prtHistory": { endpoint: "integrations/proranktracker/history", method: "GET" },
    "integrations.prtPageRanks": { endpoint: "integrations/proranktracker/page-ranks", method: "POST" },
    "integrations.gscProperties": { endpoint: "integrations/gsc/properties", method: "GET" },
    "integrations.gscStats": { endpoint: "integrations/gsc/stats", method: "POST" },
    "integrations.gscOauthStart": { endpoint: "integrations/gsc/oauth-start", method: "POST" },
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
    "models.resync": { endpoint: "models/resync", method: "POST" },
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
    "brands.setAssetAsLogo": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.brandId}/assets/set-logo`, body: input }),
    },
    "brands.updateColors": {
        endpoint: "brands",
        method: "POST",
        transform: (input: any) => ({ url: `brands/${input.brandId}/colors`, body: input }),
    },
    "brands.scrapeUrl": { endpoint: "brands/scrape-url", method: "POST" },

    // ── Deliveries ──
    "deliveries.list": { endpoint: "deliveries", method: "GET" },
    "deliveries.getById": {
        endpoint: "deliveries",
        method: "GET",
        transform: (input: any) => ({ url: `deliveries/${input.id}` }),
    },
    "deliveries.create": { endpoint: "deliveries", method: "POST" },
    "deliveries.update": {
        endpoint: "deliveries",
        method: "PATCH",
        transform: (input: any) => ({ url: `deliveries/${input.id}`, body: input }),
    },
    "deliveries.delete": {
        endpoint: "deliveries",
        method: "DELETE",
        transform: (input: any) => ({ url: `deliveries/${input.id}` }),
    },
    "deliveries.logs": {
        endpoint: "deliveries",
        method: "GET",
        transform: (input: any) => ({ url: `deliveries/${input.id}/logs` }),
    },
    "deliveries.addLog": {
        endpoint: "deliveries",
        method: "POST",
        transform: (input: any) => ({ url: `deliveries/${input.id}/logs`, body: { note: input.note } }),
    },
    "deliveries.setLead": {
        endpoint: "deliveries",
        method: "PATCH",
        transform: (input: any) => ({ url: `deliveries/${input.id}/lead`, body: { userId: input.userId } }),
    },

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
        transform: (input: any) => ({
            url: `strategies/${input.id}/generate`,
            body: input.itemId ? { itemId: input.itemId } : {},
        }),
    },

    // ── Writer ──
    "writer.list": { endpoint: "articles", method: "GET" },
    "writer.get": {
        endpoint: "articles",
        method: "GET",
        transform: (input: any) => ({ url: `articles/${input.id}` }),
    },
    "writer.create": { endpoint: "articles", method: "POST" },
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
    "sites.gscVerify": {
        endpoint: "sites",
        method: "POST",
        transform: (input: any) => ({ url: `sites/${input.id}/gsc-verify`, body: { targetUrl: input.targetUrl ?? "" } }),
    },
    "sites.gscPreview": {
        endpoint: "sites",
        method: "POST",
        transform: (input: any) => ({ url: `sites/${input.id}/gsc-preview` }),
    },
    "sites.updateConnectors": { endpoint: "sites/update-connectors", method: "POST" },
    "sites.connectorVersion": {
        endpoint: "sites",
        method: "GET",
        transform: (input: any) => ({ url: `sites/${input.id}/connector-version` }),
    },
    "sites.updateConnector": {
        endpoint: "sites",
        method: "POST",
        transform: (input: any) => ({ url: `sites/${input.id}/update-connector` }),
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

    // ── Templates ──
    // ── SEO (content-SEO workbench) ──
    "seo.listContent": { endpoint: "seo/content", method: "GET" },
    "seo.contentOptions": { endpoint: "seo/content/options", method: "GET" },
    "seo.quickCreate": { endpoint: "seo/content", method: "POST" },
    "seo.saveCell": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/cell`, body: { field: input.field, value: input.value } }),
    },
    "seo.bulkDelete": { endpoint: "seo/content/bulk-delete", method: "POST" },
    "seo.duplicateContent": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/duplicate` }),
    },
    "seo.getBody": {
        endpoint: "seo/content",
        method: "GET",
        transform: (input: any) => ({ url: `seo/content/${input.id}/body` }),
    },
    "seo.optimizeBody": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/optimize`, body: { brandId: input.brandId, model: input.model } }),
    },
    "seo.saveBody": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/body`, body: { body: input.body } }),
    },
    "seo.generateField": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/generate`, body: { field: input.field, brandId: input.brandId, model: input.model, provider: input.provider, templateId: input.templateId } }),
    },
    "seo.scanLinks": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/scan-links` }),
    },
    // ── Link inspector (popup) — local ──
    "seo.getLinks": {
        endpoint: "seo/content",
        method: "GET",
        transform: (input: any) => ({ url: `seo/content/${input.id}/links` }),
    },
    "seo.updateLink": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/links/${input.index}`, body: { anchor: input.anchor, href: input.href } }),
    },
    "seo.removeLink": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/links/${input.index}/remove` }),
    },
    // ── Heading editor (expandable rows) — local ──
    "seo.getHeadings": {
        endpoint: "seo/content",
        method: "GET",
        transform: (input: any) => ({ url: `seo/content/${input.id}/headings` }),
    },
    "seo.getContentNodes": {
        endpoint: "seo/content",
        method: "GET",
        transform: (input: any) => ({ url: `seo/content/${input.id}/content-nodes` }),
    },
    "seo.updateHeading": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/headings/${input.index}`, body: { text: input.text, level: input.level } }),
    },
    "seo.optimizeHeading": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/headings/${input.index}/optimize`, body: { text: input.text, brandId: input.brandId, model: input.model, provider: input.provider, templateId: input.templateId } }),
    },
    // ── Heading editor — remote (connected sites) ──
    "seo.remoteGetInventory": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/inventory?type=${input.type ?? 'post'}` }),
    },
    "seo.remoteGetContentNodes": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/content-nodes` }),
    },
    "seo.remoteMigrateOverrides": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/migrate-overrides`, body: {} }),
    },
    "seo.remotePushConfig": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/push-config`, body: {} }),
    },
    "seo.remoteGetParagraphRules": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/rules` }),
    },
    "seo.remoteSaveParagraphRule": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/paragraph-rule`, body: { text: input.text, occurrence: input.occurrence, replacement: input.replacement, anchor: input.anchor } }),
    },
    "seo.remoteOptimizeParagraph": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/paragraph-optimize`, body: { type: input.type, text: input.text, model: input.model, provider: input.provider, templateId: input.templateId } }),
    },
    // ── Section editor (contracts v2) — remote (connected sites) ──
    "seo.remoteSaveSectionRule": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/section-rule`, body: { kind: input.kind, headingText: input.headingText, headingLevel: input.headingLevel, headingOccurrence: input.headingOccurrence, paragraphs: input.paragraphs, anchorText: input.anchorText, anchorLevel: input.anchorLevel, anchorOccurrence: input.anchorOccurrence, position: input.position, ruleId: input.ruleId, replacement: input.replacement } }),
    },
    "seo.remoteSectionVersions": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/section-versions?text=${encodeURIComponent(input.text)}&occurrence=${input.occurrence ?? 0}` }),
    },
    "seo.remoteDeleteSectionVersion": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/section-versions/${input.versionId}/delete` }),
    },
    "seo.remoteOptimizeSection": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/section-optimize`, body: { type: input.type, html: input.html, topic: input.topic, model: input.model, provider: input.provider, templateId: input.templateId } }),
    },
    "seo.remoteGetHeadings": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/headings?type=${input.type ?? 'post'}` }),
    },
    "seo.remoteUpdateHeading": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/headings/${input.index}`, body: { type: input.type, text: input.text, level: input.level } }),
    },
    "seo.remoteOptimizeHeading": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/headings/${input.index}/optimize`, body: { type: input.type, text: input.text, model: input.model, provider: input.provider, templateId: input.templateId } }),
    },
    // ── Link inspector (popup) — remote (connected sites) ──
    "seo.remoteGetLinks": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/links?type=${input.type ?? 'post'}` }),
    },
    "seo.remoteUpdateLink": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/links/${input.index}`, body: { type: input.type, anchor: input.anchor, href: input.href, oldHref: input.oldHref, elId: input.elId, oldAnchor: input.oldAnchor } }),
    },
    "seo.remoteRemoveLink": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/links/${input.index}/remove`, body: { type: input.type } }),
    },
    "seo.remoteContent": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content` }),
    },
    "seo.sitePreview": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/preview`, body: { url: input.url } }),
    },
    "seo.remoteSaveCell": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/cell`, body: { field: input.field, value: input.value, type: input.type } }),
    },
    "seo.remoteGenerateField": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/generate`, body: { field: input.field, type: input.type, model: input.model, provider: input.provider, templateId: input.templateId } }),
    },
    "seo.remoteCreate": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content`, body: { type: input.type } }),
    },
    "seo.remoteDelete": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/delete`, body: { type: input.type } }),
    },
    "seo.remoteDuplicate": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/duplicate`, body: { type: input.type } }),
    },
    "seo.remoteSetFeatured": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/featured`, body: { type: input.type, imageUrl: input.imageUrl } }),
    },
    "seo.remoteScanLinks": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/scan-links`, body: { type: input.type } }),
    },
    "seo.remoteSiteGet": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/site` }),
    },
    "seo.remoteSiteSave": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/site`, body: { robots: input.robots, jsonld: input.jsonld, siteTitle: input.siteTitle, tagline: input.tagline } }),
    },
    "seo.remoteSiteGenerate": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/site/generate`, body: { field: input.field, brandId: input.brandId, model: input.model, provider: input.provider } }),
    },
    "seo.remoteAiGet": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/ai` }),
    },
    "seo.remoteAiSave": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/ai`, body: { llms: input.llms, enabled: input.enabled } }),
    },
    "seo.remoteAiBuild": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/ai/build`, body: { desc: input.desc } }),
    },
    "seo.remoteAiPosts": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/ai/posts` }),
    },
    "seo.remoteAiSiteDesc": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/ai/site-desc`, body: { model: input.model, provider: input.provider } }),
    },
    "seo.remoteSetSchema": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/content/${input.postId}/schema`, body: { type: input.type, types: input.types } }),
    },
    // /llm-info/ — AI-optimization summary (local)
    "seo.llmInfoGet": { endpoint: "seo/llm-info", method: "GET" },
    "seo.llmInfoSave": {
        endpoint: "seo/llm-info",
        method: "POST",
        transform: (input: any) => ({ url: `seo/llm-info`, body: input }),
    },
    "seo.llmInfoBuild": {
        endpoint: "seo/llm-info/build",
        method: "POST",
        transform: (input: any) => ({ url: `seo/llm-info/build`, body: input }),
    },
    "seo.llmInfoKeywords": {
        endpoint: "seo/llm-info/keywords",
        method: "POST",
        transform: () => ({ url: `seo/llm-info/keywords`, body: {} }),
    },
    // /llm-info/ — AI-optimization summary (connected site)
    "seo.remoteLlmInfoGet": {
        endpoint: "seo/sites",
        method: "GET",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/llm-info` }),
    },
    "seo.remoteLlmInfoSave": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/llm-info`, body: { content: input.content, enabled: input.enabled } }),
    },
    "seo.remoteLlmInfoBuild": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/llm-info/build`, body: { keywords: input.keywords, years: input.years, area: input.area, strengths: input.strengths, model: input.model, provider: input.provider } }),
    },
    "seo.remoteLlmInfoKeywords": {
        endpoint: "seo/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seo/sites/${input.siteId}/llm-info/keywords`, body: {} }),
    },
    // AI Readiness (llms.txt / virtual routes)
    "seo.airStatus": { endpoint: "seo/ai-readiness", method: "GET" },
    "seo.airBuild": { endpoint: "seo/ai-readiness/build", method: "POST" },
    "seo.airPublish": { endpoint: "seo/ai-readiness/publish", method: "POST" },
    "seo.airSettings": { endpoint: "seo/ai-readiness/settings", method: "POST" },
    "seo.airGenerate": { endpoint: "seo/ai-readiness/generate", method: "POST" },
    "seo.airSummarize": { endpoint: "seo/ai-readiness/summarize", method: "POST" },
    "seo.airSaveLlms": { endpoint: "seo/ai-readiness/save-llms", method: "POST" },
    "seo.airGenSiteDesc": { endpoint: "seo/ai-readiness/site-desc", method: "POST" },
    "seo.airDeleteAll": { endpoint: "seo/ai-readiness/delete-all", method: "POST" },
    // Schema (per-post)
    "seo.getSchema": {
        endpoint: "seo/content",
        method: "GET",
        transform: (input: any) => ({ url: `seo/content/${input.id}/schema` }),
    },
    "seo.setSchema": {
        endpoint: "seo/content",
        method: "POST",
        transform: (input: any) => ({ url: `seo/content/${input.id}/schema`, body: { types: input.types } }),
    },
    // Site-wide settings
    "seo.siteGet": { endpoint: "seo/site", method: "GET" },
    "seo.siteSave": { endpoint: "seo/site", method: "POST" },
    "seo.siteGenerate": {
        endpoint: "seo/site",
        method: "POST",
        transform: (input: any) => ({ url: "seo/site/generate", body: { field: input.field, brandId: input.brandId, model: input.model, provider: input.provider } }),
    },
    "seo.siteRestore": { endpoint: "seo/site/restore", method: "POST" },
    // GBP (Google Business Profile) — n8n-backed, provider-swappable
    "seo.gbpSearch": { endpoint: "seo/gbp/search", method: "POST" },
    "seo.gbpGet": {
        endpoint: "seo/gbp/brand",
        method: "GET",
        transform: (input: any) => ({ url: `seo/gbp/brand/${input.brand}` }),
    },
    "seo.gbpSave": {
        endpoint: "seo/gbp/brand",
        method: "POST",
        transform: (input: any) => ({ url: `seo/gbp/brand/${input.brand}/save`, body: { placeId: input.placeId } }),
    },
    "seo.gbpOverrides": {
        endpoint: "seo/gbp/brand",
        method: "POST",
        transform: (input: any) => ({ url: `seo/gbp/brand/${input.brand}/overrides`, body: { overrides: input.overrides } }),
    },
    // Export / Import SEO config
    "seo.exportConfig": { endpoint: "seo/export", method: "GET" },
    "seo.importConfig": { endpoint: "seo/import", method: "POST" },
    // Saved table Views (per-user: column visibility + active filters)
    "seo.listViews": { endpoint: "seo/views", method: "GET" },
    "seo.createView": { endpoint: "seo/views", method: "POST" },
    "seo.deleteView": {
        endpoint: "seo/views",
        method: "DELETE",
        transform: (input: any) => ({ url: `seo/views/${input.id}` }),
    },
    "seo.setDefaultView": {
        endpoint: "seo/views",
        method: "PATCH",
        transform: (input: any) => ({ url: `seo/views/${input.id}/default`, body: { isDefault: input.isDefault } }),
    },
    // SEO Hub — managed remote sites
    "seohub.listSites": { endpoint: "seohub/sites", method: "GET" },
    "seohub.createSite": { endpoint: "seohub/sites", method: "POST" },
    "seohub.revokeSite": {
        endpoint: "seohub/sites",
        method: "POST",
        transform: (input: any) => ({ url: `seohub/sites/${input.id}/revoke` }),
    },
    "seohub.deleteSite": {
        endpoint: "seohub/sites",
        method: "DELETE",
        transform: (input: any) => ({ url: `seohub/sites/${input.id}` }),
    },

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

    // ── Assets ──
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
    "assets.setProjectDelivery": {
        endpoint: "assets/projects",
        method: "PATCH",
        transform: (input: any) => ({ url: `assets/projects/${input.id}/delivery`, body: { deliveryId: input.deliveryId } }),
    },
    "assets.setProjectSite": {
        endpoint: "assets/projects",
        method: "PATCH",
        transform: (input: any) => ({ url: `assets/projects/${input.id}/site`, body: { siteId: input.siteId } }),
    },
    "assets.addProjectImages": {
        endpoint: "assets/projects",
        method: "POST",
        transform: (input: any) => ({ url: `assets/projects/${input.id}/images`, body: { urls: input.urls } }),
    },

    // ── Copy ──
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

    // ── Image ──
    // Aliases used internally by AssetDetailView (backward-compat)
    "image.suggestConcepts": { endpoint: "image/concepts", method: "POST" },
    "image.generateSingle": { endpoint: "image/generate", method: "POST" },
    "image.createTask": { endpoint: "image/generate-task", method: "POST" },
    "image.taskResult": { endpoint: "image/task-result", method: "POST" },
    "image.createEditTask": { endpoint: "image/edit-task", method: "POST" },
    "image.generateBatch": { endpoint: "image/generate-batch", method: "POST" },
    "image.editImage": { endpoint: "image/edit", method: "POST" },
    "image.upscale": { endpoint: "image/upscale", method: "POST" },
    // Primary paths used by ImageModule (index.tsx)
    "image.generateConcepts": { endpoint: "image/concepts", method: "POST" },
    "image.generate": { endpoint: "image/generate", method: "POST" },
    "image.generateSuggestions": { endpoint: "image/suggestions", method: "POST" },
    "image.generateContextSuggestions": { endpoint: "image/context-suggestions", method: "POST" },
    "image.optimizeBrief": { endpoint: "image/optimize-brief", method: "POST" },
    "image.sessionUpload": { endpoint: "image/session-upload", method: "POST" },

    // ── Video ──
    "video.suggestConcepts": { endpoint: "video/concepts", method: "POST" },
    "video.generateConcepts": { endpoint: "video/concepts", method: "POST" },
    "video.enhancePrompt": { endpoint: "video/enhance-prompt", method: "POST" },
    "video.composePrompt": { endpoint: "video/compose-prompt", method: "POST" },
    "video.generate": { endpoint: "video/generate", method: "POST" },
    "video.createTask": { endpoint: "video/generate-task", method: "POST" },
    "video.taskResult": { endpoint: "video/task-result", method: "POST" },
    "video.checkStatus": { endpoint: "video/status", method: "POST" },
    "video.getCapabilities": { endpoint: "video/capabilities", method: "GET" },

    // ── Asset Scraper ──
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
    "approvals.appendToSet": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.id}/assets`, body: { snapshot: input.snapshot } }),
    },
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
    "approvals.saveReviewDraft": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.token}/draft`, body: input }),
    },
    "approvals.updateSnapshotAsset": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.token}/assets/${input.assetId}`, body: input }),
    },
    "approvals.updateSetStatus": {
        endpoint: "approvals/sets",
        method: "PATCH",
        transform: (input: any) => ({ url: `approvals/sets/${input.id}/status`, body: { status: input.status } }),
    },
    "approvals.deleteSet": {
        endpoint: "approvals/sets",
        method: "DELETE",
        transform: (input: any) => ({ url: `approvals/sets/${input.id}` }),
    },
    "approvals.bulkDeleteSets": {
        endpoint: "approvals/sets/bulk/delete",
        method: "POST",
    },
    // Public client actions (token-scoped)
    "approvals.addPublicComment": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.token}/comment`, body: input }),
    },
    "approvals.approveAsset": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.token}/approve`, body: input }),
    },
    // Authenticated team actions (id-scoped)
    "approvals.addTeamComment": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.id}/reply`, body: input }),
    },
    "approvals.shareSet": {
        endpoint: "approvals/sets",
        method: "POST",
        transform: (input: any) => ({ url: `approvals/sets/${input.id}/share`, body: { email: input.email, message: input.message } }),
    },

    // ── Notifications (approval-flow activity feed) ──
    "notifications.list": { endpoint: "notifications", method: "GET" },
    "notifications.markSeen": { endpoint: "notifications/seen", method: "POST" },
    "notifications.clear": { endpoint: "notifications/clear", method: "POST" },

    // ── Users (admin-only user management) ──
    "users.list": { endpoint: "users", method: "GET" },
    "users.create": { endpoint: "users", method: "POST" },
    "users.setPassword": {
        endpoint: "users",
        method: "PUT",
        transform: (input: any) => ({ url: `users/${input.pcmId}/password`, body: { password: input.password } }),
    },
    "users.setRole": {
        endpoint: "users",
        method: "PUT",
        transform: (input: any) => ({ url: `users/${input.pcmId}/role`, body: { role: input.role } }),
    },
    "users.delete": {
        endpoint: "users",
        method: "DELETE",
        transform: (input: any) => ({ url: `users/${input.pcmId}` }),
    },
    "users.assignDeliveries": {
        endpoint: "users",
        method: "PUT",
        transform: (input: any) => ({ url: `users/${input.pcmId}/deliveries`, body: { deliveryIds: input.deliveryIds } }),
    },

    // ── Automations (trigger → condition → action rules) ──
    "automations.list":    { endpoint: "automations", method: "GET" },
    "automations.catalog": { endpoint: "automations/catalog", method: "GET" },
    "automations.create":  { endpoint: "automations", method: "POST" },
    "automations.update": {
        endpoint: "automations",
        method: "PATCH",
        transform: (input: any) => ({ url: `automations/${input.id}`, body: input }),
    },
    "automations.delete": {
        endpoint: "automations",
        method: "DELETE",
        transform: (input: any) => ({ url: `automations/${input.id}` }),
    },
    "automations.test":    { endpoint: "automations/test", method: "POST" },
    "automations.logs":    { endpoint: "automations/logs", method: "GET" },

    // ── Server-side site settings (PCM_Settings) — distinct from the localStorage
    // app settings in AppContext. Used for the email sender (automations_from_*). ──
    "settings.get":        { endpoint: "settings", method: "GET" },
    "settings.update":     { endpoint: "settings", method: "POST" },
};
