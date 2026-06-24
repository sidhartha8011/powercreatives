/**
 * RESULTS PANEL — Editorial / Blog-style layout
 *
 * Layout:
 *   Header bar: title + selection indicator (left) | model dropdown + action dropdown + Generate button (right)
 *   Level 1: Copy Type tabs (with checkboxes)
 *   Level 2: Audience sub-tabs (with checkboxes + duplicate button)
 *   Content: Angle cards in a grid (with checkboxes + inline editing)
 *
 * Production-ready: no mock data, no fallback results.
 */

import { useState, useEffect, useRef, useCallback } from 'react';
import {
  RefreshCw,
  PenLine,
  Sparkles,
  FileText,
  ChevronDown,
  X,
  CopyPlus,
  Pencil,
  Check,
  FolderPlus,
  FolderOpen,
  Loader2,
  Share2,
} from 'lucide-react';
import { PillButton, colors, typography, spacing } from '@/components/shared';
import type { CopyType, CopyVariation, CopyResults, CopyAudience } from '../types';
import { COPY_TYPE_LABELS, filterByAudience } from '../types';
import { useTextModels } from '../useTextModels';
import { InlineEditableCard, REGEN_PRESETS } from './InlineEditableCard';
import type { UseSelectionReturn } from '../useSelection';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import { SendToApprovalSetDialog } from '@/components/shared/SendToApprovalSetDialog';
import { BulkActionBar } from '@/components/shared/BulkActionBar';

// ─── Types ───

export type GenerateScope = 'all_types' | 'current_type' | 'current_audience';

export type BulkAction =
  | 'regenerate'
  | 'shorter'
  | 'longer'
  | 'urgent'
  | 'emotional'
  | 'professional'
  | 'casual'
  | 'change_cta'
  | 'save_to_project'
  | 'custom';

interface Props {
  results: CopyResults;
  isGenerating: boolean;
  /** Generation progress from SSE stream */
  progress: { current: number; total: number; label: string } | null;
  selectedTypes: Record<CopyType, boolean>;
  audiences: CopyAudience[];
  /** Called with (scope, activeTab, activeAudience, modelId) */
  onGenerate: (
    scope: GenerateScope,
    activeTab: CopyType | null,
    activeAudience: string,
    modelId: string,
  ) => void;
  /** Called with (variationId, instruction?) from parent */
  onRegenerateCard?: (variationId: string, instruction?: string) => void;
  /** Called with (variationIds, instruction?) for batch regeneration */
  onRegenerateBatch?: (variationIds: string[], instruction?: string) => void;
  /** Called with (audienceId, audienceName) to duplicate an audience */
  onDuplicateAudience?: (audienceId: string, audienceName: string) => void;
  /** Called with (audienceId, newName) to rename an audience tab */
  onRenameAudience?: (audienceId: string, newName: string) => void;
  /** Called with (variationId) to duplicate a single copy card */
  onDuplicateCard?: (variationId: string) => void;
  /** Called with (variationId, updates) to save inline edits */
  onSaveEdits?: (
    variationId: string,
    updates: { headline?: string; body?: string; cta?: string; hashtags?: string; description?: string },
  ) => Promise<void>;
  /** External set of card IDs being regenerated */
  regeneratingCards?: Set<string>;
  /** Whether we have real (non-mock) results */
  hasRealResults?: boolean;
  /** Selection state from useSelection hook */
  selection: UseSelectionReturn;
  /** Brand/project context for the "Send to Approval Set" flow. */
  brandId?: number | null;
  brandName?: string | null;
  projectId?: number | null;
  brandClientEmail?: string | null;
  brandLogoUrl?: string | null;
}

// ─── Shared control style ───
const CONTROL_STYLE = {
  padding: `${spacing.pillPy} ${spacing.pillPx}`,
  borderRadius: spacing.radiusPill,
  fontSize: typography.sm,
  fontWeight: typography.medium,
  color: colors.textMuted,
  background: 'transparent',
  border: `1px solid ${colors.border}`,
  cursor: 'pointer',
} as const;

