/**
 * CREATIVE MACHINE - Video Module
 * AI-powered video generation with horizontal tabs for creative angles
 * and sidebar for model selection and production parameters
 * 
 * Layout: Sidebar (left) | Main Content Area with Horizontal Tabs (right)
 * Each tab shows videos grouped by model in horizontal sections
 * 
 * Uses tRPC for:
 * - Generating creative concepts via LLM
 * - Generating videos via Kie.ai and other providers
 */

import { useSettings, useApp, type PendingVideoData } from '@/contexts/AppContext';
import { SessionReferenceImagePanel } from '@/components/shared/SessionReferenceImagePanel';
import type { SessionReferenceImage } from '@shared/referenceImageIntents';
import { VideoTemplateDropdown } from './VideoTemplateDropdown';
import { useVideoModelsForGeneration, TIER_CONFIG } from '@/hooks/useModelsForGeneration';
import { ContextPanel, createEmptyContextData } from '@/components/shared/ContextPanel';
import type { ContextData } from '@/components/shared/ContextPanel';
import { BrandColorSwatches } from '@/components/shared/BrandColorSwatches';
import { EmptyState } from '@/components/shared/EmptyState';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { trpc } from '@/lib/trpc';
import { getErrorMessage } from '@/lib/utils';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  Video as VideoIcon,
  Sparkles,
  Plug,

  Loader2,
  Download,
  RefreshCw,
  Settings2,
  Cpu,
  Check,
  Wand2,
  Play,
  SlidersHorizontal,
  Dices,
  Zap,
  Clock,
  Volume2,
  Type,
  AlertCircle,
  X,
  Pause,
  ChevronDown,
  ChevronRight
} from 'lucide-react';
import { useState, useRef, useMemo, useCallback, useEffect } from 'react';
import { AssetDetailView, Asset } from '@/components/AssetDetailView';
import { toast } from 'sonner';
import type { TextOverlayConfig, TextPlacement, CostTier } from '@/types';

// Types for video generation
interface VideoVersion {
  id: string;
  name: string;
  description: string;
  prompt?: string;
}

interface GeneratedVideo {
  id: string;
  versionId: string;
  url?: string;
  thumbnailUrl?: string;
  prompt: string;
  modelId: string;
  modelName: string;
  duration: string;
  status: 'processing' | 'complete' | 'failed';
  errorMessage?: string;
  createdAt: Date;
}

interface GenerationStatus {
  isGenerating: boolean;
  progress: number;
  message: string;
  currentModel?: string;
  totalModels?: number;
  completedModels?: number;
}

