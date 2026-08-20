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
const EMOJI_RE = /[\u{FE0E}\u{FE0F}\u{200D}\u{20E3}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{1F000}-\u{1FFFF}]/gu;

function stripEmoji(s: string): string {
  return s.replace(EMOJI_RE, '');
}

function emojiCount(s: string): number {
  return (s.match(EMOJI_RE) || []).length;
}

/**
 * Returns `next` unless the ONLY difference from `prev` is lost emoji —
 * then `prev` is returned so the emoji survive.
 */
export function keepEmojiOnPureLoss(next: string, prev: string): string {
  if (next === prev || prev === '') return next;
  if (emojiCount(next) < emojiCount(prev) && stripEmoji(next) === stripEmoji(prev)) {
    return prev;
  }
  return next;
}
