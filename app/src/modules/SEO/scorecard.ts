/**
 * SEO scorecard — client-side content metrics for the Optimize modal.
 *
 * Pure functions over an HTML body + primary keyword, mirroring the source
 * plugin's scorecard (word count, keyword density, KW in first 100 words,
 * KW in headings, heading count, FAQ/list presence, avg sentence length).
 */

export interface Scorecard {
  words: number;
  keywordCount: number;
  density: number; // 0–1
  keywordInFirst100: boolean;
  keywordInHeadings: boolean;
  headings: number;
  hasFaq: boolean;
  hasLists: boolean;
  avgSentenceLength: number;
}

const stripTags = (html: string): string =>
  html.replace(/<[^>]*>/g, ' ').replace(/&[a-z#0-9]+;/gi, ' ').replace(/\s+/g, ' ').trim();

const countOccurrences = (haystack: string, needle: string): number => {
  if (!needle) return 0;
  const re = new RegExp(needle.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
  return (haystack.match(re) ?? []).length;
};

export function computeScorecard(html: string, keyword: string): Scorecard {
  const text = stripTags(html);
  const kw = keyword.trim();
  const wordList = text ? text.split(/\s+/) : [];
  const words = wordList.length;

  const headingTexts = Array.from(html.matchAll(/<h[1-6][^>]*>(.*?)<\/h[1-6]>/gis)).map((m) => stripTags(m[1]));
  const first100 = wordList.slice(0, 100).join(' ');
  const sentences = text.split(/[.!?]+/).filter((s) => s.trim().length > 0).length;
  const keywordCount = kw ? countOccurrences(text, kw) : 0;

  return {
    words,
    keywordCount,
    density: words > 0 ? keywordCount / words : 0,
    keywordInFirst100: kw ? new RegExp(kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(first100) : false,
    keywordInHeadings: kw ? headingTexts.some((h) => new RegExp(kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'i').test(h)) : false,
    headings: headingTexts.length,
    hasFaq: /faq|frequently asked/i.test(html),
    hasLists: /<(ul|ol)[\s>]/i.test(html),
    avgSentenceLength: sentences > 0 ? Math.round(words / sentences) : 0,
  };
}

/** A red/amber/green verdict per metric, for the UI. */
export function scoreVerdict(s: Scorecard): { label: string; value: string; ok: boolean }[] {
  const pct = (s.density * 100).toFixed(1);
  return [
    { label: 'Word count', value: String(s.words), ok: s.words >= 300 },
    { label: 'Keyword density', value: `${pct}%`, ok: s.density >= 0.005 && s.density <= 0.025 },
    { label: 'KW in first 100 words', value: s.keywordInFirst100 ? 'Yes' : 'No', ok: s.keywordInFirst100 },
    { label: 'KW in a heading', value: s.keywordInHeadings ? 'Yes' : 'No', ok: s.keywordInHeadings },
    { label: 'Headings', value: String(s.headings), ok: s.headings >= 2 },
    { label: 'FAQ section', value: s.hasFaq ? 'Yes' : 'No', ok: s.hasFaq },
    { label: 'Lists', value: s.hasLists ? 'Yes' : 'No', ok: s.hasLists },
    { label: 'Avg sentence length', value: String(s.avgSentenceLength), ok: s.avgSentenceLength > 0 && s.avgSentenceLength <= 22 },
  ];
}