export function VideoModule() {
  const { settings } = useSettings();
  const { setActiveModule, consumePendingVideoData, state: appState } = useApp();

  // tRPC mutations & queries
  const enhancePromptMutation = trpc.video.enhancePrompt.useMutation();
  const generateConceptsMutation = trpc.video.generateConcepts.useMutation();
  const composePromptMutation = trpc.video.composePrompt.useMutation();
  const generateVideoMutation = trpc.video.generate.useMutation();
  const { data: videoCapabilities } = trpc.video.getCapabilities.useQuery();


  // Model Selection
  const [selectedModels, setSelectedModels] = useState<string[]>([]);

  // Production Parameters
  const [productBrief, setProductBrief] = useState('');
  const [numVersions, setNumVersions] = useState(3);
  const [variationsPerModel, setVariationsPerModel] = useState(1);
  const [duration, setDuration] = useState('smart');
  const [videoFormat, setVideoFormat] = useState<'portrait' | 'landscape'>('landscape');

  // Loading state while enhance-prompt API call is in progress.
  // Shown as a spinner on the Wand2 icon button.
  const [isEnhancing, setIsEnhancing] = useState(false);

  // Video Template System: Scene (fishbone structure) + Recipe (content type) + Enhance (prompt style)
  const [sceneTemplateId, setSceneTemplateId] = useState<number | undefined>(undefined);
  const [recipeTemplateId, setRecipeTemplateId] = useState<number | undefined>(undefined);
  const [sceneTemplateContent, setSceneTemplateContent] = useState<string | null>(null);
  const [recipeTemplateContent, setRecipeTemplateContent] = useState<string | null>(null);
  const [enhanceTemplateId, setEnhanceTemplateId] = useState<number | undefined>(undefined);
  const [enhanceTemplateContent, setEnhanceTemplateContent] = useState<string | null>(null);

  // Brand Context — shared ContextPanel (single source of truth)
  const [contextData, setContextData] = useState<ContextData>(createEmptyContextData);

  // Audio/Text Options
  const [generateAudio, setGenerateAudio] = useState(false);
  const [voiceoverScript, setVoiceoverScript] = useState('');

  // Text Overlay Configuration
  const [textOverlay, setTextOverlay] = useState<TextOverlayConfig>({
    isActive: false,
    text: '',
    optimize: true,
    placement: 'optimize' as TextPlacement
  });

  // Session reference images (starting frame for I2V models)
  const [sessionReferenceImages, setSessionReferenceImages] = useState<SessionReferenceImage[]>([]);

  // Track if we've already consumed pending data to avoid re-consuming on re-renders
  const pendingDataConsumed = useRef(false);

  // Consume pending video data from Image module (cross-module navigation)
  useEffect(() => {
    if (appState.pendingVideoData && !pendingDataConsumed.current) {
      pendingDataConsumed.current = true;
      const data = consumePendingVideoData();
      if (data) {
        setProductBrief(data.prompt);
        // Create a SessionReferenceImage from the incoming image URL
        const refImage: SessionReferenceImage = {
          id: crypto.randomUUID(),
          url: data.imageUrl,
          filename: 'Starting Frame',
          intent: 'auto',
          fromBrand: false,
        };
        setSessionReferenceImages([refImage]);
        toast.info('Image loaded as starting frame. Select a video model and generate!', {
          duration: 5000,
        });
      }
    }
    // Reset consumed flag when there's no pending data (allows future navigations)
    if (!appState.pendingVideoData) {
      pendingDataConsumed.current = false;
    }
  }, [appState.pendingVideoData, consumePendingVideoData]);

  // Generation State
  const [status, setStatus] = useState<GenerationStatus>({
    isGenerating: false,
    progress: 0,
    message: ''
  });
  const [videoVersions, setVideoVersions] = useState<VideoVersion[]>([]);
  const [activeTab, setActiveTab] = useState<string | null>(null);
  const [videos, setVideos] = useState<GeneratedVideo[]>([]);
  const [selectedVideo, setSelectedVideo] = useState<GeneratedVideo | null>(null);
  const [playingVideoId, setPlayingVideoId] = useState<string | null>(null);
  const [isDetailViewOpen, setIsDetailViewOpen] = useState(false);

  // Convert GeneratedVideo to Asset format for AssetDetailView
  // Convert GeneratedVideo → Asset shape for AssetDetailView.
  // Uses the real DB ID from pcm_assets (stored by backend save_asset).
  // Fallback to 0 (not Date.now()) to avoid sending fake IDs to backend.
  const convertToAsset = useCallback((video: GeneratedVideo): Asset | null => {
    if (!video.url) return null;
    return {
      id: parseInt(video.id) || 0,
      userId: 0,
      type: 'video',
      url: video.url,
      thumbnailUrl: video.thumbnailUrl || null,
      prompt: video.prompt,
      model: video.modelName,
      projectId: null,
      metadata: JSON.stringify({ duration: video.duration }),
      createdAt: video.createdAt,
      updatedAt: video.createdAt,
    };
  }, []);

  // Handle opening the detail view
  const handleOpenDetailView = useCallback((video: GeneratedVideo) => {
    if (video.url && video.status === 'complete') {
      setSelectedVideo(video);
      setIsDetailViewOpen(true);
    }
  }, []);

  // Handle closing the detail view
  const handleCloseDetailView = useCallback(() => {
    setIsDetailViewOpen(false);
    setSelectedVideo(null);
  }, []);

  // Use unified models table as single source of truth
  const { videoModels, videoModelsByTier: modelsByTier, isLoading: isLoadingRegistry, hasModels: hasIntegrations } = useVideoModelsForGeneration();

  // Display models with consistent interface
  const displayModels = useMemo(() =>
    videoModels.map(m => ({
      id: m.id,
      name: m.name,
      provider: m.provider,
      costTier: m.costTier,
      supportsAudio: m.supportsAudio ?? false,
    }))
    , [videoModels]);

  // Track which cost tiers are expanded (all expanded by default)
  const [expandedTiers, setExpandedTiers] = useState<Record<CostTier, boolean>>({
    budget: true,
    standard: true,
    premium: true,
  });

  const toggleTier = (tier: CostTier) => {
    setExpandedTiers(prev => ({ ...prev, [tier]: !prev[tier] }));
  };

  // Use shared tier config from hook
  const tierLabels = TIER_CONFIG;

  const toggleModel = (modelId: string) => {
    setSelectedModels(prev =>
      prev.includes(modelId)
        ? prev.filter(m => m !== modelId)
        : [...prev, modelId]
    );
  };



  /**
   * Enhance the current prompt via LLM.
   * Called when user clicks the Wand2 icon next to the textarea.
   * Updates the textarea with the enhanced version — user can edit or keep it.
   */
  const handleEnhancePrompt = async () => {
    if (!productBrief.trim() || isEnhancing) return;

    setIsEnhancing(true);
    try {
      const result = await enhancePromptMutation.mutateAsync({
        prompt: productBrief,
        modelId: settings.defaultVideoTextModel || undefined,
        enhanceTemplate: enhanceTemplateContent || undefined,
        sceneTemplate: sceneTemplateContent || undefined,
        recipeTemplate: recipeTemplateContent || undefined,
      });

      if (result?.enhanced_prompt) {
        setProductBrief(result.enhanced_prompt);
        toast.success('Prompt enhanced');
      }
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to enhance prompt'));
    } finally {
      setIsEnhancing(false);
    }
  };

  const handleGenerate = async () => {
    if (!productBrief.trim()) {
      toast.error('Please enter a product brief');
      return;
    }
    if (selectedModels.length === 0) {
      toast.error('Please select at least one model');
      return;
    }

    try {
      setStatus({ isGenerating: true, progress: 0, message: 'Preparing...' });
      setVideos([]);

      let generatedVersions: VideoVersion[];

      // Determine which path to use:
      // - If scene framework is selected → compose_prompt (fills in [...] brackets via LLM)
      // - If no framework → legacy suggest_concepts for multi-angle, or direct prompt for single
      const hasFramework = !!sceneTemplateContent;

      if (hasFramework) {
        // ── Compose path: LLM fills in framework brackets ──
        setStatus(prev => ({ ...prev, progress: 10, message: 'AI is composing your video prompt...' }));

        const composed = await composePromptMutation.mutateAsync({
          brief: productBrief,
          sceneFramework: sceneTemplateContent,
          recipeTemplate: recipeTemplateContent || undefined,
          count: numVersions,
          modelId: settings.defaultVideoTextModel || undefined,
        });

        const prompts: string[] = composed?.prompts ?? [productBrief];

        generatedVersions = prompts.map((prompt: string, idx: number) => ({
          id: crypto.randomUUID(),
          name: numVersions === 1 ? 'Composed Prompt' : `Angle ${idx + 1}`,
          description: prompt,
        }));

      } else if (numVersions === 1) {
        // ── No framework, single angle: send brief directly ──
        generatedVersions = [{
          id: crypto.randomUUID(),
          name: 'Direct Prompt',
          description: productBrief,
        }];

      } else {
        // ── No framework, multi-angle: legacy concept generation ──
        setStatus(prev => ({ ...prev, progress: 10, message: 'AI is creating scene variations...' }));

        const concepts = await generateConceptsMutation.mutateAsync({
          productBrief,
          conceptCount: numVersions,
          modelId: settings.defaultVideoTextModel || undefined,
          sceneTemplate: sceneTemplateContent || undefined,
          recipeTemplate: recipeTemplateContent || undefined,
        });

        generatedVersions = concepts.map((concept: any) => ({
          id: crypto.randomUUID(),
          name: concept.name,
          description: concept.description,
          prompt: concept.prompt,
        }));
      }

      setVideoVersions(generatedVersions);
      setActiveTab(generatedVersions[0]?.id || null);

      // Step 2: Generate videos for each concept
      const totalWork = generatedVersions.length * selectedModels.length * variationsPerModel;
      let completed = 0;
      let failed = 0;

      for (const version of generatedVersions) {
        for (const modelId of selectedModels) {
          const model = displayModels.find(m => m.id === modelId);

          for (let v = 0; v < variationsPerModel; v++) {
            setStatus(prev => ({
              ...prev,
              progress: 10 + ((completed / totalWork) * 85),
              message: `Generating: ${version.name} (${model?.name || modelId})...`,
              currentModel: modelId,
              totalModels: selectedModels.length,
              completedModels: Math.floor(completed / variationsPerModel)
            }));

            // Create placeholder
            const placeholderId = crypto.randomUUID();
            const placeholderVideo: GeneratedVideo = {
              id: placeholderId,
              versionId: version.id,
              prompt: version.prompt ?? version.description,
              modelId: modelId,
              modelName: model?.name || modelId,
              duration: duration === 'smart' ? 'auto' : `${duration}s`,
              status: 'processing',
              createdAt: new Date()
            };
            setVideos(prev => [...prev, placeholderVideo]);

            try {
              // Generate video using tRPC
              const result = await generateVideoMutation.mutateAsync({
                prompt: version.prompt ?? version.description,
                model: modelId,
                // Provider from model registry — same pattern as Image module.
                // No fallback: if provider is missing, it's a data integrity bug
                // that must surface immediately, not route silently to wrong provider.
                provider: model?.provider,
                duration: duration === 'smart' ? undefined : duration,
                format: videoFormat,
                inputUrl: sessionReferenceImages[0]?.url || undefined,
                brandId: contextData.brandId || undefined,
                // Prompt-enrichment: providers append these to the prompt string
                generateAudio: generateAudio,
                voiceoverScript: generateAudio && voiceoverScript ? voiceoverScript : undefined,
                textOverlayContent: textOverlay.isActive && textOverlay.text ? textOverlay.text : undefined,
                textOverlayPlacement: textOverlay.isActive ? textOverlay.placement : undefined,
              });

              // Update with generated video.
              // CRITICAL: Capture result.assetId (DB autoincrement from pcm_assets)
              // so Save-to-Project sends the real asset ID, not the UUID placeholder.
              const dbAssetId = (result as any).assetId;
              setVideos(prev => prev.map(v =>
                v.id === placeholderId
                  ? {
                    ...v,
                    id: dbAssetId ? String(dbAssetId) : v.id,
                    url: result.url,
                    status: 'complete' as const
                  }
                  : v
              ));
            } catch (error) {
              const errorMsg = getErrorMessage(error, 'Unknown error');
              console.error('Video generation failed:', errorMsg);
              // Mark as failed with error message visible to user
              setVideos(prev => prev.map(v =>
                v.id === placeholderId
                  ? { ...v, status: 'failed' as const, errorMessage: errorMsg }
                  : v
              ));
              toast.error(`Video failed: ${errorMsg}`);
              failed++;
            }

            completed++;
          }
        }
      }

      setStatus({ isGenerating: false, progress: 100, message: 'Generation complete!' });
      // Only show success toast if ALL videos actually succeeded.
      // Don't mislead the user — if some failed, the per-video error toasts
      // already fired above and the cards show 'failed' status.
      if (failed === 0) {
        toast.success('All videos generated successfully!');
      } else if (failed < completed) {
        toast.warning(`${completed - failed} of ${completed} videos generated. ${failed} failed.`);
      }

    } catch (error) {
      const errorMsg = getErrorMessage(error, 'Failed to generate videos');
      console.error('Generation error:', errorMsg);
      setStatus({ isGenerating: false, progress: 0, message: '' });
      toast.error(errorMsg);
    }
  };

  const downloadVideo = (url: string, filename: string) => {
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.target = '_blank';
    link.click();
  };

  // Get videos for the active tab, grouped by model
  const activeVersionVideos = videos.filter(v => v.versionId === activeTab);

  // Group videos by model for the active tab
  const videosByModel = useMemo(() => {
    const grouped: Record<string, GeneratedVideo[]> = {};

    // Initialize groups for selected models in order
    selectedModels.forEach(modelId => {
      grouped[modelId] = [];
    });

    // Populate groups
    activeVersionVideos.forEach(video => {
      if (grouped[video.modelId]) {
        grouped[video.modelId].push(video);
      }
    });

    return grouped;
  }, [activeVersionVideos, selectedModels]);

  // Get model info helper
  const getModelInfo = (modelId: string) => {
    return displayModels.find(m => m.id === modelId) || { id: modelId, name: modelId, provider: 'unknown' };
  };

  // Show empty state if no integrations configured
  if (!hasIntegrations) {
    return (
      <div className="h-full flex flex-col bg-background animate-fade-in">
        <header className="shrink-0 border-b border-border px-6 py-3 flex items-center justify-between bg-background/95 backdrop-blur-sm">
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 bg-primary rounded-lg flex items-center justify-center">
              <VideoIcon className="w-4 h-4 text-primary-foreground" />
            </div>
            <div>
              <h1 className="text-sm font-semibold">Video Generation</h1>
              <p className="text-xs text-muted-foreground">Multi-engine production suite</p>
            </div>
          </div>
        </header>

        <div className="flex-1 flex items-center justify-center p-8">
          <EmptyState
            icon={<Plug className="w-12 h-12" />}
            title="No video integrations configured"
            description="Add a video generation integration (like Kie.ai) in the Integrations module to start creating videos"
            action={
              <Button variant="outline" className="gap-2" onClick={() => setActiveModule('integrations')}>
                <Plug className="w-4 h-4" />
                Configure Integrations
              </Button>
            }
          />
        </div>
      </div>
    );
  }

  return (
    <div className="h-full flex flex-col bg-background animate-fade-in">
      {/* Header */}
      <header className="shrink-0 border-b border-border px-6 py-3 flex items-center justify-between bg-background/95 backdrop-blur-sm">
        <div className="flex items-center gap-3">
          <div className="w-8 h-8 bg-primary rounded-lg flex items-center justify-center">
            <VideoIcon className="w-4 h-4 text-primary-foreground" />
          </div>
          <div>
            <h1 className="text-sm font-semibold">Video Generation</h1>
            <p className="text-xs text-muted-foreground">Multi-engine production suite</p>
          </div>
        </div>

        <div className="flex items-center gap-3">
          {status.isGenerating && (
            <div className="flex items-center gap-3 bg-muted/50 border border-border px-4 py-1.5 rounded-full">
              <RefreshCw className="w-3 h-3 text-primary animate-spin" />
              <span className="text-xs font-medium text-muted-foreground truncate max-w-[200px]">
                {status.message}
              </span>
              <div className="w-20 h-1.5 bg-muted rounded-full overflow-hidden">
                <div
                  className="h-full bg-primary transition-all duration-500 ease-out"
                  style={{ width: `${status.progress}%` }}
                />
              </div>
            </div>
          )}

          <Button
            onClick={handleGenerate}
            disabled={status.isGenerating || !productBrief.trim() || selectedModels.length === 0}
            className="gap-2"
          >
            <Play className="w-4 h-4" />
            Launch Production
          </Button>
        </div>
      </header>

      {/* Main Content */}
      <div className="flex-1 flex overflow-hidden">
        {/* Sidebar */}
        <aside className="w-72 shrink-0 border-r border-border overflow-y-auto bg-muted/20">
          <div className="p-4 space-y-6">
            {/* Production Engines - Grouped by Cost */}
            <section>
              <div className="flex items-center gap-2 mb-3">
                <Cpu className="w-4 h-4 text-muted-foreground" />
                <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Production Engines
                </h3>
              </div>
              <div className="space-y-3">
                {displayModels.length === 0 ? (
                  <div className="p-3 rounded-lg border border-dashed border-border text-center">
                    <p className="text-xs text-muted-foreground">No video models available</p>
                    <Button
                      variant="link"
                      size="sm"
                      className="text-xs h-auto p-0 mt-1"
                      onClick={() => setActiveModule('integrations')}
                    >
                      Add Integration
                    </Button>
                  </div>
                ) : (
                  (['budget', 'standard', 'premium'] as CostTier[]).map(tier => {
                    const models = modelsByTier[tier];
                    if (models.length === 0) return null;

                    const tierInfo = tierLabels[tier];
                    const selectedInTier = models.filter(m => selectedModels.includes(m.id)).length;

                    return (
                      <div key={tier} className="border border-border rounded-lg overflow-hidden">
                        {/* Tier Header - Collapsible */}
                        <button
                          onClick={() => toggleTier(tier)}
                          className="w-full flex items-center justify-between px-3 py-2 bg-muted/50 hover:bg-muted/70 transition-colors"
                        >
                          <div className="flex items-center gap-2">
                            {expandedTiers[tier] ? (
                              <ChevronDown className="w-4 h-4 text-muted-foreground" />
                            ) : (
                              <ChevronRight className="w-4 h-4 text-muted-foreground" />
                            )}
                            <span className={`text-sm font-semibold ${tierInfo.color}`}>
                              {tierInfo.icon}
                            </span>
                            <span className="text-sm font-medium">{tierInfo.label}</span>
                          </div>
                          <span className="text-xs text-muted-foreground">
                            {selectedInTier}/{models.length} models
                          </span>
                        </button>

                        {/* Tier Models - Collapsible Content */}
                        {expandedTiers[tier] && (
                          <div className="p-2 space-y-1 bg-background">
                            {models.map(model => (
                              <button
                                key={model.id}
                                onClick={() => toggleModel(model.id)}
                                className={`w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left transition-colors ${selectedModels.includes(model.id)
                                  ? 'bg-primary/10 border border-primary/30'
                                  : 'hover:bg-muted/50'
                                  }`}
                              >
                                <div className={`w-5 h-5 rounded flex items-center justify-center ${selectedModels.includes(model.id) ? 'bg-primary text-primary-foreground' : 'bg-muted'
                                  }`}>
                                  {selectedModels.includes(model.id) && <Check className="w-3 h-3" />}
                                </div>
                                <div className="flex-1 min-w-0">
                                  <div className="text-sm font-medium truncate">{model.name}</div>
                                  <div className="flex items-center gap-1 mt-0.5">
                                    <span className="text-xs text-muted-foreground capitalize">{model.provider}</span>
                                    <div className="flex gap-0.5 ml-1">
                                      {/* Format/duration badges — from capabilities endpoint */}
                                      {videoCapabilities?.[model.id] && (
                                        <>
                                          {videoCapabilities[model.id].supportedFormats.length > 0 && (
                                            <span className="text-[10px] px-1 py-0 rounded bg-muted text-muted-foreground" title={`Formats: ${videoCapabilities[model.id].supportedFormats.join(', ')}`}>
                                              {videoCapabilities[model.id].supportedFormats.includes('portrait') || videoCapabilities[model.id].supportedFormats.includes('9:16') ? '↕↔' : '↔'}
                                            </span>
                                          )}
                                          {videoCapabilities[model.id].validDurations.length > 0 && (
                                            <span className="text-[10px] px-1 py-0 rounded bg-muted text-muted-foreground" title={`Durations: ${videoCapabilities[model.id].validDurations.join('s, ')}s`}>
                                              {videoCapabilities[model.id].validDurations[0]}–{videoCapabilities[model.id].validDurations[videoCapabilities[model.id].validDurations.length - 1]}s
                                            </span>
                                          )}
                                        </>
                                      )}
                                      {/* Audio support badge — from model registry supportsAudio field */}
                                      {model.supportsAudio && (
                                        <span className="text-[10px] px-1 py-0 rounded bg-muted text-muted-foreground inline-flex items-center gap-0.5" title="Supports audio">
                                          <Volume2 className="w-3 h-3" />
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                </div>
                              </button>
                            ))}
                          </div>
                        )}
                      </div>
                    );
                  })
                )}
              </div>
            </section>

            {/* Video Templates: Enhancement (prompt style) + Scene (fishbone) + Recipe (content type) */}
            <section className="space-y-2">
              <VideoTemplateDropdown
                templateType="enhance"
                selectedId={enhanceTemplateId}
                onSelect={setEnhanceTemplateId}
                onTemplateContent={setEnhanceTemplateContent}
                label="Enhancement"
                placeholder="Select enhancement style..."
              />
              <VideoTemplateDropdown
                templateType="scene"
                selectedId={sceneTemplateId}
                onSelect={setSceneTemplateId}
                onTemplateContent={setSceneTemplateContent}
                label="Scene Framework"
                placeholder="Select scene structure..."
              />
              <VideoTemplateDropdown
                templateType="recipe"
                selectedId={recipeTemplateId}
                onSelect={setRecipeTemplateId}
                onTemplateContent={setRecipeTemplateContent}
                label="Content Recipe"
                placeholder="Select ad recipe..."
              />
            </section>

            {/* Product Brief */}
            <section>
              <div className="flex items-center justify-between mb-3">
                <div className="flex items-center gap-2">
                  <Wand2 className="w-4 h-4 text-muted-foreground" />
                  <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Product Brief
                  </h3>
                </div>
                {/* Enhance prompt icon — calls LLM to rewrite the prompt */}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      onClick={handleEnhancePrompt}
                      disabled={!productBrief.trim() || isEnhancing}
                      className="p-1 rounded-md text-muted-foreground hover:text-primary hover:bg-primary/10 transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                      aria-label="Enhance prompt with AI"
                    >
                      {isEnhancing
                        ? <Loader2 className="w-3.5 h-3.5 animate-spin" />
                        : <Sparkles className="w-3.5 h-3.5" />
                      }
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="left" className="text-xs">
                    Enhance prompt with AI
                  </TooltipContent>
                </Tooltip>
              </div>
              <Textarea
                value={productBrief}
                onChange={(e) => setProductBrief(e.target.value)}
                placeholder="Describe your product or service..."
                className="min-h-[100px] text-sm resize-none"
              />
            </section>

            {/* Brand / URL / Theme Context — shared ContextPanel */}
            <ContextPanel
              value={contextData}
              onChange={setContextData}
            />

            {/* Brand Colors — displayed below ContextPanel when a brand is selected */}
            {contextData.brand && (contextData.brand as any).colors &&
              ((contextData.brand as any).colors as string[]).length > 0 && (
                <section>
                  <div className="flex items-center gap-2 mb-2">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Brand Colors</h3>
                  </div>
                  <BrandColorSwatches colors={(contextData.brand as any).colors as string[]} size="md" />
                </section>
              )}

            {/* Production Parameters */}
            <section>
              <div className="flex items-center gap-2 mb-3">
                <SlidersHorizontal className="w-4 h-4 text-muted-foreground" />
                <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Production Parameters
                </h3>
              </div>
              <div className="space-y-4">
                {/* Angles (Scenes) */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                      <Dices className="w-3 h-3" />
                      Angles (Scenes)
                    </label>
                    <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{numVersions}</span>
                  </div>
                  <input
                    type="range"
                    min="1"
                    max="6"
                    value={numVersions}
                    onChange={(e) => setNumVersions(parseInt(e.target.value))}
                    className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
                  />
                  <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
                    <span>1</span>
                    <span>6</span>
                  </div>
                </div>

                {/* Variations per Engine */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                      <Zap className="w-3 h-3" />
                      Variations per Engine
                    </label>
                    <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{variationsPerModel}</span>
                  </div>
                  <input
                    type="range"
                    min="1"
                    max="3"
                    value={variationsPerModel}
                    onChange={(e) => setVariationsPerModel(parseInt(e.target.value))}
                    className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
                  />
                  <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
                    <span>1</span>
                    <span>3</span>
                  </div>
                </div>

                {/* Duration */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                      <Clock className="w-3 h-3" />
                      Duration (seconds)
                    </label>
                    <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{duration === 'smart' ? 'Smart' : `${duration}s`}</span>
                  </div>
                  <div className="flex gap-2">
                    {['smart', '5', '10', '15', '20'].map(d => (
                      <button
                        key={d}
                        onClick={() => setDuration(d)}
                        className={`flex-1 py-1.5 text-xs rounded transition-colors ${duration === d
                          ? 'bg-primary text-primary-foreground'
                          : 'bg-muted hover:bg-muted/80'
                          }`}
                      >
                        {d === 'smart' ? '✦' : `${d}s`}
                      </button>
                    ))}
                  </div>
                </div>

                {/* Video Format */}
                <div>
                  <div className="flex items-center justify-between mb-2">
                    <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                      <SlidersHorizontal className="w-3 h-3" />
                      Format
                    </label>
                    <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">
                      {videoFormat === 'portrait' ? '9:16' : '16:9'}
                    </span>
                  </div>
                  <div className="flex gap-2">
                    {(['landscape', 'portrait'] as const).map(f => (
                      <button
                        key={f}
                        onClick={() => setVideoFormat(f)}
                        className={`flex-1 py-1.5 text-xs rounded transition-colors capitalize ${videoFormat === f
                          ? 'bg-primary text-primary-foreground'
                          : 'bg-muted hover:bg-muted/80'
                          }`}
                      >
                        {f === 'landscape' ? 'Landscape (16:9)' : 'Portrait (9:16)'}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            </section>

            {/* Video Options */}
            <section>
              <div className="flex items-center gap-2 mb-3">
                <Settings2 className="w-4 h-4 text-muted-foreground" />
                <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  Video Options
                </h3>
              </div>
              <div className="space-y-3">
                {/* Starting Frame — shared reference image panel (upload, URL, library) */}
                <div className="p-3 rounded-lg border border-border bg-background">
                  <span className="text-xs font-medium mb-2 block">Starting Frame</span>
                  <SessionReferenceImagePanel
                    value={sessionReferenceImages}
                    onChange={setSessionReferenceImages}
                    maxImages={1}
                  />
                  {sessionReferenceImages.length === 0 && (
                    <p className="text-xs text-muted-foreground mt-1">Optional: Add an image to use as the first frame</p>
                  )}
                </div>

                {/* Audio Toggle */}
                <div className="flex items-center justify-between p-3 rounded-lg border border-border bg-background">
                  <div className="flex items-center gap-2">
                    <Volume2 className="w-4 h-4 text-muted-foreground" />
                    <Label htmlFor="audio-toggle" className="text-xs font-medium">Generate Audio</Label>
                  </div>
                  <Switch
                    id="audio-toggle"
                    checked={generateAudio}
                    onCheckedChange={setGenerateAudio}
                  />
                </div>

                {/* Voiceover Script (shown when audio is enabled) */}
                {generateAudio && (
                  <div className="p-3 rounded-lg border border-border bg-background animate-fade-in">
                    <label className="text-xs font-medium mb-2 block">
                      Voiceover Script (Optional)
                    </label>
                    <Textarea
                      placeholder="Enter a script for the voiceover..."
                      value={voiceoverScript}
                      onChange={(e) => setVoiceoverScript(e.target.value)}
                      rows={3}
                      className="resize-none text-sm"
                    />
                  </div>
                )}

                {/* Text Overlay */}
                <div className="p-3 rounded-lg border border-border bg-background">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-medium flex items-center gap-1.5">
                      <Type className="w-3 h-3" />
                      Allow Text
                    </span>
                    <Switch
                      checked={textOverlay.isActive}
                      onCheckedChange={(checked) => setTextOverlay(prev => ({ ...prev, isActive: checked }))}
                    />
                  </div>

                  {textOverlay.isActive && (
                    <div className="space-y-3 mt-3">
                      {/* Text Input */}
                      <div>
                        <input
                          type="text"
                          value={textOverlay.text}
                          onChange={(e) => setTextOverlay(prev => ({ ...prev, text: e.target.value }))}
                          placeholder="Enter text to display..."
                          className="w-full px-3 py-2 text-xs bg-muted/50 border border-border rounded-md focus:outline-none focus:ring-1 focus:ring-primary"
                        />
                      </div>

                      {/* Optimize Checkbox */}
                      <label className="flex items-center gap-2 cursor-pointer">
                        <input
                          type="checkbox"
                          checked={textOverlay.optimize}
                          onChange={(e) => setTextOverlay(prev => ({ ...prev, optimize: e.target.checked }))}
                          className="w-3.5 h-3.5 rounded border-border text-primary focus:ring-primary"
                        />
                        <span className="text-xs text-muted-foreground">Optimize text (allow model to adjust)</span>
                      </label>

                      {/* Placement Dropdown */}
                      <div>
                        <label className="text-xs text-muted-foreground mb-1 block">Placement</label>
                        <select
                          value={textOverlay.placement}
                          onChange={(e) => setTextOverlay(prev => ({ ...prev, placement: e.target.value as TextPlacement }))}
                          className="w-full px-3 py-2 text-xs bg-muted/50 border border-border rounded-md focus:outline-none focus:ring-1 focus:ring-primary"
                        >
                          <option value="optimize">Optimize (model chooses)</option>
                          <option value="top-left">Top Left</option>
                          <option value="top-center">Top Center</option>
                          <option value="top-right">Top Right</option>
                          <option value="center">Center</option>
                          <option value="bottom-left">Bottom Left</option>
                          <option value="bottom-center">Bottom Center</option>
                          <option value="bottom-right">Bottom Right</option>
                        </select>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            </section>
          </div>
        </aside>

        {/* Main Content Area */}
        <main className="flex-1 flex flex-col overflow-hidden">
          {/* Horizontal Tabs for Video Versions */}
          {videoVersions.length > 0 && (
            <div className="shrink-0 border-b border-border bg-muted/20">
              <div className="flex overflow-x-auto">
                {videoVersions.map((version) => (
                  <button
                    key={version.id}
                    onClick={() => setActiveTab(version.id)}
                    className={`px-6 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${activeTab === version.id
                      ? 'border-primary text-primary bg-background'
                      : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-background/50'
                      }`}
                  >
                    {version.name}
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* Results - Grouped by Model */}
          <div className="flex-1 overflow-y-auto p-6">
            {videoVersions.length === 0 ? (
              <div className="h-full flex items-center justify-center">
                <EmptyState
                  icon={<Sparkles className="w-12 h-12" />}
                  title="Ready to create"
                  description="Enter a product brief, select video models, and click 'Launch Production' to generate AI-powered video ads"
                />
              </div>
            ) : (
              <div className="space-y-8">
                {/* Render each model as a horizontal section */}
                {selectedModels.map(modelId => {
                  const modelInfo = getModelInfo(modelId);
                  const modelVideos = videosByModel[modelId] || [];

                  return (
                    <section key={modelId} className="space-y-3">
                      {/* Model Header */}
                      <div className="flex items-center gap-3 pb-2 border-b border-border">
                        <div className="w-6 h-6 rounded bg-primary/10 flex items-center justify-center">
                          <Cpu className="w-3 h-3 text-primary" />
                        </div>
                        <div>
                          <h3 className="text-sm font-semibold">{modelInfo.name}</h3>
                          <p className="text-xs text-muted-foreground capitalize">{modelInfo.provider}</p>
                        </div>
                        <div className="ml-auto text-xs text-muted-foreground">
                          {modelVideos.filter(v => v.status === 'complete').length} / {modelVideos.length} generated
                        </div>
                      </div>

                      {/* Model Videos - Horizontal Scroll */}
                      <div className="flex gap-4 overflow-x-auto pb-2">
                        {modelVideos.length === 0 ? (
                          <div className="flex-shrink-0 w-64 h-36 rounded-lg border border-dashed border-border flex items-center justify-center bg-muted/30">
                            <span className="text-xs text-muted-foreground">No videos yet</span>
                          </div>
                        ) : (
                          modelVideos.map(video => (
                            <div
                              key={video.id}
                              className={`flex-shrink-0 relative w-64 rounded-lg overflow-hidden border transition-all cursor-pointer ${selectedVideo?.id === video.id
                                ? 'border-primary ring-2 ring-primary/20'
                                : 'border-border hover:border-primary/50'
                                }`}
                              onClick={() => handleOpenDetailView(video)}
                            >
                              <div className="aspect-video relative">
                                {video.status === 'processing' ? (
                                  <div className="absolute inset-0 flex flex-col items-center justify-center bg-muted">
                                    <Loader2 className="w-8 h-8 text-primary animate-spin mb-2" />
                                    <span className="text-xs text-muted-foreground">Generating...</span>
                                  </div>
                                ) : video.status === 'failed' ? (
                                  <div className="absolute inset-0 flex flex-col items-center justify-center bg-muted">
                                    <AlertCircle className="w-8 h-8 text-destructive mb-2" />
                                    <span className="text-xs text-destructive">Failed</span>
                                  </div>
                                ) : (
                                  <>
                                    {video.url ? (
                                      <video
                                        src={video.url}
                                        poster={video.thumbnailUrl}
                                        className="w-full h-full object-cover"
                                        muted
                                        loop
                                        playsInline
                                        onMouseEnter={(e) => {
                                          e.currentTarget.play();
                                          setPlayingVideoId(video.id);
                                        }}
                                        onMouseLeave={(e) => {
                                          e.currentTarget.pause();
                                          e.currentTarget.currentTime = 0;
                                          setPlayingVideoId(null);
                                        }}
                                      />
                                    ) : (
                                      <div className="w-full h-full bg-muted flex items-center justify-center">
                                        <VideoIcon className="w-8 h-8 text-muted-foreground" />
                                      </div>
                                    )}
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 hover:opacity-100 transition-opacity">
                                      <div className="absolute bottom-0 left-0 right-0 p-3">
                                        <Button
                                          size="sm"
                                          variant="secondary"
                                          className="h-7 text-xs gap-1 w-full"
                                          onClick={(e) => {
                                            e.stopPropagation();
                                            if (video.url) downloadVideo(video.url, `${video.modelName}-${video.id}.mp4`);
                                          }}
                                        >
                                          <Download className="w-3 h-3" />
                                          Download
                                        </Button>
                                      </div>
                                    </div>
                                    {/* Duration badge */}
                                    <div className="absolute bottom-2 right-2 bg-black/70 text-white text-xs px-2 py-0.5 rounded">
                                      {video.duration}
                                    </div>
                                    {/* Play indicator */}
                                    {playingVideoId !== video.id && (
                                      <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                                        <div className="w-10 h-10 rounded-full bg-black/50 flex items-center justify-center">
                                          <Play className="w-5 h-5 text-white ml-0.5" />
                                        </div>
                                      </div>
                                    )}
                                  </>
                                )}
                              </div>
                            </div>
                          ))
                        )}
                      </div>
                    </section>
                  );
                })}
              </div>
            )}
          </div>
        </main>
      </div>

      {/* Asset Detail View Modal */}
      {selectedVideo && selectedVideo.url && (
        <AssetDetailView
          asset={convertToAsset(selectedVideo)!}
          isOpen={isDetailViewOpen}
          onClose={handleCloseDetailView}
          onVariationsCreated={(newAssets) => {
            toast.success('Variations created!');
          }}
          onAssetRefined={(refinedAsset) => {
            toast.success('Video refined!');
          }}
        />
      )}
    </div>
  );
}
