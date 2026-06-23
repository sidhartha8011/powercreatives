/**
 * WRITER MODULE — Review Editor Canvas
 *
 * Central editor area with Tiptap rich-text editor.
 * Formatting is handled via BubbleMenu (floating toolbar on text selection).
 * Top bar contains only: Model dropdown + Generate button.
 *
 * Architecture:
 * - All Tiptap extensions are configured in extensions/editorExtensions.ts (single source of truth).
 * - BubbleMenu provides all formatting tools as a floating contextual toolbar.
 * - Model dropdown reads from writerSelectedModelAtom (synced from Settings defaults).
 * - Generate button triggers article generation using all context from the active document.
 * - Editor syncs with Jotai store bidirectionally: typing updates store, external updates
 *   (e.g. generation) inject content into editor via useEffect.
 * - Typography is handled by `#pcm-root .writer-editor` CSS class (see index.css).
 */

import { useEditor, EditorContent } from '@tiptap/react';
import { useAtom, useAtomValue, useSetAtom } from 'jotai';
import { activeDocumentAtom, updateActiveDocumentAtom, writerSelectedModelAtom } from '../store';
import { colors, typography } from '@/components/shared';
import { useEffect, useCallback, useState } from 'react';
import { trpc, getConfig } from '@/lib/trpc';
import { parseSSEStream } from '@/lib/sse';
import { WRITER_DEFAULTS } from '../writerConfig';
import { toast } from 'sonner';
import { WriterBubbleMenu } from './WriterBubbleMenu';
import { getEditorExtensions } from '../extensions/editorExtensions';
import { Sparkles, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { WriterImagePicker } from './WriterImagePicker';
import { useImageOrchestration } from '../hooks/useImageOrchestration';

declare const wp: any;



export function ReviewEditorCanvas() {
  const [selectedModelId, setSelectedModelId] = useAtom(writerSelectedModelAtom);
  const { data: textModels = [] } = trpc.models.getForGeneration.useQuery({ type: 'text' });
  const doc = useAtomValue(activeDocumentAtom);
  const updateDoc = useSetAtom(updateActiveDocumentAtom);



  const uploadImageMutation = trpc.writer.uploadImage.useMutation();

  // ── Tiptap editor instance — extensions from centralized registry ──
  // shouldRerenderOnTransaction: false → zero-rerender architecture
  // React only re-renders via explicit useEditorState selectors, not on every keystroke.
  const editor = useEditor({
    extensions: getEditorExtensions({
      onImageFiles: async (files) => {
        if (!doc) return;
        const file = files[0];
        if (!file) return;

        const toastId = toast.loading('Uploading image...');

        try {
          // Convert file to Base64 via FileReader (same pattern as useWriterImageUpload)
          const reader = new FileReader();
          const base64 = await new Promise<string>((resolve, reject) => {
            reader.onload = () => resolve(reader.result as string);
            reader.onerror = reject;
            reader.readAsDataURL(file);
          });

          const result = await uploadImageMutation.mutateAsync({
            fileData: base64,
            filename: file.name,
            mimeType: file.type,
          });

          // Insert the server-hosted image URL into the editor
          if (editor) {
            editor.chain().focus().setImage({ src: result.url }).run();
          }

          toast.success('Image uploaded', { id: toastId });
        } catch (err) {
          toast.error('Failed to upload image', { id: toastId });
        }
      }
    }),
    content: doc ? doc.content : '',
    shouldRerenderOnTransaction: false,
    onUpdate: ({ editor }) => {
      if (doc) updateDoc({ content: editor.getHTML() });
    },
    editorProps: {
      attributes: {
        class: 'writer-editor focus:outline-none',
        /* Padding and min-height controlled via CSS (#pcm-root .writer-editor) */
      },
    },
  });

  // Attach asynchronous image orchestrator (scans generationSettings.mediaManifest and processes pending images)
  useImageOrchestration({ editor, brandId: doc?.generationSettings?.brandId });


  // ── SSE-based generation state ──
  const [isGenerating, setIsGenerating] = useState(false);

  /**
   * Process the final article payload from the SSE 'done' event.
   * Replaces [MEDIA:image:XX] placeholders with skeleton <img> tags
   * and persists mediaManifest for the image orchestration pipeline.
   */
  const handleGenerationResult = useCallback((data: any) => {
    // Pre-process HTML to replace [MEDIA:image:XX] with loading skeleton <img>
    let formattedContent = data.content ?? '';

    const skeletonSvg = `data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='400'%3E%3Crect width='100%25' height='100%25' fill='%23e2e8f0'/%3E%3Ctext x='50%25' y='50%25' font-family='sans-serif' font-size='24' fill='%2364748b' text-anchor='middle' dy='.3em'%3EGenerating AI Image...%3C/text%3E%3C/svg%3E`;

    formattedContent = formattedContent.replace(
      /\[MEDIA:image:(\d+)\]/g,
      `<img src="${skeletonSvg}" data-media-id="$1" class="pcm-skeleton" alt="Generating image $1" />`
    );

    const docUpdate: any = {
      title: data.title ?? doc?.title,
      content: formattedContent,
      metaTitle: data.metaTitle ?? '',
      metaDescription: data.metaDescription ?? '',
    };

    // Persist mediaManifest for downstream image pipeline consumption
    if (data.mediaManifest) {
      docUpdate.generationSettings = {
        ...doc?.generationSettings,
        mediaManifest: data.mediaManifest,
      };
    }

    updateDoc(docUpdate);
    toast.success('Article generated successfully!');
  }, [doc, updateDoc]);

  // ── Generate handler — SSE streaming via fetch + parseSSEStream ──
  const handleGenerate = useCallback(async () => {
    if (!doc) return;
    const s = doc.generationSettings || {};

    if (!s.primaryKeyword) {
      toast.error('Primary keyword is required. Configure it in the Settings panel.');
      return;
    }

    setIsGenerating(true);

    try {
      const config = getConfig();
      const url = `${config.restUrl}articles/generate`;

      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce,
        },
        body: JSON.stringify({
          primaryKeyword: s.primaryKeyword,
          supportingKeywords: s.supportingKeywords || '',
          writerPrompt: s.writerPrompt || s.customPromptText || '',
          brandId: s.brandId || null,
          templateId: s.templateId || null,
          format: s.format || WRITER_DEFAULTS.format,
          perspective: s.perspective || WRITER_DEFAULTS.perspective,
          toneOfVoice: s.toneOfVoice || WRITER_DEFAULTS.toneOfVoice,
          siteUrl: s.targetSiteUrl || '',
          modelId: selectedModelId !== 'auto' ? selectedModelId : null,
          imageCount: s.imageCount || '0',
          imageStyle: s.imageStyle || 'auto',
          imageModel: s.imageModel || 'auto',
        }),
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      // Consume SSE stream — dispatch on event type
      await parseSSEStream(response, (event, payload) => {
        switch (event) {
          case 'done':
            // The 'result' field from PCM_SSE::send_done($result)
            handleGenerationResult(payload.result);
            break;
          case 'error':
            toast.error(payload.message || 'Generation failed');
            break;
          // 'token' events are heartbeats — ignored in this phase
          default:
            break;
        }
      });
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Generation failed';
      toast.error(message);
      console.error('[WriterGeneration] SSE generation failed:', err);
    } finally {
      setIsGenerating(false);
    }
  }, [doc, selectedModelId, handleGenerationResult]);

  // ── Image Picker State & Handlers ──
  const [isImagePickerOpen, setIsImagePickerOpen] = useState(false);
  const [imageContext, setImageContext] = useState('');

  const handleOpenImagePicker = useCallback(() => {
    if (!editor) return;

    // Extract context for AI suggestions based on cursor position
    const { $from } = editor.state.selection;
    let contextText = '';

    // Walk up the tree to find the nearest block node
    const blockNode = $from.node(1) || $from.parent;
    if (blockNode && blockNode.isBlock) {
      contextText = blockNode.textContent;
    }

    // If context is too short, try grabbing the preceding node
    if (contextText.length < 50 && $from.index(0) > 0) {
      const prevNode = editor.state.doc.child($from.index(0) - 1);
      if (prevNode && prevNode.isBlock) {
        contextText = prevNode.textContent + ' ' + contextText;
      }
    }

    setImageContext(contextText.trim());
    setIsImagePickerOpen(true);
  }, [editor]);

  const handleOpenWP = useCallback(() => {
    setIsImagePickerOpen(false); // Close our modal
    if (typeof wp === 'undefined' || !wp.media) {
      const inputInfo = prompt('Insert image URL (WP Media not available):');
      if (inputInfo) {
        editor?.chain().focus().setImage({ src: inputInfo }).run();
      }
      return;
    }

    const frame = wp.media({
      title: 'Select or Upload Image',
      button: { text: 'Insert into article' },
      multiple: false,
      library: { type: 'image' }
    });

    frame.on('select', () => {
      const attachment = frame.state().get('selection').first().toJSON();
      if (attachment && attachment.url) {
        editor?.chain().focus().setImage({ src: attachment.url, alt: attachment.alt || '' }).run();
      }
    });

    frame.open();
  }, [editor]);

  const handleInsertImage = useCallback((url: string) => {
    if (url && editor) {
      editor.chain().focus().setImage({ src: url }).run();
    }
  }, [editor]);

  // Sync editor content when active document changes OR content is updated externally (e.g. generation)
  useEffect(() => {
    if (editor && doc) {
      if (editor.getHTML() !== doc.content) {
        editor.commands.setContent(doc.content);
      }
    }
  }, [doc?.id, doc?.content, editor]);

  // ── Empty state ──
  if (!doc || !editor) {
    return (
      <div className="h-full flex items-center justify-center" style={{ background: colors.bgPage }}>
        <span style={{ fontSize: typography.sm, color: colors.textMuted }}>No document selected</span>
      </div>
    );
  }

  const isPending = isGenerating;
  const canGenerate = !!doc.generationSettings?.primaryKeyword;

  return (
    <div className="h-full flex flex-col overflow-hidden" style={{ background: colors.bgPage }}>
      {/* ── Minimal Top Bar — Model selector + Generate only ──────── */}
      <div
        className="px-4 py-2 shrink-0 flex items-center justify-end gap-2"
        style={{ borderBottom: `1px solid ${colors.borderLight}`, background: colors.bgSurface }}
      >
        {/* Model dropdown */}
        <Select value={selectedModelId} onValueChange={setSelectedModelId}>
          <SelectTrigger
            className="bg-white"
            style={{
              height: 28,
              fontSize: typography.xs,
              borderColor: colors.borderLight,
              width: 160,
            }}
          >
            <SelectValue placeholder="Select Model" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="auto">Auto (Default)</SelectItem>
            {textModels.map((model: any) => (
              <SelectItem key={model.id} value={model.modelId}>
                {model.customName || model.originalName}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {/* Generate button */}
          <Button
            size="sm"
            className="h-8 gap-1.5 text-xs"
            onClick={handleGenerate}
            disabled={isPending || !canGenerate}
          >
            {isPending ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Sparkles className="w-3.5 h-3.5" />}
            {isPending ? 'Generating…' : 'Generate'}
          </Button>
      </div>

      {/* ── Editor Canvas Area ───────────────────────────── */}
      {/* Scroll container — paper grows with content, never clips to viewport */}
      <div className="flex-1 overflow-y-auto flex justify-center" style={{ paddingTop: 32, paddingBottom: 32, paddingLeft: 24, paddingRight: 24 }}>
        {/* Paper surface — expands infinitely with editor content */}
        <div
          className="w-full rounded-md"
          style={{
            maxWidth: 820,
            background: colors.bgSurface,
            border: `1px solid ${colors.borderLight}`,
            boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
            /* No minHeight: '100%' — that caps to viewport. Paper grows with content. */
            alignSelf: 'start', /* Prevents flex stretch from capping height */
          }}
        >
          {editor && <WriterBubbleMenu editor={editor} onOpenImagePicker={handleOpenImagePicker} />}
          <EditorContent editor={editor} />
        </div>

        {/* ── Image Picker Modal ── */}
        {editor && (
          <WriterImagePicker
            isOpen={isImagePickerOpen}
            onClose={() => setIsImagePickerOpen(false)}
            onOpenWP={handleOpenWP}
            onInsertImage={handleInsertImage}
            contextText={imageContext}
          />
        )}
      </div>
    </div>
  );
}
