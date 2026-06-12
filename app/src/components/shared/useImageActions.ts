/**
 * SHARED — Image Actions Hook
 *
 * Generic hook for image regeneration and editing within any Tiptap editor.
 * Calls the existing image.generate and image.editImage APIs.
 * Used by ImageNodeView overlay — no module-specific logic.
 *
 * Architecture: ALL logic lives here. UI components (ImageOverlay) are dumb.
 *
 * @package PowerCreatives
 * @module  Shared
 */

import { useState, useCallback } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import type { Editor } from '@tiptap/react';

export interface ImageModel {
  id?: string | number;
  modelId: string;
  provider: string;
  customName?: string;
  originalName?: string;
}

export interface UseImageActionsReturn {
  /** Available image generation models */
  imageModels: ImageModel[];
  /** Currently selected model ID */
  selectedModel: string;
  /** Update selected model */
  setSelectedModel: (modelId: string) => void;
  /** Regenerate an image at the given node position */
  regenerate: (editor: Editor, pos: number, additionalPrompt?: string) => Promise<void>;
  /** Edit an image with instructions */
  editImage: (editor: Editor, pos: number, instructions: string) => Promise<void>;
  /** Whether a generation/edit is in progress */
  isProcessing: boolean;
}

/**
 * Hook providing image regeneration and editing actions for Tiptap editors.
 * Fetches available image models and exposes regenerate/edit callbacks.
 */
export function useImageActions(): UseImageActionsReturn {
  const [isProcessing, setIsProcessing] = useState(false);
  const { data: imageModels = [] } = trpc.models.getForGeneration.useQuery({ type: 'image' });
  const [selectedModel, setSelectedModel] = useState<string>('');

  const generateMutation = trpc.image.generate.useMutation();
  const editMutation = trpc.image.editImage.useMutation();
  // Async pair for Kie.ai models — the blocking generate/edit requests get
  // killed by shared hosts' timeouts on slow models. Create task → poll.
  const createTaskMutation = trpc.image.createTask.useMutation();
  const createEditTaskMutation = trpc.image.createEditTask.useMutation();
  const taskResultMutation = trpc.image.taskResult.useMutation();

  /**
   * Run a Kie.ai task to completion: create it via `create`, then poll
   * /image/task-result every 5s (12-min cap, tolerates 2 transient poll
   * errors). Resolves to the stored asset ({ url, ... }).
   */
  const runKieTask = useCallback(async (
    create: () => Promise<{ taskId: string; prompt?: string }>,
    pollPayload: Record<string, unknown>,
  ): Promise<{ url?: string }> => {
    const task = await create();
    const deadline = Date.now() + 12 * 60_000;
    let pollErrors = 0;
    while (Date.now() < deadline) {
      await new Promise((r) => setTimeout(r, 5000));
      let poll: any;
      try {
        poll = await taskResultMutation.mutateAsync({
          ...pollPayload,
          prompt: task.prompt ?? pollPayload.prompt,
          taskId: task.taskId,
        });
      } catch (pollError) {
        if (++pollErrors >= 3) throw pollError;
        continue;
      }
      pollErrors = 0;
      if (poll.status === 'completed') return poll.asset as { url?: string };
      if (poll.status === 'failed') throw new Error(poll.error || 'Generation failed.');
    }
    throw new Error('Timed out after 12 minutes — the task may still finish on Kie.ai.');
  }, [taskResultMutation]);

  /**
   * Replace an image node at `pos` with a new URL.
   * Re-reads state fresh to avoid stale ProseMirror positions.
   */
  const replaceImageAtPos = useCallback((
    editor: Editor,
    pos: number,
    newUrl: string,
    altText?: string,
    newOriginalPrompt?: string
  ) => {
    const { doc, tr } = editor.state;
    const node = doc.nodeAt(pos);
    if (!node || node.type.name !== 'image') return;

    tr.setNodeMarkup(pos, null, {
      ...node.attrs,
      src: newUrl,
      alt: altText || node.attrs.alt || '',
      'data-original-prompt': newOriginalPrompt || node.attrs['data-original-prompt'] || '',
    });
    editor.view.dispatch(tr);
  }, []);

  /**
   * Regenerate the image at `pos` using the same or modified prompt.
   * Optionally appends additional instructions to the original prompt.
   */
  const regenerate = useCallback(async (editor: Editor, pos: number, additionalPrompt?: string) => {
    const node = editor.state.doc.nodeAt(pos);
    if (!node || node.type.name !== 'image') return;

    // Resolve model + provider from selection or first available
    const modelObj = imageModels.find((m) => m.modelId === selectedModel) || imageModels[0];
    if (!modelObj) {
      toast.error('No image model available. Check Settings → Integrations.');
      return;
    }

    // Build prompt: prioritize the stored original detailed prompt, fallback to alt text
    const basePrompt = node.attrs['data-original-prompt'] || node.attrs.alt || 'A professional image';
    const finalPrompt = additionalPrompt
      ? `${basePrompt}. Additional instructions: ${additionalPrompt}`
      : basePrompt;

    setIsProcessing(true);
    try {
      const payload = {
        prompt: finalPrompt,
        model: modelObj.modelId,
        provider: modelObj.provider,
      };
      const result = modelObj.provider === 'kieai'
        ? await runKieTask(
            () => createTaskMutation.mutateAsync(payload) as Promise<{ taskId: string; prompt?: string }>,
            payload,
          )
        : await generateMutation.mutateAsync(payload) as { url?: string };

      if (result?.url) {
        replaceImageAtPos(editor, pos, result.url, node.attrs.alt, finalPrompt);
        toast.success('Image regenerated!');
      }
    } catch (error) {
      const msg = error instanceof Error ? error.message : 'Regeneration failed.';
      toast.error(msg);
    } finally {
      setIsProcessing(false);
    }
  }, [imageModels, selectedModel, generateMutation, createTaskMutation, runKieTask, replaceImageAtPos]);

  /**
   * Edit the image at `pos` using the image.editImage API.
   * Sends the current image URL + edit instructions.
   */
  const editImage = useCallback(async (editor: Editor, pos: number, instructions: string) => {
    const node = editor.state.doc.nodeAt(pos);
    if (!node || node.type.name !== 'image') return;

    const imageUrl = node.attrs.src;
    if (!imageUrl || imageUrl.startsWith('data:')) {
      toast.error('Cannot edit a placeholder image. Generate it first.');
      return;
    }

    const modelObj = imageModels.find((m) => m.modelId === selectedModel) || imageModels[0];
    if (!modelObj) {
      toast.error('No image model available.');
      return;
    }

    setIsProcessing(true);
    try {
      const payload = {
        imageUrl,
        prompt: instructions,
        model: modelObj.modelId,
        provider: modelObj.provider,
      };
      const result = modelObj.provider === 'kieai'
        ? await runKieTask(
            () => createEditTaskMutation.mutateAsync(payload) as Promise<{ taskId: string; prompt?: string }>,
            { ...payload, storageContext: 'image-edit' },
          )
        : await editMutation.mutateAsync(payload) as { url?: string };

      if (result?.url) {
        replaceImageAtPos(editor, pos, result.url, node.attrs.alt);
        toast.success('Image edited!');
      }
    } catch (error) {
      const msg = error instanceof Error ? error.message : 'Edit failed.';
      toast.error(msg);
    } finally {
      setIsProcessing(false);
    }
  }, [imageModels, selectedModel, editMutation, createEditTaskMutation, runKieTask, replaceImageAtPos]);

  return {
    imageModels,
    selectedModel,
    setSelectedModel,
    regenerate,
    editImage,
    isProcessing,
  };
}
