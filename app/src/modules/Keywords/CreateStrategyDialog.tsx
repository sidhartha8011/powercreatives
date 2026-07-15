import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { KeywordPicker } from '@/components/shared';
import { trpc } from '@/lib/trpc';
import { Loader2 } from 'lucide-react';
import {
  RecurrenceEditor,
  recurrenceFromConfig,
  recurrenceToConfig,
  type ScheduleRecurrence,
} from '../Strategies/RecurrenceEditor';

export interface StrategyPayload {
  name: string;
  templateId: number;
  /** LLM the articles are generated with. Empty → backend default (Gemini 2.5 Flash). */
  model?: string;
  provider?: string;
  structure: string;
  hierarchyMode: string;
  parentTargetUrl?: string;
  parentKeyword?: string;
  publishingMode: string;
  /** Connected site the strategy targets. Stored on every strategy; auto-publish
   *  pushes each article here when publishingMode === 'publish'. */
  siteId?: number;
  approvalMode: string;
  /** AutoPress parity: generate a featured image per article at creation time. */
  featuredImages?: boolean;
  /** AutoPress parity: insert in-content images & charts ([IMAGE_N] media_assets). Default on. */
  inContentMedia?: boolean;
  /** AutoPress parity: research the topic (Google-grounded Gemini) before writing. */
  research?: boolean;
  interlinksConfig?: any;
  scheduleConfig?: any;
}

interface CreateStrategyDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  defaultName: string;
  onSave: (payload: StrategyPayload) => void;
  isSaving: boolean;
  selectedCount: number;
  selectedKeywords: string[];
}

