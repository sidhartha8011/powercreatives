/**
 * CREATIVE MACHINE - Integrations Module (Integration Center)
 * Central hub for managing API integrations.
 *
 * Responsibilities:
 * - Add / remove provider integrations (API key + validation)
 * - Master on/off toggle per integration (controls model visibility everywhere)
 * - Verify connection health
 *
 * Model-level controls (enable/disable, rename, cost tier, capabilities)
 * live in Settings → Model Registry.  This module does NOT touch models directly.
 *
 * All state is read from / written to the database via tRPC.
 * No localStorage is used.
 */

import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { EmptyState } from '@/components/shared/EmptyState';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  Plug,
  Plus,
  Key,
  Trash2,
  Image,
  Video,
  Check,
  ExternalLink,
  Sparkles,
  Loader2,
  X,
  RefreshCw,
  CheckCircle2,
  XCircle,
  Type,
  Eye,
  ChevronDown,
  ChevronRight,
  Music,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { trpc } from '@/lib/trpc';
import { PrtLiveTestSection } from './PrtLiveTestSection';

// ============================================
// Types (local to this module)
// ============================================

interface ValidationResult {
  valid: boolean;
  error?: string;
  capabilities: {
    image: boolean;
    video: boolean;
    text: boolean;
    vision: boolean;
  };
  models: Array<{
    id: string;
    name: string;
    type: 'image' | 'video' | 'text';
    description?: string;
  }>;
}

interface ProviderInfo {
  id: string;
  name: string;
  apiKeyUrl?: string;
  supportsImage: boolean;
  supportsVideo: boolean;
  supportsText: boolean;
  supportsVision: boolean;
  isBuiltIn: boolean;
}

// ============================================
// Component
// ============================================

