/**
 * INLINE EDITABLE CARD — Facebook Ad-style Layout
 *
 * Self-contained copy card with three states: READ → EDIT → SAVE.
 *
 * Layout (top to bottom, matching real Facebook ad structure):
 *   1. Angle label + checkbox + edit button (meta row)
 *   2. Body copy (primary text — the main ad copy)
 *   3. Hashtags (organic only)
 *   4. Headline (bold, moved to bottom like Facebook link preview)
 *   5. Description (short link description below headline)
 *   6. CTA (call-to-action)
 *   7. Footer (model info + action buttons)
 *
 * Features:
 *   - Click-to-edit on headline, body (Tiptap), description, CTA, hashtags
 *   - Checkbox for multi-select
 *   - Regenerate split button
 *   - Copy to clipboard
 *   - Save button persists edits to backend
 */

import { useState, useCallback, useRef, useEffect, memo } from 'react';
import { useClipboard } from '@/hooks/useClipboard';
import {
  Copy,
  RefreshCw,
  Save,
  Check,
  ChevronDown,
  X,
  Pencil,
  CopyPlus,
  Target,
  Zap,
} from 'lucide-react';
import { colors } from '@/components/shared';
import type { CopyVariation } from '../types';

// Preset regeneration adjustments (shared with ResultsPanel)
export const REGEN_PRESETS = [
  { id: 'shorter', label: 'Make shorter' },
  { id: 'longer', label: 'Make longer' },
  { id: 'urgent', label: 'More urgent' },
  { id: 'emotional', label: 'More emotional' },
  { id: 'professional', label: 'More professional' },
  { id: 'casual', label: 'More casual' },
  { id: 'change_cta', label: 'Change CTA' },
  { id: 'custom', label: 'Custom instructions…' },
] as const;

interface InlineEditableCardProps {
  variation: CopyVariation;
  index: number;
  /** Whether this card is selected in multi-select mode */
  isSelected?: boolean;
  /** Toggle selection for this card */
  onToggleSelect?: (cardId: string) => void;
  /** Regenerate this card with optional instruction */
  onRegenerateCard?: (variationId: string, instruction?: string) => void;
  /** Duplicate this card — inserts an identical copy directly after it */
  onDuplicate?: (variationId: string) => void;
  /** Whether this card is currently being regenerated */
  isRegenerating?: boolean;
  /** Save edited text fields to backend */
  onSaveEdits?: (
    variationId: string,
    updates: { headline?: string; body?: string; cta?: string; hashtags?: string; description?: string },
  ) => Promise<void>;
  /** Whether any selection exists (to show checkboxes) */
  showCheckbox?: boolean;
  /** Whether the card is in read-only presentation mode */
  readOnly?: boolean;
}

type EditState = 'read' | 'edit';

