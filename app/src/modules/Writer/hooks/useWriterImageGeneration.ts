import { useState, useCallback, useEffect } from 'react';
import { trpc } from '@/lib/trpc';
import { runKieTask } from '@/lib/kieTask';
import { toast } from 'sonner';
import { STYLE_PRESETS, ASPECT_RATIOS } from '@/modules/Image/imageConfig';

interface GenerateImageResult {
    url?: string;
    id?: string | number;
    [key: string]: unknown;
}

interface SuggestionResult {
    suggestions?: string[];
    [key: string]: unknown;
}

export interface UseWriterImageGenerationReturn {
    prompt: string;
    setPrompt: (p: string) => void;
    selectedStyle: string;
    setSelectedStyle: (s: string) => void;
    selectedRatio: string;
    setSelectedRatio: (r: string) => void;
    selectedModel: string;
    setSelectedModel: (m: string) => void;
    imageModels: Array<{ id: string; modelId: string; provider: string; customName?: string; originalName?: string }>;
    isGenerating: boolean;
    generatedImages: string[];
    setGeneratedImages: (imgs: string[]) => void;
    generateImage: () => Promise<void>;
    suggestPrompt: (contextText: string) => Promise<void>;
    isSuggesting: boolean;
}

export function useWriterImageGeneration(initialContext: string = ''): UseWriterImageGenerationReturn {
    const [prompt, setPrompt] = useState(initialContext);
    const [selectedStyle, setSelectedStyle] = useState('auto');
    const [selectedRatio, setSelectedRatio] = useState('1:1');
    const [isGenerating, setIsGenerating] = useState(false);
    const [isSuggesting, setIsSuggesting] = useState(false);
    const [generatedImages, setGeneratedImages] = useState<string[]>([]);
    
    const { data: imageModels = [] } = trpc.models.getForGeneration.useQuery({ type: 'image' });
    const [selectedModel, setSelectedModel] = useState<string>('');

    // Default to the first available model if none selected
    useEffect(() => {
        if (!selectedModel && imageModels.length > 0) {
            setSelectedModel(imageModels[0].modelId);
        }
    }, [selectedModel, imageModels]);

    const generateImageMutation = trpc.image.generate.useMutation();
    // Async pair for Kie.ai models (blocking generate dies on shared hosting).
    const createImageTaskMutation = trpc.image.createTask.useMutation();
    const imageTaskResultMutation = trpc.image.taskResult.useMutation();
    const suggestMutation = trpc.image.generateSuggestions.useMutation();

    const suggestPrompt = useCallback(async (contextText: string) => {
        if (!contextText.trim()) {
            toast.error("No text context available around the cursor.");
            return;
        }

        setIsSuggesting(true);
        try {
            const result = await suggestMutation.mutateAsync({
                brief: `Create an image that matches this text context: ${contextText}`,
                count: 1,
            }) as SuggestionResult | string[];
            
            const suggestions = Array.isArray(result) ? result : result?.suggestions;
            if (Array.isArray(suggestions) && suggestions.length > 0) {
                setPrompt(suggestions[0]);
                toast.success("AI suggested a prompt!");
            }
        } catch (error) {
            console.error('Failed to suggest prompt:', error);
            toast.error("Failed to generate AI suggestion.");
        } finally {
            setIsSuggesting(false);
        }
    }, [suggestMutation]);

    const generateImage = useCallback(async () => {
        if (!prompt.trim()) {
            toast.error('Please enter a prompt.');
            return;
        }
        if (!selectedModel) {
            toast.error('No generation model available.');
            return;
        }

        setIsGenerating(true);
        setGeneratedImages([]); // Clear previous results

        try {
            const modelObj = imageModels.find((m) => m.modelId === selectedModel);
            const provider = modelObj ? modelObj.provider : 'google';

            const styleObj = STYLE_PRESETS.find(s => s.id === selectedStyle) || STYLE_PRESETS[0];
            const ratioObj = ASPECT_RATIOS.find(r => r.id === selectedRatio) || ASPECT_RATIOS[0];

            const fullPrompt = selectedStyle === 'auto' 
                ? prompt 
                : `${styleObj.prefix} ${prompt} ${styleObj.suffix}`.trim();

            const payload = {
                prompt: fullPrompt,
                model: selectedModel,
                provider,
                width: ratioObj.width,
                height: ratioObj.height,
                aspectRatio: selectedRatio, // Pass string ratio as well for flexbility
                negativePrompt: styleObj.negativePrompt,
                style: selectedStyle
            };
            const result = (provider === 'kieai'
                ? (await runKieTask({
                    createTask: () => createImageTaskMutation.mutateAsync(payload) as Promise<{ taskId: string; prompt?: string }>,
                    pollTask: (body) => imageTaskResultMutation.mutateAsync(body) as Promise<any>,
                    payload,
                })).asset
                : await generateImageMutation.mutateAsync(payload)) as GenerateImageResult;

            const url = result?.url;
            if (url) {
                setGeneratedImages([url]);
            }
        } catch (error) {
            console.error('Failed to generate image:', error);
            const msg = error instanceof Error ? error.message : 'Generation failed.';
            toast.error(msg);
        } finally {
            setIsGenerating(false);
        }
    }, [prompt, selectedModel, selectedStyle, selectedRatio, imageModels, generateImageMutation, createImageTaskMutation, imageTaskResultMutation]);

    return {
        prompt,
        setPrompt,
        selectedStyle,
        setSelectedStyle,
        selectedRatio,
        setSelectedRatio,
        selectedModel,
        setSelectedModel,
        imageModels,
        isGenerating,
        generatedImages,
        setGeneratedImages,
        generateImage,
        suggestPrompt,
        isSuggesting
    };
}
