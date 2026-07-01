/* Escape EVERY non-ASCII character (code point > U+007F) to an ASCII numeric HTML
 * entity (e.g. 🚀 → "&#128640;", ✨ → "&#10024;", å → "&#229;") BEFORE a value hits
 * the wire. Some hosts run a security/WAF layer that strips non-ASCII bytes from the
 * request body before PHP can read it. An earlier version escaped only astral-plane
 * (4-byte, > U+FFFF) chars on the assumption that BMP symbols survive — but this host
 * also strips 3-byte BMP emoji/symbols (✨ U+2728, ❤ U+2764, ☺, dingbats, variation
 * selectors), so those were still being dropped. Escaping the FULL non-ASCII range to
 * pure ASCII means nothing in transport can drop anything; the server decodes the
 * numeric entities back to UTF-8 (PCM_REST_Approvals::decode_numeric_entities /
 * decode_snapshot_emojis), so accented letters round-trip losslessly too. Plain ASCII
 * (≤ U+007F) is left untouched — it survives any filter and needs no decode. */
export function escapeAstral(text: string): string {
  if (!text) return text;
  let out = '';
  for (const ch of text) {
    const cp = ch.codePointAt(0) ?? 0;
    out += cp > 0x7f ? `&#${cp};` : ch;
  }
  return out;
}

/* Recursively escape non-ASCII chars in every string leaf of a value (arrays + plain
 * objects are walked; non-strings pass through). Use on an approval snapshot before
 * sending it so emoji in copy/headline/body/custom survive a WAF that strips non-ASCII
 * bytes — the pair to the server's recursive decode on create/append. */
export function escapeAstralDeep<T>(value: T): T {
  if (typeof value === 'string') return escapeAstral(value) as unknown as T;
  if (Array.isArray(value)) return value.map((v) => escapeAstralDeep(v)) as unknown as T;
  if (value && typeof value === 'object') {
    const out: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
      out[k] = escapeAstralDeep(v);
    }
    return out as unknown as T;
  }
  return value;
}
