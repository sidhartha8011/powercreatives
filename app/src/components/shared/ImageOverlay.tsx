/**
 * SHARED — Image Overlay (Dumb UI Component)
 *
 * Hover overlay that appears on images inside a Tiptap editor.
 * Renders action buttons: Edit, Regenerate (with dropdown for prompt + model).
 *
 * Architecture: This component is DUMB — no API calls, no state management.
 * All callbacks are injected via props from useImageActions hook.
 *
 * @package PowerCreatives
 * @module  Shared
 */

import { useState, useRef, useEffect } from 'react';
import { RefreshCw, Pencil, ChevronDown, Loader2, Sparkles, X } from 'lucide-react';

interface ImageModel {
  modelId: string;
  provider: string;
  customName?: string;
  originalName?: string;
}

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

/** Shared button style for overlay actions */
function OverlayButton({
  onClick,
  disabled = false,
  title,
  children,
  variant = 'default',
}: {
  onClick: () => void;
  disabled?: boolean;
  title: string;
  children: React.ReactNode;
  variant?: 'default' | 'primary';
}) {
  return (
    <button
      type="button"
      onClick={(e) => { e.stopPropagation(); onClick(); }}
      disabled={disabled}
      title={title}
      className="pcm-img-overlay-btn"
      style={{
        background: variant === 'primary' ? 'rgba(99, 102, 241, 0.9)' : 'rgba(24, 24, 27, 0.85)',
        color: '#f0f0f0',
        border: '1px solid rgba(255,255,255,0.1)',
        borderRadius: '8px',
        padding: '6px 10px',
        fontSize: '12px',
        fontWeight: 500,
        cursor: disabled ? 'not-allowed' : 'pointer',
        opacity: disabled ? 0.5 : 1,
        display: 'flex',
        alignItems: 'center',
        gap: '5px',
        transition: 'all 0.15s ease',
        backdropFilter: 'blur(8px)',
      }}
    >
      {children}
    </button>
  );
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

  const handleQuickRegenerate = () => {
    onRegenerate();
  };

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
          <Loader2 className="w-6 h-6 animate-spin" style={{ color: '#a5b4fc' }} />
          <span style={{ fontSize: '11px', color: 'rgba(255,255,255,0.7)' }}>Generating...</span>
        </div>
      ) : (
        <>
          {/* Edit button */}
          <OverlayButton
            onClick={() => { setMode('edit'); setShowDropdown(true); }}
            title="Edit image with instructions"
          >
            <Pencil className="w-3.5 h-3.5" />
            Edit
          </OverlayButton>

          {/* Regenerate button + dropdown toggle */}
          <div style={{ position: 'relative' }} ref={dropdownRef}>
            <div style={{ display: 'flex', gap: '1px' }}>
              {/* Quick regenerate (same prompt) */}
              <OverlayButton onClick={handleQuickRegenerate} title="Regenerate with same prompt" variant="primary">
                <RefreshCw className="w-3.5 h-3.5" />
                Regenerate
              </OverlayButton>

              {/* Dropdown arrow — prompt + model options */}
              <OverlayButton
                onClick={() => { setMode('regenerate'); setShowDropdown(!showDropdown); }}
                title="Regenerate with options"
                variant="primary"
              >
                <ChevronDown className="w-3.5 h-3.5" />
              </OverlayButton>
            </div>

            {/* Dropdown panel */}
            {showDropdown && (
              <div
                style={{
                  position: 'absolute',
                  bottom: '100%',
                  right: 0,
                  marginBottom: '6px',
                  background: '#18181b',
                  border: '1px solid rgba(255,255,255,0.1)',
                  borderRadius: '10px',
                  padding: '10px',
                  minWidth: '260px',
                  boxShadow: '0 12px 40px rgba(0,0,0,0.5)',
                  backdropFilter: 'blur(12px)',
                  zIndex: 20,
                }}
                onClick={(e) => e.stopPropagation()}
              >
                {/* Mode label */}
                <div style={{
                  fontSize: '11px',
                  fontWeight: 600,
                  color: 'rgba(255,255,255,0.5)',
                  textTransform: 'uppercase',
                  letterSpacing: '0.05em',
                  marginBottom: '8px',
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                }}>
                  <span>{mode === 'edit' ? 'Edit Image' : 'Regenerate with Instructions'}</span>
                  <button
                    type="button"
                    onClick={() => { setShowDropdown(false); setMode(null); }}
                    style={{ background: 'none', border: 'none', color: 'rgba(255,255,255,0.4)', cursor: 'pointer', padding: '2px' }}
                  >
                    <X className="w-3 h-3" />
                  </button>
                </div>

                {/* Prompt input */}
                <div style={{ display: 'flex', gap: '4px', marginBottom: '8px' }}>
                  <input
                    ref={inputRef}
                    type="text"
                    value={promptText}
                    onChange={(e) => setPromptText(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') handleSubmitPrompt(); }}
                    placeholder={mode === 'edit' ? 'Describe what to change...' : 'Additional instructions...'}
                    style={{
                      flex: 1,
                      background: 'rgba(255,255,255,0.06)',
                      border: '1px solid rgba(255,255,255,0.1)',
                      borderRadius: '6px',
                      padding: '6px 8px',
                      fontSize: '12px',
                      color: '#e4e4e7',
                      outline: 'none',
                    }}
                  />
                  <OverlayButton onClick={handleSubmitPrompt} title="Submit" variant="primary">
                    <Sparkles className="w-3.5 h-3.5" />
                  </OverlayButton>
                </div>

                {/* Model selector */}
                {imageModels.length > 1 && (
                  <div>
                    <div style={{ fontSize: '10px', color: 'rgba(255,255,255,0.4)', marginBottom: '4px' }}>Model</div>
                    <select
                      value={selectedModel || imageModels[0]?.modelId}
                      onChange={(e) => onModelChange(e.target.value)}
                      style={{
                        width: '100%',
                        background: 'rgba(255,255,255,0.06)',
                        border: '1px solid rgba(255,255,255,0.1)',
                        borderRadius: '6px',
                        padding: '5px 8px',
                        fontSize: '11px',
                        color: '#e4e4e7',
                        outline: 'none',
                        cursor: 'pointer',
                      }}
                    >
                      {imageModels.map((m) => (
                        <option key={m.modelId} value={m.modelId}>
                          {m.customName || m.originalName || m.modelId}
                        </option>
                      ))}
                    </select>
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