export function IntegrationsModule() {
  // Dialog state
  const [isAddDialogOpen, setIsAddDialogOpen] = useState(false);
  const [selectedProvider, setSelectedProvider] = useState<string>('');
  const [apiKey, setApiKey] = useState('');

  // Validation state
  const [isValidating, setIsValidating] = useState(false);
  const [validationResult, setValidationResult] = useState<ValidationResult | null>(null);
  const [hasValidated, setHasValidated] = useState(false);

  // Connection verification state
  const [verifyingIds, setVerifyingIds] = useState<Set<number>>(new Set());

  // Custom Kie.ai model dialog state
  const [isCustomModelOpen, setIsCustomModelOpen] = useState(false);
  const [customModelName, setCustomModelName] = useState('');
  const [customDisplayName, setCustomDisplayName] = useState('');
  const [customModelType, setCustomModelType] = useState<'image' | 'video'>('image');

  // Expanded "All Models" state per provider
  const [expandedProviders, setExpandedProviders] = useState<Set<string>>(new Set());

  const toggleProviderExpanded = (provider: string) => {
    setExpandedProviders((prev) => {
      const next = new Set(prev);
      if (next.has(provider)) next.delete(provider);
      else next.add(provider);
      return next;
    });
  };

  // ---- tRPC queries & mutations ----
  const integrationsQuery = trpc.integrations.list.useQuery(undefined, {
    staleTime: 10_000,
  });
  const providersQuery = trpc.integrations.providers.useQuery();
  const validateApiKeyMutation = trpc.integrations.validateApiKey.useMutation();
  const createIntegrationMutation = trpc.integrations.create.useMutation();
  const toggleActiveMutation = trpc.integrations.toggleActive.useMutation();
  const deleteByProviderMutation = trpc.integrations.deleteByProvider.useMutation();
  const syncModelsMutation = trpc.models.syncFromIntegrations.useMutation();
  const resyncMutation = trpc.models.resync.useMutation();
  const addCustomKieModelMutation = trpc.models.addCustomKieModel.useMutation();
  const trpcUtils = trpc.useUtils();

  const integrations = integrationsQuery.data ?? [];
  const providers: ProviderInfo[] = providersQuery.data ?? [];
  const selectedProviderData = providers.find((p) => p.id === selectedProvider);

  // ---- Helpers ----

  const invalidateAll = () => {
    trpcUtils.integrations.list.invalidate();
    trpcUtils.models.getForGeneration.invalidate();
    trpcUtils.models.getForEditing.invalidate();
    trpcUtils.models.listByProvider.invalidate(); // the integration card's "All Models" list
  };

  const handleAddCustomKieModel = async () => {
    if (!customModelName.trim() || !customDisplayName.trim()) {
      toast.error('Please fill in all required fields');
      return;
    }
    try {
      await addCustomKieModelMutation.mutateAsync({
        kieModelName: customModelName.trim(),
        displayName: customDisplayName.trim(),
        type: customModelType,
      });
      invalidateAll();
      setIsCustomModelOpen(false);
      setCustomModelName('');
      setCustomDisplayName('');
      setCustomModelType('image');
      toast.success(`Custom model "${customDisplayName.trim()}" added`);
    } catch (error) {
      const msg = error instanceof Error ? error.message : 'Failed to add custom model';
      toast.error(msg);
    }
  };

  const resetForm = () => {
    setSelectedProvider('');
    setApiKey('');
    setValidationResult(null);
    setHasValidated(false);
    setIsAddDialogOpen(false);
  };

  // ---- Validate API key ----

  const handleValidateApiKey = async () => {
    if (!selectedProvider || !apiKey.trim()) {
      toast.error('Please enter an API key');
      return;
    }

    setIsValidating(true);
    setHasValidated(false);
    setValidationResult(null);

    try {
      const result = await validateApiKeyMutation.mutateAsync({
        provider: selectedProvider,
        apiKey: apiKey.trim(),
      });

      setValidationResult(result);
      setHasValidated(true);

      if (result.valid) {
        toast.success('API key validated successfully!');
      } else {
        toast.error(result.error || 'Invalid API key');
      }
    } catch (error) {
      console.error('Validation failed:', error);
      toast.error('Failed to validate API key');
      setValidationResult({
        valid: false,
        error: 'Validation failed',
        capabilities: { image: false, video: false, text: false, vision: false },
        models: [],
      });
      setHasValidated(true);
    } finally {
      setIsValidating(false);
    }
  };

  // ---- Add integration ----

  const handleAddIntegration = async () => {
    if (!selectedProviderData) {
      toast.error('Please select a provider');
      return;
    }

    // Manus (built-in) path
    if (selectedProvider === 'manus') {
      const existingManus = integrations.find((i) => i.provider === 'manus');
      if (existingManus) {
        toast.error('Manus integration already exists');
        resetForm();
        return;
      }

      try {
        await createIntegrationMutation.mutateAsync({
          provider: 'manus',
          name: selectedProviderData.name,
          apiKey: 'built-in',
          enabledModules: ['image', 'text'],
          models: [
            { id: 'manus-image', name: 'Manus Image', type: 'image' as const, description: 'Built-in Manus image generation' },
            { id: 'manus-text', name: 'Manus Text (Gemini 2.5 Flash)', type: 'text' as const, description: 'Built-in Manus text generation' },
          ],
        });

        // Sync models to registry
        syncModelsMutation.mutate(
          { provider: 'manus', apiKey: 'built-in' },
          { onSuccess: () => invalidateAll(), onError: (e) => console.error('Sync failed:', e) },
        );

        resetForm();
        toast.success(`${selectedProviderData.name} integration added`);
      } catch (error) {
        console.error('Failed to create Manus integration:', error);
        toast.error('Failed to add Manus integration');
      }
      return;
    }

    // External provider path
    if (!hasValidated || !validationResult?.valid) {
      toast.error('Please validate your API key first');
      return;
    }

    // Determine enabled AI modules from validation capabilities
    const enabledModules: ('image' | 'video' | 'text')[] = [];
    if (validationResult.capabilities.image) enabledModules.push('image');
    if (validationResult.capabilities.video) enabledModules.push('video');
    if (validationResult.capabilities.text) enabledModules.push('text');

    // Non-AI data providers (e.g. Ahrefs, DataForSEO) have no image/video/text capabilities
    // but are still valid integrations. Save directly without model sync.
    if (enabledModules.length === 0) {
      try {
        await createIntegrationMutation.mutateAsync({
          provider: selectedProvider,
          name: selectedProviderData.name,
          apiKey: apiKey.trim(),
          enabledModules: [],
          models: [],
        });
        invalidateAll();
        resetForm();
        toast.success(`${selectedProviderData.name} integration added`);
      } catch (error) {
        console.error('Failed to save integration:', error);
        toast.error('Failed to save integration');
      }
      return;
    }

    // Build model list including vision-capable models as separate entries
    const modelsToStore: Array<{ id: string; name: string; type: 'image' | 'video' | 'text' | 'vision'; description?: string }> = [];
    for (const m of validationResult.models) {
      if (m.type === 'image' || m.type === 'video' || m.type === 'text') {
        modelsToStore.push({ id: m.id, name: m.name, type: m.type, description: m.description });
      }
    }
    // Add vision entries for text models that support vision (Gemini models)
    if (validationResult.capabilities.vision) {
      const textModels = validationResult.models.filter(m => m.type === 'text');
      for (const m of textModels) {
        // Gemini models support vision
        const id = m.id.toLowerCase();
        if (id.includes('gemini') || id.includes('gpt-4') || id.includes('claude')) {
          modelsToStore.push({ id: `${m.id}-vision`, name: `${m.name} (Vision)`, type: 'vision', description: `${m.name} - vision/multimodal input` });
        }
      }
    }

    try {
      await createIntegrationMutation.mutateAsync({
        provider: selectedProvider,
        name: selectedProviderData.name,
        apiKey: apiKey.trim(),
        enabledModules,
        models: modelsToStore,
      });

      // Sync to unified models table
      syncModelsMutation.mutate(
        { provider: selectedProvider, apiKey: apiKey.trim() },
        { onSuccess: () => invalidateAll(), onError: (e) => console.error('Sync failed:', e) },
      );

      resetForm();
      toast.success(`${selectedProviderData.name} integration added`);
    } catch (error) {
      console.error('Failed to save integration:', error);
      toast.error('Failed to save integration. Please try again.');
    }
  };

  // ---- Toggle active ----

  const handleToggleActive = async (id: number, isActive: boolean) => {
    try {
      await toggleActiveMutation.mutateAsync({ id, isActive });
      invalidateAll();
      toast.success(isActive ? 'Integration enabled' : 'Integration disabled');
    } catch (error) {
      console.error('Toggle failed:', error);
      toast.error('Failed to toggle integration');
    }
  };

  // ---- Remove integration ----

  const handleRemoveIntegration = async (provider: string, name: string) => {
    try {
      await deleteByProviderMutation.mutateAsync({ provider });
      invalidateAll();
      toast.success(`${name} integration removed`);
    } catch (error) {
      console.error('Delete failed:', error);
      toast.error('Failed to remove integration');
    }
  };

  // ---- Verify connection ----

  const handleVerifyConnection = async (integration: (typeof integrations)[number]) => {
    if (!integration.apiKey || integration.apiKey === 'built-in') return;

    setVerifyingIds((prev) => new Set(prev).add(integration.id));

    try {
      // Re-sync using the STORED key (no re-entry): the backend re-fetches the
      // provider's live model list and upserts it — picking up newly released
      // models (e.g. GPT-5) and marking removed ones unavailable.
      const res: any = await resyncMutation.mutateAsync({ provider: integration.provider });
      toast.success(`Refreshed ${integration.provider} — ${res?.total ?? 0} model(s) synced`);
      invalidateAll();
    } catch (e: any) {
      toast.error(e?.message ?? 'Refresh failed — check the API key');
    } finally {
      setVerifyingIds((prev) => {
        const next = new Set(prev);
        next.delete(integration.id);
        return next;
      });
    }
  };

  // ---- All Models expandable section ----

  /** Derives a human-readable type tag from capability flags */
  function deriveModelType(m: { canGenerateImage: boolean; canEditImage: boolean; canGenerateVideo: boolean; canEditVideo: boolean; canGenerateText: boolean; canVision: boolean }): string {
    const types: string[] = [];
    if (m.canGenerateImage || m.canEditImage) types.push('image');
    if (m.canGenerateVideo || m.canEditVideo) types.push('video');
    if (m.canGenerateText) types.push('text');
    if (m.canVision) types.push('vision');
    return types.join(', ') || 'unknown';
  }

  function AllModelsSection({ provider, isExpanded, onToggle, totalCount }: {
    provider: string;
    isExpanded: boolean;
    onToggle: () => void;
    totalCount: number;
  }) {
    // Lazy-load: only fetch when expanded
    const modelsQuery = trpc.models.listByProvider.useQuery(
      { provider },
      { enabled: isExpanded, staleTime: 30_000 }
    );

    const allModels = modelsQuery.data ?? [];

    return (
      <div className="mt-2">
        <button
          onClick={onToggle}
          className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors"
        >
          {isExpanded ? <ChevronDown className="w-3.5 h-3.5" /> : <ChevronRight className="w-3.5 h-3.5" />}
          <span>All Models ({totalCount})</span>
        </button>

        {isExpanded && (
          <div className="mt-2 bg-muted/30 rounded-lg border border-border/50 max-h-[300px] overflow-y-auto">
            {modelsQuery.isLoading ? (
              <div className="flex items-center justify-center py-4">
                <Loader2 className="w-4 h-4 animate-spin text-muted-foreground" />
                <span className="ml-2 text-xs text-muted-foreground">Loading models...</span>
              </div>
            ) : modelsQuery.isError ? (
              <div className="p-3 text-xs text-destructive">
                Error loading models: {modelsQuery.error?.message ?? 'Unknown error'}
              </div>
            ) : allModels.length === 0 ? (
              <div className="p-3 text-xs text-muted-foreground">No models found in database.</div>
            ) : (
              <div className="divide-y divide-border/30">
                {allModels.map((model) => {
                  const typeStr = deriveModelType(model);
                  const displayName = model.customName || model.originalName;
                  return (
                    <div key={model.id} className="flex items-center justify-between px-3 py-1.5 text-xs">
                      <div className="flex items-center gap-2 min-w-0">
                        {typeStr.includes('image') && <Image className="w-3 h-3 text-blue-500 flex-shrink-0" />}
                        {typeStr.includes('video') && <Video className="w-3 h-3 text-purple-500 flex-shrink-0" />}
                        {typeStr.includes('text') && <Type className="w-3 h-3 text-amber-500 flex-shrink-0" />}
                        {typeStr === 'unknown' && <Music className="w-3 h-3 text-muted-foreground flex-shrink-0" />}
                        <span className="truncate font-medium">{displayName}</span>
                      </div>
                      <div className="flex items-center gap-1.5 flex-shrink-0 ml-2">
                        <span className="text-[10px] px-1.5 py-0.5 rounded bg-muted text-muted-foreground">
                          {typeStr}
                        </span>
                        {!model.isEnabled && (
                          <span className="text-[10px] px-1.5 py-0.5 rounded bg-destructive/10 text-destructive">
                            disabled
                          </span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </div>
    );
  }

  // ---- Capability badges (read-only) ----

  const CapabilityBadge = ({ label, supported, icon }: { label: string; supported: boolean; icon: React.ReactNode }) => (
    <span
      className={`text-xs px-2 py-0.5 rounded-full flex items-center gap-1 ${
        supported
          ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400'
          : 'bg-muted/50 text-muted-foreground/50'
      }`}
    >
      {supported ? <Check className="w-3 h-3" /> : icon}
      {label}
    </span>
  );

  // ---- Render ----

  return (
    <div className="module-container animate-fade-in">
      <ModuleHeader
        title="Integration Center"
        description="Manage API integrations. Toggle integrations on/off to control which models appear in generation modules."
        action={
          <Dialog
            open={isAddDialogOpen}
            onOpenChange={(open) => {
              setIsAddDialogOpen(open);
              if (!open) resetForm();
            }}
          >
            <DialogTrigger asChild>
              <Button className="gap-2">
                <Plus className="w-4 h-4" />
                Add Integration
              </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[600px] max-h-[80vh] overflow-y-auto">
              <DialogHeader>
                <DialogTitle>Add Integration</DialogTitle>
                <DialogDescription>Connect an AI provider to enable image, video, and text generation</DialogDescription>
              </DialogHeader>

              <div className="space-y-4 py-4">
                {/* Provider Selection */}
                <div className="space-y-2">
                  <Label>Provider</Label>
                  <Select
                    value={selectedProvider}
                    onValueChange={(value) => {
                      setSelectedProvider(value);
                      setApiKey('');
                      setValidationResult(null);
                      setHasValidated(false);
                    }}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select a provider" />
                    </SelectTrigger>
                    <SelectContent>
                      {providers.map((provider) => (
                        <SelectItem key={provider.id} value={provider.id}>
                          <div className="flex items-center gap-2">
                            {provider.id === 'manus' ? (
                              <Sparkles className="w-4 h-4 text-primary" />
                            ) : (
                              <Plug className="w-4 h-4" />
                            )}
                            {provider.name}
                            {provider.id === 'manus' && (
                              <span className="text-xs text-muted-foreground">(No API key needed)</span>
                            )}
                          </div>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* API Key Input (non-Manus) */}
                {selectedProvider && selectedProvider !== 'manus' && (
                  <div className="space-y-2">
                    <Label>API Key</Label>
                    <div className="flex gap-2">
                      <div className="relative flex-1">
                        <Key className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                        <Input
                          type="password"
                          placeholder="Enter your API key"
                          value={apiKey}
                          onChange={(e) => {
                            setApiKey(e.target.value);
                            setValidationResult(null);
                            setHasValidated(false);
                          }}
                          className="pl-10"
                        />
                      </div>
                      <Button variant="outline" onClick={handleValidateApiKey} disabled={isValidating || !apiKey.trim()}>
                        {isValidating ? <Loader2 className="w-4 h-4 animate-spin" /> : 'Validate'}
                      </Button>
                    </div>
                    {selectedProviderData?.apiKeyUrl && (
                      <a
                        href={selectedProviderData.apiKeyUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="text-xs text-primary hover:underline inline-flex items-center gap-1"
                      >
                        Get API key <ExternalLink className="w-3 h-3" />
                      </a>
                    )}
                  </div>
                )}

                {/* Manus Built-in Notice */}
                {selectedProvider === 'manus' && (
                  <div className="bg-primary/5 border border-primary/20 rounded-lg p-3">
                    <div className="flex items-start gap-2">
                      <Sparkles className="w-4 h-4 text-primary mt-0.5" />
                      <div>
                        <p className="text-sm font-medium text-primary">Built-in Integration</p>
                        <p className="text-xs text-muted-foreground mt-1">
                          Manus uses the built-in API and does not require an external API key. Usage is subject to platform limits.
                        </p>
                      </div>
                    </div>
                  </div>
                )}

                {/* Validation Result */}
                {hasValidated && validationResult && (
                  <div
                    className={`rounded-lg p-4 border ${
                      validationResult.valid
                        ? 'bg-emerald-50 border-emerald-200 dark:bg-emerald-950/20 dark:border-emerald-800'
                        : 'bg-destructive/10 border-destructive/20'
                    }`}
                  >
                    <div className="flex items-center gap-2 mb-3">
                      {validationResult.valid ? (
                        <>
                          <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                          <span className="font-medium text-emerald-700 dark:text-emerald-400">API Key Valid</span>
                        </>
                      ) : (
                        <>
                          <XCircle className="w-5 h-5 text-destructive" />
                          <span className="font-medium text-destructive">{validationResult.error || 'Invalid API Key'}</span>
                        </>
                      )}
                    </div>

                    {validationResult.valid && (
                      <>
                        {/* Capabilities */}
                        <div className="space-y-2 mb-4">
                          <Label className="text-sm">Detected Capabilities</Label>
                          <div className="flex gap-3 flex-wrap">
                            {[
                              { key: 'image', label: 'Image', icon: <Image className="w-4 h-4" /> },
                              { key: 'video', label: 'Video', icon: <Video className="w-4 h-4" /> },
                              { key: 'text', label: 'Text', icon: <Type className="w-4 h-4" /> },
                              { key: 'vision', label: 'Vision', icon: <Eye className="w-4 h-4" /> },
                            ].map(({ key, label, icon }) => {
                              const supported = validationResult.capabilities[key as keyof typeof validationResult.capabilities];
                              return (
                                <div
                                  key={key}
                                  className={`flex items-center gap-2 px-3 py-2 rounded-md ${
                                    supported ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-muted opacity-50'
                                  }`}
                                >
                                  {supported ? <Check className="w-4 h-4 text-emerald-600" /> : <X className="w-4 h-4 text-muted-foreground" />}
                                  {icon}
                                  <span className="text-sm">{label}</span>
                                </div>
                              );
                            })}
                          </div>
                        </div>

                        {/* Available Models */}
                        {validationResult.models.length > 0 && (
                          <div className="space-y-2">
                            <Label className="text-sm">Available Models ({validationResult.models.length})</Label>
                            <div className="bg-background/50 rounded-lg p-3 space-y-2 max-h-[200px] overflow-y-auto">
                              {validationResult.models.map((model) => (
                                <div key={model.id} className="flex items-center gap-2 text-sm">
                                  <Check className="w-3 h-3 text-emerald-500 flex-shrink-0" />
                                  <span className="font-medium">{model.name}</span>
                                  <span className="text-xs text-muted-foreground px-1.5 py-0.5 bg-muted rounded">{model.type}</span>
                                </div>
                              ))}
                            </div>
                          </div>
                        )}
                      </>
                    )}
                  </div>
                )}
              </div>

              <DialogFooter>
                <Button variant="outline" onClick={() => setIsAddDialogOpen(false)}>
                  Cancel
                </Button>
                <Button
                  onClick={handleAddIntegration}
                  disabled={!selectedProvider || (selectedProvider !== 'manus' && (!hasValidated || !validationResult?.valid))}
                >
                  Add Integration
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        }
      />

      {/* Integrations List */}
      {integrationsQuery.isLoading ? (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="w-6 h-6 animate-spin text-muted-foreground" />
          <span className="ml-2 text-muted-foreground">Loading integrations...</span>
        </div>
      ) : integrations.length === 0 ? (
        <EmptyState
          icon={<Plug className="w-12 h-12" />}
          title="No integrations yet"
          description="Add your first integration to start generating AI-powered content"
          action={
            <Button onClick={() => setIsAddDialogOpen(true)} className="gap-2">
              <Plus className="w-4 h-4" />
              Add Integration
            </Button>
          }
        />
      ) : (
        <div className="grid gap-4">
          {integrations.map((integration) => {
            const parsedModels: Array<{ id: string; name: string; type: string }> =
              Array.isArray(integration.models) ? integration.models : [];
            const imageModelCount = parsedModels.filter((m) => m.type === 'image').length;
            const videoModelCount = parsedModels.filter((m) => m.type === 'video').length;
            const textModelCount = parsedModels.filter((m) => m.type === 'text').length;
            const visionModelCount = parsedModels.filter((m) => m.type === 'vision').length;

            return (
              <div
                key={integration.id}
                className={`card-powerkeys p-4 transition-opacity ${!integration.isActive ? 'opacity-60' : ''}`}
              >
                <div className="flex items-start justify-between">
                  <div className="flex items-start gap-3">
                    {/* Provider icon */}
                    <div
                      className={`p-2 rounded-lg ${
                        integration.provider === 'manus' ? 'bg-primary/10 text-primary' : 'bg-muted'
                      }`}
                    >
                      {integration.provider === 'manus' ? <Sparkles className="w-4 h-4" /> : <Plug className="w-4 h-4" />}
                    </div>

                    <div>
                      {/* Name + built-in badge */}
                      <h3 className="font-medium flex items-center gap-2">
                        {integration.name}
                        {integration.provider === 'manus' && (
                          <span className="text-xs bg-primary/10 text-primary px-2 py-0.5 rounded-full">Built-in</span>
                        )}
                      </h3>

                      {/* API key preview */}
                      <p className="text-sm text-muted-foreground mt-0.5">
                        {integration.apiKey === 'built-in' ? 'No API key required' : `API Key: ${integration.apiKey}`}
                      </p>

                      {/* Capability badges (read-only) */}
                      <div className="flex items-center gap-2 mt-2 flex-wrap">
                        {imageModelCount > 0 && (
                          <CapabilityBadge label={`Image (${imageModelCount})`} supported icon={<Image className="w-3 h-3" />} />
                        )}
                        {videoModelCount > 0 && (
                          <CapabilityBadge label={`Video (${videoModelCount})`} supported icon={<Video className="w-3 h-3" />} />
                        )}
                        {textModelCount > 0 && (
                          <CapabilityBadge label={`Text (${textModelCount})`} supported icon={<Type className="w-3 h-3" />} />
                        )}
                        {visionModelCount > 0 && (
                          <CapabilityBadge label={`Vision (${visionModelCount})`} supported icon={<Eye className="w-3 h-3" />} />
                        )}
                        {parsedModels.length === 0 && (
                          <span className="text-xs text-muted-foreground">No models detected</span>
                        )}
                      </div>

                      {/* All Models expandable list */}
                      {parsedModels.length > 0 && (
                        <AllModelsSection
                          provider={integration.provider}
                          isExpanded={expandedProviders.has(integration.provider)}
                          onToggle={() => toggleProviderExpanded(integration.provider)}
                          totalCount={parsedModels.length}
                        />
                      )}

                      {/* Add Custom Model button for Kie.ai */}
                      {integration.provider === 'kieai' && integration.isActive && (
                        <Dialog open={isCustomModelOpen} onOpenChange={setIsCustomModelOpen}>
                          <DialogTrigger asChild>
                            <Button variant="outline" size="sm" className="mt-2 gap-1.5 text-xs">
                              <Plus className="w-3 h-3" />
                              Add Custom Model
                            </Button>
                          </DialogTrigger>
                          <DialogContent className="sm:max-w-[420px]">
                            <DialogHeader>
                              <DialogTitle>Add Custom Kie.ai Model</DialogTitle>
                              <DialogDescription>
                                Add a model from <a href="https://kie.ai/market" target="_blank" rel="noopener noreferrer" className="text-primary hover:underline">kie.ai/market</a>. Uses your existing Kie.ai API key.
                              </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-4 py-2">
                              <div className="space-y-2">
                                <Label>Model Name <span className="text-destructive">*</span></Label>
                                <Input
                                  placeholder="e.g. seedream/v4.5"
                                  value={customModelName}
                                  onChange={(e) => setCustomModelName(e.target.value)}
                                />
                                <p className="text-xs text-muted-foreground">The model identifier from Kie.ai docs (used in API calls)</p>
                              </div>
                              <div className="space-y-2">
                                <Label>Display Name <span className="text-destructive">*</span></Label>
                                <Input
                                  placeholder="e.g. Seedream 4.5"
                                  value={customDisplayName}
                                  onChange={(e) => setCustomDisplayName(e.target.value)}
                                />
                              </div>
                              <div className="space-y-2">
                                <Label>Type</Label>
                                <Select value={customModelType} onValueChange={(v) => setCustomModelType(v as 'image' | 'video')}>
                                  <SelectTrigger>
                                    <SelectValue />
                                  </SelectTrigger>
                                  <SelectContent>
                                    <SelectItem value="image">Image</SelectItem>
                                    <SelectItem value="video">Video</SelectItem>
                                  </SelectContent>
                                </Select>
                              </div>
                            </div>
                            <DialogFooter>
                              <Button variant="outline" onClick={() => setIsCustomModelOpen(false)}>Cancel</Button>
                              <Button onClick={handleAddCustomKieModel} disabled={addCustomKieModelMutation.isPending}>
                                {addCustomKieModelMutation.isPending ? <Loader2 className="w-4 h-4 animate-spin mr-2" /> : null}
                                Add Model
                              </Button>
                            </DialogFooter>
                          </DialogContent>
                        </Dialog>
                      )}

                      {/* ProRankTracker live connection test */}
                      {integration.provider === 'proranktracker' && integration.isActive && (
                        <PrtLiveTestSection />
                      )}
                    </div>
                  </div>

                  {/* Controls */}
                  <div className="flex items-center gap-2">
                    {/* Connection indicator */}
                    {integration.provider !== 'manus' && (
                      <div className="flex items-center gap-1">
                        <div
                          className={`w-2 h-2 rounded-full ${
                            integration.isActive ? 'bg-emerald-500' : 'bg-gray-400'
                          }`}
                          title={integration.isActive ? 'Active' : 'Inactive'}
                        />
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 text-muted-foreground hover:text-primary"
                          onClick={() => handleVerifyConnection(integration)}
                          disabled={verifyingIds.has(integration.id)}
                          title="Refresh models (re-sync latest)"
                        >
                          {verifyingIds.has(integration.id) ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <RefreshCw className="w-3.5 h-3.5" />
                          )}
                        </Button>
                      </div>
                    )}

                    {/* Master on/off switch */}
                    <Switch
                      checked={integration.isActive}
                      onCheckedChange={(checked) => handleToggleActive(integration.id, checked)}
                    />

                    {/* Delete */}
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => handleRemoveIntegration(integration.provider, integration.name)}
                      className="text-muted-foreground hover:text-destructive"
                    >
                      <Trash2 className="w-4 h-4" />
                    </Button>
                  </div>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
