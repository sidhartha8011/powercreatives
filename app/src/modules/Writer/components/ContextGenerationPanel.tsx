/**
 * WRITER MODULE — Context & Generation Panel
 *
 * Left-side panel providing all generation context:
 * Brand, Target Site, Templates, Keywords, Prompt, Advanced Settings, SEO Metadata.
 *
 * Architecture:
 * - This panel is SETTINGS-ONLY. It does NOT trigger generation.
 *   Generation is handled exclusively by the toolbar Generate button in ReviewEditorCanvas.
 * - All settings are persisted in the active document's `generationSettings` object via Jotai.
 * - Template entries are resolved by category (prompt/tonality → customPromptText, preset → form fields).
 * - Collapsible accordion sections for Advanced AI Settings, Content Hierarchy, and SEO Metadata.
 */

import { useAtomValue, useSetAtom } from 'jotai';
import { activeDocumentAtom, updateActiveDocumentAtom } from '../store';
import { PanelRightClose } from 'lucide-react';
import { SectionLabel, colors, typography, AsyncSelectField, UNSELECTED } from '@/components/shared';
import { toSiteOptions } from '@/lib/select-helpers';
import type { SiteRecord } from '@/lib/select-helpers';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

import { ContextPanel } from '@/components/shared/ContextPanel';
import { TemplateDropdown } from '../../Copy/components/TemplateDropdown';
import { WriterDynamicSection } from './WriterDynamicSection';
import { WRITER_SECTIONS } from '../writerConfig';
import { useState, useMemo } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { PanelHeader } from '@/components/shared/PanelHeader';
import { trpc } from '@/lib/trpc';

interface Props {
  onCollapse?: () => void;
}

