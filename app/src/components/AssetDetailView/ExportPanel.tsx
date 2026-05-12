/**
 * EXPORT PANEL
 * 
 * Export buttons for different formats.
 * Supports PNG, JPEG for images; MP4 for videos.
 * PowerKeys-consistent compact design.
 */

import { Download } from "lucide-react";
import { SectionPanel } from "./SectionPanel";
import { ActionButton } from "./ActionButton";
import type { ExportPanelProps, ExportFormat } from "./types";

export function ExportPanel({ asset, isLoading, onExport }: ExportPanelProps) {
  // Determine available formats based on asset type
  const formats: ExportFormat[] = asset.type === "video" 
    ? ["mp4"] 
    : ["png", "jpeg"];

  return (
    <SectionPanel title="Export Creative">
      <div className="flex gap-2">
        {formats.map((format) => (
          <ActionButton
            key={format}
            onClick={() => onExport(format)}
            loading={isLoading}
            variant="outline"
            className="flex-1"
            icon={<Download className="h-3.5 w-3.5" />}
          >
            {format.toUpperCase()}
          </ActionButton>
        ))}
      </div>
    </SectionPanel>
  );
}
