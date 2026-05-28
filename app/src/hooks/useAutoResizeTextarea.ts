/**
 * useAutoResizeTextarea
 *
 * Auto-resizes a <textarea> to fit its content, eliminating scrollbars
 * and matching the visual height of a block-level text element (<p>, <h4>).
 *
 * Uses useLayoutEffect to measure and set height synchronously before
 * the browser paints, preventing any visual flicker.
 *
 * Usage:
 *   const ref = useAutoResizeTextarea(value);
 *   <textarea ref={ref} value={value} ... />
 */

import { useRef, useLayoutEffect } from 'react';

export function useAutoResizeTextarea(value: string) {
  const ref = useRef<HTMLTextAreaElement>(null);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;

    // Reset height to auto so scrollHeight recalculates from content
    el.style.height = 'auto';
    // Set height to scrollHeight — the minimum height to show all content
    el.style.height = el.scrollHeight + 'px';
  }, [value]); // Re-measure whenever value changes (typing, server update, etc.)

  return ref;
}
