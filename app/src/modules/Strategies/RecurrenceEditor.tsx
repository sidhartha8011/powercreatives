/**
 * RECURRENCE EDITOR (Task G-step3)
 *
 * A pure, controlled custom-recurrence editor mirroring Google Calendar's
 * "Custom recurrence" dialog, used to configure strategy posting schedules.
 *
 * This module owns the canonical `ScheduleRecurrence` shape plus two tolerant
 * (de)serializers so callers can round-trip both the new interval/unit configs
 * and the older `{ frequency, startDate }` configs without losing their legacy
 * label. Steps 4/5 build against the exported contract below — keep it stable.
 *
 * No fetching, no atoms, no side effects: value in, onChange out.
 */

import React from 'react';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { colors, typography } from '@/components/shared/design-tokens';

// ─── Public contract ──────────────────────────────────────
export interface ScheduleRecurrence {
  interval: number; // 1–12
  unit: 'day' | 'week' | 'month';
  byDays: number[]; // ISO 1(Mon)–7(Sun); only meaningful when unit==='week'
  ends: { type: 'never' } | { type: 'on'; date: string } | { type: 'after'; count: number };
  /** Derived legacy label kept for back-compat readers. */
  frequency: string;
}

// ─── Bounds ───────────────────────────────────────────────
const MIN_INTERVAL = 1;
const MAX_INTERVAL = 12;
const MIN_COUNT = 1;
const MAX_COUNT = 365;

const clamp = (n: number, lo: number, hi: number): number =>
  Math.min(hi, Math.max(lo, n));

function clampInterval(n: unknown): number {
  const v = Math.floor(Number(n));
  return Number.isFinite(v) ? clamp(v, MIN_INTERVAL, MAX_INTERVAL) : MIN_INTERVAL;
}

function normalizeUnit(u: unknown): ScheduleRecurrence['unit'] {
  return u === 'day' || u === 'week' || u === 'month' ? u : 'week';
}

function normalizeByDays(days: unknown): number[] {
  if (!Array.isArray(days)) return [];
  const seen = new Set<number>();
  for (const d of days) {
    const n = Math.floor(Number(d));
    if (Number.isFinite(n) && n >= 1 && n <= 7) seen.add(n);
  }
  return Array.from(seen).sort((a, b) => a - b);
}

function normalizeEnds(ends: unknown): ScheduleRecurrence['ends'] {
  if (ends && typeof ends === 'object') {
    const e = ends as Record<string, unknown>;
    if (e.type === 'on' && typeof e.date === 'string') return { type: 'on', date: e.date };
    if (e.type === 'after') return { type: 'after', count: clamp(Math.floor(Number(e.count)) || MIN_COUNT, MIN_COUNT, MAX_COUNT) };
    if (e.type === 'never') return { type: 'never' };
  }
  return { type: 'never' };
}

/** interval/unit → canonical legacy frequency label (safe weekly fallback). */
function deriveFrequency(interval: number, unit: ScheduleRecurrence['unit']): string {
  if (interval === 1 && unit === 'day') return 'daily';
  if (interval === 2 && unit === 'day') return 'every_other_day';
  if (interval === 1 && unit === 'week') return 'weekly';
  if (interval === 1 && unit === 'month') return 'monthly';
  return 'weekly';
}

const DEFAULT_RECURRENCE: ScheduleRecurrence = {
  interval: 1,
  unit: 'week',
  byDays: [],
  ends: { type: 'never' },
  frequency: 'weekly',
};

/**
 * Tolerant parser. Accepts legacy `{ frequency, startDate }` configs
 * (daily→1/day, every_other_day→2/day, weekly→1/week, biweekly→1/week label
 * preserved, monthly→1/month, all_once→1/day label preserved) and new-key
 * `{ interval, unit, byDays, ends }` configs. Always returns a complete object
 * (default 1/week, byDays [], ends never).
 */