// ─── Model Dropdown ───
function ModelDropdown({
  modelGroups,
  selectedModel,
  onModelChange,
}: {
  modelGroups: { label: string; models: { id: string; name: string }[] }[];
  selectedModel: string;
  onModelChange: (id: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    if (open) document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [open]);

  const allModels = modelGroups.flatMap((g) => g.models);
  const selectedName = allModels.find((m) => m.id === selectedModel)?.name ?? 'Select model';

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(!open)} style={CONTROL_STYLE} className="flex items-center gap-1.5">
        <span className="truncate max-w-[120px]">{selectedName}</span>
        <ChevronDown className="w-3.5 h-3.5 shrink-0" />
      </button>
      {open && (
        <div
          className="absolute right-0 top-full mt-1 z-50 rounded-lg py-1 max-h-60 overflow-y-auto"
          style={{
            background: colors.bgSurface,
            border: `1px solid ${colors.border}`,
            minWidth: '200px',
          }}
        >
          {modelGroups.map((group) => (
            <div key={group.label}>
              <div
                className="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider"
                style={{ color: colors.textFaint }}
              >
                {group.label}
              </div>
              {group.models.map((model) => (
                <button
                  key={model.id}
                  onClick={() => {
                    onModelChange(model.id);
                    setOpen(false);
                  }}
                  className="w-full text-left px-3 py-1.5 text-xs transition-colors hover:bg-gray-50"
                  style={{
                    color: model.id === selectedModel ? colors.text : colors.textSecondary,
                    fontWeight: model.id === selectedModel ? '600' : '400',
                  }}
                >
                  {model.name}
                </button>
              ))}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Action Dropdown (replaces ScopeDropdown) ───
function ActionDropdown({
  hasSelection,
  selectedCount,
  onBulkAction,
  onCustomInstruction,
}: {
  hasSelection: boolean;
  selectedCount: number;
  onBulkAction: (action: BulkAction) => void;
  onCustomInstruction: (instruction: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [showCustomInput, setShowCustomInput] = useState(false);
  const [customText, setCustomText] = useState('');
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
        setShowCustomInput(false);
      }
    };
    if (open) document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [open]);

  if (!hasSelection) return null;

  const actions: { id: BulkAction; label: string }[] = [
    { id: 'regenerate', label: 'Regenerate' },
    { id: 'shorter', label: 'Make shorter' },
    { id: 'longer', label: 'Make longer' },
    { id: 'urgent', label: 'More urgent' },
    { id: 'emotional', label: 'More emotional' },
    { id: 'professional', label: 'More professional' },
    { id: 'casual', label: 'More casual' },
    { id: 'change_cta', label: 'Change CTA' },
    { id: 'save_to_project', label: 'Save to Project...' },
    { id: 'custom', label: 'Custom instructions…' },
  ];

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(!open)}
        className="flex items-center gap-1.5"
        style={{
          ...CONTROL_STYLE,
          background: '#eff6ff',
          borderColor: '#bfdbfe',
          color: '#2563eb',
        }}
      >
        <span>Actions ({selectedCount})</span>
        <ChevronDown className="w-3.5 h-3.5" />
      </button>

      {open && (
        <div
          className="absolute right-0 top-full mt-1 z-50 rounded-lg py-1"
          style={{
            background: colors.bgSurface,
            border: `1px solid ${colors.border}`,
            minWidth: '220px',
            boxShadow: '0 4px 12px rgba(0,0,0,0.08)',
          }}
        >
          <div
            className="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider"
            style={{ color: colors.textFaint }}
          >
            Bulk actions for {selectedCount} selected
          </div>

          {actions.map((action) => {
            if (action.id === 'custom') {
              return (
                <div key={action.id}>
                  <button
                    onClick={() => setShowCustomInput(!showCustomInput)}
                    className="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-gray-50"
                    style={{
                      color: colors.textSecondary,
                      borderTop: `1px solid ${colors.bgHover}`,
                    }}
                  >
                    {action.label}
                  </button>
                  {showCustomInput && (
                    <div className="px-3 pb-3 pt-1">
                      <textarea
                        value={customText}
                        onChange={(e) => setCustomText(e.target.value)}
                        placeholder="Write your instructions…"
                        className="w-full text-xs rounded-md border px-2 py-1.5 resize-none focus:outline-none focus:ring-1 focus:ring-blue-400"
                        style={{ borderColor: colors.border, minHeight: '60px' }}
                        autoFocus
                      />
                      <button
                        onClick={() => {
                          if (customText.trim()) {
                            onCustomInstruction(customText.trim());
                            setCustomText('');
                            setOpen(false);
                            setShowCustomInput(false);
                          }
                        }}
                        disabled={!customText.trim()}
                        className="mt-1.5 w-full text-xs font-medium py-1.5 rounded-md transition-colors"
                        style={{
                          background: customText.trim() ? '#2563eb' : colors.border,
                          color: customText.trim() ? '#fff' : colors.textFaint,
                        }}
                      >
                        Apply to {selectedCount} cards
                      </button>
                    </div>
                  )}
                </div>
              );
            }

            return (
              <button
                key={action.id}
                onClick={() => {
                  onBulkAction(action.id);
                  setOpen(false);
                }}
                className="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-gray-50"
                style={{ color: colors.textSecondary }}
              >
                {action.label}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ─── Loading Skeleton for cards ───
function CardSkeleton() {
  return (
    <div
      className="rounded-xl animate-pulse"
      style={{
        background: colors.bgSurface,
        border: `1px solid ${colors.borderLight}`,
        padding: '1.25rem 1.5rem',
      }}
    >
      <div className="h-2.5 w-24 rounded bg-gray-200 mb-3" />
      <div className="h-4 w-3/4 rounded bg-gray-200 mb-3" />
      <div className="space-y-2 mb-3">
        <div className="h-3 w-full rounded bg-gray-100" />
        <div className="h-3 w-full rounded bg-gray-100" />
        <div className="h-3 w-5/6 rounded bg-gray-100" />
      </div>
      <div className="h-3.5 w-40 rounded bg-blue-100 mb-3" />
      <div
        className="flex justify-between pt-3"
        style={{ borderTop: `1px solid ${colors.bgHover}` }}
      >
        <div className="h-3 w-20 rounded bg-gray-100" />
        <div className="flex gap-2">
          <div className="h-7 w-16 rounded-lg bg-gray-100" />
          <div className="h-7 w-14 rounded-lg bg-gray-100" />
        </div>
      </div>
    </div>
  );
}

// ─── Empty State ───
function EmptyState({
  hasAudiences,
  hasTypes,
}: {
  hasAudiences: boolean;
  hasTypes: boolean;
}) {
  if (!hasTypes) {
    return (
      <div className="flex-1 flex items-center justify-center mx-8 my-4" style={{ borderRadius: '15px', background: '#f8f8f7' }}>
        <div className="text-center max-w-sm">
          <FileText className="w-10 h-10 mx-auto mb-3" style={{ color: colors.textGhost }} />
          <p className="text-sm font-medium mb-1" style={{ color: colors.textSecondary }}>
            Select a copy type
          </p>
          <p className="text-xs" style={{ color: colors.textFaint }}>
            Choose Social Ads, Social Organic, or both from the sidebar to get started.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex-1 flex items-center justify-center mx-8 my-4" style={{ borderRadius: '15px', background: '#f8f8f7' }}>
      <div className="text-center max-w-sm">
        <Sparkles className="w-10 h-10 mx-auto mb-3" style={{ color: colors.textGhost }} />
        <p className="text-sm font-medium mb-1" style={{ color: colors.textSecondary }}>
          Ready to generate
        </p>
        <p className="text-xs" style={{ color: colors.textFaint }}>
          {!hasAudiences
            ? 'Add at least one audience (or set to Auto mode) in the sidebar, then click Generate.'
            : 'Fill in your business info and click Generate to create AI-powered copy.'}
        </p>
      </div>
    </div>
  );
}

// ─── Main ResultsPanel ───
export function ResultsPanel({
  results,
  isGenerating,
  progress,
  selectedTypes,
  audiences,
  onGenerate,
  onRegenerateCard: externalRegenerateCard,
  onRegenerateBatch,
  onDuplicateAudience,
  onRenameAudience,
  onDuplicateCard,
  onSaveEdits,
  regeneratingCards: externalRegeneratingCards,
  hasRealResults = false,
  selection,
  brandId,
  brandName,
  projectId,
  brandClientEmail,
  brandLogoUrl,
}: Props) {
  const activeTypes = (Object.keys(selectedTypes) as CopyType[]).filter((t) => selectedTypes[t]);
  const hasResults =
    hasRealResults || activeTypes.some((t) => results[t] && results[t]!.length > 0);

  const { textModels, groups: modelGroups } = useTextModels();

  const [modelPerType, setModelPerType] = useState<Record<string, string>>({});
  const getSelectedModel = (type: CopyType) => modelPerType[type] || textModels[0]?.id || '';
  const setSelectedModel = (type: CopyType, modelId: string) =>
    setModelPerType((prev) => ({ ...prev, [type]: modelId }));

  // Tab state
  const [activeTab, setActiveTab] = useState<CopyType | null>(activeTypes[0] ?? null);
  const [activeAudience, setActiveAudience] = useState<string>(audiences[0]?.id ?? '');

  // Generate scope — default to all_types for simplicity
  const [generateScope] = useState<GenerateScope>('all_types');
  const [isSaveDialogOpen, setIsSaveDialogOpen] = useState(false);
  const [isApprovalDialogOpen, setIsApprovalDialogOpen] = useState(false);

  useEffect(() => {
    if (activeTypes.length === 0) {
      setActiveTab(null);
    } else if (!activeTab || !selectedTypes[activeTab]) {
      setActiveTab(activeTypes[0]);
    }
  }, [selectedTypes, activeTypes, activeTab]);

  useEffect(() => {
    if (audiences.length > 0 && !audiences.find((a) => a.id === activeAudience)) {
      setActiveAudience(audiences[0].id);
    }
  }, [audiences, activeAudience]);

  const allVariationsForType = activeTab ? (results[activeTab] ?? []) : [];
  const activeVariations = filterByAudience(allVariationsForType, activeAudience);

  const getAudienceAngleCount = (audienceId: string) => {
    return filterByAudience(allVariationsForType, audienceId).length;
  };

  // ─── Generate Handler ───
  const handleGenerate = useCallback(() => {
    if (!activeTab) return;
    const modelId = getSelectedModel(activeTab);
    onGenerate(generateScope, activeTab, activeAudience, modelId);
  }, [activeTab, activeAudience, generateScope, onGenerate, getSelectedModel]);

  // ─── Card-level regenerate ───
  const handleRegenerateCard = useCallback(
    (variationId: string, instruction?: string) => {
      if (externalRegenerateCard) {
        externalRegenerateCard(variationId, instruction);
      }
    },
    [externalRegenerateCard],
  );

  // ─── Save edits handler ───
  const handleSaveEdits = useCallback(
    async (
      variationId: string,
      updates: { headline?: string; body?: string; cta?: string; hashtags?: string; description?: string },
    ) => {
      if (onSaveEdits) {
        await onSaveEdits(variationId, updates);
      }
    },
    [onSaveEdits],
  );

  // ─── Duplicate card handler ───
  const handleDuplicateCard = useCallback(
    (variationId: string) => {
      if (onDuplicateCard) {
        onDuplicateCard(variationId);
      }
    },
    [onDuplicateCard],
  );

  // ─── Bulk action handlers ───
  const handleBulkAction = useCallback(
    (action: BulkAction) => {
      if (action === 'save_to_project') {
        setIsSaveDialogOpen(true);
        return;
      }
      if (!onRegenerateBatch) return;
      const ids = selection.getSelectedIds();
      if (ids.length === 0) return;

      const instructionMap: Record<string, string | undefined> = {
        regenerate: undefined,
        shorter: 'Make shorter',
        longer: 'Make longer',
        urgent: 'More urgent',
        emotional: 'More emotional',
        professional: 'More professional',
        casual: 'More casual',
        change_cta: 'Change CTA',
        custom: undefined, // handled separately
      };

      const instruction = instructionMap[action];
      if (instruction !== undefined) {
        onRegenerateBatch(ids, instruction);
      }
    },
    [onRegenerateBatch, selection],
  );

  const handleCustomBulkInstruction = useCallback(
    (instruction: string) => {
      if (!onRegenerateBatch) return;
      const ids = selection.getSelectedIds();
      if (ids.length === 0) return;
      onRegenerateBatch(ids, instruction);
    },
    [onRegenerateBatch, selection],
  );

  // ─── Duplicate audience handler ───
  const handleDuplicateAudience = useCallback(
    (audienceId: string) => {
      if (!onDuplicateAudience) return;
      const audience = audiences.find((a) => a.id === audienceId);
      if (!audience) return;
      onDuplicateAudience(audienceId, audience.name);
    },
    [onDuplicateAudience, audiences],
  );

  // ─── Audience rename state ───
  const [editingAudienceId, setEditingAudienceId] = useState<string | null>(null);
  const [editingAudienceName, setEditingAudienceName] = useState('');
  const editInputRef = useRef<HTMLInputElement>(null);

  const startEditingAudience = useCallback((audience: CopyAudience) => {
    setEditingAudienceId(audience.id);
    setEditingAudienceName(audience.name);
    // Focus the input after render
    setTimeout(() => editInputRef.current?.focus(), 0);
  }, []);

  const confirmRenameAudience = useCallback(() => {
    if (!editingAudienceId) return;
    const trimmed = editingAudienceName.trim();
    if (trimmed && onRenameAudience) {
      const currentAudience = audiences.find((a) => a.id === editingAudienceId);
      // Only call rename if the name actually changed
      if (currentAudience && currentAudience.name !== trimmed) {
        onRenameAudience(editingAudienceId, trimmed);
      }
    }
    setEditingAudienceId(null);
    setEditingAudienceName('');
  }, [editingAudienceId, editingAudienceName, onRenameAudience, audiences]);

  const cancelRenameAudience = useCallback(() => {
    setEditingAudienceId(null);
    setEditingAudienceName('');
  }, []);

  const mergedRegeneratingCards = externalRegeneratingCards ?? new Set<string>();

  return (
    <div className="h-full flex flex-col">
      {/* ── Header bar ── */}
      <div
        className="flex items-center justify-between shrink-0"
        style={{
          padding: '1rem 2rem',
          borderBottom: `1px solid ${colors.borderLight}`,
          background: colors.bgSurface,
        }}
      >
        <div className="flex items-center gap-3">
          <h2 className="font-bold tracking-tight" style={{ color: colors.text, fontSize: '1rem' }}>
            Generated Copy
          </h2>

          {/* Selection indicator — appears inline when cards are selected */}
          {selection.hasSelection && (
            <div className="flex items-center gap-2 animate-in fade-in duration-150">
              <span
                className="text-[11px] font-semibold px-2 py-0.5 rounded-full"
                style={{ background: '#eff6ff', color: '#2563eb' }}
              >
                {selection.selectedCount} selected
              </span>
              <button
                onClick={selection.clearSelection}
                className="flex items-center gap-0.5 text-[11px] transition-colors"
                style={{ color: colors.textFaint }}
                onMouseEnter={(e) => (e.currentTarget.style.color = colors.textSecondary)}
                onMouseLeave={(e) => (e.currentTarget.style.color = colors.textFaint)}
              >
                <X className="w-3 h-3" />
                Clear
              </button>
            </div>
          )}
        </div>

        {/* Right side: Model dropdown + Action dropdown + Generate button */}
        <div className="flex items-center gap-2">
          {activeTab && (
            <ModelDropdown
              modelGroups={modelGroups}
              selectedModel={getSelectedModel(activeTab)}
              onModelChange={(modelId) => setSelectedModel(activeTab, modelId)}
            />
          )}

          {/* Action dropdown — only visible when cards are selected */}
          <ActionDropdown
            hasSelection={selection.hasSelection}
            selectedCount={selection.selectedCount}
            onBulkAction={handleBulkAction}
            onCustomInstruction={handleCustomBulkInstruction}
          />

          <PillButton
            variant="active"
            icon={<PenLine />}
            onClick={handleGenerate}
            loading={isGenerating}
            disabled={activeTypes.length === 0 || isGenerating}
          >
            {isGenerating ? 'Generating…' : 'Generate'}
          </PillButton>
        </div>
      </div>

      {/* ── Progress bar during generation ── */}
      {isGenerating && progress && progress.total > 0 && (
        <div
          style={{
            padding: '0.5rem 2rem',
            borderBottom: `1px solid ${colors.borderLight}`,
            background: colors.bgSurface,
          }}
        >
          <div className="flex items-center gap-3">
            <div
              className="flex-1 h-1.5 rounded-full overflow-hidden"
              style={{ background: colors.bgHover }}
            >
              <div
                className="h-full rounded-full transition-all duration-500 ease-out"
                style={{
                  width: `${Math.max(2, (progress.current / progress.total) * 100)}%`,
                  background: 'linear-gradient(90deg, #3b82f6, #6366f1)',
                }}
              />
            </div>
            <span
              className="text-xs font-medium shrink-0"
              style={{ color: colors.textSecondary }}
            >
              {progress.current} / {progress.total}
            </span>
          </div>
          <p
            className="text-[11px] mt-1"
            style={{ color: colors.textFaint }}
          >
            {progress.label}
          </p>
        </div>
      )}

      {/* Empty state when no types selected */}
      {activeTypes.length === 0 && (
        <EmptyState hasAudiences={audiences.length > 0} hasTypes={false} />
      )}

      {/* Empty state when types selected but no results yet */}
      {activeTypes.length > 0 && !hasResults && !isGenerating && (
        <EmptyState hasAudiences={audiences.length > 0} hasTypes={true} />
      )}

      {/* Loading skeleton during generation when no results yet */}
      {activeTypes.length > 0 && isGenerating && !hasResults && (
        <div className="flex-1 overflow-y-auto">
          <div
            className="mx-auto"
            style={{ maxWidth: '100%', padding: '2rem clamp(1rem, 10vw, 12.5rem)', borderRadius: '15px', background: '#f8f8f7' }}
          >
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
              {Array.from({ length: progress?.total || 6 }).map((_, i) => (
                <CardSkeleton key={i} />
              ))}
            </div>
          </div>
        </div>
      )}

      {/* Tabs + Content (only when we have results) */}
      {activeTypes.length > 0 && hasResults && (
        <>
          {/* Level 1: Copy Type Tabs (with checkboxes) */}
          <div
            className="flex items-center gap-0 shrink-0"
            style={{
              padding: '0 2rem',
              borderBottom: `1px solid ${colors.borderLight}`,
              background: colors.bgSurface,
            }}
          >
            {activeTypes.map((type) => {
              const variations = results[type] ?? [];
              const isActive = type === activeTab;
              const isTypeChecked = selection.isTypeSelected(type);
              const isTypeIndet = selection.isTypeIndeterminate(type);

              return (
                <div key={type} className="flex items-center relative">
                  {/* Type checkbox */}
                  <div className="pl-2">
                    <input
                      type="checkbox"
                      checked={isTypeChecked}
                      ref={(el) => {
                        if (el) el.indeterminate = isTypeIndet;
                      }}
                      onChange={() => selection.toggleType(type)}
                      className="w-3.5 h-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
                    />
                  </div>

                  <button
                    onClick={() => setActiveTab(type)}
                    className="flex items-center gap-2 transition-colors"
                    style={{
                      padding: '0.75rem 1rem 0.75rem 0.5rem',
                      color: isActive ? colors.text : colors.textFaint,
                      background: 'transparent',
                      fontWeight: isActive ? typography.semibold : typography.regular,
                      fontSize: typography.sm,
                    }}
                  >
                    <span
                      className="px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wider"
                      style={{
                        background: isActive
                          ? type === 'social_ads'
                            ? '#dbeafe'
                            : colors.successLight
                          : '#f3f4f6',
                        color: isActive
                          ? type === 'social_ads'
                            ? '#1d4ed8'
                            : colors.success
                          : colors.textFaint,
                      }}
                    >
                      {COPY_TYPE_LABELS[type]}
                    </span>
                    <span
                      className="text-xs"
                      style={{ color: isActive ? colors.textSecondary : colors.textGhost }}
                    >
                      {variations.length} angle{variations.length !== 1 ? 's' : ''}
                    </span>
                  </button>

                  {isActive && (
                    <div
                      className="absolute bottom-0 left-4 right-4 h-[2px] rounded-full"
                      style={{
                        background: type === 'social_ads' ? '#2563eb' : colors.success,
                      }}
                    />
                  )}
                </div>
              );
            })}

            {/* Generating indicator in tabs area */}
            {isGenerating && (
              <div className="flex items-center gap-1.5 ml-auto pr-2">
                <div className="w-2 h-2 rounded-full bg-blue-500 animate-pulse" />
                <span className="text-xs" style={{ color: colors.textFaint }}>
                  Generating…
                </span>
              </div>
            )}
          </div>

          {/* Level 2: Audience Sub-Tabs (with checkboxes + duplicate) */}
          {audiences.length > 0 && (
            <div
              className="flex items-center gap-1 shrink-0"
              style={{
                padding: '0.5rem 2rem',
                borderBottom: `1px solid ${colors.borderLight}`,
                background: colors.bgMuted,
              }}
            >
              {audiences.map((audience) => {
                const isActiveAud = audience.id === activeAudience;
                const count = getAudienceAngleCount(audience.id);
                const isAudChecked =
                  activeTab ? selection.isAudienceSelected(activeTab, audience.id) : false;
                const isAudIndet =
                  activeTab ? selection.isAudienceIndeterminate(activeTab, audience.id) : false;

                const isEditing = editingAudienceId === audience.id;

                return (
                  <div key={audience.id} className="flex items-center gap-1 group/aud">
                    {/* Audience checkbox */}
                    <input
                      type="checkbox"
                      checked={isAudChecked}
                      ref={(el) => {
                        if (el) el.indeterminate = isAudIndet;
                      }}
                      onChange={() => {
                        if (activeTab) selection.toggleAudience(activeTab, audience.id);
                      }}
                      className="w-3 h-3 rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
                    />

                    {isEditing ? (
                      /* Inline edit input */
                      <div
                        className="flex items-center gap-1 rounded-full"
                        style={{
                          padding: '0.25rem 0.5rem',
                          border: `1px solid ${colors.primary}`,
                          background: colors.bgSurface,
                        }}
                      >
                        <input
                          ref={editInputRef}
                          type="text"
                          value={editingAudienceName}
                          onChange={(e) => setEditingAudienceName(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') confirmRenameAudience();
                            if (e.key === 'Escape') cancelRenameAudience();
                          }}
                          onBlur={confirmRenameAudience}
                          className="bg-transparent outline-none min-w-[80px]"
                          style={{
                            fontSize: typography.xs,
                            fontWeight: typography.medium,
                            color: colors.text,
                            width: `${Math.max(80, editingAudienceName.length * 7)}px`,
                          }}
                        />
                        <button
                          onMouseDown={(e) => {
                            e.preventDefault(); // prevent blur before click
                            confirmRenameAudience();
                          }}
                          className="p-0.5 rounded hover:bg-gray-200/50"
                          title="Confirm rename"
                        >
                          <Check className="w-3 h-3" style={{ color: colors.primary }} />
                        </button>
                      </div>
                    ) : (
                      /* Normal audience tab button */
                      <button
                        onClick={() => setActiveAudience(audience.id)}
                        onDoubleClick={() => startEditingAudience(audience)}
                        className="flex items-center gap-1.5 rounded-full transition-all duration-150"
                        style={{
                          padding: '0.375rem 0.875rem',
                          fontSize: typography.xs,
                          fontWeight: isActiveAud ? typography.semibold : typography.regular,
                          color: isActiveAud ? colors.text : colors.textMuted,
                          background: isActiveAud ? colors.bgSurface : 'transparent',
                          border: isActiveAud
                            ? `1px solid ${colors.border}`
                            : '1px solid transparent',
                        }}
                        title="Double-click to rename"
                      >
                        {audience.name}
                        {count > 0 && (
                          <span
                            className="text-[10px] rounded-full px-1.5 py-0.5"
                            style={{
                              background: isActiveAud ? colors.bgHover : '#e8e8e8',
                              color: isActiveAud ? colors.textSecondary : colors.textFaint,
                            }}
                          >
                            {count}
                          </span>
                        )}
                      </button>
                    )}

                    {/* Rename button — visible on hover (only when not editing) */}
                    {!isEditing && (
                      <button
                        onClick={() => startEditingAudience(audience)}
                        className="opacity-0 group-hover/aud:opacity-100 transition-opacity p-1 rounded hover:bg-gray-200/50"
                        title={`Rename "${audience.name}"`}
                      >
                        <Pencil className="w-3 h-3" style={{ color: colors.textMuted }} />
                      </button>
                    )}

                    {/* Duplicate button — visible on hover */}
                    {!isEditing && (
                      <button
                        onClick={() => handleDuplicateAudience(audience.id)}
                        className="opacity-0 group-hover/aud:opacity-100 transition-opacity p-1 rounded hover:bg-gray-200/50"
                        title={`Duplicate "${audience.name}"`}
                      >
                        <CopyPlus className="w-3 h-3" style={{ color: colors.textMuted }} />
                      </button>
                    )}
                  </div>
                );
              })}
            </div>
          )}

          {/* Content area */}
          <div className="flex-1 overflow-y-auto">
            <div
              className="mx-auto"
              style={{ maxWidth: '100%', padding: '2rem clamp(1rem, 10vw, 12.5rem)', borderRadius: '15px', background: 'rgb(248, 249, 250)' }}
            >
              {activeVariations.length > 0 ? (
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                  {activeVariations.map((v, i) => (
                    <InlineEditableCard
                      key={v.id}
                      variation={v}
                      index={i}
                      isSelected={selection.isCardSelected(v.id)}
                      onToggleSelect={selection.toggleCard}
                      onRegenerateCard={handleRegenerateCard}
                      onDuplicate={handleDuplicateCard}
                      isRegenerating={mergedRegeneratingCards.has(v.id)}
                      onSaveEdits={handleSaveEdits}
                      showCheckbox={selection.hasSelection}
                    />
                  ))}
                  {/* Show remaining skeletons while generating */}
                  {isGenerating && progress && progress.current < progress.total &&
                    Array.from({ length: Math.max(0, progress.total - allVariationsForType.length) }).map((_, i) => (
                      <CardSkeleton key={`skel-${i}`} />
                    ))
                  }
                </div>
              ) : isGenerating ? (
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2">
                  {Array.from({ length: progress?.total || 3 }).map((_, i) => (
                    <CardSkeleton key={`skel-${i}`} />
                  ))}
                </div>
              ) : (
                <div className="flex items-center justify-center" style={{ minHeight: '200px' }}>
                  <p className="text-sm" style={{ color: colors.textFaint }}>
                    No angles for this audience yet. Click Generate to create copy.
                  </p>
                </div>
              )}
            </div>
          </div>
        </>
      )}

      {/* ── Save to Project Dialog ── */}
      <SaveToProjectDialog
        open={isSaveDialogOpen}
        onOpenChange={setIsSaveDialogOpen}
        selectedIds={selection.getSelectedIds()}
        onComplete={() => {
          selection.clearSelection();
        }}
      />

      {/* ── Send to Approval Set Dialog ── mounted only while open, so the
          snapshot mapping below isn't recomputed on every panel render. */}
      {isApprovalDialogOpen && (
      <SendToApprovalSetDialog
        isOpen={isApprovalDialogOpen}
        onClose={() => setIsApprovalDialogOpen(false)}
        copy={selection.getSelectedVariations().map((v) => ({
          id: String(v.id),
          headline: v.headline,
          body: v.body,
          cta: v.cta || '',
          description: v.description || '',
          hashtags: v.hashtags || [],
          audienceName: audiences.find((a) => a.id === v.audienceId)?.name || '',
          angleName: v.angleName || '',
          modelUsed: v.modelUsed,
        }))}
        defaultName={`Copy Set — ${brandName || 'Draft'} (${new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric' })})`}
        brandId={brandId}
        projectId={projectId}
        brandName={brandName}
        brandLogoUrl={brandLogoUrl}
        brandClientEmail={brandClientEmail}
        itemSummary={`${selection.selectedCount} ${selection.selectedCount === 1 ? 'copy' : 'copies'}`}
      />
      )}

      {/* ── Floating bulk-action bar (mirrors Ads/Image) — appears on selection ── */}
      <BulkActionBar count={selection.selectedCount} onClear={selection.clearSelection}>
        <BulkActionBar.Action
          icon={Share2}
          label="Send to Approval Set"
          onClick={() => setIsApprovalDialogOpen(true)}
        />
      </BulkActionBar>
    </div>
  );
}

// ============================================================================
// SaveToProjectDialog Component
// ============================================================================

interface SaveToProjectDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedIds: string[];
  onComplete: () => void;
}

export function SaveToProjectDialog({
  open,
  onOpenChange,
  selectedIds,
  onComplete,
}: SaveToProjectDialogProps) {
  const [projectId, setProjectId] = useState<string>('');
  const [showNew, setShowNew] = useState(false);
  const [newName, setNewName] = useState('');
  const [isSaving, setIsSaving] = useState(false);

  // Fetch projects list
  const { data: projects, isLoading: projectsLoading, refetch: refetchProjects } =
    trpc.assets.getProjects.useQuery(undefined, { enabled: open });

  const saveToProjectMutation = trpc.copy.saveToProject.useMutation();
  const createProjectMutation = trpc.assets.createProject.useMutation();

  const handleSave = async () => {
    if (!projectId || projectId === 'none') {
      toast.error('Please select a project');
      return;
    }

    setIsSaving(true);
    try {
      // Parse card IDs from selections (they are string IDs e.g. "v-123", let's extract ints)
      const cleanIds = selectedIds.map(id => {
        const parsed = parseInt(id.replace(/[^\d]/g, ''), 10);
        return isNaN(parsed) ? 0 : parsed;
      }).filter(id => id > 0);

      if (cleanIds.length === 0) {
        toast.error('No valid copy results selected.');
        return;
      }

      await saveToProjectMutation.mutateAsync({
        resultIds: cleanIds,
        projectId: parseInt(projectId, 10),
      });

      const projectName = projects?.find((p: any) => p.id.toString() === projectId)?.name ?? 'project';
      toast.success(`Successfully saved ${cleanIds.length} copy card(s) to "${projectName}"`);
      onComplete();
      onOpenChange(false);
    } catch (err: any) {
      toast.error(err?.message || 'Failed to save copy cards to project');
    } finally {
      setIsSaving(false);
    }
  };

  const handleCreateProject = async () => {
    if (!newName.trim()) return;
    setIsSaving(true);
    try {
      const result = await createProjectMutation.mutateAsync({ name: newName.trim(), type: 'copy' });
      await refetchProjects();
      setProjectId(result.id.toString());
      setNewName('');
      setShowNew(false);
      toast.success(`Created project "${result.name}"`);
    } catch (err) {
      toast.error('Failed to create project');
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[425px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <FolderOpen className="w-5 h-5 text-blue-600" />
            Save Copy to Project
          </DialogTitle>
        </DialogHeader>

        <div className="grid gap-6 py-4">
          <p className="text-sm text-slate-500">
            You are saving <strong>{selectedIds.length}</strong> selected copy card(s).
          </p>

          {showNew ? (
            <div className="flex flex-col gap-3 p-3 rounded-lg border border-slate-100 bg-slate-50/50 animate-in fade-in slide-in-from-top-2 duration-200">
              <Label htmlFor="new-project-name" className="text-xs font-semibold text-slate-600">New Project Name</Label>
              <Input
                id="new-project-name"
                placeholder="e.g. Summer Campaign 2026"
                value={newName}
                onChange={(e) => setNewName(e.target.value)}
                className="w-full h-9 text-sm"
                onKeyDown={(e) => e.key === 'Enter' && handleCreateProject()}
                disabled={isSaving}
                autoFocus
              />
              <div className="flex justify-end gap-2 mt-1">
                <Button variant="ghost" size="sm" onClick={() => setShowNew(false)} disabled={isSaving} className="h-8 text-xs">
                  Cancel
                </Button>
                <Button size="sm" onClick={handleCreateProject} disabled={!newName.trim() || isSaving} className="h-8 text-xs gap-1">
                  {isSaving ? <Loader2 className="w-3 h-3 animate-spin" /> : <FolderPlus className="w-3.5 h-3.5" />}
                  Create Project
                </Button>
              </div>
            </div>
          ) : (
            <div className="flex flex-col gap-2">
              <Label htmlFor="project-select" className="text-xs font-semibold text-slate-600">Choose Project</Label>
              <div className="flex gap-2">
                <Select value={projectId} onValueChange={setProjectId} disabled={isSaving || projectsLoading}>
                  <SelectTrigger id="project-select" className="flex-1 text-sm h-10">
                    <SelectValue placeholder="Select a project..." />
                  </SelectTrigger>
                  <SelectContent>
                    {projectsLoading ? (
                      <div className="flex items-center justify-center p-4 text-xs text-slate-400">
                        <Loader2 className="w-4 h-4 animate-spin mr-2" /> Loading...
                      </div>
                    ) : !projects || projects.length === 0 ? (
                      <SelectItem value="none" disabled>No projects yet</SelectItem>
                    ) : (
                      projects.map((p: any) => (
                        <SelectItem key={p.id} value={p.id.toString()}>
                          {p.name}
                        </SelectItem>
                      ))
                    )}
                  </SelectContent>
                </Select>
                <Button
                  variant="outline"
                  onClick={() => setShowNew(true)}
                  disabled={isSaving}
                  className="h-10 px-3 shrink-0"
                  title="Create New Project"
                >
                  <FolderPlus className="w-4 h-4" />
                </Button>
              </div>
            </div>
          )}
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={isSaving}>
            Cancel
          </Button>
          <Button onClick={handleSave} disabled={isSaving || !projectId || projectId === 'none'} className="bg-blue-600 hover:bg-blue-700 text-white gap-1.5">
            {isSaving && <Loader2 className="w-4 h-4 animate-spin" />}
            Save to Project
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