export function CreateStrategyDialog({
  open,
  onOpenChange,
  defaultName,
  onSave,
  isSaving,
  selectedCount,
  selectedKeywords,
}: CreateStrategyDialogProps) {
  const [name, setName] = useState(selectedKeywords?.[0] || '');
  const [templateId, setTemplateId] = useState<string>('');
  const [modelId, setModelId] = useState<string>('');
  const [structure, setStructure] = useState('individual');

  const [hierarchyMode, setHierarchyMode] = useState('standalone');
  const [parentTargetType, setParentTargetType] = useState('custom');
  const [parentTargetUrl, setParentTargetUrl] = useState('');
  const [parentKeywordIndex, setParentKeywordIndex] = useState(0);
  
  const [autoInterlink, setAutoInterlink] = useState(false);
  const [interlinkQuantity, setInterlinkQuantity] = useState(3);
  const [interlinkMode, setInterlinkMode] = useState('auto');
  const [featuredImages, setFeaturedImages] = useState(true);
  const [inContentMedia, setInContentMedia] = useState(true);
  const [research, setResearch] = useState(true);

  const [publishingMode, setPublishingMode] = useState('draft');
  const [siteId, setSiteId] = useState<string>('');
  const [approvalMode, setApprovalMode] = useState('none');
  const [recurrence, setRecurrence] = useState<ScheduleRecurrence>(() => recurrenceFromConfig({}));
  const [startDate, setStartDate] = useState('');

  React.useEffect(() => {
    if (open) {
      setName(selectedKeywords?.[0] || '');
      setTemplateId('');
      setModelId('');
      setStructure('individual');
      setHierarchyMode('standalone');
      setParentTargetType('custom');
      setParentTargetUrl('');
      setParentKeywordIndex(0);
      setAutoInterlink(false);
      setInterlinkQuantity(3);
      setInterlinkMode('auto');
      setFeaturedImages(true);
      setInContentMedia(true);
      setResearch(true);
      setPublishingMode('draft');
      setSiteId('');
      setApprovalMode('none');
      setRecurrence(recurrenceFromConfig({}));
      setStartDate('');
    }
  }, [open]);

  const { data: templates, isLoading: templatesLoading } = trpc.templates.list.useQuery({ module: 'writer' });
  const { data: genModels = [], isLoading: modelsLoading } = trpc.models.getForGeneration.useQuery(
    { type: 'text' },
    { staleTime: 30_000 },
  );
  const { data: sites = [], isLoading: sitesLoading } = trpc.sites.list.useQuery(undefined, {
    staleTime: 30_000,
  });

  // Default the Target Site to the first connected site (mirrors AutoPress, where a
  // strategy is always bound to a site). Functional updater only fills an EMPTY
  // selection, so it never clobbers an explicit choice nor races the open-reset effect.
  React.useEffect(() => {
    if (!open) return;
    const list = sites as any[];
    if (list.length > 0) {
      setSiteId((cur) => cur || String(list[0].id));
    }
  }, [open, sites]);

  // Consolidated = one article for all keywords, so hierarchy is meaningless.
  // Selecting it forces hierarchy back to standalone (belt); the payload also
  // normalizes it below (braces) so parent keys never leak when consolidated.
  const handleStructureChange = (value: string) => {
    setStructure(value);
    if (value === 'consolidated') setHierarchyMode('standalone');
  };

  const handleSave = () => {
    if (!templateId) return;

    const finalName = name.trim() ? name.trim() : defaultName;
    const selectedModel = (genModels as any[]).find((m) => m.modelId === modelId);
    const effectiveHierarchy = structure === 'consolidated' ? 'standalone' : hierarchyMode;

    onSave({
      name: finalName,
      templateId: parseInt(templateId, 10),
      model: modelId || undefined,
      provider: selectedModel?.provider || undefined,
      structure,
      hierarchyMode: effectiveHierarchy,
      parentTargetUrl: effectiveHierarchy === 'children_only' ? parentTargetUrl : undefined,
      parentKeyword: effectiveHierarchy === 'parent_and_children' ? selectedKeywords[parentKeywordIndex] : undefined,
      publishingMode,
      siteId: siteId ? parseInt(siteId, 10) : undefined,
      approvalMode,
      featuredImages,
      inContentMedia,
      research,
      interlinksConfig: autoInterlink ? { mode: interlinkMode, quantity: interlinkQuantity } : undefined,
      scheduleConfig: publishingMode === 'schedule' ? { ...recurrenceToConfig(recurrence), startDate } : undefined,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[550px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Strategy ({selectedCount} Keywords)</DialogTitle>
        </DialogHeader>
        
        <div className="py-2 space-y-4">

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="strategy-name">Strategy Name (Optional)</Label>
              <Input
                id="strategy-name"
                placeholder={defaultName}
                value={name}
                onChange={(e) => setName(e.target.value)}
                className="w-full bg-background"
              />
            </div>
            <div className="space-y-1.5">
              <Label
                htmlFor="target-site"
                title="The site this strategy is for. When Publishing Mode is Publish Automatically, each generated article is published here."
              >
                Target Site
              </Label>
              <Select value={siteId} onValueChange={setSiteId}>
                <SelectTrigger id="target-site" className="w-full bg-background">
                  <SelectValue placeholder={sitesLoading ? 'Loading sites…' : 'Select a connected site...'} />
                </SelectTrigger>
                <SelectContent>
                  {(sites as any[]).length > 0 ? (
                    (sites as any[]).map((s) => (
                      <SelectItem key={s.id} value={s.id.toString()}>
                        {s.name || s.url}
                      </SelectItem>
                    ))
                  ) : (
                    <div className="p-2 text-sm text-muted-foreground text-center">
                      No connected sites — add one in the Sites module.
                    </div>
                  )}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
               <Label>Workflow (Prompt)</Label>
               <Select value={templateId} onValueChange={setTemplateId}>
                 <SelectTrigger className="w-full bg-background">
                   <SelectValue placeholder="Select Template..." />
                 </SelectTrigger>
                 <SelectContent>
                   {templatesLoading ? (
                     <div className="flex items-center p-2 text-sm text-muted-foreground">
                       <Loader2 className="mr-2 h-4 w-4 animate-spin" /> Fetching...
                     </div>
                   ) : templates && templates.length > 0 ? (
                     templates.map((t: any) => (
                       <SelectItem key={t.id} value={t.id.toString()}>
                         {t.name}
                       </SelectItem>
                     ))
                   ) : (
                     <div className="p-2 text-sm text-muted-foreground text-center">
                       No Writer templates found.
                     </div>
                   )}
                 </SelectContent>
               </Select>
            </div>
            <div className="space-y-1.5">
               <Label>Content per Keyword</Label>
               <Select value={structure} onValueChange={handleStructureChange}>
                 <SelectTrigger className="w-full bg-background">
                   <SelectValue />
                 </SelectTrigger>
                 <SelectContent>
                   <SelectItem value="individual">1 per Keyword</SelectItem>
                   <SelectItem value="consolidated">Consolidated</SelectItem>
                 </SelectContent>
               </Select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label
                htmlFor="ai-model"
                title="Which model writes each article. Leave unset to use the default."
              >
                AI Model
              </Label>
              <Select value={modelId} onValueChange={setModelId}>
                <SelectTrigger id="ai-model" className="w-full bg-background">
                  <SelectValue placeholder={modelsLoading ? 'Loading models…' : 'Default (Gemini 2.5 Flash)'} />
                </SelectTrigger>
                <SelectContent>
                  {(genModels as any[]).length > 0 ? (
                    (genModels as any[]).map((m) => (
                      <SelectItem key={m.modelId} value={m.modelId}>
                        {(m.customName || m.originalName || m.modelId)} ({m.provider})
                      </SelectItem>
                    ))
                  ) : (
                    <div className="p-2 text-sm text-muted-foreground text-center">
                      No text models registered — add one in Settings → Models.
                    </div>
                  )}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label>Content Hierarchy</Label>
              <Select
                value={hierarchyMode}
                onValueChange={setHierarchyMode}
                disabled={structure === 'consolidated'}
              >
                <SelectTrigger className="w-full bg-background">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="standalone">Standalone (No Hierarchy)</SelectItem>
                  <SelectItem value="parent_only">Parent Only (Hub Page)</SelectItem>
                  <SelectItem value="children_only">Children Only (Link to Existing)</SelectItem>
                  <SelectItem value="parent_and_children">Parent + Children Together</SelectItem>
                </SelectContent>
              </Select>
              {structure === 'consolidated' && (
                <p className="text-xs text-muted-foreground">
                  Hierarchy applies to per-keyword strategies.
                </p>
              )}
            </div>
          </div>

          {structure !== 'consolidated' && hierarchyMode === 'children_only' && (
            <div className="space-y-1.5">
              <Label htmlFor="parent-url" title="All generated content will link to this parent.">
                Target Parent URL
              </Label>
              <Input
                 id="parent-url"
                 type="url"
                 value={parentTargetUrl}
                 onChange={(e) => setParentTargetUrl(e.target.value)}
                 placeholder="https://example.com/parent-page"
                 className="w-full bg-background"
              />
            </div>
          )}

          {structure !== 'consolidated' && hierarchyMode === 'parent_and_children' && selectedKeywords?.length > 0 && (
            <div className="space-y-1.5">
              <Label title="First article becomes parent, others link to it.">Select Parent Keyword</Label>
              <KeywordPicker
                keywords={selectedKeywords}
                selectedIndex={parentKeywordIndex}
                onSelect={setParentKeywordIndex}
                selectedLabel="Parent"
                unselectedLabel={null}
                maxHeight="8rem"
              />
            </div>
          )}

          <div className="space-y-2">
            <Label className="text-xs text-muted-foreground">Generation options</Label>
            <div className="space-y-2">
              <div
                className="flex items-center space-x-2"
                title="Automatically generate a featured image for each article when content is generated."
              >
                <Checkbox
                  id="featured-images"
                  checked={featuredImages}
                  onCheckedChange={(c) => setFeaturedImages(c as boolean)}
                />
                <Label htmlFor="featured-images" className="cursor-pointer font-medium leading-none">
                  Generate featured images
                </Label>
              </div>

              <div
                className="flex items-center space-x-2"
                title="Let the writer place supporting images and charts inline within each article body."
              >
                <Checkbox
                  id="in-content-media"
                  checked={inContentMedia}
                  onCheckedChange={(c) => setInContentMedia(c as boolean)}
                />
                <Label htmlFor="in-content-media" className="cursor-pointer font-medium leading-none">
                  In-content images &amp; charts
                </Label>
              </div>

              <div
                className="flex items-center space-x-2"
                title="Summarize the current search landscape (top themes, common questions, content gaps) and feed it into generation."
              >
                <Checkbox
                  id="research-topic"
                  checked={research}
                  onCheckedChange={(c) => setResearch(c as boolean)}
                />
                <Label htmlFor="research-topic" className="cursor-pointer font-medium leading-none">
                  Research the topic before writing
                </Label>
              </div>

              <div
                className="flex items-center space-x-2"
                title="Automatically inject internal links between articles when content is generated."
              >
                <Checkbox
                  id="auto-interlink"
                  checked={autoInterlink}
                  onCheckedChange={(c) => setAutoInterlink(c as boolean)}
                />
                <Label htmlFor="auto-interlink" className="cursor-pointer font-medium leading-none">
                  Auto-Interlink after generation
                </Label>
              </div>

              {autoInterlink && (
                <div className="ml-6 flex items-center justify-between gap-4 p-3 bg-muted/20 border rounded-md text-sm">
                  <div className="flex items-center space-x-3">
                    <span className="font-medium text-muted-foreground">Max Links:</span>
                    <Input
                      type="number"
                      min={1}
                      max={10}
                      value={interlinkQuantity}
                      onChange={(e) => setInterlinkQuantity(parseInt(e.target.value) || 3)}
                      className="w-16 h-8 bg-background"
                    />
                  </div>
                  <div className="flex items-center space-x-2">
                    <span className="font-medium text-muted-foreground">Mode:</span>
                    <span>Auto (Phrase)</span>
                  </div>
                </div>
              )}
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
               <Label>Publishing Mode</Label>
               <Select value={publishingMode} onValueChange={setPublishingMode}>
                 <SelectTrigger className="w-full bg-background">
                   <SelectValue />
                 </SelectTrigger>
                 <SelectContent>
                   <SelectItem value="draft">Draft (Manual Publish)</SelectItem>
                   <SelectItem value="publish">Publish Automatically</SelectItem>
                   <SelectItem value="schedule">Schedule Mode</SelectItem>
                 </SelectContent>
               </Select>
            </div>
            <div className="space-y-1.5">
               <Label>Approvals</Label>
               <Select value={approvalMode} onValueChange={setApprovalMode}>
                 <SelectTrigger className="w-full bg-background">
                   <SelectValue />
                 </SelectTrigger>
                 <SelectContent>
                   <SelectItem value="none">None</SelectItem>
                   <SelectItem value="internal">Internal Only</SelectItem>
                   <SelectItem value="client">Client Only</SelectItem>
                 </SelectContent>
               </Select>
            </div>
          </div>

          {publishingMode === 'schedule' && (
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1.5">
                <Label>Frequency</Label>
                <RecurrenceEditor value={recurrence} onChange={setRecurrence} />
              </div>
              <div className="space-y-1.5">
                <Label>Start Date</Label>
                <Input
                  type="date"
                  value={startDate}
                  onChange={(e) => setStartDate(e.target.value)}
                  className="w-full bg-background"
                />
                <p className="text-[0.8rem] text-muted-foreground">Leave blank to start today.</p>
              </div>
            </div>
          )}

          {(publishingMode === 'publish' || publishingMode === 'schedule') && !siteId && (
            <p className="text-[0.8rem] text-destructive">
              {(sites as any[]).length > 0
                ? `Select a Target Site above to ${publishingMode === 'schedule' ? 'schedule' : 'publish'} automatically.`
                : `Connect a site in the Sites module to ${publishingMode === 'schedule' ? 'schedule' : 'publish'} automatically.`}
            </p>
          )}

        </div>

        <p className="text-[0.8rem] text-muted-foreground pt-2">
          Generation starts automatically in the background after creation.
        </p>

        <DialogFooter className="pt-4 border-t">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button
            onClick={handleSave}
            disabled={isSaving || !templateId || ((publishingMode === 'publish' || publishingMode === 'schedule') && !siteId)}
          >
            {isSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            Create Strategy
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
