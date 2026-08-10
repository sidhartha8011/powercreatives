/**
 * Approval-owned configuration for the shared selection formatter.
 *
 * Tiptap v3 positions BubbleMenu with Floating UI. The previous integration
 * passed the removed v2 `tippyOptions` prop, so its positioning contract was
 * ignored. This adapter keeps Approval-specific modal/scroll knowledge out of
 * the Writer component while reusing every existing formatting command.
 */

import { useMemo } from 'react';
import type { Editor } from '@tiptap/react';
import type { BubbleMenuProps } from '@tiptap/react/menus';

import { WriterBubbleMenu } from '@/modules/Writer/components/WriterBubbleMenu';
import { useApprovalEditorMenuPortal } from './useApprovalEditorMenuPortal';

interface ApprovalSelectionMenuProps {
  editor: Editor;
  onOpenImagePicker: () => void;
}

export function ApprovalSelectionMenu({ editor, onOpenImagePicker }: ApprovalSelectionMenuProps) {
  const { appendTo, scrollTarget } = useApprovalEditorMenuPortal(editor);

  const floatingOptions = useMemo<BubbleMenuProps['options']>(() => ({
    strategy: 'fixed',
    placement: 'top',
    offset: 10,
    flip: { padding: 12 },
    shift: { padding: 12 },
    inline: true,
    // The opened document scrolls inside the modal rather than the window.
    scrollTarget,
  }), [scrollTarget]);

  return (
    <WriterBubbleMenu
      editor={editor}
      onOpenImagePicker={onOpenImagePicker}
      selectionOnly
      compact
      showImageAction={false}
      appendTo={appendTo}
      floatingOptions={floatingOptions}
    />
  );
}
