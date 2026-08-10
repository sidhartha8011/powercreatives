/**
 * WRITER MODULE — Bubble Menu (Floating Toolbar)
 *
 * Floating toolbar that appears when the user selects text in the editor.
 * Contains ALL free Tiptap formatting commands organised in logical groups:
 *
 * Group 1: Headings (H1, H2, H3, H4)
 * Group 2: Inline formatting (Bold, Italic, Underline, Strikethrough, Code)
 * Group 3: Alignment (Left, Center, Right, Justify)
 * Group 4: Block formatting (BulletList, OrderedList, Blockquote, HorizontalRule)
 * Group 5: Rich elements (Link, Highlight, Superscript, Subscript)
 * Group 6: History (Undo, Redo)
 *
 * Replaces the fixed top toolbar — all formatting is now contextual.
 *
 * @package PowerCreatives
 * @module  Writer
 */

declare const wp: any;

import { BubbleMenu, type BubbleMenuProps } from '@tiptap/react/menus';
import type { Editor } from '@tiptap/react';
import {
  Heading1, Heading2, Heading3, Heading4,
  BoldIcon, ItalicIcon, UnderlineIcon, StrikethroughIcon,
  Code, Link, Unlink,
  AlignLeft, AlignCenter, AlignRight, AlignJustify,
  List, ListOrdered, Quote, Minus,
  Highlighter, Superscript, Subscript,
  Undo2, Redo2, Image as ImageIcon,
} from 'lucide-react';
import React, { useCallback, useState } from 'react';

interface WriterBubbleMenuProps {
  editor: Editor;
  onOpenImagePicker: () => void;
  /**
   * When true, the menu appears ONLY on a non-empty TEXT selection — not on empty
   * paragraphs and not on node selections (e.g. a clicked image). The Writer canvas
   * leaves this off (default) so the menu also pops on empty lines for quick formatting;
   * the Approvals custom-card editor turns it on so the popup is strictly selection-driven.
   */
  selectionOnly?: boolean;
  /**
   * Compact, responsive presentation for bounded document cards. The Writer
   * keeps its existing full-width presentation; Approval cards opt into this
   * mode so the same commands remain available in a true selection popover.
   */
  compact?: boolean;
  /** Keep the shared Writer default; Approval cards move image insertion to `/`. */
  showImageAction?: boolean;
  /** Optional Floating UI host supplied by a consuming surface. */
  appendTo?: BubbleMenuProps['appendTo'];
  /** Optional Tiptap v3 Floating UI configuration supplied by a consumer. */
  floatingOptions?: BubbleMenuProps['options'];
}

/** Shared button style — active state uses design system accent */
function MenuButton({
  onClick,
  isActive = false,
  disabled = false,
  title,
  children,
}: {
  onClick: () => void;
  isActive?: boolean;
  disabled?: boolean;
  title: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={`rounded p-1.5 transition-colors hover:bg-accent ${
        isActive
          ? 'bg-accent text-accent-foreground'
          : 'text-muted-foreground'
      } ${disabled ? 'opacity-30 cursor-default' : ''}`}
      title={title}
    >
      {children}
    </button>
  );
}

/** Thin vertical separator between button groups */
function Divider() {
  return <div className="mx-1 h-5 w-px bg-border" />;
}

const iconSize = 'h-[15px] w-[15px]';

