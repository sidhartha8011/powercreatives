/**
 * SHARED — Image Overlay (Dumb UI Component)
 *
 * Hover overlay that appears on images inside a Tiptap editor.
 * Renders action buttons: Edit, Regenerate (with dropdown for prompt + model).
 *
 * Architecture: This component is DUMB — no API calls, no state management.
 * All callbacks are injected via props from useImageActions hook.
 * All UI primitives are from the existing design system (ui/).
 *
 * @package PowerCreatives
 * @module  Shared
 */

import { useState, useRef, useEffect } from 'react';
import { RefreshCw, Pencil, ChevronDown, Loader2, Sparkles, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectTrigger,
  SelectContent,
  SelectItem,
  SelectValue,
} from '@/components/ui/select';
import { colors, typography } from '@/components/shared/design-tokens';
import type { ImageModel } from './useImageActions';

interface ImageOverlayProps {
  /** Whether the overlay is visible */
  isVisible: boolean;
  /** Whether a generation/edit is currently in progress */
  isProcessing: boolean;
  /** Available image generation models */
  imageModels: ImageModel[];
  /** Currently selected model ID */
  selectedModel: string;
  /** Update selected model */
  onModelChange: (modelId: string) => void;
  /** Regenerate with optional additional prompt */
  onRegenerate: (additionalPrompt?: string) => void;
  /** Edit image with instructions */
  onEdit: (instructions: string) => void;
}

export function ImageOverlay({
  isVisible,
  isProcessing,
  imageModels,
  selectedModel,
  onModelChange,
  onRegenerate,
  onEdit,
}: ImageOverlayProps) {
  const [showDropdown, setShowDropdown] = useState(false);
  const [promptText, setPromptText] = useState('');
  const [mode, setMode] = useState<'regenerate' | 'edit' | null>(null);
  const dropdownRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  // Close dropdown on outside click
  useEffect(() => {
    if (!showDropdown) return;
    const handleClick = (e: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(e.target as Node)) {
        setShowDropdown(false);
        setMode(null);
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [showDropdown]);

  // Auto-focus input when mode changes
  useEffect(() => {
    if (mode && inputRef.current) inputRef.current.focus();
  }, [mode]);

  if (!isVisible && !showDropdown) return null;

  /** Submit prompt for edit or regenerate */
  const handleSubmitPrompt = () => {
    if (mode === 'edit') {
      onEdit(promptText);
    } else {
      onRegenerate(promptText || undefined);
    }
    setPromptText('');
    setShowDropdown(false);
    setMode(null);
  };

  return (
    <div
      className="pcm-img-overlay"
      style={{
        position: 'absolute',
        inset: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        gap: '8px',
        background: isProcessing ? 'rgba(0,0,0,0.6)' : 'rgba(0,0,0,0.45)',
        borderRadius: '8px',
        opacity: isVisible || showDropdown ? 1 : 0,
        pointerEvents: isVisible || showDropdown ? 'auto' : 'none',
        transition: 'opacity 0.2s ease',
        zIndex: 10,
      }}
      onClick={(e) => e.stopPropagation()}
    >
      {/* Loading spinner during processing */}
      {isProcessing ? (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: '8px' }}>
          <Loader2 className="w-6 h-6 animate-spin" style={{ color: colors.primary }} />
          <span style={{ fontSize: typography.xs, color: 'rgba(255,255,255,0.7)' }}>Generating...</span>
        </div>
      ) : (
        <>
          {/* Edit button — opens prompt input for edit instructions */}
          <Button
            size="sm"
            variant="secondary"
            onClick={(e) => { e.stopPropagation(); setMode('edit'); setShowDropdown(true); }}
            className="gap-1.5"
          >
            <Pencil className="w-3.5 h-3.5" />
            Edit
          </Button>

          {/* Regenerate button group + dropdown */}
          <div style={{ position: 'relative' }} ref={dropdownRef}>
            <div className="flex gap-0.5">
              {/* Quick regenerate (same prompt) */}
              <Button
                size="sm"
                onClick={(e) => { e.stopPropagation(); onRegenerate(); }}
                className="gap-1.5 rounded-r-none"
                style={{ background: colors.primary }}
              >
                <RefreshCw className="w-3.5 h-3.5" />
                Regenerate
              </Button>

              {/* Dropdown arrow — prompt + model options */}
              <Button
                size="sm"
                onClick={(e) => { e.stopPropagation(); setMode('regenerate'); setShowDropdown(!showDropdown); }}
                className="rounded-l-none px-2"
                style={{ background: colors.primary }}
              >
                <ChevronDown className="w-3.5 h-3.5" />
              </Button>
            </div>

            {/* Dropdown panel */}
            {showDropdown && (
              <div
                className="rounded-lg border shadow-lg"
                style={{
                  position: 'absolute',
                  bottom: '100%',
                  right: 0,
                  marginBottom: '6px',
                  background: colors.bgSurface,
                  borderColor: colors.border,
                  padding: '10px',
                  minWidth: '260px',
                  zIndex: 20,
                }}
                onClick={(e) => e.stopPropagation()}
              >
                {/* Mode label + close */}
                <div className="flex justify-between items-center" style={{ marginBottom: '8px' }}>
                  <span style={{ fontSize: typography.xs, fontWeight: typography.semibold, color: colors.textMuted, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
                    {mode === 'edit' ? 'Edit Image' : 'Regenerate with Instructions'}
                  </span>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    onClick={() => { setShowDropdown(false); setMode(null); }}
                    className="h-5 w-5"
                  >
                    <X className="w-3 h-3" />
                  </Button>
                </div>

                {/* Prompt input */}
                <div className="flex gap-1" style={{ marginBottom: '8px' }}>
                  <Input
                    ref={inputRef}
                    value={promptText}
                    onChange={(e) => setPromptText(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') handleSubmitPrompt(); }}
                    placeholder={mode === 'edit' ? 'Describe what to change...' : 'Additional instructions...'}
                    className="h-8 text-xs"
                  />
                  <Button size="sm" onClick={handleSubmitPrompt} style={{ background: colors.primary }} className="h-8 px-2">
                    <Sparkles className="w-3.5 h-3.5" />
                  </Button>
                </div>

                {/* Model selector */}
                {imageModels.length > 1 && (
                  <div>
                    <div style={{ fontSize: typography.xxs, color: colors.textMuted, marginBottom: '4px' }}>Model</div>
                    <Select
                      value={selectedModel || imageModels[0]?.modelId}
                      onValueChange={onModelChange}
                    >
                      <SelectTrigger className="h-8 text-xs">
                        <SelectValue placeholder="Select model" />
                      </SelectTrigger>
                      <SelectContent>
                        {imageModels.map((m) => (
                          <SelectItem key={m.modelId} value={m.modelId}>
                            {m.customName || m.originalName || m.modelId}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                )}
              </div>
            )}
          </div>
        </>
      )}
    </div>
  );
}
