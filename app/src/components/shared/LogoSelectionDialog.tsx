/**
 * LOGO SELECTION — Content + Dialog Wrapper
 *
 * Two exports:
 *   1. LogoSelectionContent — Pure content (state + UI, no Dialog)
 *      Used inline by BrandDialog wizard
 *   2. LogoSelectionDialog — Thin Dialog wrapper around Content
 *      Used by ContextPanel
 *
 * Pattern: "Content-Only" (industry standard for reusable modal content)
 * — Content is dialog-agnostic, renders anywhere
 * — Dialog wrapper adds Radix Dialog + accessibility
 *
 * Features:
 *   - Image grid with click-to-select (highlighted border on selection)
 *   - Resolution badge (e.g. "512×512") on each thumbnail
 *   - Upload tab for file upload
 *   - URL paste tab for direct image URL
 *   - "Skip" / "Confirm" actions
 *   - Optional color assignment step after logo selection
 *
 * @package PowerCreatives
 */

import { useState, useRef, useCallback } from "react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Button } from "@/components/ui/button";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Upload,
  Link,
  Check,
  Loader2,
  ImageIcon,
} from "lucide-react";
import { colors, typography } from "@/components/shared/design-tokens";

// ============================================
// Types
// ============================================

/** Image data returned from the unified scraper */
export interface ImageInfo {
  url: string;
  width: number;
  height: number;
}

/** Props for the content component (dialog-agnostic) */
export interface LogoSelectionContentProps {
  /** All images scraped from the page, with dimensions, sorted by resolution */
  images: ImageInfo[];
  /** Called when user confirms a logo — returns extracted logo colors (for pre-selection) */
  onLogoSelected: (url: string) => Promise<string[]>;
  /** Called when user uploads a file */
  onUploadFile?: (file: File) => void;
  /** Called when user pastes a direct URL */
  onUrlPaste?: (url: string) => void;
  /** Called when user skips logo selection */
  onSkip: () => void;
  /** When true, the confirm button shows a spinner and all actions are disabled */
  isConfirming?: boolean;
  /** Extracted colors from CSS + logo — shown in color assignment step */
  extractedColors?: string[];
  /** Called when user confirms primary/secondary color assignment */
  onColorsAssigned?: (primary: string, secondary: string) => void;
  /** Called when content wants to close/finish (after confirm/skip/upload) */
  onDone?: () => void;
}

/** Props for the Dialog wrapper (adds open/onOpenChange) */
interface LogoSelectionDialogProps extends LogoSelectionContentProps {
  /** Whether the dialog is open */
  open: boolean;
  /** Control dialog visibility */
  onOpenChange: (open: boolean) => void;
}

// ============================================
// Constants
// ============================================

const THUMB_SIZE = 80;
const ACCEPTED_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif", "image/svg+xml"];
const MAX_UPLOAD_SIZE_MB = 10;

// ============================================
// Content Component (dialog-agnostic)
// ============================================

