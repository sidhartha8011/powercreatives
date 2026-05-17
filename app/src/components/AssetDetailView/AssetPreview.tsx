/**
 * ASSET PREVIEW
 * 
 * Left side of the detail view.
 * Premium dark design with glass-embossed overlay for creative brief.
 * Adapts to image size while maintaining elegant layout.
 * 
 * Click on the image to open a fullscreen (80vh) lightbox overlay.
 */

import { useState, useCallback } from "react";
import { X } from "lucide-react";
import type { Asset } from "./types";

interface AssetPreviewProps {
  asset: Asset;
}

export function AssetPreview({ asset }: AssetPreviewProps) {
  const [isLightboxOpen, setIsLightboxOpen] = useState(false);

  const openLightbox = useCallback(() => setIsLightboxOpen(true), []);
  const closeLightbox = useCallback(() => setIsLightboxOpen(false), []);

  return (
    <div className="relative bg-neutral-950 flex flex-col min-w-[400px]">
      {/* Asset Display - Adapts to image size, clickable for fullscreen */}
      <div className="relative flex items-center justify-center p-6">
        {asset.type === "video" ? (
          <video
            src={asset.url}
            controls
            className="max-w-full max-h-[60vh] object-contain rounded-lg shadow-2xl"
            poster={asset.thumbnailUrl || undefined}
          />
        ) : (
          <img
            src={asset.url}
            alt={asset.prompt}
            className="max-w-full max-h-[60vh] object-contain rounded-lg shadow-2xl cursor-zoom-in transition-transform hover:scale-[1.02]"
            onClick={openLightbox}
            title="Click to enlarge"
          />
        )}
      </div>

      {/* Glass-Embossed Overlay at Bottom */}
      <div className="mx-6 mb-6 backdrop-blur-xl bg-black/50 border border-white/10 rounded-xl shadow-2xl">
        <div className="p-4">
          {/* Creative Brief Text */}
          <p className="text-white text-sm leading-relaxed line-clamp-2">
            {asset.prompt}
          </p>
          
          {/* Model & Date Info */}
          <div className="mt-2 flex items-center gap-3 text-xs text-white/70">
            <span className="px-3 py-1 bg-white/15 rounded-full backdrop-blur-sm font-medium">
              {asset.model}
            </span>
            <span className="text-white/40">•</span>
            <span>{new Date(asset.createdAt).toLocaleDateString()}</span>
          </div>
        </div>
      </div>

      {/* Fullscreen Lightbox Overlay */}
      {isLightboxOpen && asset.type !== "video" && (
        <div
          className="fixed inset-0 z-[9999] flex items-center justify-center bg-black/90 backdrop-blur-sm cursor-zoom-out"
          onClick={closeLightbox}
          role="dialog"
          aria-label="Fullscreen image preview"
        >
          {/* Close button */}
          <button
            onClick={closeLightbox}
            className="absolute top-6 right-6 z-10 h-10 w-10 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-colors border border-white/20"
            aria-label="Close fullscreen"
          >
            <X className="h-5 w-5" />
          </button>

          {/* Full-size image — 80% viewport max */}
          <img
            src={asset.url}
            alt={asset.prompt}
            className="max-w-[90vw] max-h-[90vh] object-contain rounded-lg shadow-2xl"
            onClick={(e) => e.stopPropagation()}
            style={{ cursor: 'default' }}
          />

          {/* Model badge at bottom */}
          <div className="absolute bottom-6 left-1/2 -translate-x-1/2 px-4 py-2 bg-black/60 backdrop-blur-sm rounded-full text-white/80 text-xs border border-white/10">
            {asset.model} • {new Date(asset.createdAt).toLocaleDateString()}
          </div>
        </div>
      )}
    </div>
  );
}
