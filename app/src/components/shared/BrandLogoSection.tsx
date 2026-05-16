/**
 * BRANDS MODULE — Logo Section (Right Panel)
 *
 * Renders the brand logo preview with upload/URL popover.
 * Handles logo upload (file + URL), display, and loading states.
 *
 * Extracted from BrandDialog.tsx for separation of concerns.
 */

import { useState, useRef, useCallback } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Separator } from "@/components/ui/separator";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";
import { Loader2, ImageIcon, Upload, Link, X } from "lucide-react";
import { toast } from "sonner";
import { trpc, apiFetch } from "@/lib/trpc";
import type { BrandAsset } from "@shared/brandTypes";
import type { Brand } from "@/modules/Brands/types";

interface BrandLogoSectionProps {
  /** Brand being edited (null = create mode — logo disabled) */
  editBrand?: Brand | null;
  /** Current logo asset (first asset = logo) */
  logoAsset: BrandAsset | null;
  /** Called after logo is successfully uploaded/fetched (refetch brand) */
  onLogoChanged: () => void;
  /** Called with extracted colors after logo upload/URL fetch */
  onColorsExtracted?: (colors: string[]) => void;
}

export function BrandLogoSection({
  editBrand,
  logoAsset,
  onLogoChanged,
  onColorsExtracted,
}: BrandLogoSectionProps) {
  const [uploadingLogo, setUploadingLogo] = useState(false);
  const [logoPopoverOpen, setLogoPopoverOpen] = useState(false);
  const [logoUrlInput, setLogoUrlInput] = useState("");
  const [fetchingLogoUrl, setFetchingLogoUrl] = useState(false);
  const logoInputRef = useRef<HTMLInputElement>(null);

  const addAssetMutation = trpc.brands.addAsset.useMutation();
  const addAssetFromUrlMutation = trpc.brands.addAssetFromUrl.useMutation();
  const removeAssetMutation = trpc.brands.removeAsset.useMutation();
  const reorderMutation = trpc.brands.reorderAssets.useMutation();

  const handleRemoveLogo = useCallback(async () => {
    if (!editBrand || !logoAsset) return;
    try {
      await removeAssetMutation.mutateAsync({
        brandId: editBrand.id,
        fileKey: logoAsset.fileKey,
      });
      onLogoChanged();
      toast.success("Logo removed");
    } catch {
      toast.error("Failed to remove logo");
    }
  }, [editBrand, logoAsset, removeAssetMutation, onLogoChanged]);

  const isEdit = !!editBrand;

  // ── Upload logo from file ──
  const handleLogoUpload = useCallback(
    async (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (!file || !editBrand) return;

      const ACCEPTED = ["image/jpeg", "image/png", "image/webp", "image/gif"];
      if (!ACCEPTED.includes(file.type)) {
        toast.error("Unsupported format. Use JPEG, PNG, WebP, or GIF.");
        return;
      }
      if (file.size > 10 * 1024 * 1024) {
        toast.error("File too large. Max 10MB.");
        return;
      }

      setUploadingLogo(true);
      try {
        const buffer = await file.arrayBuffer();
        const base64 = btoa(
          new Uint8Array(buffer).reduce(
            (data, byte) => data + String.fromCharCode(byte),
            ""
          )
        );

        const result = await addAssetMutation.mutateAsync({
          brandId: editBrand.id,
          fileData: base64,
          filename: file.name,
          mimeType: file.type,
          role: 'logo',
        });

        // Move the newly added asset to position 0 (logo)
        const refreshed = await apiFetch<any>(`brands/${editBrand.id}`);
        const refreshedAssets: BrandAsset[] = (refreshed as any)?.assets ?? [];
        if (refreshedAssets.length > 1) {
          const newOrder = refreshedAssets.map((a) => a.fileKey);
          const last = newOrder.pop()!;
          newOrder.unshift(last);
          await reorderMutation.mutateAsync({
            brandId: editBrand.id,
            fileKeys: newOrder,
          });
        }

        onLogoChanged();
        toast.success("Logo uploaded");

        // Notify parent about extracted colors
        const extracted = (result as any)?.extractedColors as string[] | undefined;
        if (extracted && extracted.length > 0) {
          onColorsExtracted?.(extracted);
        }
      } catch {
        toast.error("Failed to upload logo");
      } finally {
        setUploadingLogo(false);
        if (logoInputRef.current) logoInputRef.current.value = "";
      }
    },
    [editBrand, addAssetMutation, reorderMutation, onLogoChanged, onColorsExtracted]
  );

  // ── Fetch logo from URL ──
  const handleLogoFromUrl = useCallback(
    async () => {
      const url = logoUrlInput.trim();
      if (!url || !editBrand) return;

      // Basic URL validation
      try {
        new URL(url);
      } catch {
        toast.error("Please enter a valid URL (e.g. https://example.com/logo.png)");
        return;
      }

      setFetchingLogoUrl(true);
      try {
        const result = await addAssetFromUrlMutation.mutateAsync({
          brandId: editBrand.id,
          imageUrl: url,
          role: 'logo',
        });

        // Move the newly added asset to position 0 (logo)
        const refreshed = await apiFetch<any>(`brands/${editBrand.id}`);
        const refreshedAssets: BrandAsset[] = (refreshed as any)?.assets ?? [];
        if (refreshedAssets.length > 1) {
          const newOrder = refreshedAssets.map((a) => a.fileKey);
          const last = newOrder.pop()!;
          newOrder.unshift(last);
          await reorderMutation.mutateAsync({
            brandId: editBrand.id,
            fileKeys: newOrder,
          });
        }

        onLogoChanged();
        setLogoUrlInput("");
        setLogoPopoverOpen(false);
        toast.success("Logo fetched from URL");

        // Notify parent about extracted colors
        const extracted = (result as any)?.extractedColors as string[] | undefined;
        if (extracted && extracted.length > 0) {
          onColorsExtracted?.(extracted);
        }
      } catch (err: any) {
        toast.error(err?.message || "Failed to fetch logo from URL");
      } finally {
        setFetchingLogoUrl(false);
      }
    },
    [editBrand, logoUrlInput, addAssetFromUrlMutation, reorderMutation, onLogoChanged, onColorsExtracted]
  );

  return (
    <div className="space-y-1.5">
      <Label className="text-sm font-medium">Brand Logo</Label>
      <Popover open={logoPopoverOpen} onOpenChange={(open) => {
        if (!isEdit) {
          toast.info("Save the brand first, then add a logo.");
          return;
        }
        setLogoPopoverOpen(open);
        if (!open) setLogoUrlInput("");
      }}>
        <div className="relative inline-block">
          <PopoverTrigger asChild>
            <button
              type="button"
              className={cn(
                "w-16 h-16 rounded-lg overflow-hidden transition-all",
                isEdit
                  ? "cursor-pointer hover:ring-2 hover:ring-primary/50 hover:scale-105"
                  : "cursor-default",
                logoAsset
                  ? "border border-border bg-muted"
                  : "border border-dashed border-border bg-muted/30 flex items-center justify-center"
              )}
              title={isEdit ? "Click to change logo" : "Save the brand first"}
            >
              {(uploadingLogo || fetchingLogoUrl || removeAssetMutation.isPending) ? (
                <div className="w-full h-full flex items-center justify-center">
                  <Loader2 size={20} className="animate-spin text-muted-foreground" />
                </div>
              ) : logoAsset ? (
                <img
                  src={logoAsset.url}
                  alt="Brand logo"
                  className="w-full h-full object-contain"
                />
              ) : (
                <ImageIcon size={20} className="text-muted-foreground" />
              )}
            </button>
          </PopoverTrigger>
          {logoAsset && isEdit && (
            <button
              type="button"
              onClick={handleRemoveLogo}
              disabled={removeAssetMutation.isPending}
              className="absolute -top-2 -right-2 w-5 h-5 bg-destructive text-destructive-foreground rounded-full flex items-center justify-center shadow-sm hover:scale-110 transition-transform z-10 disabled:opacity-50"
              title="Remove Logo"
            >
              <X className="w-3 h-3" />
            </button>
          )}
        </div>
        <PopoverContent align="start" side="right" className="w-72 p-3 space-y-3">
          {/* Option 1: Upload file */}
          <div>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="w-full justify-start gap-2"
              onClick={() => {
                setLogoPopoverOpen(false);
                logoInputRef.current?.click();
              }}
            >
              <Upload size={14} />
              Upload Image
            </Button>
          </div>
          {/* Divider */}
          <div className="flex items-center gap-2">
            <Separator className="flex-1" />
            <span className="text-xs text-muted-foreground">or</span>
            <Separator className="flex-1" />
          </div>
          {/* Option 2: Fetch from URL */}
          <div className="space-y-2">
            <div className="flex gap-1.5">
              <Input
                placeholder="https://example.com/logo.png"
                value={logoUrlInput}
                onChange={(e) => setLogoUrlInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter") {
                    e.preventDefault();
                    handleLogoFromUrl();
                  }
                }}
                className="h-8 text-xs"
                disabled={fetchingLogoUrl}
              />
              <Button
                type="button"
                size="sm"
                className="h-8 shrink-0 gap-1.5"
                onClick={handleLogoFromUrl}
                disabled={!logoUrlInput.trim() || fetchingLogoUrl}
              >
                {fetchingLogoUrl ? (
                  <Loader2 size={12} className="animate-spin" />
                ) : (
                  <Link size={12} />
                )}
                Fetch
              </Button>
            </div>
            <p className="text-[10px] text-muted-foreground">
              Paste a direct image URL to use as logo
            </p>
          </div>
        </PopoverContent>
      </Popover>
      <input
        ref={logoInputRef}
        type="file"
        accept="image/jpeg,image/png,image/webp,image/gif"
        className="sr-only"
        onChange={handleLogoUpload}
      />
      <p className="text-xs text-muted-foreground">
        {isEdit
          ? "Click to upload or fetch from URL."
          : "Save the brand first, then add a logo."}
      </p>
    </div>
  );
}
