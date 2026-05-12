import { useEffect, useRef, useState, useCallback } from 'react';
import { trpc } from '@/lib/trpc';
import { Editor } from '@tiptap/react';
import { toast } from 'sonner';
import { useAtomValue } from 'jotai';
import { activeDocumentAtom } from '../store';

export interface UseImageOrchestrationProps {
  editor: Editor | null;
  brandId?: number;
}

/**
 * Orchestrates image generation for Writer articles.
 *
 * After article generation, the mediaManifest contains entries for each
 * planned image. This hook processes them SEQUENTIALLY to avoid
 * ProseMirror position invalidation that occurs with parallel dispatches.
 *
 * ISOLATION STRATEGY:
 * All state keys (completedImages, processingIds) are namespaced with
 * the document ID: `${docId}::${mediaId}`. This ensures complete isolation
 * between documents without fragile reset logic. Switching documents
 * naturally scopes lookups to the active document's keys.
 *
 * Flow:
 *   1. mediaManifest arrives via Jotai store
 *   2. For each manifest entry: resolve model/provider → call generateSingle → replace skeleton
 *   3. Alt-text from LLM manifest is applied during replacement (SEO)
 */
export function useImageOrchestration({ editor, brandId }: UseImageOrchestrationProps) {
  const activeDocument = useAtomValue(activeDocumentAtom);
  const mediaManifest = activeDocument?.generationSettings?.mediaManifest || [];
  const docId = activeDocument?.id ?? '';

  /**
   * Build a document-scoped key for state lookups.
   * Ensures images from Doc A ("01") never collide with Doc B ("01").
   */
  const scopedKey = useCallback((mediaId: string) => `${docId}::${mediaId}`, [docId]);

  // Completed images — keyed by `docId::mediaId` for cross-document isolation
  const [completedImages, setCompletedImages] = useState<Record<string, string>>({});

  // Processing IDs — namespaced Set to prevent duplicate API calls
  const processingIds = useRef<Set<string>>(new Set());

  // Sequential pipeline lock — keyed per document to allow parallel document processing
  const runningDocRef = useRef<string | null>(null);

  const generateSingleMutation = trpc.image.generateSingle.useMutation();
  const { data: imageModels } = trpc.models.getForGeneration.useQuery({ type: 'image' });

  /**
   * Replace a skeleton <img> with the real generated image URL.
   * Uses a FRESH editor.state on each call to ensure positions are accurate
   * after previous dispatches have been applied.
   */
  const replaceSkeletonWithImage = useCallback((
    editor: Editor,
    mediaId: string,
    url: string,
    altText?: string
  ) => {
    const { doc, tr } = editor.state;
    let posToUpdate: number | null = null;
    let nodeToUpdate: any = null;

    doc.descendants((node, pos) => {
      if (node.type.name === 'image' && node.attrs['data-media-id'] === mediaId) {
        posToUpdate = pos;
        nodeToUpdate = node;
        return false;
      }
    });

    if (posToUpdate !== null && nodeToUpdate) {
      tr.setNodeMarkup(posToUpdate, null, {
        ...nodeToUpdate.attrs,
        src: url,
        alt: altText || nodeToUpdate.attrs.alt || '',
        class: 'pcm-generated-image',
        'data-media-id': undefined,  // Clear so it won't be matched again
      });
      editor.view.dispatch(tr);
    }
  }, []);

  /**
   * Remove skeleton on error to prevent stuck UI.
   */
  const removeSkeletonOnError = useCallback((editor: Editor, mediaId: string) => {
    const { doc, tr } = editor.state;
    let posToUpdate: number | null = null;

    doc.descendants((node, pos) => {
      if (node.type.name === 'image' && node.attrs['data-media-id'] === mediaId) {
        posToUpdate = pos;
        return false;
      }
    });

    if (posToUpdate !== null) {
      tr.setNodeMarkup(posToUpdate, null, {
        ...editor.state.doc.resolve(posToUpdate).nodeAfter?.attrs,
        src: '',
        class: 'pcm-image-error',
        alt: 'Image generation failed',
        'data-media-id': undefined,
      });
      editor.view.dispatch(tr);
    }
  }, []);

  useEffect(() => {
    if (!editor || !docId || !mediaManifest || mediaManifest.length === 0 || !imageModels) return;

    // Filter to pending items using document-scoped keys
    const pendingItems = mediaManifest.filter((item: any) => {
      const key = scopedKey(item.id);
      return !completedImages[key] && !processingIds.current.has(key);
    });

    // Skip if nothing to do, or this document's pipeline is already running
    if (pendingItems.length === 0 || runningDocRef.current === docId) return;

    // Process images SEQUENTIALLY to avoid ProseMirror position invalidation.
    // Completion of image N changes node positions, making image N+1's pos stale.
    const processSequentially = async () => {
      runningDocRef.current = docId;

      for (const item of pendingItems) {
        const mediaId = item.id;
        const key = scopedKey(mediaId);

        // Double-check: wasn't processed while awaiting previous image
        if (completedImages[key] || processingIds.current.has(key)) continue;

        processingIds.current.add(key);

        try {
          // Resolve model — 'auto' sentinel → first available from integrations
          const rawModelId = (item.requested_model && item.requested_model !== 'auto')
            ? item.requested_model
            : (activeDocument?.generationSettings?.imageModel && activeDocument.generationSettings.imageModel !== 'auto')
              ? activeDocument.generationSettings.imageModel
              : null;

          const firstAvailable = imageModels?.[0];
          const modelObj = rawModelId
            ? imageModels?.find((m: any) => m.modelId === rawModelId) || firstAvailable
            : firstAvailable;

          const modelId = modelObj?.modelId || firstAvailable?.modelId || 'imagen-4';
          const provider = modelObj?.provider || firstAvailable?.provider || 'google';

          const styleId = (item.requested_style && item.requested_style !== 'auto')
            ? item.requested_style
            : activeDocument?.generationSettings?.imageStyle || 'auto';

          const response = await generateSingleMutation.mutateAsync({
            prompt: item.prompt,
            model: modelId,
            provider,
            style: styleId,
          }) as { url: string };

          // Replace skeleton in Tiptap with real image + SEO alt-text
          if (response?.url) {
            setCompletedImages(prev => ({ ...prev, [key]: response.url }));
            replaceSkeletonWithImage(editor, mediaId, response.url, item.alt_text);
          }
        } catch (error) {
          console.error(`[ImageOrchestration] Failed ${key}:`, error);
          toast.error(`Image generation failed for image ${mediaId}`);
          removeSkeletonOnError(editor, mediaId);
          // Mark as "completed" (with error) to prevent infinite retries
          setCompletedImages(prev => ({ ...prev, [key]: '__error__' }));
        } finally {
          processingIds.current.delete(key);
        }
      }

      // Only clear lock if this is still the running document
      if (runningDocRef.current === docId) {
        runningDocRef.current = null;
      }
    };

    processSequentially();
  }, [mediaManifest, editor, docId, imageModels, completedImages, scopedKey, replaceSkeletonWithImage, removeSkeletonOnError]);

  // Return only this document's completed images (filtered by docId prefix)
  const docCompletedImages = Object.fromEntries(
    Object.entries(completedImages)
      .filter(([k]) => k.startsWith(`${docId}::`))
      .map(([k, v]) => [k.split('::')[1], v])
  );

  return { processingIds: Array.from(processingIds.current), completedImages: docCompletedImages };
}
