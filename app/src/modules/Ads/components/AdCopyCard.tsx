import { memo, useState, useRef, useEffect, useCallback } from 'react';
import { Target, Zap, CheckCircle2, Pencil, RefreshCw, Save, X, Copy, Check, ChevronDown } from 'lucide-react';
import type { TextSlot } from '../types';
import { tokens } from '@/components/shared';
import { useClipboard } from '@/hooks/useClipboard';

export interface AdCopyCardProps {
  text: TextSlot;
  isSelected?: boolean;
  onSelect?: () => void;
  onUpdate?: (updates: { headline?: string; body?: string; cta?: string; description?: string }) => void;
  onRegenerate?: (instruction?: string) => void;
  isRegenerating?: boolean;
}

const REGEN_PRESETS = [
  { id: 'shorter', label: 'Make shorter' },
  { id: 'longer', label: 'Make longer' },
  { id: 'urgent', label: 'More urgent' },
  { id: 'professional', label: 'More professional' },
  { id: 'casual', label: 'More casual' },
  { id: 'change_cta', label: 'Change CTA' },
  { id: 'custom', label: 'Custom instructions…' },
] as const;

export const AdCopyCard = memo(function AdCopyCard({
  text,
  isSelected,
  onSelect,
  onUpdate,
  onRegenerate,
  isRegenerating = false,
}: AdCopyCardProps) {
  const { colors, typography, spacing, shadows } = tokens;
  const [isEditing, setIsEditing] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const { copy, copied } = useClipboard();

  const bodyRef = useRef<HTMLDivElement>(null);
  const headlineRef = useRef<HTMLHeadingElement>(null);
  const descriptionRef = useRef<HTMLParagraphElement>(null);

  // Sync DOM content when not editing
  useEffect(() => {
    if (!isEditing) {
      if (bodyRef.current) bodyRef.current.innerText = text.body || '';
      if (headlineRef.current) headlineRef.current.innerText = text.headline || '';
      if (descriptionRef.current) descriptionRef.current.innerText = text.description || '';
    }
  }, [text, isEditing]);

  const handleCancelEdit = useCallback((e?: React.MouseEvent) => {
    e?.stopPropagation();
    if (bodyRef.current) bodyRef.current.innerText = text.body || '';
    if (headlineRef.current) headlineRef.current.innerText = text.headline || '';
    if (descriptionRef.current) descriptionRef.current.innerText = text.description || '';
    setIsEditing(false);
  }, [text]);

  const handleSave = useCallback(async (e: React.MouseEvent) => {
    e.stopPropagation();
    if (!onUpdate) return;
    
    const newBody = bodyRef.current?.innerText ?? text.body;
    const newHeadline = headlineRef.current?.innerText?.trim() ?? text.headline;
    const newDescription = descriptionRef.current?.innerText?.trim() ?? text.description ?? '';

    setIsSaving(true);
    try {
      const updates: Record<string, string> = {};
      if (newHeadline !== text.headline) updates.headline = newHeadline;
      if (newBody !== text.body) updates.body = newBody;
      if (newDescription !== (text.description ?? '')) updates.description = newDescription;

      if (Object.keys(updates).length > 0) {
        // Assume synchronous or fast update for now, but await if it returns a promise
        await Promise.resolve(onUpdate(updates));
      }
      setIsEditing(false);
    } finally {
      setIsSaving(false);
    }
  }, [text, onUpdate]);

  const handleCopy = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    const textToCopy = [
      text.body,
      text.headline,
      text.description,
    ].filter(Boolean).join('\n\n');
    copy(textToCopy);
  }, [text, copy]);

  return (
    <div
      onClick={(e) => {
        // Prevent selection when editing
        if (!isEditing) onSelect?.();
      }}
      className={`flex flex-col relative transition-all duration-200 group ${
        !isEditing ? 'cursor-pointer hover:shadow-md' : ''
      } ${
        isSelected && !isEditing ? 'ring-2 ring-[#2563eb] ring-offset-2' : ''
      } ${
        isEditing ? 'ring-2 ring-amber-400/40' : ''
      }`}
      style={{
        background: '#fff',
        borderRadius: spacing.radius,
        border: `1px solid ${isSelected && !isEditing ? '#2563eb' : colors.borderLight}`,
        boxShadow: shadows.card,
      }}
    >
      {isSelected && !isEditing && (
        <div className="absolute top-2 right-2 z-10 text-[#2563eb] bg-white rounded-full">
          <CheckCircle2 className="w-5 h-5" />
        </div>
      )}

      {/* Edit toggle floating button */}
      {!isEditing && onUpdate && (
        <button
          onClick={(e) => {
            e.stopPropagation();
            setIsEditing(true);
          }}
          className="absolute top-2 right-2 z-20 opacity-0 group-hover:opacity-100 transition-opacity p-1.5 rounded bg-white shadow-sm hover:bg-gray-50 border border-gray-200"
          title="Edit copy"
        >
          <Pencil className="w-3.5 h-3.5" style={{ color: colors.textSecondary }} />
        </button>
      )}

      {/* ── Meta Ad Anatomy ── */}
      <div className="flex flex-col flex-1">
        
        {/* 1. Primary Text (Body) */}
        <div className="px-4 py-4 pb-3">
          <div
            ref={bodyRef}
            contentEditable={isEditing || undefined}
            suppressContentEditableWarning
            className="whitespace-pre-line"
            style={{
              color: colors.text,
              fontSize: typography.sm,
              lineHeight: 1.5,
              outline: 'none',
              cursor: isEditing ? 'text' : 'pointer',
              minHeight: isEditing ? '2em' : undefined,
            }}
          >
            {text.body}
          </div>
        </div>

        {/* 2. Link Preview Box (Grey background) */}
        <div 
          className="mt-auto border-t border-b"
          style={{ 
            backgroundColor: '#f0f2f5', 
            borderColor: colors.borderLight,
            padding: '10px 16px' 
          }}
        >
          <div className="flex items-center justify-between gap-4">
            <div className="flex-1 min-w-0 flex flex-col justify-center">

              
              <div
                ref={headlineRef}
                contentEditable={isEditing || undefined}
                suppressContentEditableWarning
                className="font-bold leading-tight"
                style={{
                  color: colors.text,
                  fontSize: typography.body,
                  outline: 'none',
                  cursor: isEditing ? 'text' : 'pointer',
                  minHeight: isEditing ? '1.25em' : undefined,
                  wordBreak: 'break-word'
                }}
              >
                {text.headline}
              </div>

              {/* Description (Under Headline) */}
              {(text.description || isEditing) && (
                <div
                  ref={descriptionRef}
                  contentEditable={isEditing || undefined}
                  suppressContentEditableWarning
                  className="mt-0.5 leading-snug"
                  style={{
                    color: '#65676b',
                    fontSize: '0.75rem',
                    outline: 'none',
                    cursor: isEditing ? 'text' : 'pointer',
                    minHeight: isEditing ? '1.25em' : undefined,
                  }}
                >
                  {text.description}
                </div>
              )}
            </div>
            

          </div>
        </div>

        {/* ── Context Footer (Audience, Angle & Actions) ── */}
        <div className="px-4 py-3 flex items-center justify-between" style={{ backgroundColor: '#fff', borderBottomLeftRadius: spacing.radius, borderBottomRightRadius: spacing.radius }}>
          
          <div className="flex items-center gap-3">
            {text.audienceName && (
              <span className="flex items-center gap-1.5 text-[10px] font-medium" style={{ color: colors.textFaint }}>
                <Target className="w-3.5 h-3.5 shrink-0" /> <span className="truncate max-w-[100px]">{text.audienceName}</span>
              </span>
            )}
            {text.angleName && (
              <span className="flex items-center gap-1.5 text-[10px] font-medium" style={{ color: colors.textFaint }}>
                <Zap className="w-3.5 h-3.5 shrink-0" /> <span className="truncate max-w-[100px]">{text.angleName}</span>
              </span>
            )}
          </div>

          <div className="flex items-center gap-2">
            {isEditing ? (
              <>
                <button
                  onClick={handleCancelEdit}
                  className="flex items-center gap-1.5 px-2 py-1 rounded text-xs font-medium transition-all duration-150 hover:bg-gray-50"
                  style={{ color: colors.textMuted, border: `1px solid ${colors.border}` }}
                >
                  <X className="w-3 h-3" /> Cancel
                </button>
                <button
                  onClick={handleSave}
                  disabled={isSaving}
                  className="flex items-center gap-1.5 px-2 py-1 rounded text-xs font-medium transition-all duration-150"
                  style={{
                    background: '#2563eb',
                    color: '#fff',
                    opacity: isSaving ? 0.6 : 1,
                  }}
                >
                  {isSaving ? <RefreshCw className="w-3 h-3 animate-spin" /> : <Save className="w-3 h-3" />}
                  Save
                </button>
              </>
            ) : (
              <>
                {onRegenerate && (
                  <RegenerateSplitButton 
                    onRegenerate={onRegenerate} 
                    isRegenerating={isRegenerating} 
                  />
                )}
                <button
                  onClick={handleCopy}
                  title={copied ? 'Copied!' : 'Copy to clipboard'}
                  className="flex items-center p-1.5 rounded text-xs font-medium transition-all duration-150 hover:bg-gray-50"
                  style={{
                    background: copied ? colors.successLight : 'transparent',
                    color: copied ? colors.success : colors.textMuted,
                    border: copied ? `1px solid ${colors.successBorder}` : `1px solid ${colors.borderLight}`,
                  }}
                >
                  {copied ? <Check className="w-3.5 h-3.5" /> : <Copy className="w-3.5 h-3.5" />}
                </button>
              </>
            )}
          </div>
        </div>

      </div>
    </div>
  );
});

// ─── Regenerate Split Button (Local Copy for Ads) ───
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
  const { colors } = tokens;

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
        className="flex items-center rounded overflow-hidden"
        style={{ border: `1px solid ${colors.borderLight}` }}
        onClick={(e) => e.stopPropagation()}
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

        <div style={{ width: '1px', height: '16px', background: colors.borderLight }} />

        <button
          onClick={() => {
            setOpen(!open);
            setShowCustomInput(false);
          }}
          className="flex items-center px-1 py-1.5 transition-all duration-150 hover:bg-gray-50"
          style={{ color: colors.textMuted, background: 'transparent' }}
        >
          <ChevronDown className="w-3.5 h-3.5" />
        </button>
      </div>

      {open && (
        <div
          onClick={(e) => e.stopPropagation()}
          className="absolute right-0 bottom-full mb-1 z-50 rounded-lg shadow-lg"
          style={{
            background: '#fff',
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
                      borderTop: `1px solid ${colors.borderLight}`,
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
