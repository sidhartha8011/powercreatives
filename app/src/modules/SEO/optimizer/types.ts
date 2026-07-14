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
}

/** Registry metadata — one rail section per teacher, in `order`. */
export interface TeacherMeta {
  id: string;
  label: string;
  order: number;
}

/** One teacher's run state in the rail. */
export interface TeacherRun {
  status: 'idle' | 'running' | 'done' | 'failed';
  items: OptimizerItem[];
  error?: string;
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
}

/** Purpose pill labels (presentation only — teacherIds stay the truth). */
export const TEACHER_PILLS: Record<string, string> = {
  search: 'SEO',
  subtopics: 'TOPICS',
  keywords: 'KW',
  interlinks: 'LINKS',
  'ai-visibility': 'AI',
};
