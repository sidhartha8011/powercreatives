/**
 * REFERENCE IMAGE SELECTOR
 *
 * Displays a brand's reference images as a thumbnail grid with:
 *   - Priority ordering (index 0 = primary, shown with star badge)
 *   - Move up/down to reorder
 *   - Remove button (X) per thumbnail
 *   - Upload button (+) for manual uploads
 *   - Fetch from URL button (two-step: click → input → fetch)
 *   - "Clear all" to deselect everything
 *
 * Reusable across any module that needs brand reference images:
 *   - Image module sidebar
 *   - Video module sidebar (future)
 *   - BrandDialog visual identity panel
 *
 * DATA OWNERSHIP: This component queries its own brand data via
 * `brands.getById`. After any mutation it invalidates the query,
 * so it always shows fresh assets without relying on stale parent props.
 *
 * URL FETCH: Uses `brands.addAssetFromUrl` for single direct image URLs
 * and `brands.fetchAssets` for webpage scraping (extracts multiple images).
 * The UI auto-detects: if the URL ends with an image extension, it uses
 * the single-image endpoint; otherwise it scrapes the page.
 *
 * Usage:
 *   <ReferenceImageSelector
 *     brandId={brand.id}
 *     onAssetsChanged={() => utils.brands.list.invalidate()}
 *   />
 */

import { useState, useRef, useCallback } from "react";
import { cn } from "@/lib/utils";
import { trpc } from "@/lib/trpc";
import { SectionLabel } from "@/components/shared/SectionLabel";
import { Button } from "@/components/ui/button";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";
import {
  Star,
  Crown,
  X,
  Plus,
  ChevronUp,
  ChevronDown,
  Loader2,
  Globe,
  ImageIcon,
  Trash2,
  Link,
} from "lucide-react";
import { colors as tokenColors, typography } from "@/components/shared/design-tokens";
import type { BrandAsset } from "@shared/brandTypes";

// ============================================
// Types
// ============================================

interface ReferenceImageSelectorProps {
  /** Brand ID to manage assets for */
  brandId: number;
  /**
   * Optional external asset list — if provided, used instead of querying.
   * Useful when the parent already has fresh data (e.g. Image module sidebar).
   * When omitted, the component queries brands.getById for its own data.
   */
  assets?: BrandAsset[];
  /** Called after any mutation so parent can invalidate/refetch */
  onAssetsChanged?: () => void;
  /** Max images that can be in the list */
  maxImages?: number;
  /** Additional className */
  className?: string;
  /** Optional callback to set an image as the primary/logo — when provided, shows a crown icon on hover */
  onSetAsPrimary?: (index: number) => void;
}

// ============================================
// Constants
// ============================================

const THUMB_SIZE = 56;
const MAX_UPLOAD_SIZE_MB = 10;
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif"];

