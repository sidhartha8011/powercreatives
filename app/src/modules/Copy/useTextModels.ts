/**
 * USE TEXT MODELS HOOK
 *
 * Fetches models with canGenerateText capability from the unified models table.
 * Groups them by cost tier for the model selection dropdown.
 *
 * The built-in Manus text model is seeded into the DB on first login
 * (see server/seedManus.ts), so it appears alongside user-added models.
 * No hardcoded fallback needed.
 */

import { useMemo } from 'react';
import { trpc } from '@/lib/trpc';
import type { CostTier } from '@shared/types/models';

export interface TextModel {
  id: string;
  name: string;
  provider: string;
  costTier: CostTier;
  registryId: number;
}

export interface TextModelGroup {
  tier: CostTier;
  label: string;
  models: TextModel[];
}

const TIER_ORDER: CostTier[] = ['budget', 'standard', 'premium'];

const TIER_LABELS: Record<CostTier, string> = {
  budget: 'Budget $',
  standard: 'Standard $$',
  premium: 'Premium $$$',
};

export function useTextModels() {
  const { data: models = [], isLoading } = trpc.models.getForGeneration.useQuery(
    { type: 'text' },
    { staleTime: 30_000 },
  );

  const textModels: TextModel[] = useMemo(() => {
    return models.map((m) => ({
      id: m.modelId,
      name: m.customName || m.originalName,
      provider: m.provider,
      costTier: m.costTier,
      registryId: m.id,
    }));
  }, [models]);

  const groups: TextModelGroup[] = useMemo(() => {
    return TIER_ORDER.map((tier) => ({
      tier,
      label: TIER_LABELS[tier],
      models: textModels.filter((m) => m.costTier === tier),
    })).filter((g) => g.models.length > 0);
  }, [textModels]);

  return {
    textModels,
    groups,
    isLoading,
    hasModels: textModels.length > 0,
    hasRegisteredModels: models.length > 0,
  };
}
