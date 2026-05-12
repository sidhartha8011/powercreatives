/**
 * EDIT RESULTS
 * 
 * Purpose: Display results from parallel image editing across multiple models
 * Features:
 * - Grid layout showing all edit results
 * - Download button per result
 * - Use button to replace current asset
 * - Loading state per model
 * 
 * Used by: AssetDetailView
 */

import { Button } from "@/components/ui/button";
import { Download, Check, Loader2, AlertCircle } from "lucide-react";
import { cn } from "@/lib/utils";

export interface EditResult {
  modelId: string;
  modelName: string;
  provider: string;
  status: "pending" | "loading" | "success" | "error";
  imageUrl?: string | null;
  error?: string;
}

export interface EditResultsProps {
  results: EditResult[];
  onDownload: (result: EditResult) => void;
  onUse: (result: EditResult) => void;
  onClose: () => void;
}

export function EditResults({ results, onDownload, onUse, onClose }: EditResultsProps) {
  const successCount = results.filter((r) => r.status === "success").length;
  const loadingCount = results.filter((r) => r.status === "loading" || r.status === "pending").length;
  const errorCount = results.filter((r) => r.status === "error").length;

  return (
    <div className="space-y-3">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="text-xs font-medium text-foreground">
          Your edits
          {loadingCount > 0 && (
            <span className="text-muted-foreground ml-1">
              ({loadingCount} processing...)
            </span>
          )}
          {loadingCount === 0 && successCount > 0 && (
            <span className="text-muted-foreground ml-1">
              ({successCount} result{successCount > 1 ? "s" : ""})
            </span>
          )}
        </div>
        <Button
          variant="ghost"
          size="sm"
          onClick={onClose}
          className="text-xs h-7"
        >
          Close
        </Button>
      </div>

      {/* Results grid */}
      <div className="grid grid-cols-2 gap-2 max-h-[300px] overflow-y-auto">
        {results.map((result) => (
          <EditResultCard
            key={result.modelId}
            result={result}
            onDownload={() => onDownload(result)}
            onUse={() => onUse(result)}
          />
        ))}
      </div>

      {/* Error summary */}
      {errorCount > 0 && (
        <p className="text-xs text-destructive">
          {errorCount} model{errorCount > 1 ? "s" : ""} failed to process
        </p>
      )}
    </div>
  );
}

interface EditResultCardProps {
  result: EditResult;
  onDownload: () => void;
  onUse: () => void;
}

function EditResultCard({ result, onDownload, onUse }: EditResultCardProps) {
  const { status, imageUrl, modelName, provider, error } = result;

  return (
    <div
      className={cn(
        "relative rounded-lg border overflow-hidden",
        status === "error" && "border-destructive/50 bg-destructive/5"
      )}
    >
      {/* Image or placeholder */}
      <div className="aspect-square bg-muted relative">
        {status === "loading" || status === "pending" ? (
          <div className="absolute inset-0 flex items-center justify-center">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : status === "error" ? (
          <div className="absolute inset-0 flex flex-col items-center justify-center p-2">
            <AlertCircle className="h-5 w-5 text-destructive mb-1" />
            <p className="text-[10px] text-destructive text-center line-clamp-2">
              {error || "Failed"}
            </p>
          </div>
        ) : imageUrl ? (
          <img
            src={imageUrl}
            alt={`Edit by ${modelName}`}
            className="w-full h-full object-cover"
          />
        ) : null}
      </div>

      {/* Model info */}
      <div className="p-2 bg-background">
        <p className="text-xs font-medium truncate">{modelName}</p>
        <p className="text-[10px] text-muted-foreground truncate">{provider}</p>
      </div>

      {/* Action buttons (only show on success) */}
      {status === "success" && imageUrl && (
        <div className="absolute bottom-12 left-0 right-0 p-1.5 flex gap-1 bg-gradient-to-t from-black/60 to-transparent">
          <Button
            variant="secondary"
            size="sm"
            onClick={onDownload}
            className="flex-1 h-7 text-xs"
          >
            <Download className="h-3 w-3 mr-1" />
            Download
          </Button>
          <Button
            variant="default"
            size="sm"
            onClick={onUse}
            className="flex-1 h-7 text-xs"
          >
            <Check className="h-3 w-3 mr-1" />
            Use
          </Button>
        </div>
      )}
    </div>
  );
}
