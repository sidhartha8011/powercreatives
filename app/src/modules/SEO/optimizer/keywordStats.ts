/**
 * Live keyword presence — consumed by the smart Keywords button AND the
 * drawer's selected table, recomputed per keystroke via the editor's
 * existing update tick (zero new plumbing).
 *
 * Density uses the standard phrase definition so the number MEANS
 * something: occurrences × words-in-phrase ÷ total words × 100.
 */
export function keywordUses(text: string, phrase: string): { uses: number; density: number } {
  const t = text.toLowerCase();
  const p = phrase.toLowerCase().trim();
  if (p === '' || t === '') return { uses: 0, density: 0 };
  let uses = 0;
  let idx = 0;
  while ((idx = t.indexOf(p, idx)) !== -1) {
    uses++;
    idx += p.length;
  }
  const totalWords = t.split(/\s+/).filter(Boolean).length;
  const phraseWords = p.split(/\s+/).filter(Boolean).length;
  return {
    uses,
    density: totalWords > 0 ? Math.round(((uses * phraseWords) / totalWords) * 1000) / 10 : 0,
  };
}
