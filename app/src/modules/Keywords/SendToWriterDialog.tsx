/**
 * SendToWriterDialog — Pick primary keyword + optional brand/template/site,
 * then send to the Writer module.
 *
 * Uses shared KeywordPicker and AsyncSelectField to stay DRY.
 */

import { useEffect, useMemo, useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { KeywordPicker, AsyncSelectField, UNSELECTED } from '@/components/shared';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { PendingWriterData } from '@/contexts/AppContext';
import { trpc } from '@/lib/trpc';
import { toOptions, toSiteOptions, parseId } from '@/lib/select-helpers';
import type { SiteRecord } from '@/lib/select-helpers';

// ── Types ──

interface SendToWriterDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedKeywords: string[];
  onSend: (payload: Omit<PendingWriterData, 'sourceModule'>) => void;
}

// ── Component ──

export function SendToWriterDialog({
  open,
  onOpenChange,
  selectedKeywords,
  onSend,
}: SendToWriterDialogProps) {
  const [structure, setStructure] = useState<'individual' | 'consolidated'>('individual');
  const [primaryIndex, setPrimaryIndex] = useState(0);
  const [brandId, setBrandId] = useState(UNSELECTED);
  const [siteId, setSiteId] = useState(UNSELECTED);
  const [templateId, setTemplateId] = useState(UNSELECTED);

  // ── Data fetching ──
  const { data: brands, isLoading: brandsLoading } = trpc.brands.list.useQuery();
  const { data: sitesRaw, isLoading: sitesLoading } = trpc.sites.list.useQuery();
  const { data: templates, isLoading: templatesLoading } = trpc.templates.list.useQuery({ module: 'writer' });

  const sites = useMemo<SiteRecord[]>(() => (Array.isArray(sitesRaw) ? sitesRaw : []), [sitesRaw]);
  const brandOptions = useMemo(() => toOptions(brands as NamedRecord[] | undefined), [brands]);
  const siteOptions = useMemo(() => toSiteOptions(sites), [sites]);
  const templateOptions = useMemo(() => toOptions(templates as NamedRecord[] | undefined), [templates]);

  // ── Reset on open ──
  useEffect(() => {
    if (open) {
      setStructure(selectedKeywords.length > 1 ? 'individual' : 'consolidated');
      setPrimaryIndex(0);
      setBrandId(UNSELECTED);
      setSiteId(UNSELECTED);
      setTemplateId(UNSELECTED);
    }
  }, [open, selectedKeywords.length]);

  // ── Shared settings (applied to every document) ──
  const sharedSettings = useMemo(() => {
    const selectedSite = sites.find((s) => String(s.id) === siteId);
    return {
      brandId: parseId(brandId),
      siteId: parseId(siteId),
      siteUrl: selectedSite?.url,
      templateId: parseId(templateId),
    };
  }, [brandId, siteId, templateId, sites]);

  // ── Submit ──
  const handleSend = () => {
    const documents =
      structure === 'individual'
        ? selectedKeywords.map((kw) => ({ primaryKeyword: kw, supportingKeywords: [] as string[] }))
        : [{
            primaryKeyword: selectedKeywords[primaryIndex],
            supportingKeywords: selectedKeywords.filter((_, i) => i !== primaryIndex),
          }];

    onSend({ documents, ...sharedSettings });
    onOpenChange(false);
  };

  const isMultiple = selectedKeywords.length > 1;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[500px] max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Send to Writer ({selectedKeywords.length} Keywords)</DialogTitle>
        </DialogHeader>

        <div className="py-2 space-y-5">
          {/* Structure mode — only shown when multiple keywords */}
          {isMultiple && (
            <div className="space-y-2">
              <Label>Content Structure</Label>
              <Select value={structure} onValueChange={(v) => setStructure(v as 'individual' | 'consolidated')}>
                <SelectTrigger className="w-full bg-background">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="individual">1 Article per Keyword ({selectedKeywords.length} articles)</SelectItem>
                  <SelectItem value="consolidated">Consolidated (1 article)</SelectItem>
                </SelectContent>
              </Select>
            </div>
          )}

          {/* Primary keyword picker — only for consolidated mode */}
          {structure === 'consolidated' && isMultiple && (
            <div className="space-y-2">
              <Label>Select Primary Keyword</Label>
              <p className="text-[0.8rem] text-muted-foreground">
                The rest will be added as supporting keywords.
              </p>
              <KeywordPicker
                keywords={selectedKeywords}
                selectedIndex={primaryIndex}
                onSelect={setPrimaryIndex}
              />
            </div>
          )}

          {/* Brand + Template */}
          <div className="grid grid-cols-2 gap-4">
            <AsyncSelectField
              label="Brand (Optional)"
              value={brandId}
              onChange={setBrandId}
              options={brandOptions}
              isLoading={brandsLoading}
              placeholder="Select Brand…"
              noneLabel="No Brand"
            />
            <AsyncSelectField
              label="Template (Optional)"
              value={templateId}
              onChange={setTemplateId}
              options={templateOptions}
              isLoading={templatesLoading}
              placeholder="Select Template…"
              noneLabel="No Template"
              emptyMessage="No Writer templates found"
            />
          </div>

          {/* Target site */}
          <AsyncSelectField
            label="Target Site (Optional)"
            value={siteId}
            onChange={setSiteId}
            options={siteOptions}
            isLoading={sitesLoading}
            placeholder="Select Site…"
            noneLabel="No Site"
          />
        </div>

        <DialogFooter className="pt-4 border-t">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={handleSend}>
            {structure === 'individual' && isMultiple
              ? `Send ${selectedKeywords.length} Articles to Writer`
              : 'Send to Writer'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
