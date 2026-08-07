/**
 * TIER SELECTOR
 * 
 * Purpose: Dropdown to select cost tier for a model
 */

import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { CostTier } from "@shared/types/models";
import { ALL_COST_TIERS, COST_TIER_LABELS } from "@shared/types/models";
import { TIER_COLORS, type TierSelectorProps } from "./types";

export function TierSelector({ tier, onChange, disabled }: TierSelectorProps) {
  const colors = TIER_COLORS[tier];
  
  return (
    <Select
      value={tier}
      onValueChange={(value) => onChange(value as CostTier)}
      disabled={disabled}
    >
      <SelectTrigger className={`w-full h-7 text-[10px] ${colors.bg} ${colors.text}`}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {ALL_COST_TIERS.map((t) => (
          <SelectItem key={t} value={t}>
            {COST_TIER_LABELS[t]}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
