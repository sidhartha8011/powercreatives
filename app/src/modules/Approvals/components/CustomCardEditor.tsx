/**
 * CustomCardEditor — the Notion-style authoring surface for a Custom approval card.
 *
 * Reuses the SHARED Tiptap stack (`getEditorExtensions`) — the exact same extension
 * set the public review page renders custom content with — so authoring and review
 * stay pixel-consistent. Images are inserted through the existing WordPress media
 * library frame (`wp.media`, enqueued in class-pcm-admin.php); no new editor and no
 * new upload endpoint. AI image actions (Edit / Regenerate hover overlay) are disabled
 * here via `imageActions: false`.
 *
 * Two annotation tools:
 *   • Annotate an image — flattens strokes onto ONE picked image (ImageAnnotator).
 *   • Draw — a persistent freehand layer over the WHOLE card (text + images), stored as
 *     a transparent PNG overlay and rendered on top of the content here and on review.
 */

import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useEditor, EditorContent, type Editor } from '@tiptap/react';
import { Bold, Italic, Heading2, Heading3, List, ListOrdered, Image as ImageIcon, ImagePlus, PenLine, Brush } from 'lucide-react';

import { getEditorExtensions } from '@/components/shared/editorExtensions';
import { WriterBubbleMenu } from '@/modules/Writer/components/WriterBubbleMenu';
import { ImageAnnotator } from './ImageAnnotator';
import { CardDrawLayer } from './CardDrawLayer';

// WordPress media library global (wp_enqueue_media() runs in class-pcm-admin.php).
declare const wp: any;

interface CustomCardEditorProps {
  /** Tiptap HTML content. */
  content: string;
  /** Emitted with updated HTML on every change. */
  onChange: (html: string) => void;
  placeholder?: string;
  /** Persistent draw-layer overlay (transparent PNG data-URL), or null. */
  overlay?: string | null;
  /** Emitted when the draw layer is committed/cleared. */
  onOverlayChange?: (url: string | null) => void;
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

export function CustomCardEditor({ content, onChange, placeholder, overlay, onOverlayChange }: CustomCardEditorProps) {
  // Image to annotate (set after the user picks one via the media library).
  const [annotateUrl, setAnnotateUrl] = useState<string | null>(null);
  // Whole-card draw layer.
  const [drawing, setDrawing] = useState(false);
  const [drawDims, setDrawDims] = useState<{ w: number; h: number }>({ w: 0, h: 0 });
  const contentBoxRef = useRef<HTMLDivElement | null>(null);
  const overlayBeforeDraw = useRef<string | null>(null);
  const editorRef = useRef<Editor | null>(null);

  // Insert pasted / dropped image files inline (as base64). Lets you paste an image
  // straight from the clipboard onto the card. Uses a ref because the editor isn't
  // created yet when these extensions are built.
  const insertImageFiles = (files: File[]) => {
    const imageFiles = files.filter((f) => f.type.startsWith('image/'));
    if (imageFiles.length === 0) return;
    // Read every file, then insert ALL images in ONE transaction. Block images are atom
    // NodeSelections, so inserting them one-by-one makes each replace the previous; a single
    // insertContent with an array adds them as a fragment so they all land in order.
    Promise.all(imageFiles.map((file) => new Promise<{ src: string; alt: string } | null>((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result ? { src: String(reader.result), alt: file.name || 'Pasted image' } : null);
      reader.onerror = () => resolve(null);
      reader.readAsDataURL(file);
    }))).then((results) => {
      const nodes = results
        .filter((r): r is { src: string; alt: string } => r !== null)
        .map((r) => ({ type: 'image', attrs: { src: r.src, alt: r.alt } }));
      if (nodes.length) editorRef.current?.chain().focus().insertContent(nodes).run();
    });
  };

  const editor = useEditor({
    // imageActions: false → plain images, no Edit/Regenerate hover overlay in this editor.
    extensions: getEditorExtensions({ placeholder: placeholder ?? 'Write your document…', imageActions: false, onImageFiles: insertImageFiles }),
    content: content || '<p></p>',
    editable: true,
    editorProps: {
      attributes: { class: 'outline-none prose prose-sm max-w-none min-h-[460px] focus:outline-none' },
    },
    onUpdate: ({ editor }: { editor: Editor }) => onChange(editor.getHTML()),
  });

