import { useState } from 'react';
import { useAtomValue, useSetAtom } from 'jotai';
import { activeDocumentAtom, updateActiveDocumentAtom } from '../store';
import { Image as ImageIcon, PanelRightClose } from 'lucide-react';
import { SectionLabel, CharacterCounter, PillButton, colors, typography } from '@/components/shared';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';

interface SeoMetadataPanelProps {
  onCollapse?: () => void;
}

export function SeoMetadataPanel({ onCollapse }: SeoMetadataPanelProps) {
  const doc = useAtomValue(activeDocumentAtom);
  const updateDoc = useSetAtom(updateActiveDocumentAtom);

  // If no document is selected, show an empty state.
  if (!doc) {
    return (
      <div className="h-full flex flex-col items-center justify-center p-4 text-center" style={{ background: colors.bgMuted }}>
        <span style={{ fontSize: typography.sm, color: colors.textMuted }}>Select a document to edit SEO</span>
      </div>
    );
  }

  return (
    <div className="h-full flex flex-col bg-white overflow-hidden">
      {/* ── Header ────────────────────────────────────────── */}
      <div
        className="px-4 py-3 shrink-0 flex items-center justify-between"
        style={{ borderBottom: `1px solid ${colors.borderLight}` }}
      >
        <SectionLabel>SEO & Metadata</SectionLabel>
        <button
          onClick={onCollapse}
          className="p-1 rounded transition-colors hover:bg-gray-100"
          style={{ color: colors.textMuted }}
          title="Collapse Panel"
        >
          <PanelRightClose style={{ width: 14, height: 14 }} />
        </button>
      </div>

      <div className="flex-1 overflow-y-auto">
        {/* ── Meta Section ─────────────────────────────────── */}
        <div className="p-4 flex flex-col gap-4">
            <div className="flex flex-col gap-4">
              {/* Meta Title */}
              <div>
                <label className="block mb-1.5" style={{ fontSize: '0.75rem', fontWeight: typography.medium, color: colors.textSecondary }}>
                  Meta Title
                </label>
                <Input
                  value={doc.metaTitle}
                  onChange={(e) => updateDoc({ metaTitle: e.target.value })}
                  placeholder="Enter SEO title..."
                  className="bg-white"
                />
                <CharacterCounter value={doc.metaTitle} max={60} className="mt-1.5" />
              </div>

              {/* Meta Description */}
              <div>
                <label className="block mb-1.5" style={{ fontSize: '0.75rem', fontWeight: typography.medium, color: colors.textSecondary }}>
                  Meta Description
                </label>
                <Textarea
                  value={doc.metaDescription}
                  onChange={(e) => updateDoc({ metaDescription: e.target.value })}
                  placeholder="Enter SEO description..."
                  className="bg-white resize-none"
                  rows={3}
                />
                <CharacterCounter value={doc.metaDescription} max={160} className="mt-1.5" />
              </div>

              {/* Slug */}
              <div>
                <label className="block mb-1.5" style={{ fontSize: '0.75rem', fontWeight: typography.medium, color: colors.textSecondary }}>
                  URL Slug
                </label>
                <div className="flex items-center gap-1">
                  <span style={{ fontSize: typography.xs, color: colors.textMuted }}>/</span>
                  <Input
                    value={doc.slug}
                    onChange={(e) => updateDoc({ slug: e.target.value })}
                    className="bg-white font-mono text-xs"
                  />
                </div>
              </div>
            </div>
        </div>

        <div style={{ height: 1, background: colors.borderLight }} />

        {/* ── Schema Section ───────────────────────────────── */}
        <div className="p-4 flex flex-col gap-4">
            <div className="flex flex-col gap-4">
              {/* Featured Image */}
              <div>
                <label className="block mb-1.5" style={{ fontSize: '0.75rem', fontWeight: typography.medium, color: colors.textSecondary }}>
                  Featured Image
                </label>
                <button
                  className="w-full flex flex-col items-center justify-center gap-2 rounded-lg transition-colors hover:bg-gray-50"
                  style={{
                    height: 90,
                    border: `1px dashed ${colors.borderMedium}`,
                    background: doc.featuredImage ? 'transparent' : colors.bgPage,
                  }}
                >
                  <ImageIcon style={{ width: 24, height: 24, color: colors.textMuted }} />
                  <span style={{ fontSize: typography.xs, color: colors.textMuted }}>
                    Click to add image
                  </span>
                </button>
              </div>

               {/* Schema Type */}
               <div>
                <label className="block mb-1.5" style={{ fontSize: '0.75rem', fontWeight: typography.medium, color: colors.textSecondary }}>
                  Schema Type
                </label>
                <Select
                  value={doc.schemaType}
                  onValueChange={(val: any) => updateDoc({ schemaType: val })}
                >
                  <SelectTrigger className="bg-white">
                    <SelectValue placeholder="Select schema..." />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="Article">Article</SelectItem>
                    <SelectItem value="WebPage">WebPage</SelectItem>
                    <SelectItem value="NewsArticle">NewsArticle</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {/* Generate Schema */}
              <div className="pt-2">
                <PillButton variant="default" className="w-full justify-center">
                  Preview JSON-LD
                </PillButton>
              </div>
            </div>
        </div>

      </div>
    </div>
  );
}
