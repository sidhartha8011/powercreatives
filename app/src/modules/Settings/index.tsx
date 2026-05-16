/**
 * CREATIVE MACHINE - Settings Module
 * App-wide settings that all modules subscribe to
 *
 * Layout: Horizontal tabbed navigation (Radix Tabs)
 * Tabs: AI Models | Prompts | General
 *
 * Uses the same Tabs component pattern as PromptEditorSection
 * for consistency across the codebase.
 */

import { useSettings } from '@/contexts/AppContext';
import { useImageModelsForGeneration, useVideoModelsForGeneration } from '@/hooks/useModelsForGeneration';
import { useTextModels } from '@/modules/Copy/useTextModels';
import { ModuleHeader } from '@/components/shared/ModuleHeader';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Settings as SettingsIcon,
  Palette,
  Globe,
  Image,
  Video,
  Bell,
  Save,
  Check,
  Database,
  MessageSquareCode,
  SlidersHorizontal,
  PenLine,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ModelRegistrySection } from './ModelRegistrySection';
import { PromptEditorSection } from './PromptEditorSection';
import MultipleSelector from '@/components/ui/multiple-selector';

export function SettingsModule() {
  const { settings, updateSettings } = useSettings();
  const { imageModels: imageModelsList } = useImageModelsForGeneration();
  const { videoModels: videoModelsList } = useVideoModelsForGeneration();
  const { textModels: textModelsList } = useTextModels();
  const [hasChanges, setHasChanges] = useState(false);

  // Map to simple {id, name} for select dropdowns
  const imageModels = imageModelsList.map(m => ({ id: m.id, name: m.name }));
  const videoModels = videoModelsList.map(m => ({ id: m.id, name: m.name }));
  const textModels = textModelsList.map(m => ({ id: m.id, name: m.name }));

  const handleSettingChange = <K extends keyof typeof settings>(
    key: K,
    value: typeof settings[K]
  ) => {
    updateSettings({ [key]: value });
    setHasChanges(true);
  };

  const handleSave = () => {
    // Settings are auto-saved via context, but we show feedback
    setHasChanges(false);
    toast.success('Settings saved successfully');
  };

  return (
    <div className="module-container animate-fade-in">
      <ModuleHeader
        title="Settings"
        description="Configure app-wide preferences and defaults"
        action={
          hasChanges ? (
            <Button onClick={handleSave} className="gap-2">
              <Save className="w-4 h-4" />
              Save Changes
            </Button>
          ) : (
            <Button variant="outline" disabled className="gap-2">
              <Check className="w-4 h-4" />
              Saved
            </Button>
          )
        }
      />

      {/* Tabbed navigation — groups settings into logical categories */}
      <Tabs defaultValue="registry" className="w-full">
        <TabsList className="mb-6">
          <TabsTrigger value="registry">Model Registry</TabsTrigger>
          <TabsTrigger value="defaults">Module Defaults</TabsTrigger>
          <TabsTrigger value="prompts">Prompts</TabsTrigger>
          <TabsTrigger value="general">General</TabsTrigger>
        </TabsList>

        {/* ── Tab: Model Registry ─────────────────────────── */}
        <TabsContent value="registry">
          <div className="space-y-6">
            {/* Model Registry Section - Primary Feature (full width) */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                  <Database className="w-4 h-4 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground">Model Registry</h3>
                  <p className="text-xs text-muted-foreground">
                    Manage AI model capabilities, pricing tiers, and settings
                  </p>
                </div>
              </div>

              <div className="mt-4">
                <ModelRegistrySection />
              </div>
            </section>
          </div>
        </TabsContent>

        {/* ── Tab: Module Defaults ────────────────────────── */}
        <TabsContent value="defaults">
          <div className="max-w-4xl space-y-4">

            {/* ── Image Module ── */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-7 h-7 rounded-md bg-primary/10 flex items-center justify-center">
                  <Image className="w-3.5 h-3.5 text-primary" />
                </div>
                <h3 className="text-sm font-medium text-foreground">Image Module</h3>
              </div>
              <div className="flex items-center justify-between pl-10">
                <div>
                  <Label className="text-sm">Default Models</Label>
                  <p className="text-xs text-muted-foreground">Pre-selected models when opening Image module</p>
                </div>
                <div className="w-[280px]">
                  <MultipleSelector
                    value={(settings.defaultImageModels || [])
                      .map(id => imageModels.find(m => m.id === id))
                      .filter(Boolean)
                      .map(m => ({ value: m!.id, label: m!.name }))}
                    options={imageModels.map(m => ({ value: m.id, label: m.name }))}
                    onChange={(opts) => handleSettingChange('defaultImageModels', opts.map(o => o.value))}
                    placeholder="Select default models..."
                    showCheckboxes
                    hidePlaceholderWhenSelected
                    emptyIndicator={<span className="text-xs text-muted-foreground">No models available</span>}
                  />
                </div>
              </div>

              {/* Menu Intelligence — text model used for suggestions, concepts, optimize brief */}
              <div className="flex items-center justify-between pl-10">
                <div>
                  <Label className="text-sm">Menu Intelligence</Label>
                  <p className="text-xs text-muted-foreground">Text model for suggestions, concepts, and brief optimization</p>
                </div>
                <Select
                  value={settings.defaultImageTextModel || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultImageTextModel', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No default (auto-detect)</SelectItem>
                    {textModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </section>

            {/* ── Video Module ── */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-7 h-7 rounded-md bg-primary/10 flex items-center justify-center">
                  <Video className="w-3.5 h-3.5 text-primary" />
                </div>
                <h3 className="text-sm font-medium text-foreground">Video Module</h3>
              </div>
              <div className="flex items-center justify-between pl-10 mb-3">
                <div>
                  <Label className="text-sm">Default Model</Label>
                  <p className="text-xs text-muted-foreground">Pre-selected model for video generation</p>
                </div>
                <Select
                  value={settings.defaultVideoModel || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultVideoModel', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No default</SelectItem>
                    {videoModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Menu Intelligence — text model used for concepts, enhance, compose */}
              <div className="flex items-center justify-between pl-10">
                <div>
                  <Label className="text-sm">Menu Intelligence</Label>
                  <p className="text-xs text-muted-foreground">Text model for concepts, prompt enhancement, and composition</p>
                </div>
                <Select
                  value={settings.defaultVideoTextModel || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultVideoTextModel', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No default (auto-detect)</SelectItem>
                    {textModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </section>

            {/* ── Copy Module ── */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-7 h-7 rounded-md bg-primary/10 flex items-center justify-center">
                  <MessageSquareCode className="w-3.5 h-3.5 text-primary" />
                </div>
                <h3 className="text-sm font-medium text-foreground">Copy Module</h3>
              </div>

              {/* Menu Intelligence — used for copy generation, angles, audiences */}
              <div className="flex items-center justify-between pl-10 mb-3">
                <div>
                  <Label className="text-sm">Menu Intelligence</Label>
                  <p className="text-xs text-muted-foreground">Model for copy generation, angles, and audiences</p>
                </div>
                <Select
                  value={settings.defaultCopyMenuIntelligence || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultCopyMenuIntelligence', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none" className="text-muted-foreground italic">— Select a model —</SelectItem>
                    {textModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Research Model — used for audience market research (grounding/search) */}
              <div className="flex items-center justify-between pl-10 mb-3">
                <div>
                  <Label className="text-sm">Research Model</Label>
                  <p className="text-xs text-muted-foreground">Model for audience research (Google Search grounding)</p>
                </div>
                <Select
                  value={settings.defaultCopyResearchModel || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultCopyResearchModel', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No default (auto-detect)</SelectItem>
                    {textModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Scraping Model — used for URL scraping and text analysis */}
              <div className="flex items-center justify-between pl-10">
                <div>
                  <Label className="text-sm">Scraping Model</Label>
                  <p className="text-xs text-muted-foreground">Model used for URL scraping and text analysis</p>
                </div>
                <Select
                  value={settings.defaultTextModel || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultTextModel', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No default (auto-detect)</SelectItem>
                    {textModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </section>

            {/* ── Writer Module ── */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-7 h-7 rounded-md bg-primary/10 flex items-center justify-center">
                  <PenLine className="w-3.5 h-3.5 text-primary" />
                </div>
                <h3 className="text-sm font-medium text-foreground">Writer Module</h3>
              </div>

              {/* Default Model — used for article generation */}
              <div className="flex items-center justify-between pl-10">
                <div>
                  <Label className="text-sm">Default Model</Label>
                  <p className="text-xs text-muted-foreground">Pre-selected model for article generation</p>
                </div>
                <Select
                  value={settings.defaultWriterModel || 'none'}
                  onValueChange={(value) => handleSettingChange('defaultWriterModel', value === 'none' ? null : value)}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select model" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">No default (auto-detect)</SelectItem>
                    {textModels.map(model => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </section>

          </div>
        </TabsContent>

        {/* ── Tab: Prompts ────────────────────────────────────── */}
        <TabsContent value="prompts">
          <section className="card-powerkeys p-5">
            <div className="flex items-center gap-3 mb-4">
              <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                <MessageSquareCode className="w-4 h-4 text-primary" />
              </div>
              <div>
                <h3 className="font-medium text-foreground">Prompt Editor</h3>
                <p className="text-xs text-muted-foreground">
                  Edit system prompts used for AI generation. Changes take effect immediately.
                </p>
              </div>
            </div>

            <div className="mt-4">
              <PromptEditorSection />
            </div>
          </section>
        </TabsContent>

        {/* ── Tab: General ─────────────────────────────────── */}
        <TabsContent value="general">
          <div className="max-w-4xl space-y-6">
            {/* Appearance Section */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                  <Palette className="w-4 h-4 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground">Appearance</h3>
                  <p className="text-xs text-muted-foreground">Customize how the app looks</p>
                </div>
              </div>

              <div className="space-y-4 pl-11">
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-sm font-medium">Theme</Label>
                    <p className="text-xs text-muted-foreground">Choose your preferred color scheme</p>
                  </div>
                  <Select
                    value={settings.theme}
                    onValueChange={(value: 'light' | 'dark' | 'system') => handleSettingChange('theme', value)}
                  >
                    <SelectTrigger className="w-[140px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="light">Light</SelectItem>
                      <SelectItem value="dark">Dark</SelectItem>
                      <SelectItem value="system">System</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </section>

            {/* Language Section */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                  <Globe className="w-4 h-4 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground">Language</h3>
                  <p className="text-xs text-muted-foreground">Set your preferred language</p>
                </div>
              </div>

              <div className="space-y-4 pl-11">
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-sm font-medium">Display Language</Label>
                    <p className="text-xs text-muted-foreground">Language for the interface</p>
                  </div>
                  <Select
                    value={settings.language}
                    onValueChange={(value: 'en' | 'sv') => handleSettingChange('language', value)}
                  >
                    <SelectTrigger className="w-[140px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="en">English</SelectItem>
                      <SelectItem value="sv">Svenska</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </section>

            {/* Notifications Section */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                  <Bell className="w-4 h-4 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground">Notifications</h3>
                  <p className="text-xs text-muted-foreground">Configure notification preferences</p>
                </div>
              </div>

              <div className="space-y-4 pl-11">
                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-sm font-medium">Enable Notifications</Label>
                    <p className="text-xs text-muted-foreground">Receive notifications for completed generations</p>
                  </div>
                  <Switch
                    checked={settings.notifications}
                    onCheckedChange={(checked) => handleSettingChange('notifications', checked)}
                  />
                </div>

                <div className="flex items-center justify-between">
                  <div>
                    <Label className="text-sm font-medium">Auto-save</Label>
                    <p className="text-xs text-muted-foreground">Automatically save generated assets to projects</p>
                  </div>
                  <Switch
                    checked={settings.autoSave}
                    onCheckedChange={(checked) => handleSettingChange('autoSave', checked)}
                  />
                </div>
              </div>
            </section>

            {/* Token Budgets Section — Advanced */}
            <section className="card-powerkeys p-5">
              <div className="flex items-center gap-3 mb-4">
                <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                  <SlidersHorizontal className="w-4 h-4 text-primary" />
                </div>
                <div>
                  <h3 className="font-medium text-foreground">Token Budgets</h3>
                  <p className="text-xs text-muted-foreground">
                    Max tokens per AI operation. Includes reasoning for thinking models (Gemini 2.5+). Increase if outputs are truncated.
                  </p>
                </div>
              </div>

              <div className="space-y-4 pl-11">
                {([
                  { key: 'token_budget_audience' as const, label: 'Audience Generation', desc: 'Auto-generated target audiences' },
                  { key: 'token_budget_angle' as const, label: 'Angle Generation', desc: 'Auto-generated marketing angles' },
                  { key: 'token_budget_copy' as const, label: 'Copy Generation', desc: 'Ad copy, organic posts, URL extraction' },
                  { key: 'token_budget_video' as const, label: 'Video Intelligence', desc: 'Prompt enrichment, concepts, compose' },
                  { key: 'token_budget_writer' as const, label: 'Writer Generation', desc: 'Full article generation' },
                ] as const).map(({ key, label, desc }) => (
                  <div key={key} className="flex items-center justify-between">
                    <div>
                      <Label className="text-sm font-medium">{label}</Label>
                      <p className="text-xs text-muted-foreground">{desc}</p>
                    </div>
                    <Input
                      type="number"
                      min={1024}
                      max={65536}
                      step={1024}
                      value={settings[key] ?? 8192}
                      onChange={(e) => handleSettingChange(key, Number(e.target.value))}
                      className="w-[120px]"
                    />
                  </div>
                ))}
              </div>
            </section>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
