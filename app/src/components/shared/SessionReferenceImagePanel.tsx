/**
 * SESSION REFERENCE IMAGE PANEL
 *
 * Session-level reference image management with intent selection per image.
 * This component manages a local array of SessionReferenceImage objects that
 * are independent of the brand's permanent asset library.
 *
 * Responsibilities:
 *   - Initialize from brand assets when a brand is selected (copy, not link)
 *   - Allow session-only uploads (not persisted to brand)
 *   - Provide intent pill selector per thumbnail
 *   - Expose the session images + intents for the generation flow
 *
 * The parent (Image module) owns the state via `value` / `onChange` props,
 * following the same controlled-component pattern as other sidebar panels.
 *
 * Usage:
 *   <SessionReferenceImagePanel
 *     value={sessionImages}
 *     onChange={setSessionImages}
 *     brandAssets={contextData.brand?.assets ?? []}
 *   />
 */

import { useState, useRef, useCallback } from "react";
import { cn } from "@/lib/utils";
import { SectionLabel } from "@/components/shared/SectionLabel";
import { Button } from "@/components/ui/button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  X,
  Plus,
  Loader2,
  ImageIcon,
  Trash2,
  Star,
  Link2,
} from "lucide-react";
import { colors as tokenColors, typography, spacing } from "@/components/shared/design-tokens";
import { trpc } from "@/lib/trpc";
import type { BrandAsset } from "@shared/brandTypes";
import {
  ALL_INTENTS,
  INTENT_LABELS,
  INTENT_DESCRIPTIONS,
} from "@shared/referenceImageIntents";
import type {
  ReferenceImageIntent,
  SessionReferenceImage,
} from "@shared/referenceImageIntents";

// ============================================
// Props
// ============================================

interface SessionReferenceImagePanelProps {
  /** Current session reference images (controlled) */
  value: SessionReferenceImage[];
  /** Called when session images change */
  onChange: (images: SessionReferenceImage[]) => void;
  /** Max images allowed in session */
  maxImages?: number;
  /** Additional className */
  className?: string;
}

// ============================================
// Constants
// ============================================

const THUMB_SIZE = 56;
const MAX_UPLOAD_SIZE_MB = 10;
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif"];

// ============================================
// Component
// ============================================