export function WriterBubbleMenu({
  editor,
  onOpenImagePicker,
  selectionOnly = false,
  compact = false,
  showImageAction = true,
  appendTo,
  floatingOptions,
}: WriterBubbleMenuProps) {
  const [showLinkInput, setShowLinkInput] = useState(false);
  const [linkUrl, setLinkUrl] = useState('');

  const menuClassName = compact
    ? 'pcm-selection-popover pcm-selection-popover--compact flex flex-wrap items-center justify-center gap-0.5 rounded-lg border border-border bg-popover p-1 shadow-lg'
    : 'pcm-selection-popover flex items-center gap-0.5 rounded-lg border border-border bg-popover p-1 shadow-lg';
  const linkMenuClassName = compact
    ? 'pcm-selection-popover pcm-selection-popover--compact flex items-center gap-1 rounded-lg border border-border bg-popover p-1.5 shadow-lg'
    : 'pcm-selection-popover flex items-center gap-1 rounded-lg border border-border bg-popover p-1.5 shadow-lg';

  // ── Custom shouldShow Logic ──
  // Shows on a text selection, and (unless selectionOnly) also on an empty paragraph.
  const shouldShow = useCallback(({ editor: currentEditor, state }: { editor: Editor, state: any }) => {
    const { selection } = state;
    const { empty } = selection;

    // 1. Text selection (not empty). In selectionOnly mode, exclude node selections
    //    (e.g. a clicked image) so the popup is strictly text-selection driven.
    if (!empty) {
      return selectionOnly ? !selection.node : true;
    }

    // In selectionOnly mode nothing else triggers the popup.
    if (selectionOnly) return false;

    // 2. Empty paragraph
    const { $from } = selection;
    const parent = $from.parent;

    if (parent.type.name === 'paragraph' && parent.textContent === '') {
      return true;
    }

    return false;
  }, [selectionOnly]);

  // ── Link handling ──
  const handleSetLink = useCallback(() => {
    if (linkUrl.trim()) {
      editor
        .chain()
        .focus()
        .extendMarkRange('link')
        .setLink({ href: linkUrl.trim() })
        .run();
    }
    setShowLinkInput(false);
    setLinkUrl('');
  }, [editor, linkUrl]);

  const handleUnlink = useCallback(() => {
    editor.chain().focus().unsetLink().run();
    setShowLinkInput(false);
    setLinkUrl('');
  }, [editor]);

  const openLinkInput = useCallback(() => {
    const existingHref = editor.getAttributes('link').href || '';
    setLinkUrl(existingHref);
    setShowLinkInput(true);
  }, [editor]);

  // ── WordPress Media Library UI ──
  // Now delegated to ReviewEditorCanvas via onOpenImagePicker.

  // ── Link input overlay ──
  if (showLinkInput) {
    return (
      <BubbleMenu
        editor={editor}
        shouldShow={shouldShow}
        appendTo={appendTo}
        options={floatingOptions}
        className={linkMenuClassName}
      >
        <input
          type="url"
          value={linkUrl}
          onChange={(e) => setLinkUrl(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') handleSetLink();
            if (e.key === 'Escape') setShowLinkInput(false);
          }}
          placeholder="https://..."
          className="h-6 w-48 rounded border border-border bg-background px-2 text-xs outline-none focus:border-ring"
          autoFocus
        />
        <MenuButton onClick={handleSetLink} title="Apply Link">
          <Link className={iconSize} />
        </MenuButton>
        <MenuButton onClick={() => setShowLinkInput(false)} title="Cancel">
          <Unlink className={iconSize} />
        </MenuButton>
      </BubbleMenu>
    );
  }

  return (
    <BubbleMenu
      editor={editor}
      shouldShow={shouldShow}
      appendTo={appendTo}
      options={floatingOptions}
      className={menuClassName}
    >
      {/* ── Group 1: Headings ── */}
      <MenuButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()}
        isActive={editor.isActive('heading', { level: 1 })}
        title="Heading 1"
      >
        <Heading1 className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}
        isActive={editor.isActive('heading', { level: 2 })}
        title="Heading 2"
      >
        <Heading2 className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}
        isActive={editor.isActive('heading', { level: 3 })}
        title="Heading 3"
      >
        <Heading3 className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleHeading({ level: 4 }).run()}
        isActive={editor.isActive('heading', { level: 4 })}
        title="Heading 4"
      >
        <Heading4 className={iconSize} />
      </MenuButton>

      <Divider />

      {/* ── Group 2: Inline formatting ── */}
      <MenuButton
        onClick={() => editor.chain().focus().toggleBold().run()}
        isActive={editor.isActive('bold')}
        title="Bold (Ctrl+B)"
      >
        <BoldIcon className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleItalic().run()}
        isActive={editor.isActive('italic')}
        title="Italic (Ctrl+I)"
      >
        <ItalicIcon className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleUnderline().run()}
        isActive={editor.isActive('underline')}
        title="Underline (Ctrl+U)"
      >
        <UnderlineIcon className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleStrike().run()}
        isActive={editor.isActive('strike')}
        title="Strikethrough"
      >
        <StrikethroughIcon className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleCode().run()}
        isActive={editor.isActive('code')}
        title="Inline Code"
      >
        <Code className={iconSize} />
      </MenuButton>

      <Divider />

      {/* ── Group 3: Text Alignment ── */}
      <MenuButton
        onClick={() => editor.chain().focus().setTextAlign('left').run()}
        isActive={editor.isActive({ textAlign: 'left' })}
        title="Align Left"
      >
        <AlignLeft className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().setTextAlign('center').run()}
        isActive={editor.isActive({ textAlign: 'center' })}
        title="Align Center"
      >
        <AlignCenter className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().setTextAlign('right').run()}
        isActive={editor.isActive({ textAlign: 'right' })}
        title="Align Right"
      >
        <AlignRight className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().setTextAlign('justify').run()}
        isActive={editor.isActive({ textAlign: 'justify' })}
        title="Justify"
      >
        <AlignJustify className={iconSize} />
      </MenuButton>

      <Divider />

      {/* ── Group 4: Block formatting ── */}
      <MenuButton
        onClick={() => editor.chain().focus().toggleBulletList().run()}
        isActive={editor.isActive('bulletList')}
        title="Bullet List"
      >
        <List className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleOrderedList().run()}
        isActive={editor.isActive('orderedList')}
        title="Ordered List"
      >
        <ListOrdered className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleBlockquote().run()}
        isActive={editor.isActive('blockquote')}
        title="Blockquote"
      >
        <Quote className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().setHorizontalRule().run()}
        title="Horizontal Rule"
      >
        <Minus className={iconSize} />
      </MenuButton>

      <Divider />

      {/* ── Group 5: Rich elements ── */}
      {showImageAction && (
        <MenuButton
          onClick={onOpenImagePicker}
          title="Insert Image"
        >
          <ImageIcon className={iconSize} />
        </MenuButton>
      )}
      <MenuButton
        onClick={openLinkInput}
        isActive={editor.isActive('link')}
        title="Insert/Edit Link"
      >
        <Link className={iconSize} />
      </MenuButton>
      {editor.isActive('link') && (
        <MenuButton onClick={handleUnlink} title="Remove Link">
          <Unlink className={iconSize} />
        </MenuButton>
      )}
      <MenuButton
        onClick={() => editor.chain().focus().toggleHighlight().run()}
        isActive={editor.isActive('highlight')}
        title="Highlight"
      >
        <Highlighter className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleSuperscript().run()}
        isActive={editor.isActive('superscript')}
        title="Superscript"
      >
        <Superscript className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().toggleSubscript().run()}
        isActive={editor.isActive('subscript')}
        title="Subscript"
      >
        <Subscript className={iconSize} />
      </MenuButton>

      <Divider />

      {/* ── Group 6: History ── */}
      <MenuButton
        onClick={() => editor.chain().focus().undo().run()}
        disabled={!editor.can().undo()}
        title="Undo (Ctrl+Z)"
      >
        <Undo2 className={iconSize} />
      </MenuButton>
      <MenuButton
        onClick={() => editor.chain().focus().redo().run()}
        disabled={!editor.can().redo()}
        title="Redo (Ctrl+Y)"
      >
        <Redo2 className={iconSize} />
      </MenuButton>
    </BubbleMenu>
  );
}
