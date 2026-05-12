/**
 * WordPress Plugin Entry Point
 *
 * Replaces the standalone main.tsx that used tRPC.
 * Uses @tanstack/react-query directly with fetch-based API calls.
 * WordPress auth is handled via nonce (pcmConfig.nonce).
 *
 * Block 2 will add the REST API adapter layer.
 */

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { createRoot } from "react-dom/client";
import App from "./App";
import "./index.css";

// QueryClient for @tanstack/react-query (used by all API calls)
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      // Reasonable defaults for a WP admin SPA
      staleTime: 30_000, // 30 seconds
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});

/**
 * Mount the React app into the WordPress admin page.
 *
 * PCM_Admin renders <div id="pcm-root"></div>, and this script
 * is enqueued via wp_enqueue_script in the footer.
 *
 * pcmConfig (injected via wp_localize_script) provides:
 * - restUrl: REST API base URL
 * - nonce: WP REST nonce for authenticated requests
 * - user: Current WP user info
 * - pluginUrl: Plugin assets URL
 */
const rootElement = document.getElementById("pcm-root");

if (rootElement) {
  createRoot(rootElement).render(
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  );
} else {
  // Fallback for Vite dev mode (index.html has <div id="pcm-root">)
  console.warn("[PCM] #pcm-root not found — running outside WordPress?");
}
