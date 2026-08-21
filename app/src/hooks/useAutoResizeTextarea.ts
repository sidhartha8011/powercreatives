/**
 * useAutoResizeTextarea
 *
 * Auto-resizes a <textarea> to fit its content, eliminating scrollbars
 * and matching the visual height of a block-level text element (<p>, <h4>).
 *
 * Returns a CALLBACK ref (assign it to the textarea's `ref` prop). The measure
 * runs in two situations, and both matter:
 *
 *   1. When the element ATTACHES. The first version measured in a
 *      useLayoutEffect keyed on [value] — but the card's edit textareas mount
 *      only when the user clicks into edit mode, and at that moment the value
 *      has NOT changed, so the effect never re-ran and the textarea stayed at
 *      rows={1} with overflow:hidden. Everything past the first line was
 *      invisible until the user typed (card 18 follow-up: "click to edit the
 *      card and half of the content disappears").
 *   2. When the value changes (typing, server update) — same as before,
 *      synchronously before paint so there is no flicker.
 *
 * Usage:
 *   const ref = useAutoResizeTextarea(value);
 *   <textarea ref={ref} value={value} ... />
 */

import { useCallback, useLayoutEffect, useRef } from 'react';

function fit(el: HTMLTextAreaElement): void {
  // Reset height to auto so scrollHeight recalculates from content,
  // then set the minimum height that shows all of it.
  el.style.height = 'auto';
  el.style.height = el.scrollHeight + 'px';
}

export function useAutoResizeTextarea(value: string) {
  const elRef = useRef<HTMLTextAreaElement | null>(null);

  // Attach/detach — fires the measure the moment the textarea exists,
  // which is exactly the moment edit mode opens.
  const ref = useCallback((el: HTMLTextAreaElement | null) => {
    elRef.current = el;
    if (el) fit(el);
  }, []);

  // Value changes while mounted (typing, query invalidation).
  useLayoutEffect(() => {
    if (elRef.current) fit(elRef.current);
  }, [value]);

  return ref;
}