export function LogoSelectionContent({
  images,
  onLogoSelected,
  onUploadFile,
  onUrlPaste,
  onSkip,
  isConfirming = false,
  extractedColors = [],
  onColorsAssigned,
  onDone,
}: LogoSelectionContentProps) {
  const [selectedUrl, setSelectedUrl] = useState<string | null>(null);
  const [urlInput, setUrlInput] = useState("");
  const [uploadError, setUploadError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Color assignment step state
  const [showColorStep, setShowColorStep] = useState(false);
  const [primaryColor, setPrimaryColor] = useState<string | null>(null);
  const [secondaryColor, setSecondaryColor] = useState<string | null>(null);
  // Colors displayed in the color step grid — starts with CSS colors, logo colors merged in on confirm
  const [displayColors, setDisplayColors] = useState<string[]>(extractedColors);

  // ── Handlers ──

  /** Confirm logo selection. Awaits save → gets logo colors → merges into grid → pre-selects → shows color step. */
  const handleConfirm = useCallback(async () => {
    if (selectedUrl) {
      // Save logo and get extracted colors back from backend
      const logoColors = await onLogoSelected(selectedUrl);

      // If colors available and callback exists, merge logo colors + pre-select + show color step
      if ((extractedColors.length > 0 || logoColors.length > 0) && onColorsAssigned && !showColorStep) {
        // Merge logo colors into display list (logo colors first, then CSS colors without duplicates)
        const existingLower = new Set(extractedColors.map(c => c.toLowerCase()));
        const uniqueLogoColors = logoColors.filter(c => !existingLower.has(c.toLowerCase()));
        const merged = [...uniqueLogoColors, ...extractedColors];
        setDisplayColors(merged);

        // Pre-select: first logo color = Primary, second = Secondary
        if (logoColors.length >= 1) setPrimaryColor(logoColors[0]);
        if (logoColors.length >= 2) setSecondaryColor(logoColors[1]);
        setShowColorStep(true);
        return;
      }
      setSelectedUrl(null);
      onDone?.();
    }
  }, [selectedUrl, onLogoSelected, extractedColors, onColorsAssigned, showColorStep, onDone]);

  const handleSkip = useCallback(() => {
    onSkip();
    setSelectedUrl(null);
    onDone?.();
  }, [onSkip, onDone]);

  const handleFileUpload = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (!file) return;

      setUploadError(null);

      if (!ACCEPTED_TYPES.includes(file.type)) {
        setUploadError("Unsupported format. Use JPEG, PNG, WebP, GIF, or SVG.");
        return;
      }

      if (file.size > MAX_UPLOAD_SIZE_MB * 1024 * 1024) {
        setUploadError(`File too large. Max ${MAX_UPLOAD_SIZE_MB}MB.`);
        return;
      }

      onUploadFile?.(file);
      setSelectedUrl(null);
      onDone?.();

      // Reset file input
      if (fileInputRef.current) fileInputRef.current.value = "";
    },
    [onUploadFile, onDone]
  );

  const handleUrlSubmit = useCallback(() => {
    const trimmed = urlInput.trim();
    if (!trimmed) return;

    onUrlPaste?.(trimmed);
    setSelectedUrl(null);
    setUrlInput("");
    onDone?.();
  }, [urlInput, onUrlPaste, onDone]);

  /** Confirm color assignment */
  const handleColorsConfirm = useCallback(() => {
    if (primaryColor && secondaryColor && onColorsAssigned) {
      onColorsAssigned(primaryColor, secondaryColor);
    }
    onDone?.();
  }, [primaryColor, secondaryColor, onColorsAssigned, onDone]);

  // ── Render ──

  // Step 2: Color assignment (after logo confirmed)
  if (showColorStep && displayColors.length > 0) {
    return (
      <div>
        <div className="flex flex-col space-y-1.5 text-center sm:text-left">
          <h3 className="text-lg font-semibold leading-none tracking-tight">Assign Brand Colors</h3>
          <p className="text-sm" style={{ color: colors.textMuted }}>
            Click a color to set as <strong>Primary</strong>, right-click for{" "}
            <strong>Secondary</strong>.
          </p>
        </div>

        <div
          className="grid gap-2 mt-3"
          style={{ gridTemplateColumns: `repeat(auto-fill, minmax(${THUMB_SIZE}px, 1fr))` }}
        >
          {displayColors.map((color, i) => {
            const isPrimary = primaryColor === color;
            const isSecondary = secondaryColor === color;
            return (
              <button
                key={`${color}-${i}`}
                type="button"
                title={`${color}${isPrimary ? " (Primary)" : ""}${isSecondary ? " (Secondary)" : ""}\nClick = Primary, Right-click = Secondary`}
                onClick={() => setPrimaryColor(color)}
                onContextMenu={(e) => {
                  e.preventDefault();
                  setSecondaryColor(color);
                }}
                className="relative rounded-md transition-all cursor-pointer"
                style={{
                  width: THUMB_SIZE,
                  height: THUMB_SIZE,
                  backgroundColor: color,
                  border: isPrimary
                    ? "3px solid #3b82f6"
                    : isSecondary
                    ? "3px solid #22c55e"
                    : `1px solid ${colors.border}`,
                  boxShadow: isPrimary || isSecondary ? "0 0 0 2px rgba(0,0,0,0.1)" : "none",
                }}
              >
                {isPrimary && (
                  <span className="absolute -top-1 -right-1 text-[9px] font-bold bg-blue-500 text-white rounded-full px-1">
                    P
                  </span>
                )}
                {isSecondary && (
                  <span className="absolute -bottom-1 -right-1 text-[9px] font-bold bg-green-500 text-white rounded-full px-1">
                    S
                  </span>
                )}
              </button>
            );
          })}
        </div>

        {/* Legend */}
        <div className="flex gap-4 mt-2 text-xs" style={{ color: colors.textFaint }}>
          <span className="flex items-center gap-1">
            <span className="w-3 h-3 rounded-sm bg-blue-500" /> Primary (click)
          </span>
          <span className="flex items-center gap-1">
            <span className="w-3 h-3 rounded-sm bg-green-500" /> Secondary (right-click)
          </span>
        </div>

        <div className="flex justify-end mt-4 gap-2">
          <Button
            variant="ghost"
            onClick={() => onDone?.()}
            className="text-sm"
          >
            Skip Colors
          </Button>
          <Button
            onClick={handleColorsConfirm}
            disabled={!primaryColor || !secondaryColor}
            className="gap-1.5"
          >
            <Check size={14} />
            Confirm Colors
          </Button>
        </div>
      </div>
    );
  }

  // Step 1: Logo selection
  return (
    <div>
      <div className="flex flex-col space-y-1.5 text-center sm:text-left">
        <h3 className="text-lg font-semibold leading-none tracking-tight">Select Logo</h3>
        <p className="text-sm" style={{ color: colors.textMuted }}>
          Choose the brand logo from the images found on the website, or upload
          your own.
        </p>
      </div>

      <Tabs defaultValue="select" className="mt-2">
        <TabsList className="grid w-full grid-cols-3">
          <TabsTrigger value="select" className="text-xs gap-1.5">
            <ImageIcon size={14} />
            Select
          </TabsTrigger>
          <TabsTrigger value="upload" className="text-xs gap-1.5">
            <Upload size={14} />
            Upload
          </TabsTrigger>
          <TabsTrigger value="url" className="text-xs gap-1.5">
            <Link size={14} />
            From URL
          </TabsTrigger>
        </TabsList>

        {/* ── Tab: Select from scraped images ── */}
        <TabsContent value="select" className="mt-3">
          {images.length > 0 ? (
            <>
              <div
                className="grid gap-2 max-h-[300px] overflow-y-auto p-1"
                style={{
                  gridTemplateColumns: `repeat(auto-fill, minmax(${THUMB_SIZE}px, 1fr))`,
                }}
              >
                {images.map((img, index) => {
                  const isSelected = selectedUrl === img.url;
                  const dimLabel =
                    img.width > 0 && img.height > 0
                      ? `${img.width}×${img.height}`
                      : "?";

                  return (
                    <button
                      key={img.url}
                      type="button"
                      onClick={() => setSelectedUrl(img.url)}
                      className="relative rounded-md overflow-hidden cursor-pointer transition-all hover:opacity-90"
                      style={{
                        width: THUMB_SIZE,
                        height: THUMB_SIZE,
                        border: isSelected
                          ? `3px solid ${colors.primary}`
                          : `1px solid ${colors.border}`,
                        background: colors.bgMuted,
                      }}
                    >
                      <img
                        src={img.url}
                        alt={`Image ${index + 1}`}
                        className="w-full h-full object-contain"
                        loading="lazy"
                        onError={(e) => {
                          (e.target as HTMLImageElement).style.display = "none";
                        }}
                      />

                      {/* Resolution badge */}
                      <span
                        className="absolute bottom-0 left-0 right-0 text-center"
                        style={{
                          fontSize: 9,
                          lineHeight: "14px",
                          background: "rgba(0,0,0,0.6)",
                          color: "#fff",
                          padding: "0 2px",
                        }}
                      >
                        {dimLabel}
                      </span>

                      {/* Selected checkmark */}
                      {isSelected && (
                        <span
                          className="absolute top-1 right-1 flex items-center justify-center rounded-full"
                          style={{
                            width: 22,
                            height: 22,
                            background: colors.primary,
                            color: "#fff",
                          }}
                        >
                          <Check size={14} />
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </>
          ) : (
            <div
              className="flex flex-col items-center gap-2 py-6 rounded"
              style={{
                border: `1px dashed ${colors.border}`,
                background: colors.bgHover,
              }}
            >
              <ImageIcon size={24} style={{ color: colors.textGhost }} />
              <p
                className="text-center px-4"
                style={{ fontSize: typography.xs, color: colors.textMuted }}
              >
                No images found on this page.
                <br />
                Upload a logo or paste a URL instead.
              </p>
            </div>
          )}
        </TabsContent>

        {/* ── Tab: Upload file ── */}
        <TabsContent value="upload" className="mt-3">
          <div
            className="flex flex-col items-center gap-3 py-8 rounded cursor-pointer hover:opacity-80 transition-opacity"
            style={{
              border: `2px dashed ${colors.border}`,
              background: colors.bgHover,
            }}
            onClick={() => fileInputRef.current?.click()}
          >
            <Upload size={28} style={{ color: colors.textMuted }} />
            <p className="text-center" style={{ fontSize: typography.sm, color: colors.textSecondary }}>
              Click to upload logo
            </p>
            <p className="text-center" style={{ fontSize: typography.xxs, color: colors.textFaint }}>
              JPEG, PNG, WebP, GIF, or SVG. Max {MAX_UPLOAD_SIZE_MB}MB.
            </p>
          </div>

          {uploadError && (
            <p className="text-xs mt-2" style={{ color: colors.danger }}>
              {uploadError}
            </p>
          )}

          <input
            ref={fileInputRef}
            type="file"
            accept={ACCEPTED_TYPES.join(",")}
            onChange={handleFileUpload}
            className="hidden"
          />
        </TabsContent>

        {/* ── Tab: Paste URL ── */}
        <TabsContent value="url" className="mt-3 space-y-3">
          <div className="flex gap-2">
            <input
              type="text"
              value={urlInput}
              onChange={(e) => setUrlInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  handleUrlSubmit();
                }
              }}
              placeholder="https://example.com/logo.png"
              className="flex-1 px-3 py-2 rounded-md text-sm outline-none"
              style={{
                border: `1px solid ${colors.border}`,
                background: colors.bgSurface,
                fontSize: typography.sm,
              }}
              autoFocus
            />
            <Button
              size="sm"
              onClick={handleUrlSubmit}
              disabled={!urlInput.trim()}
              className="shrink-0"
            >
              <Link size={14} className="mr-1" />
              Use
            </Button>
          </div>
          <p className="text-xs" style={{ color: colors.textFaint, fontSize: typography.xxs }}>
            Paste a direct link to the logo image file.
          </p>
        </TabsContent>
      </Tabs>

      <div className="flex justify-end mt-4 gap-2">
        <Button variant="ghost" onClick={handleSkip} className="text-sm" disabled={isConfirming}>
          Skip — No logo
        </Button>
        <Button
          onClick={handleConfirm}
          disabled={!selectedUrl || isConfirming}
          className="gap-1.5"
        >
          {isConfirming ? (
            <Loader2 size={14} className="animate-spin" />
          ) : (
            <Check size={14} />
          )}
          {isConfirming ? "Saving..." : "Use as Logo"}
        </Button>
      </div>
    </div>
  );
}

// ============================================
// Dialog Wrapper (thin — used by ContextPanel)
// ============================================

export function LogoSelectionDialog({
  open,
  onOpenChange,
  ...contentProps
}: LogoSelectionDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[560px]">
        {/* Key forces state reset when dialog re-opens */}
        <LogoSelectionContent
          key={open ? "open" : "closed"}
          {...contentProps}
          onDone={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  );
}
