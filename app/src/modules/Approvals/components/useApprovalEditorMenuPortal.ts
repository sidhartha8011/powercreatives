import { useCallback, useLayoutEffect, useState } from 'react';
import type { Editor } from '@tiptap/react';

/**
 * Resolves the two DOM boundaries shared by Approval editor popovers.
 *
 * The editor scrolls inside `.pcm-notion-modal`, while menus must render in
 * `.pcm-notion-overlay` so they are not clipped by that scroll container. The
 * editor DOM is not attached to the modal during the first React render, so the
 * scroll owner is intentionally resolved after layout and the portal host is
 * intentionally resolved only when Tiptap asks to show a menu.
 */
export function useApprovalEditorMenuPortal(editor: Editor) {
  const [scrollTarget, setScrollTarget] = useState<HTMLElement | null>(null);

  useLayoutEffect(() => {
    setScrollTarget(editor.view.dom.closest<HTMLElement>('.pcm-notion-modal'));
  }, [editor]);

  const appendTo = useCallback(() => (
    editor.view.dom.closest<HTMLElement>('.pcm-notion-overlay')
      ?? editor.view.dom.parentElement
      ?? document.body
  ), [editor]);

  return {
    appendTo,
    scrollTarget: scrollTarget ?? window,
  };
}