/** Image file extensions for auto-detecting direct image URLs */
const IMAGE_EXTENSIONS = [".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".svg", ".ico"];

/**
 * Heuristic: does this URL look like a direct link to an image file?
 * If yes → use addAssetFromUrl (single image download).
 * If no  → use fetchAssets (scrape the webpage for images).
 */
function looksLikeDirectImageUrl(url: string): boolean {
  try {
    const pathname = new URL(url).pathname.toLowerCase();
    return IMAGE_EXTENSIONS.some((ext) => pathname.endsWith(ext));
  } catch {
    return false;
  }
}

// ============================================
// Component
// ============================================

export function ReferenceImageSelector({
  brandId,
  assets: externalAssets,
  onAssetsChanged,
  maxImages = 20,
  className,
  onSetAsPrimary,
}: ReferenceImageSelectorProps) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [fetchingUrl, setFetchingUrl] = useState(false);
  const [urlInput, setUrlInput] = useState("");
  const [showUrlInput, setShowUrlInput] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const utils = trpc.useUtils();

  // ── Own data query (only when no external assets provided) ──
  const brandQuery = trpc.brands.getById.useQuery(
    { id: brandId },
    { enabled: externalAssets === undefined && brandId > 0 }
  );

  // Resolve assets: prefer external, fall back to own query.
  // Filter out logo assets — logos are displayed in BrandLogoSection, not here.
  const allAssets: BrandAsset[] =
    externalAssets ?? (brandQuery.data as any)?.assets ?? [];
  const assets = allAssets.filter((a) => a.role !== 'logo');

  // Invalidate both own query and parent list after mutations
  const invalidateAll = useCallback(() => {
    brandQuery.refetch();
    utils.brands.list.invalidate();
    onAssetsChanged?.();
  }, [brandQuery, utils, onAssetsChanged]);

  // ── Mutations ──
  const addAssetMutation = trpc.brands.addAsset.useMutation({
    onSuccess: invalidateAll,
  });

  const addAssetFromUrlMutation = trpc.brands.addAssetFromUrl.useMutation({
    onSuccess: invalidateAll,
  });

  const removeAssetMutation = trpc.brands.removeAsset.useMutation({
    onSuccess: invalidateAll,
  });

  const reorderMutation = trpc.brands.reorderAssets.useMutation({
    onSuccess: invalidateAll,
  });

  const fetchAssetsMutation = trpc.brands.fetchAssets.useMutation({
    onSuccess: invalidateAll,
  });

  const updateMutation = trpc.brands.update.useMutation({
    onSuccess: invalidateAll,
  });

  // ── Handlers ──

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

          if (assets.length + i >= maxImages) {
            setError(`Maximum ${maxImages} images reached.`);
            break;
          }

          // Convert to base64
          const buffer = await file.arrayBuffer();
          const base64 = btoa(
            new Uint8Array(buffer).reduce(
              (data, byte) => data + String.fromCharCode(byte),
              ""
            )
          );

          await addAssetMutation.mutateAsync({
            brandId,
            fileData: base64,
            filename: file.name,
            mimeType: file.type,
            role: 'reference',
          });
        }
      } catch (err: any) {
        setError(err?.message || "Upload failed");
      } finally {
        setUploading(false);
        if (fileInputRef.current) fileInputRef.current.value = "";
      }
    },
    [brandId, assets.length, maxImages, addAssetMutation]
  );

  /**
   * Fetch image(s) from a URL.
   *
   * Smart routing:
   * - Direct image URL (e.g. .png, .jpg) → addAssetFromUrl (single download)
   * - Webpage URL → fetchAssets (scrape page for images)
   */
  const handleFetchUrl = useCallback(async () => {
    const rawUrl = urlInput.trim();
    if (!rawUrl) return;

    // Normalize: add https:// if missing
    const url = rawUrl.startsWith("http") ? rawUrl : `https://${rawUrl}`;

    setError(null);
    setFetchingUrl(true);

    try {
      if (looksLikeDirectImageUrl(url)) {
        // Single image download
        await addAssetFromUrlMutation.mutateAsync({
          brandId,
          imageUrl: url,
          role: 'reference',
        });
        setUrlInput("");
        setShowUrlInput(false);
      } else {
        // Webpage scrape
        const result = await fetchAssetsMutation.mutateAsync({
          brandId,
          url,
        });

        if (result.added.length === 0) {
          setError(result.message || "No images found on this page");
        } else {
          setUrlInput("");
          setShowUrlInput(false);
        }
      }
    } catch (err: any) {
      setError(err?.message || "Failed to fetch images from URL");
    } finally {
      setFetchingUrl(false);
    }
  }, [brandId, urlInput, addAssetFromUrlMutation, fetchAssetsMutation]);

  const handleRemove = useCallback(
    async (fileKey: string) => {
      setError(null);
      try {
        await removeAssetMutation.mutateAsync({ brandId, fileKey });
      } catch (err: any) {
        setError(err?.message || "Failed to remove image");
      }
    },
    [brandId, removeAssetMutation]
  );

  const handleMoveUp = useCallback(
    async (index: number) => {
      if (index === 0) return;
      const newOrder = [...assets.map((a) => a.fileKey)];
      [newOrder[index - 1], newOrder[index]] = [newOrder[index], newOrder[index - 1]];
      try {
        await reorderMutation.mutateAsync({ brandId, fileKeys: newOrder });
      } catch {
        // Silent
      }
    },
    [brandId, assets, reorderMutation]
  );

  const handleMoveDown = useCallback(
    async (index: number) => {
      if (index >= assets.length - 1) return;
      const newOrder = [...assets.map((a) => a.fileKey)];
      [newOrder[index], newOrder[index + 1]] = [newOrder[index + 1], newOrder[index]];
      try {
        await reorderMutation.mutateAsync({ brandId, fileKeys: newOrder });
      } catch {
        // Silent
      }
    },
    [brandId, assets, reorderMutation]
  );

  const handleClearAll = useCallback(async () => {
    setError(null);
    try {
      await updateMutation.mutateAsync({ id: brandId, assets: [] });
    } catch (err: any) {
      setError(err?.message || "Failed to clear images");
    }
  }, [brandId, updateMutation]);

  const isBusy = uploading || fetchingUrl || addAssetMutation.isPending || removeAssetMutation.isPending || reorderMutation.isPending;

  // ── Render ──
  return (
    <div className={cn("space-y-2", className)}>
      {/* Header */}
      <div className="flex items-center justify-between">
        <SectionLabel count={assets.length}>Reference Images</SectionLabel>
        {assets.length > 0 && (
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

      {/* Thumbnail grid */}
      {assets.length > 0 ? (
        <div className="flex flex-wrap gap-2">
          {assets.map((asset, index) => (
            <div
              key={asset.fileKey}
              className="relative group"
              style={{
                width: THUMB_SIZE,
                height: THUMB_SIZE,
              }}
            >
              {/* Thumbnail */}
              <img
                src={asset.url}
                alt={asset.filename}
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

              {/* Hover overlay with controls */}
              <div className="absolute inset-0 bg-black/50 rounded opacity-0 group-hover:opacity-100 transition-opacity flex flex-col items-center justify-center gap-0.5">
                {/* Set as primary (crown) — only shown when onSetAsPrimary provided and not already primary */}
                {onSetAsPrimary && index > 0 && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        onClick={() => onSetAsPrimary(index)}
                        disabled={isBusy}
                        className="p-0.5 rounded hover:bg-yellow-500/40 text-white disabled:opacity-40"
                      >
                        <Crown size={14} />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-xs">
                      Set as logo
                    </TooltipContent>
                  </Tooltip>
                )}

                {/* Move up */}
                {index > 0 && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        onClick={() => handleMoveUp(index)}
                        disabled={isBusy}
                        className="p-0.5 rounded hover:bg-white/20 text-white disabled:opacity-40"
                      >
                        <ChevronUp size={14} />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-xs">
                      Move up (higher priority)
                    </TooltipContent>
                  </Tooltip>
                )}

                {/* Move down */}
                {index < assets.length - 1 && (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <button
                        onClick={() => handleMoveDown(index)}
                        disabled={isBusy}
                        className="p-0.5 rounded hover:bg-white/20 text-white disabled:opacity-40"
                      >
                        <ChevronDown size={14} />
                      </button>
                    </TooltipTrigger>
                    <TooltipContent side="top" className="text-xs">
                      Move down (lower priority)
                    </TooltipContent>
                  </Tooltip>
                )}

                {/* Remove */}
                <Tooltip>
                  <TooltipTrigger asChild>
                    <button
                      onClick={() => handleRemove(asset.fileKey)}
                      disabled={isBusy}
                      className="p-0.5 rounded hover:bg-red-500/40 text-white disabled:opacity-40"
                    >
                      <X size={14} />
                    </button>
                  </TooltipTrigger>
                  <TooltipContent side="top" className="text-xs">
                    Remove
                  </TooltipContent>
                </Tooltip>
              </div>
            </div>
          ))}

          {/* Upload button (inline with thumbnails) */}
          {assets.length < maxImages && (
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  onClick={() => fileInputRef.current?.click()}
                  disabled={isBusy}
                  className="flex items-center justify-center rounded transition-colors"
                  style={{
                    width: THUMB_SIZE,
                    height: THUMB_SIZE,
                    border: `1px dashed ${tokenColors.border}`,
                    color: tokenColors.textMuted,
                    background: tokenColors.bgHover,
                  }}
                >
                  {uploading ? (
                    <Loader2 size={16} className="animate-spin" />
                  ) : (
                    <Plus size={16} />
                  )}
                </button>
              </TooltipTrigger>
              <TooltipContent side="top" className="text-xs">
                Upload image
              </TooltipContent>
            </Tooltip>
          )}
        </div>
      ) : !showUrlInput ? (
        /* Empty state — only shown when URL input is NOT open */
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
            No reference images yet.
            <br />
            Upload or fetch from a URL.
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => fileInputRef.current?.click()}
              disabled={isBusy}
              className="text-xs h-7"
            >
              {uploading ? <Loader2 size={12} className="animate-spin mr-1" /> : <Plus size={12} className="mr-1" />}
              Upload
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setShowUrlInput(true)}
              disabled={isBusy}
              className="text-xs h-7"
            >
              <Globe size={12} className="mr-1" />
              From URL
            </Button>
          </div>
        </div>
      ) : null}

      {/* URL fetch input — shown when toggled, persists during fetch */}
      {showUrlInput && (
        <div className="space-y-1.5">
          <div className="flex gap-1.5">
            <input
              type="text"
              value={urlInput}
              onChange={(e) => setUrlInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  handleFetchUrl();
                }
              }}
              placeholder="https://example.com/logo.png or website URL"
              className="flex-1 px-2 py-1 rounded text-xs"
              style={{
                border: `1px solid ${tokenColors.border}`,
                fontSize: typography.xs,
              }}
              disabled={fetchingUrl}
              autoFocus
            />
            <Button
              variant="outline"
              size="sm"
              onClick={handleFetchUrl}
              disabled={fetchingUrl || !urlInput.trim()}
              className="text-xs h-7 px-2"
            >
              {fetchingUrl ? (
                <Loader2 size={12} className="animate-spin" />
              ) : (
                <Link size={12} className="mr-1" />
              )}
              {fetchingUrl ? "" : "Fetch"}
            </Button>
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setShowUrlInput(false);
                setUrlInput("");
                setError(null);
              }}
              disabled={fetchingUrl}
              className="text-xs h-7 px-1.5"
            >
              <X size={12} />
            </Button>
          </div>
          <p
            className="text-xs px-0.5"
            style={{ color: tokenColors.textFaint, fontSize: typography.xxs }}
          >
            Paste a direct image URL or a webpage URL to extract images from.
          </p>
        </div>
      )}

      {/* Fetch from URL link (when images exist and URL input is hidden) */}
      {assets.length > 0 && !showUrlInput && (
        <button
          onClick={() => setShowUrlInput(true)}
          disabled={isBusy}
          className="flex items-center gap-1 text-xs transition-colors hover:opacity-80"
          style={{ color: tokenColors.primary, fontSize: typography.xs }}
        >
          <Globe size={11} />
          Fetch from URL
        </button>
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

      {/* Priority hint */}
      {assets.length > 1 && (
        <p
          className="text-xs"
          style={{ color: tokenColors.textFaint, fontSize: typography.xxs }}
        >
          First image is primary. Hover to reorder. Models use images in priority order.
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