export function recurrenceFromConfig(cfg: any): ScheduleRecurrence {
  if (!cfg || typeof cfg !== 'object') return { ...DEFAULT_RECURRENCE };

  // New-key config: any of the structured keys present.
  if (cfg.interval != null || cfg.unit != null || cfg.byDays != null || cfg.ends != null) {
    const interval = clampInterval(cfg.interval ?? 1);
    const unit = normalizeUnit(cfg.unit);
    const byDays = normalizeByDays(cfg.byDays);
    const ends = normalizeEnds(cfg.ends);
    const frequency =
      typeof cfg.frequency === 'string' && cfg.frequency
        ? cfg.frequency
        : deriveFrequency(interval, unit);
    return { interval, unit, byDays, ends, frequency };
  }

  // Legacy config: `{ frequency, startDate }`.
  const freq = typeof cfg.frequency === 'string' ? cfg.frequency : '';
  switch (freq) {
    case 'daily':
      return { ...DEFAULT_RECURRENCE, interval: 1, unit: 'day', frequency: 'daily' };
    case 'every_other_day':
      return { ...DEFAULT_RECURRENCE, interval: 2, unit: 'day', frequency: 'every_other_day' };
    case 'weekly':
      return { ...DEFAULT_RECURRENCE, interval: 1, unit: 'week', frequency: 'weekly' };
    case 'biweekly':
      return { ...DEFAULT_RECURRENCE, interval: 1, unit: 'week', frequency: 'biweekly' };
    case 'monthly':
      return { ...DEFAULT_RECURRENCE, interval: 1, unit: 'month', frequency: 'monthly' };
    case 'all_once':
      return { ...DEFAULT_RECURRENCE, interval: 1, unit: 'day', frequency: 'all_once' };
    default:
      return { ...DEFAULT_RECURRENCE };
  }
}

/**
 * Emits `{ interval, unit, byDays, ends, frequency }`. `frequency` is derived
 * from interval/unit (daily/every_other_day/weekly/monthly, else weekly), but
 * the incoming `r.frequency` is preserved verbatim when it is `biweekly` or
 * `all_once` and interval/unit still match the default the parser assigned
 * (1/week and 1/day respectively).
 */
export function recurrenceToConfig(r: ScheduleRecurrence): Record<string, any> {
  let frequency = deriveFrequency(r.interval, r.unit);
  if (r.frequency === 'biweekly' && r.interval === 1 && r.unit === 'week') frequency = 'biweekly';
  if (r.frequency === 'all_once' && r.interval === 1 && r.unit === 'day') frequency = 'all_once';
  return {
    interval: r.interval,
    unit: r.unit,
    byDays: r.byDays,
    ends: r.ends,
    frequency,
  };
}

// ─── Day chips (Mon-first, ISO 1–7) ───────────────────────
const DAY_CHIPS: { iso: number; label: string }[] = [
  { iso: 1, label: 'M' },
  { iso: 2, label: 'T' },
  { iso: 3, label: 'W' },
  { iso: 4, label: 'T' },
  { iso: 5, label: 'F' },
  { iso: 6, label: 'S' },
  { iso: 7, label: 'S' },
];

// ─── Component ────────────────────────────────────────────
interface RecurrenceEditorProps {
  value: ScheduleRecurrence;
  onChange: (r: ScheduleRecurrence) => void;
}

