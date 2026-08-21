/**
 * THE EMOJI LOSS GUARD (card 18, Safari round).
 *
 * The Chrome-side killer (wp-emoji rewriting emoji to <img> inside ProseMirror) is
 * fixed at the source, but Safari still loses emoji through a path we cannot
 * reproduce from here (its own contentEditable normalisation, or a stale cached
 * bundle on the public board — Safari caches hard). So the save path now defends
 * against the failure SIGNATURE instead of any one mechanism: when an edited value
 * is identical to the original except that emoji are MISSING, the original is kept.
 *
 * Deliberate trade-off: deleting ONLY an emoji (and changing nothing else) is
 * undone by this guard. Removing an emoji together with any other character works
 * as expected. The owner's priority is explicit — "the emojis has to stay".
 */

/** Emoji-ish code points: VS15/16, ZWJ, keycap, misc symbols + dingbats, arrows/stars
 *  block used by emoji (⭐ ⬆), and the entire astral emoji plane (incl. skin tones). */
const EMOJI_RE = /[\u{00A9}\u{00AE}\u{FE0E}\u{FE0F}\u{200D}\u{203C}\u{2049}\u{20E3}\u{2122}\u{2139}\u{2194}-\u{2199}\u{21A9}\u{21AA}\u{231A}\u{231B}\u{2328}\u{23CF}\u{23E9}-\u{23FA}\u{24C2}\u{25AA}\u{25AB}\u{25B6}\u{25C0}\u{25FB}-\u{25FE}\u{2600}-\u{27BF}\u{2934}\u{2935}\u{2B00}-\u{2BFF}\u{3030}\u{303D}\u{3297}\u{3299}\u{1F000}-\u{1FFFF}]/gu;

/**
 * The comparison form: emoji removed, then WHITESPACE made canonical. The first
 * guard compared raw stripped strings and was beaten in the field (card 18, 4th
 * report): a line-end emoji — where ad copy keeps them, "… it performs. 🎬" —
 * dies TOGETHER with its surrounding space, because the editor trims paragraph-
 * edge whitespace when it drops the <img>; one space of drift and the loss sailed
 * through. So: emoji out, object-replacement chars (U+FFFC) out, NBSP → space,
 * CRLF → LF, space runs collapsed, line edges trimmed. The emoji-count condition
 * still keeps every same-count edit (incl. pure whitespace edits) untouched.
 */
/** Keycap sequences (1️⃣ = digit+VS16+U+20E3): twemoji folds the whole sequence into
 * one <img>, so a kill removes the ASCII digit too — strip it as a unit first. */
const KEYCAP_SEQ = /[0-9#*]\u{FE0F}?\u{20E3}/gu;

function emojiLossCanon(s: string): string {
  return s
    .replace(KEYCAP_SEQ, '')
    .replace(EMOJI_RE, '')
    .replace(/\r\n?/g, '\n')
    .replace(/[\uFFFC\u200B\uFEFF]/g, '')
    .replace(/\u00A0/g, ' ')
    .replace(/[ \t]+/g, ' ')
    .replace(/^[ \t]+|[ \t]+$/gm, '')
    // Newline RUNS collapse too: the editor renders \n\n\n as the same two-paragraph
    // document as \n\n, so runs must not alibi a kill either.
    .replace(/\n{2,}/g, '\n\n')
    .trim();
}

function emojiCount(s: string): number {
  return (s.match(EMOJI_RE) || []).length;
}

/**
 * Returns `next` unless the ONLY difference from `prev` is lost emoji (whitespace
 * drift around the kill site included) — then `prev` is returned so they survive.
 */
export function keepEmojiOnPureLoss(next: string, prev: string): string {
  if (next === prev || prev === '') return next;
  if (emojiCount(next) < emojiCount(prev) && emojiLossCanon(next) === emojiLossCanon(prev)) {
    return prev;
  }
  return next;
}
