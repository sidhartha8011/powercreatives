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

export interface StrategyPayload {
  name: string;
  templateId: number;
  structure: string;
  hierarchyMode: string;
  parentTargetUrl?: string;
  parentKeyword?: string;
  publishingMode: string;
  approvalMode: string;
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
  const [structure, setStructure] = useState('individual');

  const [hierarchyMode, setHierarchyMode] = useState('standalone');
  const [parentTargetType, setParentTargetType] = useState('custom');
  const [parentTargetUrl, setParentTargetUrl] = useState('');
  const [parentKeywordIndex, setParentKeywordIndex] = useState(0);
  
  const [autoInterlink, setAutoInterlink] = useState(false);
  const [interlinkQuantity, setInterlinkQuantity] = useState(3);
  const [interlinkMode, setInterlinkMode] = useState('auto');

  const [publishingMode, setPublishingMode] = useState('draft');
  const [approvalMode, setApprovalMode] = useState('none');
  const [frequency, setFrequency] = useState('weekly');
  const [startDate, setStartDate] = useState('');

  React.useEffect(() => {
    if (open) {
      setName(selectedKeywords?.[0] || '');
      setTemplateId('');
      setStructure('individual');
      setHierarchyMode('standalone');
      setParentTargetType('custom');
      setParentTargetUrl('');
      setParentKeywordIndex(0);
      setAutoInterlink(false);
      setInterlinkQuantity(3);
      setInterlinkMode('auto');
      setPublishingMode('draft');
      setApprovalMode('none');
      setFrequency('weekly');
      setStartDate('');
    }
  }, [open]);

  const { data: templates, isLoading: templatesLoading } = trpc.templates.list.useQuery({ module: 'writer' });

  const handleSave = () => {
    if (!templateId) return;

    const finalName = name.trim() ? name.trim() : defaultName;
    
    onSave({
      name: finalName,
      templateId: parseInt(templateId, 10),
      structure,
      hierarchyMode,
      parentTargetUrl: hierarchyMode === 'children_only' ? parentTargetUrl : undefined,
      parentKeyword: hierarchyMode === 'parent_and_children' ? selectedKeywords[parentKeywordIndex] : undefined,
      publishingMode,
      approvalMode,
      interlinksConfig: autoInterlink ? { mode: interlinkMode, quantity: interlinkQuantity } : undefined,
      scheduleConfig: publishingMode === 'schedule' ? { frequency, startDate } : undefined,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[550px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Strategy ({selectedCount} Keywords)</DialogTitle>
        </DialogHeader>
        
        <div className="py-2 space-y-6">
          
          <div className="space-y-2">
            <Label htmlFor="strategy-name">Strategy Name (Optional)</Label>
            <Input
              id="strategy-name"
              placeholder={defaultName}
              value={name}
              onChange={(e) => setName(e.target.value)}
              className="w-full bg-background"
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
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
            <div className="space-y-2">
               <Label>Content per Keyword</Label>
               <Select value={structure} onValueChange={setStructure}>
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

          <div className="space-y-4">
            <div className="space-y-2">
              <Label>Content Hierarchy</Label>
              <Select value={hierarchyMode} onValueChange={setHierarchyMode}>
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
            </div>

            {hierarchyMode === 'children_only' && (
              <div className="space-y-2">
                <Label>Target Parent URL</Label>
                <p className="text-[0.8rem] text-muted-foreground">
                  All generated content will link to this parent.
                </p>
                <Input
                   type="url"
                   value={parentTargetUrl}
                   onChange={(e) => setParentTargetUrl(e.target.value)}
                   placeholder="https://example.com/parent-page"
                   className="w-full bg-background"
                />
              </div>
            )}

            {hierarchyMode === 'parent_and_children' && selectedKeywords?.length > 0 && (
              <div className="space-y-2">
                <Label>Select Parent Keyword</Label>
                <p className="text-[0.8rem] text-muted-foreground">
                  First article becomes parent, others link to it.
                </p>
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
          </div>

          <div className="space-y-4">
            <div className="flex flex-col space-y-2">
              <div className="flex items-center space-x-2">
                <Checkbox 
                  id="auto-interlink" 
                  checked={autoInterlink} 
                  onCheckedChange={(c) => setAutoInterlink(c as boolean)}
                />
                <Label htmlFor="auto-interlink" className="cursor-pointer font-medium leading-none">
                  Auto-Interlink after generation
                </Label>
              </div>
              <p className="text-[0.8rem] text-muted-foreground pl-6">
                Automatically inject internal links between articles when content is generated.
              </p>
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

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
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
            <div className="space-y-2">
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
          
        </div>

        <DialogFooter className="pt-4 border-t">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={isSaving || !templateId}>
            {isSaving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
            Create Strategy
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
