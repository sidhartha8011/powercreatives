/**
 * TIPTAP BODY EDITOR
 *
 * Minimal Tiptap editor for inline body text editing.
 * Supports bold, italic via BubbleMenu on text selection.
 *
 * Props:
 *   - content: plain text or simple HTML string
 *   - editable: whether the editor is in edit mode
 *   - onChange: called with updated HTML content on every change
 *   - placeholder: placeholder text when empty
 *   - className: additional CSS classes
 *
 * This component is intentionally generic — it knows nothing about Copy.
 * It can be reused for email body, blog content, etc.
 */

import { useEffect, useCallback, memo } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import Document from '@tiptap/extension-document';
import Paragraph from '@tiptap/extension-paragraph';
import Text from '@tiptap/extension-text';
import Bold from '@tiptap/extension-bold';
import Italic from '@tiptap/extension-italic';
import HardBreak from '@tiptap/extension-hard-break';
import Placeholder from '@tiptap/extension-placeholder';
import { Bold as BoldIcon, Italic as ItalicIcon } from 'lucide-react';

interface TiptapBodyEditorProps {
  content: string;
  editable: boolean;
  onChange?: (html: string) => void;
  placeholder?: string;
  className?: string;
}

const extensions = [
  Document,
  Paragraph,
  Text,
  Bold,
  Italic,
  HardBreak, /* Preserves <br> tags so single \n line breaks survive HTML parsing */
  Placeholder.configure({
    placeholder: 'Body copy...',
  }),
];

/**
 * Convert plain text (with \n\n paragraph breaks) to Tiptap-compatible HTML.
 * Each paragraph becomes a <p> tag. Single \n becomes <br>.
 */
function plainTextToHtml(text: string): string {
  if (!text) return '<p></p>';
  // If it already looks like HTML, pass through
  if (text.includes('<p>') || text.includes('<br')) return text;
  // Split on double newlines for paragraphs
  const paragraphs = text.split(/\n\n+/);
  return paragraphs
    .map((p) => {
      // Convert single newlines within a paragraph to <br>
      const withBreaks = p.replace(/\n/g, '<br>');
      return `<p>${withBreaks}</p>`;
    })
    .join('');
}

/**
 * Convert Tiptap HTML back to plain text, preserving paragraph breaks.
 */
function htmlToPlainText(html: string): string {
  if (!html) return '';
  // Replace </p><p> with double newline
  let text = html.replace(/<\/p>\s*<p>/gi, '\n\n');
  // Replace <br> with single newline
  text = text.replace(/<br\s*\/?>/gi, '\n');
  // Strip remaining HTML tags
  text = text.replace(/<[^>]*>/g, '');
  // Decode HTML entities
  text = text.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
  return text.trim();
}

function TiptapBodyEditorInner({
  content,
  editable,
  onChange,
  placeholder,
  className = '',
}: TiptapBodyEditorProps) {
  // Convert plain text to HTML for Tiptap
  const htmlContent = plainTextToHtml(content);

  const editor = useEditor({
    extensions: placeholder
      ? [
          Document,
          Paragraph,
          Text,
          Bold,
          Italic,
          HardBreak,
          Placeholder.configure({ placeholder }),
        ]
      : extensions,
    content: htmlContent,
    editable,
    onUpdate: ({ editor: e }) => {
      // Convert HTML back to plain text for storage
      onChange?.(htmlToPlainText(e.getHTML()));
    },
    editorProps: {
      attributes: {
        class: `outline-none min-h-[3rem] ${className}`,
      },
    },
  });

  // Sync editable state
  useEffect(() => {
    if (editor && editor.isEditable !== editable) {
      editor.setEditable(editable);
    }
  }, [editor, editable]);

  // Sync content when switching from external updates (e.g. regeneration)
  useEffect(() => {
    if (editor && !editable) {
      const newHtml = plainTextToHtml(content);
      const currentContent = editor.getHTML();
      if (currentContent !== newHtml) {
        editor.commands.setContent(newHtml);
      }
    }
  }, [editor, content, editable]);

  const toggleBold = useCallback(() => {
    editor?.chain().focus().toggleBold().run();
  }, [editor]);

  const toggleItalic = useCallback(() => {
    editor?.chain().focus().toggleItalic().run();
  }, [editor]);

  if (!editor) return null;

  return (
    <div className="relative">
      {editable && (
        <BubbleMenu
          editor={editor}
          className="flex items-center gap-0.5 rounded-lg border border-border bg-popover p-1 shadow-md"
        >
          <button
            type="button"
            onClick={toggleBold}
            className={`rounded p-1.5 transition-colors hover:bg-accent ${
              editor.isActive('bold')
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground'
            }`}
            title="Bold"
          >
            <BoldIcon className="h-3.5 w-3.5" />
          </button>
          <button
            type="button"
            onClick={toggleItalic}
            className={`rounded p-1.5 transition-colors hover:bg-accent ${
              editor.isActive('italic')
                ? 'bg-accent text-accent-foreground'
                : 'text-muted-foreground'
            }`}
            title="Italic"
          >
            <ItalicIcon className="h-3.5 w-3.5" />
          </button>
        </BubbleMenu>
      )}
      <EditorContent editor={editor} />
    </div>
  );
}

export const TiptapBodyEditor = memo(TiptapBodyEditorInner);
