/**
 * Vite Configuration for WordPress Plugin Build
 *
 * Builds the React SPA into app/dist/ with WordPress-compatible output.
 * Differences from standalone vite.config.ts:
 * - No tRPC, Manus, or Express plugins
 * - Output goes to dist/ (loaded by PCM_Admin)
 * - Single JS + CSS bundle (no code splitting — WP enqueues one file)
 * - Asset paths are relative (not absolute)
 */

import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import path from "node:path";
import { defineConfig } from "vite";

export default defineConfig({
    plugins: [react(), tailwindcss()],

    // THE BUILD STAMP (gap MODULE-VERSION-STAMP): baked at build time, shown
    // on the Shell's module-version stamp hover — the machine truth that
    // makes a stale SPA tab visible regardless of version-bump discipline.
    define: {
        __PCM_BUILD__: JSON.stringify(new Date().toISOString().slice(0, 16).replace("T", " ") + " UTC"),
    },

    resolve: {
        alias: {
            // Match the original app's path aliases
            "@": path.resolve(__dirname, "src"),
            "@shared": path.resolve(__dirname, "shared"),
        },
    },

    // Root = the app/ directory (where index.html lives)
    root: path.resolve(__dirname),

    build: {
        // Output to dist/ — PCM_Admin enqueues from here
        outDir: path.resolve(__dirname, "dist"),
        minify: false,
        emptyOutDir: true,

        // Single bundle — WordPress enqueues one JS + one CSS file
        rollupOptions: {
            output: {
                // Predictable filenames (no hash) so PHP can reference them
                entryFileNames: "index-writer.js",
                chunkFileNames: "chunks/[name].js",
                assetFileNames: (assetInfo) => {
                    // CSS always at index.css
                    if (assetInfo.name && assetInfo.name.endsWith(".css")) {
                        return "index.css";
                    }
                    return "assets/[name][extname]";
                },
            },
        },

        // Don't inline assets — let WP handle them
        assetsInlineLimit: 0,

        // Target modern browsers (WP admin users)
        target: "es2020",

        // Source maps for debugging (can disable for production)
        sourcemap: false,
    },

    // No dev server needed — WP serves the built files
    // For local dev, use `npx vite --config vite.config.wp.ts` if needed
});