export function SessionReferenceImagePanel({
  value: sessionImages,
  onChange,
  maxImages = 10,
  className,
}: SessionReferenceImagePanelProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // URL input state
  const [showUrlInput, setShowUrlInput] = useState(false);
  const [urlInput, setUrlInput] = useState("");
  const [urlLoading, setUrlLoading] = useState(false);

  const sessionUploadMutation = trpc.image.sessionUpload.useMutation();

  // ── Handlers ──

  /** Upload a session-only image (not persisted to brand) */
  const handleUpload = useCallback(
    async (e: React.ChangeEvent<HTMLInputElement>) => {
      const files = e.target.files;
      if (!files || files.length === 0) return;

      setError(null);
      setUploading(true);

      try {
        for (let i = 0; i < files.length; i++) {
          const file = files[i];

          if (!ACCEPTED_TYPES.includes(file.type)) {
            setError(`Unsupported format: ${file.name}. Use JPEG, PNG, WebP, or GIF.`);
            continue;
          }

          if (file.size > MAX_UPLOAD_SIZE_MB * 1024 * 1024) {
            setError(`File too large: ${file.name}. Max ${MAX_UPLOAD_SIZE_MB}MB.`);
            continue;
          }

          if (sessionImages.length + i >= maxImages) {
            setError(`Maximum ${maxImages} images reached.`);
            break;
          }

          // Upload to S3 via the session upload endpoint (no brand persistence)
          const buffer = await file.arrayBuffer();
          const base64 = btoa(
            new Uint8Array(buffer).reduce(
              (data, byte) => data + String.fromCharCode(byte),
              ""
            )
          );

          const result = await sessionUploadMutation.mutateAsync({
            fileData: base64,
            filename: file.name,
            mimeType: file.type,
          });

          const newImage: SessionReferenceImage = {
            id: crypto.randomUUID(),
            url: result.url,
            filename: result.filename,
            intent: 'auto',
            fromBrand: false,
          };

          onChange([...sessionImages, newImage]);
        }
      } catch (err: any) {
        setError(err?.message || "Upload failed");
      } finally {
        setUploading(false);
        if (fileInputRef.current) fileInputRef.current.value = "";
      }
    },
    [sessionImages, maxImages, onChange, sessionUploadMutation]
  );

  /** Remove a session image */
  const handleRemove = useCallback(
    (id: string) => {
      onChange(sessionImages.filter((img) => img.id !== id));
    },
    [sessionImages, onChange]
  );

  /** Change the intent for a specific image */
  const handleIntentChange = useCallback(
    (id: string, intent: ReferenceImageIntent) => {
      onChange(
        sessionImages.map((img) =>
          img.id === id ? { ...img, intent } : img
        )
      );
    },
    [sessionImages, onChange]
  );

  /** Clear all session images */
  const handleClearAll = useCallback(() => {
    setError(null);
    onChange([]);
  }, [onChange]);

  /** Add a reference image via URL (no backend upload needed) */
  const handleAddUrl = useCallback(() => {
    const trimmed = urlInput.trim();
    if (!trimmed) return;

    // Validate URL syntax
    try {
      new URL(trimmed);
    } catch {
      setError("Invalid URL format. Please enter a full URL (https://...).");
      return;
    }

    // Check max images limit
    if (sessionImages.length >= maxImages) {
      setError(`Maximum ${maxImages} images reached.`);
      return;
    }

    setError(null);
    setUrlLoading(true);

    // Validate the URL actually loads as an image
    const img = new Image();
    img.onload = () => {
      // Extract a filename from the URL path (or fallback)
      const pathname = new URL(trimmed).pathname;
      const filename = pathname.split("/").pop() || "url-image";

      const newImage: SessionReferenceImage = {
        id: crypto.randomUUID(),
        url: trimmed,
        filename,
        intent: "auto",
        fromBrand: false,
      };

      onChange([...sessionImages, newImage]);
      setUrlInput("");
      setShowUrlInput(false);
      setUrlLoading(false);
    };
    img.onerror = () => {
      setError("Could not load image from this URL. Please check the link.");
      setUrlLoading(false);
    };
    img.src = trimmed;
  }, [urlInput, sessionImages, maxImages, onChange]);

  /** Shared URL input inline form (used in both empty and populated states) */
  const renderUrlInput = (inputHeight: number) => (
    showUrlInput ? (
      <div className="flex gap-1 w-full">
        <input
          type="url"
          value={urlInput}
          onChange={(e) => setUrlInput(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') handleAddUrl(); if (e.key === 'Escape') { setShowUrlInput(false); setUrlInput(''); } }}
          placeholder="https://example.com/image.jpg"
          autoFocus
          disabled={urlLoading}
          className="flex-1 rounded px-2 text-xs outline-none"
          style={{
            height: inputHeight,
            border: `1px solid ${tokenColors.border}`,
            background: tokenColors.bgHover,
            color: tokenColors.text,
            fontSize: typography.xs,
          }}
        />
        <Button size="sm" variant="outline" onClick={handleAddUrl}
          disabled={urlLoading || !urlInput.trim()} className={`h-[${inputHeight}px] px-2 text-xs`}>
          {urlLoading ? <Loader2 size={12} className="animate-spin" /> : <Plus size={12} />}
        </Button>
        <Button size="sm" variant="ghost"
          onClick={() => { setShowUrlInput(false); setUrlInput(''); setError(null); }}
          className={`h-[${inputHeight}px] px-1 text-xs`}>
          <X size={12} />
        </Button>
      </div>
    ) : null
  );

  const isBusy = uploading || sessionUploadMutation.isPending;

  // ── Render ──
  return (
    <div className={cn("space-y-2", className)}>
      {/* Header */}
      <div className="flex items-center justify-between">
        <SectionLabel count={sessionImages.length}>Reference Images</SectionLabel>
        {sessionImages.length > 0 && (
          <button
            onClick={handleClearAll}
            disabled={isBusy}
            className="flex items-center gap-1 text-xs transition-colors hover:opacity-80 disabled:opacity-40"
            style={{ color: tokenColors.textMuted, fontSize: typography.xs }}
          >
            <Trash2 size={12} />
            Clear all
          </button>
        )}
      </div>

      {/* Image grid with intent pills */}
      {sessionImages.length > 0 ? (
        <div className="space-y-2">
          {sessionImages.map((img, index) => (
            <div
              key={img.id}
              className="flex gap-2 items-start"
            >
              {/* Thumbnail */}
              <div
                className="relative group shrink-0"
                style={{ width: THUMB_SIZE, height: THUMB_SIZE }}
              >
                <img
                  src={img.url}
                  alt={img.filename}
                  className="w-full h-full object-cover rounded"
                  style={{
                    border: index === 0
                      ? `2px solid ${tokenColors.primary}`
                      : `1px solid ${tokenColors.border}`,
                  }}
                  loading="lazy"
                />

                {/* Primary badge */}
                {index === 0 && (
                  <span
                    className="absolute -top-1 -left-1 flex items-center justify-center rounded-full"
                    style={{
                      width: 16,
                      height: 16,
                      background: tokenColors.primary,
                      color: "#fff",
                    }}
                  >
                    <Star size={9} fill="currentColor" />
                  </span>
                )}


                {/* Remove overlay */}
                <div className="absolute inset-0 bg-black/50 rounded opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        onClick={() => handleRemove(img.id)}
                        disabled={isBusy}
                        className="p-0.5 rounded hover:bg-red-500/40 text-white disabled:opacity-40"
                      >
                        <X size={14} />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-xs">
                      Remove from session
                    </TooltipContent>
                  </Tooltip>
                </div>
              </div>

              {/* Intent pills + filename */}
              <div className="flex-1 min-w-0">
                <p
                  className="truncate mb-1"
                  style={{
                    fontSize: typography.xxs,
                    color: tokenColors.textMuted,
                  }}
                  title={img.filename}
                >
                  {img.filename}
                </p>
                <div className="flex flex-wrap gap-1">
                  {ALL_INTENTS.map((intent) => {
                    const isActive = img.intent === intent;
                    return (
                      <Tooltip key={intent}>
                        <TooltipTrigger asChild>
                          <button
                            onClick={() => handleIntentChange(img.id, intent)}
                            className="transition-all"
                            style={{
                              fontSize: typography.micro,
                              fontWeight: isActive ? typography.semibold : typography.regular,
                              color: isActive ? '#fff' : tokenColors.textSecondary,
                              background: isActive ? tokenColors.primary : tokenColors.bgHover,
                              border: `1px solid ${isActive ? tokenColors.primary : tokenColors.border}`,
                              borderRadius: spacing.radiusPill,
                              padding: '1px 7px',
                              lineHeight: '1.6',
                            }}
                          >
                            {INTENT_LABELS[intent]}
                          </button>
                        </TooltipTrigger>
                        <TooltipContent side="bottom" className="text-xs max-w-[200px]">
                          {INTENT_DESCRIPTIONS[intent]}
                        </TooltipContent>
                      </Tooltip>
                    );
                  })}
                </div>
              </div>
            </div>
          ))}

          {/* Upload button (below images) */}
          {sessionImages.length < maxImages && (
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  onClick={() => fileInputRef.current?.click()}
                  disabled={isBusy}
                  className="flex items-center justify-center rounded transition-colors w-full"
                  style={{
                    height: 32,
                    border: `1px dashed ${tokenColors.border}`,
                    color: tokenColors.textMuted,
                    background: tokenColors.bgHover,
                    fontSize: typography.xs,
                  }}
                >
                  {uploading ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <span className="flex items-center gap-1">
                      <Plus size={12} />
                      Add image
                    </span>
                  )}
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" className="text-xs">
                Upload a session reference image
              </TooltipContent>
            </Tooltip>
          )}

          {/* Add URL button (below upload, when images exist) */}
          {sessionImages.length < maxImages && (
            !showUrlInput ? (
              <button
                onClick={() => { setShowUrlInput(true); setError(null); }}
                disabled={isBusy}
                className="flex items-center justify-center rounded transition-colors w-full"
                style={{
                  height: 32,
                  border: `1px dashed ${tokenColors.border}`,
                  color: tokenColors.textMuted,
                  background: 'transparent',
                  fontSize: typography.xs,
                }}
              >
                <span className="flex items-center gap-1">
                  <Link2 size={12} />
                  Add URL
                </span>
              </button>
            ) : renderUrlInput(32)
          )}
        </div>
      ) : (
        /* Empty state */
        <div
          className="flex flex-col items-center gap-2 py-4 rounded"
          style={{
            border: `1px dashed ${tokenColors.border}`,
            background: tokenColors.bgHover,
          }}
        >
          <ImageIcon size={20} style={{ color: tokenColors.textGhost }} />
          <p
            className="text-center px-4"
            style={{ fontSize: typography.xs, color: tokenColors.textMuted }}
          >
            No reference images.
            <br />
            Upload images to guide AI generation.
          </p>
          <Button
            variant="outline"
            size="sm"
            onClick={() => fileInputRef.current?.click()}
            disabled={isBusy}
            className="text-xs h-7"
          >
            {uploading ? (
              <Loader2 size={12} className="animate-spin mr-1" />
            ) : (
              <Plus size={12} className="mr-1" />
            )}
            Upload
          </Button>

          {/* Add URL button (empty state) */}
          {!showUrlInput ? (
            <button
              onClick={() => { setShowUrlInput(true); setError(null); }}
              disabled={isBusy}
              className="flex items-center gap-1 transition-colors hover:opacity-80"
              style={{ color: tokenColors.textMuted, fontSize: typography.xs }}
            >
              <Link2 size={12} />
              or paste URL
            </button>
          ) : renderUrlInput(28)}
        </div>
      )}

      {/* Error message */}
      {error && (
        <p
          className="text-xs px-1"
          style={{ color: tokenColors.danger, fontSize: typography.xs }}
        >
          {error}
        </p>
      )}

      {/* Hint */}
      {sessionImages.length > 0 && (
        <p
          className="text-xs"
          style={{ color: tokenColors.textFaint, fontSize: typography.xxs }}
        >
          Select how each image should be used. "Auto" lets the AI decide.
        </p>
      )}

      {/* Hidden file input */}
      <input
        ref={fileInputRef}
        type="file"
        accept={ACCEPTED_TYPES.join(",")}
        multiple
        onChange={handleUpload}
        className="hidden"
      />
    </div>
  );
}
