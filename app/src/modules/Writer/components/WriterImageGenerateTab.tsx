import React from 'react';
import { Button } from '@/components/ui/button';
import { TabsContent } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Loader2Icon, Wand2Icon, Sparkles } from 'lucide-react';
import { useWriterImageGeneration } from '../hooks/useWriterImageGeneration';
import { STYLE_PRESETS, ASPECT_RATIOS } from '@/modules/Image/imageConfig';

interface WriterImageGenerateTabProps {
    onInsertImage: (url: string) => void;
    onClose: () => void;
    contextText: string;
}

export function WriterImageGenerateTab({ onInsertImage, onClose, contextText }: WriterImageGenerateTabProps) {
    const {
        prompt, setPrompt,
        selectedStyle, setSelectedStyle,
        selectedRatio, setSelectedRatio,
        selectedModel, setSelectedModel,
        imageModels,
        isGenerating,
        generatedImages, setGeneratedImages,
        generateImage,
        suggestPrompt,
        isSuggesting
    } = useWriterImageGeneration(contextText);

    return (
        <TabsContent value="generate" className="m-0 h-full flex flex-col gap-4">
            {generatedImages.length === 0 ? (
                <>
                    <div className="grid gap-2 relative">
                        <Label>Image Description</Label>
                        <Textarea
                            placeholder="Describe what you want to see..."
                            value={prompt}
                            onChange={(e) => setPrompt(e.target.value)}
                            className="resize-none h-24 pb-8"
                        />
                        <Button
                            variant="ghost"
                            size="sm"
                            className="absolute bottom-2 right-2 text-xs h-6 gap-1"
                            onClick={() => suggestPrompt(contextText || prompt)}
                            disabled={isSuggesting}
                            title="Generate AI Suggestion based on context"
                        >
                            {isSuggesting ? <Loader2Icon className="w-3 h-3 animate-spin" /> : <Sparkles className="w-3 h-3 text-primary" />}
                            <span className="text-muted-foreground">{isSuggesting ? 'Thinking...' : 'AI Suggestion'}</span>
                        </Button>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="grid gap-2">
                            <Label>Style Preset</Label>
                            <Select value={selectedStyle} onValueChange={setSelectedStyle}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select style" />
                                </SelectTrigger>
                                <SelectContent>
                                    {STYLE_PRESETS.map((style) => (
                                        <SelectItem key={style.id} value={style.id}>
                                            {style.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-2">
                            <Label>Aspect Ratio</Label>
                            <Select value={selectedRatio} onValueChange={setSelectedRatio}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select ratio" />
                                </SelectTrigger>
                                <SelectContent>
                                    {ASPECT_RATIOS.map((ratio) => (
                                        <SelectItem key={ratio.id} value={ratio.id}>
                                            {ratio.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Model</Label>
                        <Select value={selectedModel} onValueChange={setSelectedModel}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select model" />
                            </SelectTrigger>
                            <SelectContent>
                                {imageModels.map((model) => (
                                    <SelectItem key={model.id} value={model.modelId}>
                                        {model.customName || model.originalName}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="mt-auto pt-2">
                        <Button
                            className="w-full"
                            disabled={!prompt.trim() || isGenerating}
                            onClick={generateImage}
                        >
                            {isGenerating ? (
                                <><Loader2Icon className="w-4 h-4 mr-2 animate-spin" /> Generating...</>
                            ) : (
                                <><Wand2Icon className="w-4 h-4 mr-2" /> Generate Image</>
                            )}
                        </Button>
                    </div>
                </>
            ) : (
                <div className="flex-1 flex flex-col gap-4">
                    <div className="flex-1 bg-muted rounded-xl border border-border overflow-hidden flex items-center justify-center p-2 relative group">
                        <img src={generatedImages[0]} alt="Generated result" className="max-w-full max-h-[250px] object-contain rounded-md shadow-sm" />
                    </div>
                    <div className="flex gap-2 justify-end">
                        <Button variant="outline" onClick={() => setGeneratedImages([])} disabled={isGenerating}>
                            Discard & Try Again
                        </Button>
                        <Button onClick={() => { onInsertImage(generatedImages[0]); onClose(); }}>
                            Insert Image
                        </Button>
                    </div>
                </div>
            )}
        </TabsContent>
    );
}
