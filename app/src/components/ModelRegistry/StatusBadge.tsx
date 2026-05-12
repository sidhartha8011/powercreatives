/**
 * STATUS BADGE
 * 
 * Purpose: Clickable badge showing model configuration status
 * Click to confirm AI-suggested or auto-detected settings
 */

import { Badge } from "@/components/ui/badge";
import { Check, Sparkles, Zap } from "lucide-react";
import type { ModelStatus } from "@shared/types/models";
import { MODEL_STATUS_LABELS } from "@shared/types/models";
import { STATUS_COLORS, type StatusBadgeProps } from "./types";

export function StatusBadge({ status, onClick }: StatusBadgeProps) {
  const colors = STATUS_COLORS[status];
  // Use shorter labels for compact display
  const shortLabels: Record<ModelStatus, string> = {
    auto: "Auto",
    ai_suggested: "AI",
    confirmed: "OK",
  };
  const label = shortLabels[status];
  
  // Icon based on status
  const Icon = status === "confirmed" 
    ? Check 
    : status === "ai_suggested" 
      ? Sparkles 
      : Zap;
  
  // Only clickable if not already confirmed
  const isClickable = status !== "confirmed" && onClick;
  
  return (
    <Badge
      variant="outline"
      className={`
        ${colors.bg} ${colors.text} ${colors.border}
        ${isClickable ? "cursor-pointer hover:opacity-80 transition-opacity" : ""}
        text-[10px] px-1.5 py-0.5 gap-0.5 whitespace-nowrap
      `}
      onClick={isClickable ? onClick : undefined}
      title={isClickable ? "Click to confirm" : "Configuration confirmed"}
    >
      <Icon className="w-3 h-3" />
      {label}
    </Badge>
  );
}
