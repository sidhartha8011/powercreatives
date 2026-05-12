/**
 * Google Suggest JSONP Fallback
 *
 * Client-side JSONP fetcher for Google Suggest, used as automatic
 * fallback when the server-side proxy is rate-limited by Google.
 *
 * Ported from autopress-intelligence (proven, production-tested).
 * Uses browser's IP instead of server IP to avoid rate-limiting.
 */

/**
 * Fetch suggestions via JSONP script injection.
 *
 * Google's suggestqueries endpoint supports JSONP callbacks.
 * By injecting a <script> tag, the browser makes the request
 * directly — using the user's IP, not the server's.
 *
 * @param query  Full search query (e.g. "vad tandläkare")
 * @param hl     Language code (e.g. 'sv')
 * @param gl     Country code (e.g. 'se')
 * @returns      Array of suggestion strings
 */
export function fetchSuggestionsJsonp(
  query: string,
  hl: string,
  gl: string,
): Promise<string[]> {
  return new Promise((resolve, reject) => {
    const callbackName = 'jsonp_cb_' + Math.random().toString(36).substr(2, 9);
    const script = document.createElement('script');

    const url =
      `https://suggestqueries.google.com/complete/search` +
      `?client=chrome&hl=${hl}&gl=${gl}` +
      `&q=${encodeURIComponent(query)}&callback=${callbackName}`;

    script.src = url;
    script.async = true;

    // Cleanup helper — removes script tag and global callback
    const cleanup = () => {
      if ((window as any)[callbackName]) delete (window as any)[callbackName];
      if (document.body.contains(script)) document.body.removeChild(script);
    };

    // Google returns: callback([query, [suggestions], ...])
    (window as any)[callbackName] = (data: any) => {
      if (Array.isArray(data) && Array.isArray(data[1])) {
        resolve(data[1]);
      } else {
        resolve([]);
      }
      cleanup();
    };

    // Handle network errors
    script.onerror = () => {
      reject(new Error(`JSONP request failed for query: "${query}"`));
      cleanup();
    };

    // Timeout safety — 8 seconds max
    const timeout = setTimeout(() => {
      reject(new Error(`JSONP timeout for query: "${query}"`));
      cleanup();
    }, 8000);

    // Clear timeout on success
    const originalCallback = (window as any)[callbackName];
    (window as any)[callbackName] = (data: any) => {
      clearTimeout(timeout);
      originalCallback(data);
    };

    document.body.appendChild(script);
  });
}
