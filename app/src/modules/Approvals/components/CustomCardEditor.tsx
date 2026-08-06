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
import { Image as ImageIcon, PenLine, Brush, ListChecks } from 'lucide-react';
import { toast } from 'sonner';

import { useImageUpload } from '@/hooks/useImageUpload';
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
  /**
   * Drop this editor's own card chrome — its border, background and inner
   * scroll box — because the surface it is placed on already provides them.
   *
   * Used by the opened approval card, which renders this editor on the Notion
   * sheet: with the chrome on it reads as a card inside a card, and the inner
   * `max-h` box makes a second scrollbar next to the sheet's own.
   */
  bare?: boolean;
  /**
   * Whether the document can be changed. `false` renders the same document with
   * the toolbar, the bubble menu and the annotation tools withheld.
   *
   * ONE instance serving both modes, rather than a second read-only Tiptap
   * component beside this one — that is how the surfaces drift apart, and it is
   * the pattern every Notion-style editor uses. The saved draw layer still
   * renders in read-only mode; only the tools to change it are gone.
   */
  editable?: boolean;
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

export function CustomCardEditor({ content, onChange, placeholder, overlay, onOverlayChange, bare = false, editable = true }: CustomCardEditorProps) {
  // Image to annotate. `pos` is the document position of an EXISTING image (paint bakes back
  // into it in place); `pos: null` means a freshly picked image that gets inserted at the cursor.
  const [annotate, setAnnotate] = useState<{ url: string; pos: number | null } | null>(null);
  // Whole-card draw layer.
  const [drawing, setDrawing] = useState(false);
  const [drawDims, setDrawDims] = useState<{ w: number; h: number }>({ w: 0, h: 0 });
  const contentBoxRef = useRef<HTMLDivElement | null>(null);
  const overlayBeforeDraw = useRef<string | null>(null);
  const editorRef = useRef<Editor | null>(null);
  /**
   * The live canvas while drawing, held in memory only.
   *
   * `CardDrawLayer` emits after every stroke. Those emissions stay here and are
   * uploaded ONCE when the session ends — a drawing is one file, not one per
   * stroke. The strokes are visible the whole time because the canvas itself is
   * on screen; nothing needs to round-trip to show them.
   */
  const pendingOverlay = useRef<string | null>(null);

  /**
   * The ONE route an image takes out of this editor and into storage.
   *
   * Shared with the Writer's canvas. Every image this card can produce — pasted,
   * dropped, annotated, or drawn — goes through here, so a data URL can never
   * reach the document again. `compress: false` on the data-URL path: canvas
   * output is already a finished PNG and a second lossy pass would soften the
   * strokes someone just drew.
   */
  const { uploadImage, uploadDataUrl } = useImageUpload();

  /**
   * Insert pasted / dropped images — UPLOADED, never embedded.
   *
   * These used to be read straight to base64 and dropped into the document. A
   * card then weighed half a megabyte, and worse: WordPress strips `data:` from
   * any `src` on save, so the image lost its source, Tiptap dropped the node,
   * and the next save wrote the emptied document over the real one.
   *
   * They now go to the media library through the shared `useImageUpload` hook —
   * the same route the Writer's canvas uses — and the document stores a URL.
   * Uses a ref because the editor isn't created yet when the extensions build.
   */
  const insertImageFiles = (files: File[]) => {
    const imageFiles = files.filter((f) => f.type.startsWith('image/'));
    if (imageFiles.length === 0) return;

    const toastId = toast.loading(imageFiles.length > 1 ? 'Uploading images…' : 'Uploading image…');
    // Upload all, then insert in ONE transaction. Block images are atom
    // NodeSelections, so inserting them one-by-one makes each replace the
    // previous; a single insertContent with an array keeps them in order.
    Promise.all(imageFiles.map(async (file) => {
      try {
        return { src: await uploadImage(file), alt: file.name || 'Pasted image' };
      } catch {
        return null;
      }
    })).then((results) => {
      const nodes = results
        .filter((r): r is { src: string; alt: string } => r !== null)
        .map((r) => ({ type: 'image', attrs: { src: r.src, alt: r.alt } }));
      if (nodes.length) {
        editorRef.current?.chain().focus().insertContent(nodes).run();
        toast.success(nodes.length > 1 ? `${nodes.length} images added` : 'Image added', { id: toastId });
      } else {
        toast.error('Could not upload the image.', { id: toastId });
      }
    });
  };

  const editor = useEditor({
    // imageActions: false → plain images, no Edit/Regenerate hover overlay in this editor.
    extensions: getEditorExtensions({ placeholder: placeholder ?? 'Write your document…', imageActions: false, onImageFiles: insertImageFiles }),
    content: content || '<p></p>',
    editable,
    editorProps: {
      // `pcm-card-editor` carries the document typography (index.css); the
      // NOTE: the Tailwind `prose` classes used before were inert — the typography
      // plugin isn't loaded, so content rendered at unstyled browser defaults
      // (oversized, bloaty).
      // `max-w-none` REMOVED. Tailwind is imported with the `important` flag
      // (index.css:8), so `.max-w-none { max-width: none !important }` beat
      // `.pcm-card-editor { max-width: var(--pcm-doc-measure) }` and the centred
      // measure never applied — MEASURED in the browser as max-width "none" on a
      // 739px-wide editor. The measure now actually takes effect.
      //
      // NO `min-h-[460px]`. It was a writing floor for the full-window dialog,
      // and on the opened card it reserved 460px of blank body under a two-line
      // checklist. The surface grows to its content on both.
      attributes: { class: 'pcm-card-editor outline-none focus:outline-none' },
      // Double-click an image to paint directly ON it. Strokes are flattened INTO the image
      // (ImageAnnotator), so the annotation stays attached and scales with the image — it never
      // stretches or drifts when the layout reflows, unlike the whole-card draw overlay.
      handleDoubleClickOn: (_view, _pos, node, nodePos) => {
        if (node.type.name === 'image' && node.attrs?.src) {
          setAnnotate({ url: String(node.attrs.src), pos: nodePos });
          return true;
        }
        return false;
      },
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

  // Flip the mode in place. Remounting would rebuild the whole document and
  // throw away the caret, so the same instance changes what it allows.
  useEffect(() => { editor?.setEditable(editable); }, [editor, editable]);

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
    (images) => {
      if (!editor) return;
      const nodes = images.map((img) => ({ type: 'image', attrs: { src: img.url, alt: img.alt } }));
      // Insert at the END of the current selection so a highlighted range or a selected image is
      // never replaced — repeated inserts (multi-select) keep stacking images in order.
      editor.chain().focus().insertContentAt(editor.state.selection.to, nodes).run();
    },
    true,
  );
  // Annotate an image: if one is already selected in the editor, paint on THAT image in place
  // (strokes bake into it, so they stay attached and never stretch); otherwise pick one from the
  // media library and insert the annotated copy at the cursor.
  const annotateImage = () => {
    const sel = editor?.state.selection as { node?: { type: { name: string }; attrs: { src?: string } }; from: number } | undefined;
    if (sel?.node?.type?.name === 'image' && sel.node.attrs?.src) {
      setAnnotate({ url: String(sel.node.attrs.src), pos: sel.from });
      return;
    }
    pickImages((images) => setAnnotate({ url: images[0].url, pos: null }));
  };

  // Snapshot the content box size, then open the whole-card draw layer over it.
  const startDraw = () => {
    const box = contentBoxRef.current;
    if (box) setDrawDims({ w: box.offsetWidth, h: Math.max(box.scrollHeight, box.offsetHeight) });
    overlayBeforeDraw.current = overlay ?? null;
    pendingOverlay.current = overlay ?? null;
    setDrawing(true);
  };
  // Toggle paint mode. Clicking the active Brush button de-activates paint and keeps whatever was
  // drawn — the same as pressing "Done", so the two routes out cannot disagree.
  const toggleDraw = () => { if (drawing) finishDraw(); else startDraw(); };

  /**
   * The draw layer finished — upload the strokes and hand back a URL.
   *
   * `CardDrawLayer` emits canvas output (a data URL) live on every stroke. Only
   * the FINAL layer is uploaded, on done/cancel, so a drawing session is one
   * upload rather than one per stroke. `null` means the layer was cleared and
   * passes straight through.
   */
  const commitOverlay = (dataUrl: string | null) => {
    if (!onOverlayChange) return;
    if (!dataUrl) { onOverlayChange(null); return; }
    if (!dataUrl.startsWith('data:')) { onOverlayChange(dataUrl); return; } // already hosted
    const toastId = toast.loading('Saving drawing…');
    uploadDataUrl(dataUrl, `card-drawing-${Date.now()}.png`)
      .then((url) => { onOverlayChange(url); toast.success('Drawing saved', { id: toastId }); })
      .catch(() => toast.error('Could not save the drawing.', { id: toastId }));
  };

  /** Leave paint mode, keeping the strokes. One upload, at the end. */
  const finishDraw = () => {
    commitOverlay(pendingOverlay.current);
    setDrawing(false);
  };

  if (!editor) return null;

  return (
    <div className={bare ? '' : 'rounded-lg border border-border bg-card'}>
      {/* Top settings bar — IMAGE + PAINT tools only. All text formatting lives in the
          contextual bubble menu, which appears when the user selects text.
          Withheld entirely when the document may not be changed: a disabled row
          of buttons is chrome that explains nothing. */}
      {editable && (
      <div className={`flex flex-wrap items-center gap-1 p-1.5 ${bare ? '' : 'border-b border-border'}`}>
        <ToolbarButton title="Insert image" onClick={insertImage}><ImageIcon className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title="Annotate an image — select an image (or double-click it) to paint directly on it" onClick={annotateImage}><PenLine className="h-4 w-4" /></ToolbarButton>
        <ToolbarButton title={drawing ? 'Stop drawing' : 'Draw on the whole card'} active={drawing} onClick={toggleDraw}><Brush className="h-4 w-4" /></ToolbarButton>
        {/* Checklist. The node type ships in @tiptap/extension-list, already in
            the tree via starter-kit — it was simply never registered, which is
            why a checklist could not be made at all. Typing "[ ] " also works
            (TipTap's input rule), so the interaction reveals itself; this button
            is the discoverable route, not a hint. */}
        <ToolbarButton
          title="Checklist"
          active={editor?.isActive('taskList')}
          onClick={() => editor?.chain().focus().toggleTaskList().run()}
        >
          <ListChecks className="h-4 w-4" />
        </ToolbarButton>
      </div>
      )}
      <div className={bare ? '' : 'max-h-[72vh] overflow-y-auto p-4'}>
        {/* Positioned wrapper so the draw layer + overlay align with the content. */}
        <div ref={contentBoxRef} className="relative">
          <EditorContent editor={editor} />
          {/* Floating formatting toolbar — the same contextual bubble menu as the Writer canvas,
              but `selectionOnly` so it appears ONLY when the user selects text (never on empty
              lines). Image button reuses the picker; drawing mode hides it to avoid overlap. */}
          {editable && !drawing && <WriterBubbleMenu editor={editor} onOpenImagePicker={insertImage} selectionOnly />}
          {/* Saved draw layer, shown on top of the content while not actively drawing. */}
          {overlay && !drawing && (
            <img src={overlay} alt="" aria-hidden className="pointer-events-none absolute inset-x-0 top-0 w-full" />
          )}
          {/* Active whole-card draw layer. */}
          {drawing && (
            <CardDrawLayer
              width={drawDims.w}
              height={drawDims.h}
              initial={overlayBeforeDraw.current}
              /* Buffered, not saved. The canvas is on screen, so the strokes are
                 already visible; uploading here would mean one file per stroke. */
              onChange={(url) => { pendingOverlay.current = url; }}
              onDone={finishDraw}
              onCancel={() => { onOverlayChange?.(overlayBeforeDraw.current); setDrawing(false); }}
            />
          )}
        </div>
      </div>

      {/* Annotation: draw on the picked image, upload the flattened PNG, insert its URL.
          The annotator hands back canvas output — a data URL. That must not reach
          the document: WordPress strips `data:` from `src` on save, which left the
          image source-less and then emptied the card. It is uploaded first, and
          only the hosted URL is written into the document. */}
      <ImageAnnotator
        open={!!annotate}
        imageUrl={annotate?.url ?? null}
        onCancel={() => setAnnotate(null)}
        onInsert={(dataUrl) => {
          const pos = annotate?.pos ?? null;
          setAnnotate(null);
          const toastId = toast.loading('Saving annotation…');
          uploadDataUrl(dataUrl, `annotated-${Date.now()}.png`)
            .then((src) => {
              if (pos != null) {
                // Replace the annotated image IN PLACE (same document position) so the baked-in
                // strokes stay attached to that image — no duplicate inserted at the cursor.
                editor.chain().focus().command(({ tr }) => {
                  const node = tr.doc.nodeAt(pos);
                  if (!node || node.type.name !== 'image') return false;
                  tr.setNodeMarkup(pos, undefined, { ...node.attrs, src, alt: 'Annotated image' });
                  return true;
                }).run();
              } else {
                editor.chain().focus().setImage({ src, alt: 'Annotated image' }).run();
              }
              toast.success('Annotation saved', { id: toastId });
            })
            .catch(() => toast.error('Could not save the annotation.', { id: toastId }));
        }}
      />
    </div>
  );
}
