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
            /** admin-ajax.php — used only to re-mint an expired REST nonce. */
            ajaxUrl?: string;
            pluginUrl: string;
            shortcodePageUrl?: string;
            version: string;
            user: {
                id: number;
                name: string;
                email: string;
                role: string;
                avatarUrl: string;
                isLoggedIn?: boolean;
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
            ajaxUrl: "/wp-admin/admin-ajax.php",
            pluginUrl: "",
            shortcodePageUrl: window.location.origin + "/",
            version: "1.0.0",
            user: { id: 0, name: "Dev", email: "", role: "admin", avatarUrl: "", isLoggedIn: false },
        }
    );
}

// ============================================
// REST nonce lifecycle
// ============================================

/** Write a fresh nonce back to the live config so every later request uses it. */
function setNonce(nonce: string): void {
    if (window.pcmConfig) {
        window.pcmConfig.nonce = nonce;
    }
}

/**
 * Is this 403 specifically an expired/invalid REST nonce?
 *
 * Reads a CLONE so the caller can still consume the body — a Response body can
 * only be read once, and the error path below needs it. Matching is on
 * WordPress' machine-readable `code`, never the translatable message (this
 * install runs Swedish, where the text is not "Cookie check failed").
 */
async function isNonceError(response: Response): Promise<boolean> {
    try {
        const body = await response.clone().json();
        return body?.code === "rest_cookie_invalid_nonce";
    } catch {
        return false;
    }
}

/**
 * Re-mint a REST nonce via core's `wp_ajax_rest-nonce`, which authenticates on
 * the login cookie ALONE — no nonce needed, which is what makes recovery
 * possible once ours has expired. Returns null for anyone not logged into
 * WordPress (core answers "-1"), so the caller surfaces the original error
 * instead of retrying forever.
 */
async function refreshNonce(): Promise<string | null> {
    const ajaxUrl = getConfig().ajaxUrl;
    if (!ajaxUrl) return null;
    try {
        const res = await fetch(`${ajaxUrl}?action=rest-nonce`, { credentials: "same-origin" });
        if (!res.ok) return null;
        const nonce = (await res.text()).trim();
        // A WP nonce is a short hex-ish token; "-1" is core's not-logged-in reply.
        return /^[A-Za-z0-9]{8,64}$/.test(nonce) ? nonce : null;
    } catch {
        return null;
    }
}

/**
 * Keep the nonce fresh proactively. WordPress core hooks
 * wp_refresh_heartbeat_nonces() onto `heartbeat_received`, so every tick carries
 * a current `rest_nonce`. Binding here means a long-open tab renews before it
 * can lapse, so the retry path above stays a fallback rather than the norm.
 * Guarded on jQuery + the event, so it is inert wherever heartbeat isn't loaded.
 */
function bindHeartbeatNonceRefresh(): void {
    const jq = (window as unknown as { jQuery?: any }).jQuery;
    if (typeof jq !== "function") return;
    jq(document).on("heartbeat-tick", (_event: unknown, data: { rest_nonce?: string }) => {
        if (data?.rest_nonce) setNonce(data.rest_nonce);
    });
}
bindHeartbeatNonceRefresh();

// ============================================
// Route Map: imported from dedicated config file
// ============================================

import { ROUTE_MAP, type RouteConfig } from "./trpc-routes";


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
    // Compose path + query separately: on "Plain" permalinks restUrl is
    // `/?rest_route=/pcm/v1/`, so a naive `endpoint?x=y` append would create a
    // second `?` and WordPress would fail to match the route (observed as
    // empty module-filtered template lists on hosts without pretty
    // permalinks). Re-attach the endpoint's query with the right separator.
    const cleaned = endpoint.replace(/^\//, "");
    const qIndex = cleaned.indexOf("?");
    const path = qIndex === -1 ? cleaned : cleaned.slice(0, qIndex);
    const query = qIndex === -1 ? "" : cleaned.slice(qIndex + 1);
    let url = `${config.restUrl}${path}`;
    if (query) {
        url += (url.includes("?") ? "&" : "?") + query;
    }

    const send = (nonce: string) =>
        fetch(url, {
            ...options,
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": nonce,
                ...(options?.headers ?? {}),
            },
        });

    let response = await send(config.nonce);

    // EXPIRED NONCE → re-mint and retry ONCE.
    //
    // The nonce is minted once when the page loads and lives in window.pcmConfig.
    // WordPress ages it out (and invalidates it outright if the session token
    // changes — e.g. logging in again in another tab), after which every write
    // fails with WordPress' own "Cookie check failed". Leaving a Creative Machine
    // tab open long enough was therefore guaranteed to break it, and the only
    // remedy was a manual reload (owner report 2026-08-10, hit on Create Strategy).
    //
    // The heartbeat listener below normally refreshes the nonce before it can
    // lapse; this is the safety net for when it can't (heartbeat suspended on a
    // background tab, or the session changed between ticks). Bounded to a single
    // attempt: if the retry also fails, the original error surfaces unchanged.
    if (response.status === 403 && (await isNonceError(response))) {
        const fresh = await refreshNonce();
        if (fresh) {
            setNonce(fresh);
            response = await send(fresh);
        }
    }

    if (!response.ok) {
        const error = await response.json().catch(() => ({}));
        // Preserve the WordPress REST error CODE (e.g. `pcm_seo_heading_stale`) and HTTP
        // status on the thrown Error so callers can branch on the machine-readable code
        // instead of matching translatable message strings. Additive — existing consumers
        // that only read `.message` are unaffected.
        const err = new Error(
            error.message ?? `API error: ${response.status} ${response.statusText}`
        ) as Error & { code?: string; status?: number };
        if (typeof error.code === "string") err.code = error.code;
        err.status = response.status;
        throw err;
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

                                // Send body for POST, PATCH, PUT, and DELETE
                                // WordPress REST API reads params from body for all methods except GET
                                const fetchOptions: RequestInit = { method };
                                if (method !== "GET") {
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