function InlineEditableCardInner({
  variation,
  index,
  isSelected = false,
  onToggleSelect,
  onRegenerateCard,
  onDuplicate,
  isRegenerating = false,
  onSaveEdits,
  showCheckbox = false,
  readOnly = false,
}: InlineEditableCardProps) {
  const [editState, setEditState] = useState<EditState>('read');
  const { copy, copied } = useClipboard();
  const [isSaving, setIsSaving] = useState(false);

  // Refs for in-place contentEditable fields — no separate state needed.
  // We read DOM values on save and reset them on cancel.
  const bodyRef = useRef<HTMLDivElement>(null);
  const headlineRef = useRef<HTMLHeadingElement>(null);
  const hashtagsRef = useRef<HTMLParagraphElement>(null);
  const descriptionRef = useRef<HTMLParagraphElement>(null);
  const ctaRef = useRef<HTMLParagraphElement>(null);

  // Sync DOM content from variation when in read mode (e.g. after regeneration)
  useEffect(() => {
    if (editState === 'read') {
      if (bodyRef.current) bodyRef.current.innerText = variation.body ?? '';
      if (headlineRef.current) headlineRef.current.innerText = variation.headline ?? '';
      if (hashtagsRef.current) hashtagsRef.current.innerText = variation.hashtags?.join('  ') ?? '';
      if (descriptionRef.current) descriptionRef.current.innerText = variation.description ?? '';
      if (ctaRef.current) ctaRef.current.innerText = variation.cta ?? '';
    }
  }, [variation, editState]);

  const handleCopy = useCallback(() => {
    const text = [
      variation.body,
      variation.headline,
      variation.description,
      variation.cta,
      variation.hashtags?.join(' '),
    ]
      .filter(Boolean)
      .join('\n\n');
    copy(text);
  }, [variation, copy]);

  const handleEnterEdit = useCallback(() => {
    setEditState('edit');
  }, []);

  const handleCancelEdit = useCallback(() => {
    // Reset DOM content to original variation values — no state needed.
    if (bodyRef.current) bodyRef.current.innerText = variation.body ?? '';
    if (headlineRef.current) headlineRef.current.innerText = variation.headline ?? '';
    if (hashtagsRef.current) hashtagsRef.current.innerText = variation.hashtags?.join('  ') ?? '';
    if (descriptionRef.current) descriptionRef.current.innerText = variation.description ?? '';
    if (ctaRef.current) ctaRef.current.innerText = variation.cta ?? '';
    setEditState('read');
  }, [variation]);

  const handleSave = useCallback(async () => {
    // Read current DOM values from refs — the source of truth during editing.
    const newBody = bodyRef.current?.innerText ?? variation.body;
    const newHeadline = headlineRef.current?.innerText?.trim() ?? variation.headline;
    const newHashtagsRaw = hashtagsRef.current?.innerText ?? '';
    const newDescription = descriptionRef.current?.innerText?.trim() ?? variation.description ?? '';
    const newCta = ctaRef.current?.innerText?.trim() ?? variation.cta ?? '';

    setIsSaving(true);
    try {
      const updates: Record<string, string> = {};
      if (newHeadline !== variation.headline) updates.headline = newHeadline;
      if (newBody !== variation.body) updates.body = newBody;
      if (newCta !== (variation.cta ?? '')) updates.cta = newCta;
      if (newDescription !== (variation.description ?? '')) updates.description = newDescription;
      // Normalize hashtag string: split by comma or whitespace, rejoin with comma
      const newHashtagsNorm = newHashtagsRaw.split(/[,\s]+/).map((h) => h.trim()).filter(Boolean).join(',');
      const oldHashtagsNorm = (variation.hashtags ?? []).join(',');
      if (newHashtagsNorm !== oldHashtagsNorm) updates.hashtags = newHashtagsNorm;

      if (Object.keys(updates).length > 0) {
        await onSaveEdits(variation.id, updates);
      }
      setEditState('read');
    } catch {
      // Error is handled by the hook
    } finally {
      setIsSaving(false);
    }
  }, [variation, onSaveEdits]);

  const variationLabel = `${variation.copyType === 'social_ads' ? 'Social Ad' : 'Organic Post'} · Variation ${index + 1}`;

  const isEditing = editState === 'edit';

  return (
    <article
      className={`flex flex-col transition-all duration-200 rounded-xl relative group ${isSelected ? 'ring-2 ring-blue-400/50' : ''
        } ${isEditing ? 'ring-2 ring-amber-400/40' : ''}`}
      style={{
        padding: '2.25rem 2.5rem',
        background: isSelected ? 'rgba(59, 130, 246, 0.03)' : colors.bgSurface,
        border: `1px solid ${colors.borderLight}`,
      }}
    >
      {/* Checkbox + Angle label row */}
      <div className="flex items-center gap-2 mb-2">
        {/* Checkbox — always visible when showCheckbox or on hover */}
        {!readOnly && (
          <div
            className={`transition-opacity duration-150 ${showCheckbox || isSelected ? 'opacity-100' : 'opacity-0 group-hover:opacity-100'
              }`}
          >
            <input
              type="checkbox"
              checked={isSelected}
              onChange={() => onToggleSelect && onToggleSelect(variation.id)}
              className="w-3.5 h-3.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500 cursor-pointer"
            />
          </div>
        )}

        <span
          className="text-[10px] font-medium uppercase tracking-widest flex-1 animate-in fade-in duration-150"
          style={{ color: '#9ca3af' }}
        >
          {variationLabel}
        </span>

        {/* Edit toggle button */}
        {!readOnly && !isEditing && (
          <button
            onClick={handleEnterEdit}
            className="opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded hover:bg-gray-100"
            title="Edit copy"
          >
            <Pencil className="w-3 h-3" style={{ color: colors.textMuted }} />
          </button>
        )}
      </div>

      {/* ─── BODY (primary text) ─── */}
      {/* In edit mode: same div, same styling, just contentEditable=true.
          outline:none removes the browser's default focus ring. */}
      <div
        ref={bodyRef}
        contentEditable={isEditing || undefined}
        suppressContentEditableWarning
        className="whitespace-pre-line flex-1 mb-3"
        style={{
          color: '#374151',
          fontSize: '0.8125rem',
          lineHeight: 1.65,
          outline: 'none',
          cursor: isEditing ? 'text' : 'default',
        }}
      >
        {variation.body}
      </div>

      {/* ─── HASHTAGS (organic only) ─── */}
      {/* Always render when editing so user can add hashtags; hidden when empty in read mode */}
      {(variation.hashtags && variation.hashtags.length > 0 || isEditing) && (
        <p
          ref={hashtagsRef}
          contentEditable={isEditing || undefined}
          suppressContentEditableWarning
          className="text-xs mb-3"
          style={{
            color: '#6366f1',
            outline: 'none',
            cursor: isEditing ? 'text' : 'default',
            minHeight: isEditing ? '1.25em' : undefined,
          }}
        >
          {variation.hashtags?.join('  ')}
        </p>
      )}

      {/* ─── LINK PREVIEW (headline + description, Facebook-style) ─── */}
      {(variation.headline || variation.description || isEditing) && (
        <div className="mb-3 pt-3 border-t border-slate-100/80">
          {/* Headline — same h3 tag, editable in place */}
          {(variation.headline || isEditing) && (
            <h3
              ref={headlineRef}
              contentEditable={isEditing || undefined}
              suppressContentEditableWarning
              className="font-bold leading-snug"
              style={{
                color: '#111827',
                fontSize: '0.875rem',
                outline: 'none',
                cursor: isEditing ? 'text' : 'default',
                minHeight: isEditing ? '1.25em' : undefined,
              }}
            >
              {variation.headline}
            </h3>
          )}

          {/* Description — same p tag, editable in place */}
          {(variation.description || isEditing) && (
            <p
              ref={descriptionRef}
              contentEditable={isEditing || undefined}
              suppressContentEditableWarning
              className="mt-1 leading-relaxed"
              style={{
                color: '#6b7280',
                fontSize: '0.75rem',
                outline: 'none',
                cursor: isEditing ? 'text' : 'default',
                minHeight: isEditing ? '1.25em' : undefined,
              }}
            >
              {variation.description}
            </p>
          )}
        </div>
      )}

      {/* ─── CTA ─── */}
      {(variation.cta || isEditing) && (
        <p
          ref={ctaRef}
          contentEditable={isEditing || undefined}
          suppressContentEditableWarning
          className="font-semibold mb-3"
          style={{
            color: '#2563eb',
            fontSize: '0.8125rem',
            outline: 'none',
            cursor: isEditing ? 'text' : 'default',
            minHeight: isEditing ? '1.25em' : undefined,
          }}
        >
          {variation.cta}
        </p>
      )}

      {/* Footer — model + actions */}
      <div
        className="flex flex-col pt-3 mt-auto rounded-lg p-3 border border-slate-100/60 bg-slate-50/50"
      >
        {/* Context Row */}
        <div className="flex flex-col gap-2 pb-2 mb-2 border-b border-slate-100/60 w-full">
          <div className="flex items-center gap-2 min-w-0" title={variation.audienceName || 'General Audience'}>
            <Target className="w-3.5 h-3.5 shrink-0 text-slate-400" />
            <span className="text-[11px] truncate font-medium text-slate-500">
              Audience: <span className="font-semibold text-slate-700">{variation.audienceName || 'General'}</span>
            </span>
          </div>
          <div className="flex items-center gap-2 min-w-0" title={variation.angleName || `Angle ${index + 1}`}>
            <Zap className="w-3.5 h-3.5 shrink-0 text-slate-400" />
            <span className="text-[11px] truncate font-medium text-slate-500">
              Angle: <span className="font-semibold text-slate-700">{variation.angleName || `Angle ${index + 1}`}</span>
            </span>
          </div>
        </div>

        {/* Action / Model Row */}
        <div className="flex items-center justify-between w-full">
          <span className="text-[10px] font-semibold tracking-wider uppercase text-slate-400">
            via {variation.modelUsed || 'Gemini Flash'}
          </span>

          {!readOnly && (
            <div className="flex gap-2">
              {isEditing ? (
                <>
                  <button
                    onClick={handleCancelEdit}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all duration-150"
                    style={{
                      background: 'transparent',
                      color: colors.textMuted,
                      border: `1px solid ${colors.border}`,
                    }}
                  >
                    <X className="w-3.5 h-3.5" />
                    Cancel
                  </button>
                  <button
                    onClick={handleSave}
                    disabled={isSaving}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all duration-150"
                    style={{
                      background: '#2563eb',
                      color: '#fff',
                      border: '1px solid #2563eb',
                      opacity: isSaving ? 0.6 : 1,
                    }}
                  >
                    {isSaving ? (
                      <RefreshCw className="w-3.5 h-3.5 animate-spin" />
                    ) : (
                      <Save className="w-3.5 h-3.5" />
                    )}
                    {isSaving ? 'Saving…' : 'Save'}
                  </button>
                </>
              ) : (
                <>
                  <RegenerateSplitButton
                    onRegenerate={(instruction) => onRegenerateCard && onRegenerateCard(variation.id, instruction)}
                    isRegenerating={isRegenerating}
                  />

                  <button
                    onClick={handleCopy}
                    title={copied ? 'Copied!' : 'Copy to clipboard'}
                    className="flex items-center p-1.5 rounded-lg text-xs font-medium transition-all duration-150"
                    style={{
                      background: copied ? colors.successLight : 'transparent',
                      color: copied ? colors.success : colors.textMuted,
                      border: copied
                        ? `1px solid ${colors.successBorder}`
                        : `1px solid ${colors.border}`,
                    }}
                  >
                    {copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                  </button>

                  {/* Duplicate button — clones this card and inserts it directly below */}
                  {onDuplicate && (
                    <button
                      onClick={() => onDuplicate(variation.id)}
                      title="Duplicate card"
                      className="flex items-center p-1.5 text-xs font-medium transition-all duration-150 rounded-lg hover:bg-gray-50"
                      style={{
                        color: colors.textMuted,
                        background: 'transparent',
                        border: `1px solid ${colors.border}`,
                      }}
                    >
                      <CopyPlus className="w-3.5 h-3.5" />
                    </button>
                  )}
                </>
              )}
            </div>
          )}
        </div>
      </div>
    </article>
  );
}

// ─── Regenerate Split Button (extracted for reuse) ───
function RegenerateSplitButton({
  onRegenerate,
  isRegenerating,
}: {
  onRegenerate: (instruction?: string) => void;
  isRegenerating: boolean;
}) {
  const [open, setOpen] = useState(false);
  const [showCustomInput, setShowCustomInput] = useState(false);
  const [customText, setCustomText] = useState('');
  const dropdownRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setOpen(false);
        setShowCustomInput(false);
      }
    };
    if (open) document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [open]);

  return (
    <div className="relative" ref={dropdownRef}>
      <div
        className="flex items-center rounded-lg overflow-hidden"
        style={{ border: `1px solid ${colors.border}` }}
      >
        <button
          onClick={() => onRegenerate()}
          disabled={isRegenerating}
          title="Regenerate"
          className="flex items-center p-1.5 text-xs font-medium transition-all duration-150 hover:bg-gray-50 disabled:opacity-50"
          style={{ color: colors.textMuted, background: 'transparent' }}
        >
          <RefreshCw className={`w-3.5 h-3.5 ${isRegenerating ? 'animate-spin' : ''}`} />
        </button>

        <div style={{ width: '1px', height: '20px', background: colors.border }} />

        <button
          onClick={() => {
            setOpen(!open);
            setShowCustomInput(false);
          }}
          className="flex items-center px-1.5 py-1.5 transition-all duration-150 hover:bg-gray-50"
          style={{ color: colors.textMuted, background: 'transparent' }}
        >
          <ChevronDown className="w-3.5 h-3.5" />
        </button>
      </div>

      {open && (
        <div
          className="absolute right-0 bottom-full mb-1 z-50 rounded-lg"
          style={{
            background: colors.bgSurface,
            border: `1px solid ${colors.border}`,
            minWidth: '200px',
          }}
        >
          {REGEN_PRESETS.map((preset) => {
            if (preset.id === 'custom') {
              return (
                <div key={preset.id}>
                  <button
                    onClick={() => setShowCustomInput(!showCustomInput)}
                    className="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-gray-50"
                    style={{
                      color: colors.textSecondary,
                      borderTop: `1px solid ${colors.bgHover}`,
                    }}
                  >
                    {preset.label}
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
                            onRegenerate(customText.trim());
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
                        Regenerate with instructions
                      </button>
                    </div>
                  )}
                </div>
              );
            }

            return (
              <button
                key={preset.id}
                onClick={() => {
                  onRegenerate(preset.label);
                  setOpen(false);
                }}
                className="w-full text-left px-3 py-2 text-xs transition-colors hover:bg-gray-50"
                style={{ color: colors.textSecondary }}
              >
                {preset.label}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

export const InlineEditableCard = memo(InlineEditableCardInner);
