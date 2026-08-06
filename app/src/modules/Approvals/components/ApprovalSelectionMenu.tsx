/**
 * Approval-owned configuration for the shared selection formatter.
 *
 * Tiptap v3 positions BubbleMenu with Floating UI. The previous integration
 * passed the removed v2 `tippyOptions` prop, so its positioning contract was
 * ignored. This adapter keeps Approval-specific modal/scroll knowledge out of
 * the Writer component while reusing every existing formatting command.
 */

import { useCallback, useLayoutEffect, useMemo, useState } from 'react';
import type { Editor } from '@tiptap/react';
import type { BubbleMenuProps } from '@tiptap/react/menus';

import { WriterBubbleMenu } from '@/modules/Writer/components/WriterBubbleMenu';

interface ApprovalSelectionMenuProps {
  editor: Editor;
  onOpenImagePicker: () => void;
}

export function ApprovalSelectionMenu({ editor, onOpenImagePicker }: ApprovalSelectionMenuProps) {
  const [scrollTarget, setScrollTarget] = useState<HTMLElement | null>(null);

  /* The editor DOM is created before React attaches it to the modal. Resolve
     the scroll owner after that attachment; resolving during render returns
     null and leaves Floating UI listening to the window instead. */
  useLayoutEffect(() => {
    setScrollTarget(editor.view.dom.closest<HTMLElement>('.pcm-notion-modal'));
  }, [editor]);

  /* BubbleMenu calls appendTo when it becomes visible, by which time the view
     is mounted. Keeping this as a function avoids capturing the pre-mount null
     and ensures the popover sits outside the modal's clipping scroll box. */
  const appendTo = useCallback(() => (
    editor.view.dom.closest<HTMLElement>('.pcm-notion-overlay')
      ?? editor.view.dom.parentElement
      ?? document.body
  ), [editor]);

  const floatingOptions = useMemo<BubbleMenuProps['options']>(() => ({
    strategy: 'fixed',
    placement: 'top',
    offset: 10,
    flip: { padding: 12 },
    shift: { padding: 12 },
    inline: true,
    // The opened document scrolls inside the modal rather than the window.
    scrollTarget: scrollTarget ?? window,
  }), [scrollTarget]);

  return (
    <WriterBubbleMenu
      editor={editor}
      onOpenImagePicker={onOpenImagePicker}
      selectionOnly
      compact
      appendTo={appendTo}
      floatingOptions={floatingOptions}
    />
  );
}