  // Hydrate once the editor is ready (edit mode passes existing content).
  useEffect(() => {
    editorRef.current = editor; // keep the ref current for paste/drop image inserts
    if (editor && content && editor.getHTML() !== content) {
      editor.commands.setContent(content);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editor]);

  // Open the WordPress media library and hand the chosen image URL to a callback.
  // Open the WordPress media library. With `multiple`, the user can pick several images
  // at once (Insert image); single mode is used for Annotate (one image at a time).
  const pickImages = (onPick: (images: { url: string; alt: string }[]) => void, multiple = false) => {
    if (typeof wp === 'undefined' || !wp?.media) return;
    const frame = wp.media({
      title: multiple ? 'Select or upload images' : 'Select or upload image',
      button: { text: multiple ? 'Use images' : 'Use image' },
      multiple,
    });
    frame.on('select', () => {
      const items = frame.state().get('selection').toJSON();
      const images = (Array.isArray(items) ? items : [])
        .filter((a: any) => a?.url)
        .map((a: any) => ({ url: String(a.url), alt: String(a.alt || '') }));
      if (images.length) onPick(images);
    });
    frame.open();
  };

  // Insert all picked images in ONE transaction (as a fragment). Inserting block images one
  // at a time makes each replace the previous (atom NodeSelection), so only one would survive.
  const insertImage = () => pickImages(
    (images) => editor?.chain().focus()
      .insertContent(images.map((img) => ({ type: 'image', attrs: { src: img.url, alt: img.alt } })))
      .run(),
    true,
  );
  // "Add images" — always APPENDS at the end of the document, so it never replaces a selected
  // image. Repeated clicks keep stacking images (array-like), regardless of cursor position.
  const appendImages = () => pickImages((images) => {
    if (!editor) return;
    const nodes = images.map((img) => ({ type: 'image', attrs: { src: img.url, alt: img.alt } }));
    editor.chain().focus().insertContentAt(editor.state.doc.content.size, nodes).run();
  }, true);
  const annotateImage = () => pickImages((images) => setAnnotateUrl(images[0].url));

  // Snapshot the content box size, then open the whole-card draw layer over it.
  const startDraw = () => {
    const box = contentBoxRef.current;
    if (box) setDrawDims({ w: box.offsetWidth, h: Math.max(box.scrollHeight, box.offsetHeight) });
    overlayBeforeDraw.current = overlay ?? null;
    setDrawing(true);
  };

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
        <ToolbarButton title="Add images (append — keeps the existing ones)" onClick={appendImages}><ImagePlus className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Annotate an image" onClick={annotateImage}><PenLine className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Draw on the whole card" active={drawing} onClick={startDraw}><Brush className="h-4 w-4" /></ToolbarButton>
      </div>
      <div className="max-h-[72vh] overflow-y-auto p-4">
        {/* Positioned wrapper so the draw layer + overlay align with the content. */}
        <div ref={contentBoxRef} className="relative">
          <EditorContent editor={editor} />
          {/* Floating formatting toolbar — the same contextual bubble menu as the Writer
              canvas (appears on selection / empty paragraph). Image button reuses the
              multi-select picker; drawing mode hides it to avoid overlapping the canvas. */}
          {!drawing && <WriterBubbleMenu editor={editor} onOpenImagePicker={insertImage} />}
          {/* Saved draw layer, shown on top of the content while not actively drawing. */}
          {overlay && !drawing && (
            <img src={overlay} alt="" aria-hidden className="pointer-events-none absolute inset-0 h-full w-full" />
          )}
          {/* Active whole-card draw layer. */}
          {drawing && (
            <CardDrawLayer
              width={drawDims.w}
              height={drawDims.h}
              initial={overlayBeforeDraw.current}
              onChange={(url) => onOverlayChange?.(url)}
              onDone={() => setDrawing(false)}
              onCancel={() => { onOverlayChange?.(overlayBeforeDraw.current); setDrawing(false); }}
            />
          )}
        </div>
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
