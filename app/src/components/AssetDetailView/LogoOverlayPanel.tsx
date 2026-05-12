/**
 * LOGO OVERLAY PANEL
 *
 * Composites a brand logo onto the current image (no AI, pure pixel overlay).
 * If no brand logo is available, shows upload/URL options.
 * Tracks the pre-logo URL so the user can "Remove Logo" to revert.
 * Clears revert state when the asset URL changes from an external action
 * (Refine, Variations) so the undo only applies to the logo overlay.
 * Follows the same SectionPanel + ActionButton pattern as ExportPanel.
 */

import { useState, useRef, useEffect } from "react";
import { Image, Upload, Link, Undo2 } from "lucide-react";
import { SectionPanel } from "./SectionPanel";
import { ActionButton } from "./ActionButton";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Slider } from "@/components/ui/slider";
import { trpc } from "@/lib/trpc";
import { toast } from "sonner";
import type { Asset } from "./types";

type Placement = "bottom-right" | "bottom-left" | "top-right" | "top-left";

interface LogoOverlayPanelProps {
  asset: Asset;
  brandLogoUrl?: string | null;
  onAssetRefined?: (asset: Asset) => void;
}

export function LogoOverlayPanel({
  asset,
  brandLogoUrl,
  onAssetRefined,
}: LogoOverlayPanelProps) {
  const [placement, setPlacement] = useState<Placement>("bottom-right");
  const [scale, setScale] = useState(15);
  const [padding, setPadding] = useState(20);
  const [customLogoUrl, setCustomLogoUrl] = useState("");
  const [showUrlInput, setShowUrlInput] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Track the original (pre-logo) URL for undo.
  // null = no logo has been applied (or it was already removed/cleared).
  const [originalUrl, setOriginalUrl] = useState<string | null>(null);
  // Track the URL we produced via overlay so we can detect external changes.
  const [overlaidUrl, setOverlaidUrl] = useState<string | null>(null);

  const overlayMutation = trpc.image.overlayLogo.useMutation();

  const effectiveLogoUrl = brandLogoUrl || customLogoUrl || null;
  const hasLogoApplied = originalUrl !== null && overlaidUrl === asset.url;

  // If the asset URL changes to something other than our overlaid URL,
  // an external action (Refine, Variations) replaced the image.
  // Clear the revert state so "Remove Logo" doesn't undo past the external edit.
  useEffect(() => {
    if (overlaidUrl && asset.url !== overlaidUrl) {
      setOriginalUrl(null);
      setOverlaidUrl(null);
    }
  }, [asset.url, overlaidUrl]);

  const handleApply = async () => {
    if (!effectiveLogoUrl) {
      toast.error("No logo available. Upload one or enter a URL.");
      return;
    }

    // Always composite onto the original (un-logo'd) image so repeated
    // applies don't stack logos on top of each other.
    const baseUrl = originalUrl ?? asset.url;

    try {
      const result = await overlayMutation.mutateAsync({
        imageUrl: baseUrl,
        logoUrl: effectiveLogoUrl,
        placement,
        scale,
        padding,
      });

      // Save the pre-logo URL (only on first apply in this cycle).
      if (originalUrl === null) {
        setOriginalUrl(asset.url);
      }
      setOverlaidUrl(result.url);

      if (onAssetRefined) {
        onAssetRefined({
          ...asset,
          url: result.url,
          updatedAt: new Date(),
        });
      }
      toast.success("Logo applied!");
    } catch (err) {
      const msg = err instanceof Error ? err.message : "Failed to apply logo";
      toast.error(msg);
    }
  };

  const handleRemove = () => {
    if (!originalUrl || !onAssetRefined) return;

    onAssetRefined({
      ...asset,
      url: originalUrl,
      updatedAt: new Date(),
    });

    setOriginalUrl(null);
    setOverlaidUrl(null);
    toast.success("Logo removed — original image restored.");
  };

  const handleFileUpload = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
      const dataUrl = reader.result as string;
      setCustomLogoUrl(dataUrl);
      toast.success(`Logo loaded: ${file.name}`);
    };
    reader.readAsDataURL(file);
    e.target.value = "";
  };

  const handleUrlSubmit = () => {
    if (!customLogoUrl.trim()) return;
    setShowUrlInput(false);
    toast.success("Logo URL set");
  };

  const isLoading = overlayMutation.isPending;

  return (
    <SectionPanel title="Add Logo">
      {/* Logo preview or upload options */}
      {effectiveLogoUrl ? (
        <div className="flex items-center gap-2 mb-2">
          <img
            src={effectiveLogoUrl}
            alt="Logo"
            className="h-8 w-8 rounded border border-neutral-200 object-contain bg-white"
          />
          <span className="text-xs text-muted-foreground truncate flex-1">
            {brandLogoUrl ? "Brand logo" : "Custom logo"}
          </span>
          {!brandLogoUrl && (
            <Button
              variant="ghost"
              size="sm"
              className="h-6 text-xs px-2"
              onClick={() => {
                setCustomLogoUrl("");
                setShowUrlInput(false);
              }}
            >
              Clear
            </Button>
          )}
        </div>
      ) : (
        <div className="space-y-2 mb-2">
          <p className="text-xs text-muted-foreground">No brand logo found.</p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              className="flex-1 text-xs gap-1"
              onClick={() => fileInputRef.current?.click()}
            >
              <Upload className="h-3 w-3" />
              Upload
            </Button>
            <Button
              variant="outline"
              size="sm"
              className="flex-1 text-xs gap-1"
              onClick={() => setShowUrlInput(!showUrlInput)}
            >
              <Link className="h-3 w-3" />
              From URL
            </Button>
          </div>
          {showUrlInput && (
            <div className="flex gap-1.5">
              <Input
                value={customLogoUrl}
                onChange={(e) => setCustomLogoUrl(e.target.value)}
                placeholder="https://..."
                className="h-7 text-xs"
                onKeyDown={(e) => e.key === "Enter" && handleUrlSubmit()}
              />
              <Button
                variant="default"
                size="sm"
                className="h-7 text-xs px-2 shrink-0"
                onClick={handleUrlSubmit}
                disabled={!customLogoUrl.trim()}
              >
                Set
              </Button>
            </div>
          )}
        </div>
      )}

      {/* Placement selector */}
      <div className="space-y-1.5">
        <label className="text-xs text-muted-foreground">Position</label>
        <Select value={placement} onValueChange={(v) => setPlacement(v as Placement)}>
          <SelectTrigger className="h-7 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="bottom-right">Bottom Right</SelectItem>
            <SelectItem value="bottom-left">Bottom Left</SelectItem>
            <SelectItem value="top-right">Top Right</SelectItem>
            <SelectItem value="top-left">Top Left</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Scale slider */}
      <div className="space-y-1.5">
        <div className="flex justify-between">
          <label className="text-xs text-muted-foreground">Size</label>
          <span className="text-xs text-muted-foreground">{scale}%</span>
        </div>
        <Slider
          value={[scale]}
          onValueChange={([v]) => setScale(v)}
          min={5}
          max={40}
          step={1}
          className="w-full"
        />
      </div>

      {/* Action buttons */}
      <div className="flex gap-2">
        <ActionButton
          onClick={handleApply}
          loading={isLoading}
          disabled={!effectiveLogoUrl}
          icon={<Image className="h-3.5 w-3.5" />}
          className={hasLogoApplied ? "flex-1" : ""}
        >
          {hasLogoApplied ? "Re-apply" : "Apply Logo"}
        </ActionButton>

        {hasLogoApplied && (
          <ActionButton
            onClick={handleRemove}
            variant="outline"
            icon={<Undo2 className="h-3.5 w-3.5" />}
            className="flex-1"
          >
            Remove
          </ActionButton>
        )}
      </div>

      {/* Hidden file input */}
      <input
        ref={fileInputRef}
        type="file"
        accept="image/*"
        className="hidden"
        onChange={handleFileUpload}
      />
    </SectionPanel>
  );
}
