/**
 * THE OPTIMIZER — shared contracts (architecture:
 * docs/GAP-ANALYSIS-OPTIMIZER-ARCHITECTURE-20260713.md).
 *
 * Every optimization purpose is a TEACHER (one server file each); every
 * teacher contributes catalog items of this ONE shape, forever. The rail
 * renders them generically — a new teacher with no special UI needs zero
 * frontend work.
 */

/** One catalog item — a teacher's suggestion. */
export interface OptimizerItem {
  /** Stable per-teacher check id. */
  id: string;
  teacherId: string;
  /** true = a gap/opportunity (tickable, pre-selected) · false = nothing to fix (quiet). */
  found: boolean;
  label: string;
  /** Proof: a quote when passing, the concrete missing thing when found. */
  evidence: string;
  /** The directive this item sends into the ONE optimization run when ticked. */
  instruction: string;
  /** Which integration/engine answered (e.g. "ahrefs", "gsc:stored (2026-07-12)",
   *  "openai:gpt-4o") — absent for pure content judgment. The owner's provenance. */
  source?: string;
}

/** Registry metadata — one rail section per teacher, in `order`. */
export interface TeacherMeta {
  id: string;
  label: string;
  order: number;
  /** The rail's top group: 'search' = Search optimization · 'ai' = AI optimization. */
  group: 'search' | 'ai';
}

/** THE KEYWORD PACKAGE riding every analyze/compile call (live editor state —
 *  the same source-of-truth law as the html). */
export interface KeywordPackage {
  primary: string;
  supporting: string[];
  additional: string[];
}

/** THE PEEK — exactly what one analysis run was given (read-only; the owner's
 *  confirmation tool until prompts become editable Templates). */
export interface RunContext {
  keywords: KeywordPackage;
  businessFields: string[];
  businessName: string;
  pageType: string;
  model: string;
  provider: string;
  pageCount: number;
}

/** One teacher's run state in the rail. */
export interface TeacherRun {
  status: 'idle' | 'running' | 'done' | 'failed';
  items: OptimizerItem[];
  error?: string;
  /** What the run was given (from the server's contextUsed). */
  context?: RunContext;
}

/** Basket key — one ticked suggestion. */
export const itemKey = (item: Pick<OptimizerItem, 'teacherId' | 'id'>): string =>
  `${item.teacherId}:${item.id}`;

/** One compiled directive — the COMPILER's output: the run's to-do line,
 *  the purposes (teacherIds) it serves, and the input items it covers. */
export interface CompiledDirective {
  text: string;
  purposes: string[];
  sources: number[];
  /** THE ROUTER (gap eeec6b9): the section indexes this directive
   *  concerns. Absent/empty = unrouted — it rides every sent section
   *  (the honest floor, never a dropped intent). */
  targets?: number[];
}

/** Purpose pill labels (presentation only — teacherIds stay the truth). */
export const TEACHER_PILLS: Record<string, string> = {
  search: 'SEO',
  subtopics: 'TOPICS',
  keywords: 'KW',
  interlinks: 'LINKS',
  'ai-visibility': 'AI',
  onpage: 'ONPAGE',
  serp: 'SERP',
  demand: 'DEMAND',
  answerability: 'AI',
  mention: 'AI',
  facts: 'FACTS',
};

/** The two rail groups, in render order — the TWO purposes the user
 *  knows (owner rulings 2026-07-14/15). */
export const RAIL_GROUPS: Array<{ id: TeacherMeta['group']; label: string }> = [
  { id: 'search', label: 'SEO · Google results' },
  { id: 'ai', label: 'AI · recommendations' },
];

/** Group pill styling for the review side — SEO blue · AI violet. */
export const GROUP_PILLS: Record<TeacherMeta['group'], { label: string; className: string }> = {
  search: { label: 'SEO', className: 'bg-[#e7f5ff] text-primary' },
  ai: { label: 'AI', className: 'bg-violet-100 text-violet-700' },
};
