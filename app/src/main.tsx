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

/**
 * THE EMOJI KILLER, client half (card 18): WordPress's wp-emoji script swaps emoji
 * characters for <img class="emoji"> across the whole document (MutationObserver).
 * Inside the app's ProseMirror editors that image is not in the schema, so the editor
 * DROPS it — opening an ad and clicking outside saved the copy without its emoji.
 * The PHP half removes the script on our surfaces; this half covers pages that still
 * carry it (cached HTML, another plugin's twemoji): make twemoji.parse a no-op, even
 * when the library loads after us.
 */
function neutralizeTwemoji() {
  const noop = (x: unknown) => x;
  const disarm = (t: any) => { if (t && typeof t === 'object') { try { t.parse = noop; } catch { /* frozen — ignore */ } } return t; };
  const w = window as any;
  if (w.twemoji) {
    disarm(w.twemoji);
    return;
  }
  try {
    let stored: any;
    Object.defineProperty(w, 'twemoji', {
      configurable: true,
      get: () => stored,
      set: (v) => { stored = disarm(v); },
    });
  } catch { /* property not configurable — nothing more we can do */ }
}

if (rootElement) {
  neutralizeTwemoji();
  createRoot(rootElement).render(
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>
  );
} else {
  // Fallback for Vite dev mode (index.html has <div id="pcm-root">)
  console.warn("[PCM] #pcm-root not found — running outside WordPress?");
}
