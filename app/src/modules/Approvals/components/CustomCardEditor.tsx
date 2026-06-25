/**
 * CustomCardEditor — the Notion-style authoring surface for a Custom approval card.
 *
 * Reuses the SHARED Tiptap stack (`getEditorExtensions`) — the exact same extension
 * set the public review page renders custom content with — so authoring and review
 * stay pixel-consistent. Images are inserted through the existing WordPress media
 * library frame (`wp.media`, enqueued in class-pcm-admin.php); no new editor and no
 * new upload endpoint. Annotation (Phase 2) layers on top of inserted images later.
 */

import { useEffect, useState, type ReactNode } from 'react';
import { useEditor, EditorContent, type Editor } from '@tiptap/react';
import { Bold, Italic, Heading2, Heading3, List, ListOrdered, Image as ImageIcon, PenLine } from 'lucide-react';

import { getEditorExtensions } from '@/components/shared/editorExtensions';
import { ImageAnnotator } from './ImageAnnotator';

// WordPress media library global (wp_enqueue_media() runs in class-pcm-admin.php).
declare const wp: any;

interface CustomCardEditorProps {
  /** Tiptap HTML content. */
  content: string;
  /** Emitted with updated HTML on every change. */
  onChange: (html: string) => void;
  placeholder?: string;
}

function ToolbarButton({ onClick, active, title, children }: {
  onClick: () => void; active?: boolean; title: string; children: ReactNode;
}) {
  return (
    <button
      type="button"
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
      title={title}
      className={`inline-flex h-8 w-8 items-center justify-center rounded-md transition-colors ${
        active ? 'bg-accent text-accent-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'
      }`}
    >
      {children}
    </button>
  );
}

export function CustomCardEditor({ content, onChange, placeholder }: CustomCardEditorProps) {
  // Image to annotate (set after the user picks one via the media library).
  const [annotateUrl, setAnnotateUrl] = useState<string | null>(null);

  const editor = useEditor({
    extensions: getEditorExtensions({ placeholder: placeholder ?? 'Write your document…' }),
    content: content || '<p></p>',
    editable: true,
    editorProps: {
      attributes: { class: 'outline-none prose prose-sm max-w-none min-h-[280px] focus:outline-none' },
    },
    onUpdate: ({ editor }: { editor: Editor }) => onChange(editor.getHTML()),
  });

  // Hydrate once the editor is ready (edit mode passes existing content).
  useEffect(() => {
    if (editor && content && editor.getHTML() !== content) {
      editor.commands.setContent(content);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor]);

  // Open the WordPress media library and hand the chosen image URL to a callback.
  const pickImage = (onPick: (url: string, alt?: string) => void) => {
    if (typeof wp === 'undefined' || !wp?.media) return;
    const frame = wp.media({ title: 'Select or upload image', button: { text: 'Use image' }, multiple: false });
    frame.on('select', () => {
      const att = frame.state().get('selection').first()?.toJSON();
      if (att?.url) onPick(att.url, att.alt || '');
    });
    frame.open();
  };

  const insertImage = () => pickImage((url, alt) => editor?.chain().focus().setImage({ src: url, alt }).run());
  const annotateImage = () => pickImage((url) => setAnnotateUrl(url));

  if (!editor) return null;

  return (
    <div className="rounded-lg border border-border bg-card">
      {/* Formatting toolbar — drives the shared Tiptap commands (not a new editor). */}
      <div className="flex flex-wrap items-center gap-1 border-b border-border p-1.5">
        <ToolbarButton title="Bold" active={editor.isActive('bold')} onClick={() => editor.chain().focus().toggleBold().run()}><Bold className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Italic" active={editor.isActive('italic')} onClick={() => editor.chain().focus().toggleItalic().run()}><Italic className="h-4 w-4" /></ToolbarButton>
        <span className="mx-1 h-5 w-px bg-border" />
        <ToolbarButton title="Heading 2" active={editor.isActive('heading', { level: 2 })} onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()}><Heading2 className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Heading 3" active={editor.isActive('heading', { level: 3 })} onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()}><Heading3 className="h-4 w-4" /></ToolbarButton>
        <span className="mx-1 h-5 w-px bg-border" />
        <ToolbarButton title="Bullet list" active={editor.isActive('bulletList')} onClick={() => editor.chain().focus().toggleBulletList().run()}><List className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Numbered list" active={editor.isActive('orderedList')} onClick={() => editor.chain().focus().toggleOrderedList().run()}><ListOrdered className="h-4 w-4" /></ToolbarButton>
        <span className="mx-1 h-5 w-px bg-border" />
        <ToolbarButton title="Insert image" onClick={insertImage}><ImageIcon className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Annotate an image" onClick={annotateImage}><PenLine className="h-4 w-4" /></ToolbarButton>
      </div>
      <div className="max-h-[55vh] overflow-y-auto p-4">
        <EditorContent editor={editor} />
      </div>

      {/* Annotation: draw on the picked image, then insert the flattened PNG. */}
      <ImageAnnotator
        open={!!annotateUrl}
        imageUrl={annotateUrl}
        onCancel={() => setAnnotateUrl(null)}
        onInsert={(dataUrl) => {
          editor.chain().focus().setImage({ src: dataUrl, alt: 'Annotated image' }).run();
          setAnnotateUrl(null);
        }}
      />
    </div>
  );
}
