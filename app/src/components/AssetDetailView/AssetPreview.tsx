/**
 * ASSET PREVIEW
 * 
 * Left side of the detail view.
 * Premium dark design with glass-embossed overlay for creative brief.
 * Adapts to image size while maintaining elegant layout.
 */

import type { Asset } from "./types";

interface AssetPreviewProps {
  asset: Asset;
}

export function AssetPreview({ asset }: AssetPreviewProps) {
  return (
    <div className="relative bg-neutral-950 flex flex-col min-w-[400px]">
      {/* Asset Display - Adapts to image size */}
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
            className="max-w-full max-h-[60vh] object-contain rounded-lg shadow-2xl"
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
    </div>
  );
}