export function ContextGenerationPanel({ onCollapse }: Props) {
  const doc = useAtomValue(activeDocumentAtom);
  const updateDoc = useSetAtom(updateActiveDocumentAtom);

  const [seoCollapsed, setSeoCollapsed] = useState(true);

  // ── Sites dropdown data ──
  const { data: sitesRaw, isLoading: sitesLoading } = trpc.sites.list.useQuery();
  const sites = useMemo<SiteRecord[]>(() => (Array.isArray(sitesRaw) ? sitesRaw : []), [sitesRaw]);
  const siteOptions = useMemo(() => toSiteOptions(sites), [sites]);

  // ── Empty state ──
  if (!doc) {
    return (
      <div className="h-full flex flex-col items-center justify-center p-4 text-center" style={{ background: colors.bgMuted }}>
        <span style={{ fontSize: typography.sm, color: colors.textMuted }}>Select a document to edit context</span>
      </div>
    );
  }

  const settings = doc.generationSettings || {};

  /** Update a single generation setting field */
  const handleSettingChange = (fieldId: string, value: any) => {
    updateDoc({
      generationSettings: {
        ...settings,
        [fieldId]: value
      }
    });
  };

  /**
   * Single atomic handler for template select/clear.
   * Resolves entries by category:
   *  - 'prompt'/'tonality'/'reference_ad' → concatenated into `customPromptText`
   *  - 'preset' → mapped to form fields by key
   */
  const handleTemplateApply = ({ templateId, entries }: { templateId: number | undefined; entries: any[] | null }) => {
    const newSettings: Record<string, any> = { ...settings, templateId };

    if (entries && entries.length > 0) {
      const promptParts: string[] = [];
      for (const entry of entries) {
        const cat = entry.category ?? 'preset';
        if (cat === 'prompt' || cat === 'tonality' || cat === 'reference_ad') {
          promptParts.push(entry.value);
        } else {
          newSettings[entry.key] = entry.value;
        }
      }
      if (promptParts.length > 0) {
        newSettings.customPromptText = promptParts.join('\n\n');
      }
    } else {
      // Template cleared — remove stale prompt text from previous template
      delete newSettings.customPromptText;
    }

    updateDoc({ generationSettings: newSettings });
  };

  const contextData = {
    brandId: settings.brandId,
    brand: settings.brand || null,
    url: settings.url || "",
    scrapedData: settings.scrapedData || null,
    seasonEvent: settings.seasonEvent || "",
    campaignTheme: settings.campaignTheme || "",
  };

  const handleContextChange = (newContext: any) => {
    updateDoc({
      generationSettings: {
        ...settings,
        brandId: newContext.brandId,
        brand: newContext.brand,
        url: newContext.url,
        scrapedData: newContext.scrapedData,
        seasonEvent: newContext.seasonEvent,
        campaignTheme: newContext.campaignTheme,
      }
    });
  };

  // Split sections into primary (keywords) and advanced (format, perspective, etc.)
  const primarySection = WRITER_SECTIONS.find(s => s.id === 'primary_context');
  const advancedSections = WRITER_SECTIONS.filter(s => s.id !== 'primary_context');

  return (
    <div className="h-full flex flex-col overflow-hidden" style={{ background: colors.bgPage }}>
      {/* ── Panel Header ────────────────────────────────────────── */}
      <div
        className="px-4 py-3 shrink-0 flex items-center justify-between"
        style={{ borderBottom: `1px solid ${colors.borderLight}`, background: colors.bgSurface }}
      >
        <div className="flex items-center gap-2">
          <SectionLabel>Context & Generation</SectionLabel>
        </div>
        <button
          onClick={onCollapse}
          style={{
            padding: 4,
            borderRadius: 4,
            background: 'transparent',
            border: 'none',
            cursor: 'pointer',
            color: colors.textMuted,
            transition: 'background-color 0.15s',
          }}
          title="Collapse Panel"
        >
          <PanelRightClose style={{ width: 14, height: 14 }} />
        </button>
      </div>

      {/* ── Scrollable Content ────────────────────────────────── */}
      <div className="flex-1 overflow-y-auto custom-scrollbar">
        <div className="flex flex-col gap-4 p-4">

          {/* Brand & Theme Context */}
          <div className="rounded-lg border p-3" style={{ borderColor: colors.borderLight, background: colors.bgSurface }}>
            <ContextPanel
              moduleId="writer"
              value={contextData}
              onChange={handleContextChange}
              hideTheme={true}
            />
          </div>

          {/* Target Site */}
          <div className="rounded-lg border p-3" style={{ borderColor: colors.borderLight, background: colors.bgSurface }}>
            <AsyncSelectField
              label="Target Site"
              value={settings.siteId ? String(settings.siteId) : UNSELECTED}
              onChange={(val) => handleSettingChange('siteId', val !== UNSELECTED ? parseInt(val, 10) : undefined)}
              options={siteOptions}
              isLoading={sitesLoading}
              placeholder="Select site…"
              noneLabel="No Site"
            />
          </div>

          {/* Templates */}
          <TemplateDropdown
            moduleName="writer"
            selectedTemplateId={settings.templateId}
            onTemplateApply={handleTemplateApply}
          />

          {/* Primary Keywords Section */}
          {primarySection && (
            <WriterDynamicSection
              section={primarySection}
              values={settings}
              onChange={handleSettingChange}
            />
          )}

          {/* Advanced Settings & Content Hierarchy (collapsible sections) */}
          {advancedSections.map(section => (
            <WriterDynamicSection
              key={section.id}
              section={section}
              values={settings}
              onChange={handleSettingChange}
            />
          ))}

          {/* SEO Metadata Accordion */}
          <div className="rounded-lg border" style={{ borderColor: colors.border, background: colors.bgSurface }}>
            <PanelHeader
              title="SEO Metadata"
              onClick={() => setSeoCollapsed(!seoCollapsed)}
              isCollapsed={seoCollapsed}
              rightElement={
                seoCollapsed ? (
                  <ChevronRight className="w-4 h-4" style={{ color: colors.textFaint }} />
                ) : (
                  <ChevronDown className="w-4 h-4" style={{ color: colors.textFaint }} />
                )
              }
            />

            {!seoCollapsed && (
              <div className="p-3 pt-2 border-t flex flex-col gap-3" style={{ borderColor: colors.borderLight }}>
                <div>
                  <label className="block mb-1.5" style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                    Meta Title
                  </label>
                  <Input
                    value={doc.metaTitle}
                    onChange={(e) => updateDoc({ metaTitle: e.target.value })}
                    placeholder="Enter SEO title..."
                    style={{ background: colors.bgSurface }}
                  />
                </div>
                <div>
                  <label className="block mb-1.5 flex items-center justify-between" style={{ fontSize: typography.xs, fontWeight: typography.medium }}>
                    <span style={{ color: colors.textSecondary }}>Meta Description</span>
                  </label>
                  <Textarea
                    value={doc.metaDescription}
                    onChange={(e) => updateDoc({ metaDescription: e.target.value })}
                    placeholder="Enter SEO description..."
                    className="resize-none"
                    style={{ background: colors.bgSurface }}
                    rows={3}
                  />
                </div>
                <div>
                  <label className="block mb-1.5" style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                    URL Slug
                  </label>
                  <Input
                    value={doc.slug}
                    onChange={(e) => updateDoc({ slug: e.target.value })}
                    className="font-mono"
                    style={{ background: colors.bgSurface, fontSize: typography.xs }}
                  />
                </div>
                <div>
                  <label className="block mb-1.5" style={{ fontSize: typography.xs, fontWeight: typography.medium, color: colors.textSecondary }}>
                    Schema.org Type
                  </label>
                  <Select
                    value={doc.schemaType}
                    onValueChange={(val: any) => updateDoc({ schemaType: val })}
                  >
                    <SelectTrigger style={{ background: colors.bgSurface }}>
                      <SelectValue placeholder="Select schema..." />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="Article">Article</SelectItem>
                      <SelectItem value="WebPage">WebPage</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
