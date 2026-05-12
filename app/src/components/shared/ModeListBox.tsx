/**
 * MODE LIST BOX — Reusable Manual/Auto list component
 *
 * A bordered card with:
 *   - Header: label (left) + ✨ sparkle icon (if onGenerate) + Manual/Auto toggle (right)
 *   - Manual mode: editable list of items with add/remove + text input
 *   - Auto mode: count input + hint text
 *
 * The sparkle icon (visible always when onGenerate provided) calls the AI to
 * pre-generate items, then switches to Manual mode with generated items filled.
 * Design matches Video module's enhance-prompt sparkle icon pattern.
 *
 * Used for both Angles and Audiences in the Copy module sidebar.
 * Uses PowerKeys design tokens for consistent styling.
 */

import { useState } from 'react';
import { Plus, X, Sparkles, Loader2, Search } from 'lucide-react';
import { colors, typography, spacing } from './design-tokens';
import { ModeToggle, type GenerationMode } from './ModeToggle';

export interface ListItem {
  id: string;
  name: string;
}

interface ModeListBoxProps {
  /** Section label displayed in the header (e.g., "ANGLES", "AUDIENCES") */
  label: string;
  /** Current mode: manual or auto */
  mode: GenerationMode;
  /** Called when mode changes */
  onModeChange: (mode: GenerationMode) => void;
  /** Items in the manual list */
  items: ListItem[];
  /** Called when an item is added */
  onAddItem: (name: string) => void;
  /** Called when an item is removed */
  onRemoveItem: (id: string) => void;
  /** Called when items are bulk-replaced (e.g. from AI generation) */
  onItemsGenerated?: (items: ListItem[]) => void;
  /** Placeholder for the add input (e.g., "Add angle…", "Add audience…") */
  placeholder?: string;
  /** Auto mode: current count value */
  autoCount: number;
  /** Auto mode: called when count changes */
  onAutoCountChange: (count: number) => void;
  /** Auto mode: hint text (e.g., "AI will generate 3 angles based on your brief") */
  autoHint?: string;
  /** Min count for auto mode (default: 1) */
  min?: number;
  /** Max count for auto mode (default: 10) */
  max?: number;
  /**
   * Optional async callback for pre-generating items via AI.
   * When provided, a small ✨ sparkle icon appears in the header.
   * Should return an array of { id, name } items from the backend.
   */
  onGenerate?: () => Promise<ListItem[]>;
  /** Whether research is currently enabled (toggled on by user) */
  researchEnabled?: boolean;
  /** Called when user clicks the research icon to toggle on/off */
  onResearchToggle?: (enabled: boolean) => void;
  /** Whether a research model is configured and available */
  researchAvailable?: boolean;
  /** Research outcome from last generation: idle (not run), success, or failed */
  researchStatus?: 'idle' | 'success' | 'failed';
  /** Human-readable reason from backend when research was skipped or failed */
  researchSkipReason?: string;
}

