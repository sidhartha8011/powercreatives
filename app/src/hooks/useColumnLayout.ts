/**
 * useColumnLayout — spreadsheet-style column order + widths, persisted to
 * localStorage (per browser). Widths are px; order is a list of column keys.
 *
 * The canonical column set is passed in; the stored layout is reconciled against
 * it on load and whenever it changes, so new columns appear (appended) and
 * removed columns drop out without wiping the user's saved sizes.
 *
 * Generic/global: pass a unique `storageKey` per table so modules don't collide
 * (e.g. SEO passes 'pcm:seo:col-layout:v1'). Pairs with the shared `ColumnHead`
 * (drag-to-reorder + drag-to-resize) and `useColumnFilters`.
 */

import { useCallback, useEffect, useRef, useState } from 'react';

const DEFAULT_STORAGE_KEY = 'pcm:col-layout:v1';
/** Clamp so a column can never be dragged smaller than this (px). */
export const MIN_COLUMN_WIDTH = 56;

export interface ColumnLayout {
  order: string[];
  widths: Record<string, number>;
}

export interface UseColumnLayoutResult {
  order: string[];
  /** Effective px width for a column (stored → default → fallback). */
  width: (key: string) => number;
  setWidth: (key: string, px: number) => void;
  /** Move `fromKey` to occupy `toKey`'s slot. */
  moveColumn: (fromKey: string, toKey: string) => void;
  reset: () => void;
}

function reconcileOrder(order: string[], keys: string[]): string[] {
  const kept = order.filter((k) => keys.includes(k));
  for (const k of keys) if (!kept.includes(k)) kept.push(k);
  return kept;
}

function loadLayout(storageKey: string, keys: string[], defaults: Record<string, number>): ColumnLayout {
  const base: ColumnLayout = { order: [...keys], widths: { ...defaults } };
  try {
    const raw = localStorage.getItem(storageKey);
    if (!raw) return base;
    const parsed = JSON.parse(raw) as Partial<ColumnLayout>;
    const order = reconcileOrder(Array.isArray(parsed.order) ? parsed.order : [], keys);
    const widths = {
      ...defaults,
      ...(parsed.widths && typeof parsed.widths === 'object' ? parsed.widths : {}),
    };
    return { order, widths };
  } catch {
    return base;
  }
}

export function useColumnLayout(
  columnKeys: string[],
  defaultWidths: Record<string, number>,
  storageKey: string = DEFAULT_STORAGE_KEY,
): UseColumnLayoutResult {
  const [layout, setLayout] = useState<ColumnLayout>(() => loadLayout(storageKey, columnKeys, defaultWidths));

  // Reconcile the order if the canonical column set changes (added/removed cols).
  useEffect(() => {
    setLayout((cur) => {
      const order = reconcileOrder(cur.order, columnKeys);
      const same = order.length === cur.order.length && order.every((k, i) => k === cur.order[i]);
      return same ? cur : { ...cur, order };
    });
  }, [columnKeys]);

  // Persist on change (skip the very first run — nothing changed yet).
  const firstRun = useRef(true);
  useEffect(() => {
    if (firstRun.current) {
      firstRun.current = false;
      return;
    }
    try {
      localStorage.setItem(storageKey, JSON.stringify(layout));
    } catch {
      /* storage unavailable — keep working in-memory */
    }
  }, [layout, storageKey]);

  const width = useCallback(
    (key: string) => layout.widths[key] ?? defaultWidths[key] ?? 140,
    [layout.widths, defaultWidths],
  );

  const setWidth = useCallback((key: string, px: number) => {
    setLayout((cur) => ({
      ...cur,
      widths: { ...cur.widths, [key]: Math.max(MIN_COLUMN_WIDTH, Math.round(px)) },
    }));
  }, []);

  const moveColumn = useCallback((fromKey: string, toKey: string) => {
    setLayout((cur) => {
      if (fromKey === toKey) return cur;
      const order = [...cur.order];
      const from = order.indexOf(fromKey);
      const to = order.indexOf(toKey);
      if (from < 0 || to < 0) return cur;
      order.splice(from, 1);
      order.splice(to, 0, fromKey);
      return { ...cur, order };
    });
  }, []);

  const reset = useCallback(
    () => setLayout({ order: [...columnKeys], widths: { ...defaultWidths } }),
    [columnKeys, defaultWidths],
  );

  return { order: layout.order, width, setWidth, moveColumn, reset };
}
