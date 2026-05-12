/**
 * VARIATIONS TAB
 * 
 * Full-space grid view showing:
 * 1. The original asset (labeled "Original")
 * 2. Edit history items (from Refine Creative edits)
 * 3. Generated variations (from Create Variations)
 * 
 * Each card has hover actions: "Use This" + Download.
 */

import { Button } from "@/components/ui/button";
import { Download, Check, Loader2, AlertCircle } from "lucide-react";
import { toast } from "sonner";
import { cn } from "@/lib/utils";
import type { VariationResult, EditHistoryItem } from "./types";

interface VariationsTabProps {
  results: VariationResult[];
  /** Original image URL captured at mount — never changes */
  originalUrl: string;
  /** Original model name for display */
  originalModel: string;
  /** Edit history — accumulated refine results shown after original */
  editHistory: EditHistoryItem[];
  onUseVariation: (url: string, model: string) => void;
}

export function VariationsTab({
  results,
  originalUrl,
  originalModel,
  editHistory,
  onUseVariation,
}: VariationsTabProps) {
  const handleDownload = (url: string, label: string) => {
    const link = document.createElement("a");
    link.href = url;
    link.download = `${label}-${Date.now()}.png`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    toast.success("Download started");
  };

  // Group variation results by model
  const grouped = results.reduce<Record<string, VariationResult[]>>((acc, r) => {
    const key = r.modelId;
    if (!acc[key]) acc[key] = [];
    acc[key].push(r);
    return acc;
  }, {});

  const hasEditHistory = editHistory.length > 0;
  const hasVariations = results.length > 0;
  const hasContent = hasEditHistory || hasVariations;

  if (!hasContent) {
    return (
      <div className="flex items-center justify-center h-full text-neutral-400">
        <div className="text-center">
          <p className="text-sm">No edits or variations yet</p>
          <p className="text-xs mt-1">Switch to Detail tab to refine or generate</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 overflow-auto max-h-[70vh]">
      <div className="space-y-6">
        {/* Edit History Section — Original + edits side by side */}
        {hasEditHistory && (
          <div>
            <div className="flex items-center gap-2 mb-3">
              <h4 className="text-sm font-medium text-neutral-900">
                Edit History
              </h4>
              <span className="text-xs text-neutral-500">
                {editHistory.length} edit{editHistory.length !== 1 ? "s" : ""}
              </span>
            </div>

            <div className="grid grid-cols-3 gap-3">
              {/* Original card — always first, uses immutable ref URL */}
              <GridCard
                url={originalUrl}
                label="Original"
                sublabel={originalModel}
                onDownload={() => handleDownload(originalUrl, "original")}
                isOriginal
              />

              {/* Edit history cards */}
              {editHistory.map((edit, index) => (
                <GridCard
                  key={edit.id}
                  url={edit.url}
                  label={`Edit ${index + 1}`}
                  sublabel={edit.modelName}
                  onUse={() => onUseVariation(edit.url, edit.modelName)}
                  onDownload={() => handleDownload(edit.url, `edit-${index + 1}`)}
                />
              ))}
            </div>
          </div>
        )}

        {/* Variation Results Section — grouped by model */}
        {Object.entries(grouped).map(([modelId, items]) => (
          <div key={modelId}>
            <div className="flex items-center gap-2 mb-3">
              <h4 className="text-sm font-medium text-neutral-900">
                {items[0].modelName}
              </h4>
              <span className="text-xs text-neutral-500">
                {items[0].provider} · {items.filter(i => i.status === "success").length} of {items.length}
              </span>
            </div>

            <div className="grid grid-cols-3 gap-3">
              {items.map((result) => (
                <VariationCard
                  key={result.id}
                  result={result}
                  onUse={() => result.imageUrl && onUseVariation(result.imageUrl, result.modelId)}
                  onDownload={() => result.imageUrl && handleDownload(result.imageUrl, `variation-${result.modelName}`)}
                />
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Grid Card (for Original + Edit History) ─────────────────────────────────

function GridCard({
  url,
  label,
  sublabel,
  onUse,
  onDownload,
  isOriginal = false,
}: {
  url: string;
  label: string;
  sublabel?: string;
  onUse?: () => void;
  onDownload: () => void;
  isOriginal?: boolean;
}) {
  return (
    <div className="relative group rounded-xl border overflow-hidden bg-white">
      {/* Image */}
      <div className="aspect-square bg-neutral-100 relative">
        <img
          src={url}
          alt={label}
          className="w-full h-full object-cover"
        />
        {/* Original badge */}
        {isOriginal && (
          <div className="absolute top-2 left-2 px-2 py-0.5 rounded-full bg-black/60 backdrop-blur-sm text-white text-[10px] font-medium">
            Original
          </div>
        )}
      </div>

      {/* Label below */}
      <div className="px-2.5 py-2 border-t border-neutral-100">
        <p className="text-xs font-medium text-neutral-800 truncate">{label}</p>
        {sublabel && (
          <p className="text-[10px] text-neutral-500 truncate">{sublabel}</p>
        )}
      </div>

      {/* Hover overlay with actions */}
      <div className="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors flex items-end justify-center opacity-0 group-hover:opacity-100 p-3">
        <div className="flex gap-2 w-full">
          {onUse && (
            <Button
              size="sm"
              onClick={onUse}
              className="flex-1 h-8 text-xs bg-white text-neutral-900 hover:bg-neutral-100"
            >
              <Check className="h-3 w-3 mr-1" />
              Use This
            </Button>
          )}
          <Button
            size="sm"
            variant="ghost"
            onClick={onDownload}
            className={cn(
              "h-8 text-white hover:bg-white/20",
              onUse ? "w-8 p-0" : "flex-1 text-xs"
            )}
          >
            <Download className="h-3.5 w-3.5" />
            {!onUse && <span className="ml-1">Download</span>}
          </Button>
        </div>
      </div>
    </div>
  );
}

// ─── Variation Card (for Create Variations results) ──────────────────────────

function VariationCard({
  result,
  onUse,
  onDownload,
}: {
  result: VariationResult;
  onUse: () => void;
  onDownload: () => void;
}) {
  return (
    <div
      className={cn(
        "relative group rounded-xl border overflow-hidden bg-white",
        result.status === "error" && "border-red-200 bg-red-50"
      )}
    >
      {/* Image area */}
      <div className="aspect-square bg-neutral-100 relative">
        {result.status === "loading" ? (
          <div className="absolute inset-0 flex items-center justify-center">
            <Loader2 className="h-6 w-6 animate-spin text-neutral-400" />
          </div>
        ) : result.status === "error" ? (
          <div className="absolute inset-0 flex flex-col items-center justify-center p-3">
            <AlertCircle className="h-5 w-5 text-red-400 mb-1" />
            <p className="text-[11px] text-red-500 text-center line-clamp-2">
              {result.error || "Failed"}
            </p>
          </div>
        ) : result.imageUrl ? (
          <img
            src={result.imageUrl}
            alt={`Variation by ${result.modelName}`}
            className="w-full h-full object-cover"
          />
        ) : null}
      </div>

      {/* Hover overlay with actions */}
      {result.status === "success" && result.imageUrl && (
        <div className="absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors flex items-end justify-center opacity-0 group-hover:opacity-100 p-3">
          <div className="flex gap-2 w-full">
            <Button
              size="sm"
              onClick={onUse}
              className="flex-1 h-8 text-xs bg-white text-neutral-900 hover:bg-neutral-100"
            >
              <Check className="h-3 w-3 mr-1" />
              Use This
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={onDownload}
              className="h-8 w-8 p-0 text-white hover:bg-white/20"
            >
              <Download className="h-3.5 w-3.5" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