export function ModeListBox({
  label,
  mode,
  onModeChange,
  items,
  onAddItem,
  onRemoveItem,
  onItemsGenerated,
  placeholder = 'Add item…',
  autoCount,
  onAutoCountChange,
  autoHint,
  min = 1,
  max = 10,
  onGenerate,
  researchEnabled,
  onResearchToggle,
  researchAvailable = false,
  researchStatus = 'idle',
  researchSkipReason = '',
}: ModeListBoxProps) {
  const [inputValue, setInputValue] = useState('');
  const [isGenerating, setIsGenerating] = useState(false);
  /** Error message from the last failed generation attempt */
  const [generateError, setGenerateError] = useState<string | null>(null);

  const handleAdd = () => {
    const name = inputValue.trim();
    if (!name) return;
    onAddItem(name);
    setInputValue('');
  };

  /**
   * Handle ✨ sparkle icon click — pre-generate items via AI.
   * 1. Call onGenerate() (async LLM call)
   * 2. Fill items via onItemsGenerated()
   * 3. Switch to Manual mode
   */
  const handleSparkleClick = async () => {
    if (!onGenerate || isGenerating) return;

    setIsGenerating(true);
    setGenerateError(null);
    try {
      const generated = await onGenerate();
      if (generated && generated.length > 0) {
        // Fill items first, then switch to manual mode
        onItemsGenerated?.(generated);
        onModeChange('manual');
      }
    } catch (err: any) {
      // Show error inline so the user knows what went wrong
      const message = err?.message || 'Generation failed';
      setGenerateError(message);
      console.error(`[ModeListBox] Failed to generate ${label}:`, err);
    } finally {
      setIsGenerating(false);
    }
  };

  return (
    <div
      className="rounded-lg border p-3 space-y-2"
      style={{ borderColor: colors.border, background: colors.bgMuted }}
    >
      {/* Header row: label + sparkle icon + mode toggle */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-1">
          <span
            style={{
              fontSize: typography.micro,
              fontWeight: typography.semibold,
              textTransform: 'uppercase',
              letterSpacing: '0.05em',
              color: colors.textSecondary,
            }}
          >
            {label}
          </span>

          {/* ✨ Sparkle pre-generate icon — always renders first.
              Uses design tokens for icon size (spacing.iconXs) instead of
              hardcoded px values, keeping it globally changeable. */}
          {onGenerate && (
            <button
              type="button"
              onClick={handleSparkleClick}
              disabled={isGenerating}
              title={`Generate ${label.toLowerCase()} with AI`}
              className="p-1 rounded-md transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
              style={{
                color: colors.textMuted,
              }}
              onMouseEnter={(e) => {
                if (!isGenerating) {
                  e.currentTarget.style.color = colors.primary;
                  e.currentTarget.style.background = colors.primaryLight;
                }
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.color = colors.textMuted;
                e.currentTarget.style.background = 'transparent';
              }}
            >
              {isGenerating ? (
                <Loader2
                  className="animate-spin"
                  style={{ width: spacing.iconXs, height: spacing.iconXs }}
                />
              ) : (
                <Sparkles style={{ width: spacing.iconXs, height: spacing.iconXs }} />
              )}
            </button>
          )}

          {/* 🔍 Research toggle icon — visible only when onResearchToggle is provided.
              3-state color: blue = enabled/idle, green = research succeeded, grey = off/failed/unavailable. */}
          {onResearchToggle && (
            <button
              type="button"
              onClick={() => researchAvailable && onResearchToggle(!researchEnabled)}
              disabled={!researchAvailable}
              title={
                !researchAvailable
                  ? researchSkipReason || 'Research unavailable (no Google Gemini model configured)'
                  : researchStatus === 'success'
                    ? '✓ Research succeeded — audiences are data-driven'
                    : researchStatus === 'failed'
                      ? `⚠ Research failed — ${researchSkipReason || 'audiences generated without research data'}`
                      : researchEnabled
                        ? 'Research enabled — click to disable'
                        : 'Research disabled — click to enable'
              }
              className="p-0.5 rounded transition-colors disabled:cursor-not-allowed"
              style={{
                opacity: researchAvailable ? 1 : 0.35,
                color: researchStatus === 'success'
                  ? '#22c55e'
                  : researchStatus === 'failed'
                    ? colors.textMuted
                    : researchEnabled && researchAvailable
                      ? colors.primary
                      : colors.textMuted,
                textDecoration: !researchAvailable ? 'line-through' : 'none',
              }}
            >
              <Search style={{ width: spacing.iconXs, height: spacing.iconXs }} />
            </button>
          )}
        </div>

        <ModeToggle
          mode={mode}
          onModeChange={onModeChange}
          count={autoCount}
          onCountChange={onAutoCountChange}
          min={min}
          max={max}
        />
      </div>

      {/* Manual mode: editable list */}
      {mode === 'manual' && (
        <>
          <div className="space-y-1">
            {items.map((item) => (
              <div
                key={item.id}
                className="flex items-center justify-between rounded-md px-2 py-1.5 group"
                style={{
                  background: colors.bgSurface,
                  border: `1px solid ${colors.borderLight}`,
                }}
              >
                <span
                  className="truncate"
                  style={{
                    fontSize: typography.xs,
                    fontWeight: typography.medium,
                    color: colors.text,
                  }}
                >
                  {item.name}
                </span>
                <button
                  type="button"
                  onClick={() => onRemoveItem(item.id)}
                  className="opacity-0 group-hover:opacity-100 transition-opacity p-0.5 rounded hover:bg-red-50"
                >
                  <X
                    style={{
                      width: spacing.iconXs,
                      height: spacing.iconXs,
                      color: colors.danger,
                    }}
                  />
                </button>
              </div>
            ))}
          </div>

          {/* Add new item */}
          <div className="flex gap-1">
            <input
              type="text"
              value={inputValue}
              onChange={(e) => setInputValue(e.target.value)}
              placeholder={placeholder}
              onKeyDown={(e) => e.key === 'Enter' && handleAdd()}
              className="flex-1 focus:outline-none focus:ring-1 focus:ring-blue-300"
              style={{
                height: '28px',
                padding: '0 8px',
                borderRadius: spacing.radiusSm,
                border: `1px solid ${colors.border}`,
                fontSize: typography.xs,
                color: colors.text,
                background: colors.bgSurface,
              }}
            />
            <button
              type="button"
              onClick={handleAdd}
              disabled={!inputValue.trim()}
              className="shrink-0 flex items-center justify-center transition-opacity"
              style={{
                height: '28px',
                width: '28px',
                borderRadius: spacing.radiusSm,
                border: `1px solid ${colors.border}`,
                background: colors.bgSurface,
                opacity: inputValue.trim() ? 1 : 0.4,
                cursor: inputValue.trim() ? 'pointer' : 'default',
              }}
            >
              <Plus
                style={{
                  width: spacing.iconXs,
                  height: spacing.iconXs,
                  color: colors.textMuted,
                }}
              />
            </button>
          </div>
        </>
      )}

      {/* Auto mode: hint text */}
      {mode === 'auto' && autoHint && (
        <p
          style={{
            fontSize: '0.6875rem',
            color: colors.textMuted,
          }}
        >
          {autoHint}
        </p>
      )}

      {/* Error feedback — visible inline when generation fails */}
      {generateError && (
        <div
          style={{
            fontSize: typography.xxs,
            color: colors.danger,
            padding: '6px 8px',
            borderRadius: spacing.radiusSm,
            background: '#fef2f2',
            border: '1px solid #fecaca',
          }}
        >
          <p style={{ margin: 0, lineHeight: 1.4 }}>{generateError}</p>
          <button
            type="button"
            onClick={() => setGenerateError(null)}
            className="shrink-0 underline mt-1"
            style={{ fontSize: typography.xxs, color: colors.textMuted }}
          >
            dismiss
          </button>
        </div>
      )}
    </div>
  );
}