export function RecurrenceEditor({ value, onChange }: RecurrenceEditorProps): React.JSX.Element {
  // Keep the derived frequency label in sync on every structural edit so
  // downstream readers of `value.frequency` stay coherent (legacy biweekly /
  // all_once labels are preserved by recurrenceToConfig, not here).
  const emit = (patch: Partial<ScheduleRecurrence>) => {
    const next: ScheduleRecurrence = { ...value, ...patch };
    next.frequency = deriveFrequency(next.interval, next.unit);
    onChange(next);
  };

  const plural = value.interval > 1;

  const toggleDay = (iso: number) => {
    const set = new Set(value.byDays);
    if (set.has(iso)) set.delete(iso);
    else set.add(iso);
    emit({ byDays: Array.from(set).sort((a, b) => a - b) });
  };

  const setEndsType = (type: 'never' | 'on' | 'after') => {
    if (type === 'never') emit({ ends: { type: 'never' } });
    else if (type === 'on') {
      const date = value.ends.type === 'on' ? value.ends.date : '';
      emit({ ends: { type: 'on', date } });
    } else {
      const count = value.ends.type === 'after' ? value.ends.count : 13;
      emit({ ends: { type: 'after', count } });
    }
  };

  const labelStyle: React.CSSProperties = {
    fontSize: typography.xs,
    fontWeight: typography.medium,
    color: colors.text,
  };

  return (
    <div className="flex flex-col gap-4" style={{ fontFamily: typography.fontFamily }}>
      {/* Repeat every ------------------------------------------------ */}
      <div className="flex items-center gap-2">
        <span style={labelStyle}>Repeat every</span>
        <Input
          type="number"
          min={MIN_INTERVAL}
          max={MAX_INTERVAL}
          value={value.interval}
          onChange={(e) => emit({ interval: clampInterval(e.target.value) })}
          className="h-8 w-16 text-xs"
        />
        <Select
          value={value.unit}
          onValueChange={(u) => emit({ unit: u as ScheduleRecurrence['unit'] })}
        >
          <SelectTrigger className="h-8 w-28 text-xs bg-background">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="day">{plural ? 'days' : 'day'}</SelectItem>
            <SelectItem value="week">{plural ? 'weeks' : 'week'}</SelectItem>
            <SelectItem value="month">{plural ? 'months' : 'month'}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Repeat on (weekly only) ----------------------------------- */}
      {value.unit === 'week' && (
        <div className="flex flex-col gap-2">
          <span style={labelStyle}>Repeat on</span>
          <div className="flex items-center gap-1.5">
            {DAY_CHIPS.map((d, i) => {
              const selected = value.byDays.includes(d.iso);
              return (
                <button
                  key={i}
                  type="button"
                  aria-pressed={selected}
                  onClick={() => toggleDay(d.iso)}
                  className="flex items-center justify-center rounded-full transition-colors"
                  style={{
                    width: 28,
                    height: 28,
                    fontSize: typography.xs,
                    fontWeight: typography.medium,
                    border: `1px solid ${selected ? colors.primary : colors.border}`,
                    background: selected ? colors.primary : colors.bgSurface,
                    color: selected ? '#fff' : colors.textSecondary,
                    cursor: 'pointer',
                  }}
                >
                  {d.label}
                </button>
              );
            })}
          </div>
        </div>
      )}

      {/* Ends ------------------------------------------------------- */}
      <div className="flex flex-col gap-2">
        <span style={labelStyle}>Ends</span>

        {/* Never */}
        <label className="flex items-center gap-2 cursor-pointer" style={{ fontSize: typography.xs }}>
          <input
            type="radio"
            name="recurrence-ends"
            checked={value.ends.type === 'never'}
            onChange={() => setEndsType('never')}
          />
          <span style={{ color: colors.text }}>Never</span>
        </label>

        {/* On <date> */}
        <label className="flex items-center gap-2 cursor-pointer" style={{ fontSize: typography.xs }}>
          <input
            type="radio"
            name="recurrence-ends"
            checked={value.ends.type === 'on'}
            onChange={() => setEndsType('on')}
          />
          <span style={{ color: colors.text }}>On</span>
          <Input
            type="date"
            disabled={value.ends.type !== 'on'}
            value={value.ends.type === 'on' ? value.ends.date : ''}
            onChange={(e) => emit({ ends: { type: 'on', date: e.target.value } })}
            className="h-8 w-40 text-xs"
          />
        </label>

        {/* After <count> occurrences */}
        <label className="flex items-center gap-2 cursor-pointer" style={{ fontSize: typography.xs }}>
          <input
            type="radio"
            name="recurrence-ends"
            checked={value.ends.type === 'after'}
            onChange={() => setEndsType('after')}
          />
          <span style={{ color: colors.text }}>After</span>
          <Input
            type="number"
            min={MIN_COUNT}
            max={MAX_COUNT}
            disabled={value.ends.type !== 'after'}
            value={value.ends.type === 'after' ? value.ends.count : ''}
            onChange={(e) =>
              emit({ ends: { type: 'after', count: clamp(Math.floor(Number(e.target.value)) || MIN_COUNT, MIN_COUNT, MAX_COUNT) } })
            }
            className="h-8 w-20 text-xs"
          />
          <span style={{ color: colors.textSecondary }}>occurrences</span>
        </label>
      </div>
    </div>
  );
}
