import { useMemo } from 'react';
import { ChevronDown, Info } from 'lucide-react';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuCheckboxItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger, TooltipProvider } from '@/components/ui/tooltip';

import { useTextModels } from '@/modules/Copy/useTextModels';
import { useImageModelsForGeneration, useVideoModelsForGeneration, TIER_CONFIG } from '@/hooks/useModelsForGeneration';
import type { CostTier } from '@/types';

export type EngineType = 'text' | 'image' | 'video';

export interface GlobalEngineSelectorProps {
  /** The type of engine to select */
  type: EngineType;
  /** Custom title for the selector */
  title?: string;
  /** Array of currently selected model IDs */
  selectedIds: string[];
  /** Callback when selection changes. Passes the new array of IDs. */
  onChange: (ids: string[]) => void;
  /** If true, multiple models can be selected. If false, selecting one deselects others. */
  multiSelect?: boolean;
  /** If true, disables image models that do not support image inputs */
  requiresImageInput?: boolean;
}

const TIER_ORDER: CostTier[] = ['premium', 'standard', 'budget'];

const TIER_ICONS: Record<string, string> = {
  budget: '$',
  standard: '$$',
  premium: '$$$',
};

export function GlobalEngineSelector({
  type,
  title,
  selectedIds,
  onChange,
  multiSelect = false,
  requiresImageInput = false,
}: GlobalEngineSelectorProps) {
  // Fetch from all sources unconditionally to obey hook rules
  const textQuery = useTextModels();
  const imageQuery = useImageModelsForGeneration();
  const videoQuery = useVideoModelsForGeneration();

  // Unify the data into a common format based on type
  const unifiedGroups = useMemo(() => {
    const groups: { tier: string; label: string; icon: string; models: { id: string; name: string; provider: string; imageInputMode?: string | null }[] }[] = [];

    if (type === 'text') {
      textQuery.groups.forEach(g => {
        groups.push({
          tier: g.tier,
          label: g.label,
          icon: TIER_ICONS[g.tier] || '$',
          models: g.models.map(m => ({ id: m.id, name: m.name, provider: m.provider }))
        });
      });
    } else if (type === 'image') {
      TIER_ORDER.forEach(tier => {
        const models = imageQuery.imageModelsByTier[tier as CostTier];
        if (models && models.length > 0) {
          groups.push({
            tier,
            label: TIER_CONFIG[tier as CostTier].label,
            icon: TIER_CONFIG[tier as CostTier].icon,
            models: models.map(m => ({ id: m.id, name: m.name, provider: m.provider, imageInputMode: m.imageInputMode }))
          });
        }
      });
    } else if (type === 'video') {
      TIER_ORDER.forEach(tier => {
        const models = videoQuery.videoModelsByTier[tier as CostTier];
        if (models && models.length > 0) {
          groups.push({
            tier,
            label: TIER_CONFIG[tier as CostTier].label,
            icon: TIER_CONFIG[tier as CostTier].icon,
            models: models.map(m => ({ id: m.id, name: m.name, provider: m.provider }))
          });
        }
      });
    }

    return groups;
  }, [type, textQuery.groups, imageQuery.imageModelsByTier, videoQuery.videoModelsByTier]);

  const isLoading = type === 'text' ? textQuery.isLoading : (type === 'image' ? imageQuery.isLoading : videoQuery.isLoading);

  const handleToggle = (modelId: string) => {
    if (selectedIds.includes(modelId)) {
      // Remove it
      if (multiSelect) {
        onChange(selectedIds.filter(id => id !== modelId));
      } else {
        // If single select, we might allow unchecking to empty, or enforce 1
        onChange([]); 
      }
    } else {
      // Add it
      if (multiSelect) {
        onChange([...selectedIds, modelId]);
      } else {
        onChange([modelId]);
      }
    }
  };

  // Get name of selected item if single-select
  let selectedSummary = 'Select engine...';
  if (selectedIds.length === 1) {
    const allModels = unifiedGroups.flatMap(g => g.models);
    const selected = allModels.find(m => m.id === selectedIds[0]);
    if (selected) selectedSummary = selected.name;
  } else if (selectedIds.length > 1) {
    selectedSummary = `${selectedIds.length} engines selected`;
  }

  return (
    <div className={`flex items-center ${title ? 'justify-between py-2 px-1' : ''}`}>
      {/* Label — only shown when explicitly provided to avoid duplicating parent headers */}
      {title && (
        <div>
          <label className="text-sm font-medium text-foreground">{title}</label>
        </div>
      )}
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button 
            disabled={isLoading}
            className={`flex h-9 items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50 ${title ? 'w-[220px]' : 'w-full'}`}
          >
            <span className="truncate text-muted-foreground font-medium">
              {isLoading ? 'Loading...' : selectedSummary}
            </span>
            <ChevronDown className="h-4 w-4 opacity-50 shrink-0" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-[220px] max-h-[400px] overflow-y-auto">
          {unifiedGroups.length === 0 ? (
            <p className="px-2 py-1.5 text-xs text-muted-foreground">No models available</p>
          ) : (
            unifiedGroups.map((group, groupIndex) => (
              <div key={group.tier}>
                <DropdownMenuGroup>
                  <DropdownMenuLabel className="text-xs flex items-center gap-1.5">
                    <span className="text-muted-foreground font-medium">{group.icon}</span>
                    {group.label}
                  </DropdownMenuLabel>
                  {group.models.map(model => {
                    const isChecked = selectedIds.includes(model.id);
                    const isDisabled = type === 'image' && requiresImageInput && model.imageInputMode === null;

                    const itemContent = (
                      <div className="flex items-center justify-between w-full">
                        <div className="flex flex-col gap-0.5">
                          <span className="font-medium text-sm leading-none">{model.name}</span>
                          <span className="text-[10px] text-muted-foreground capitalize leading-none">{model.provider}</span>
                        </div>
                        {isDisabled && (
                          <TooltipProvider>
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <div className="ml-2 flex items-center justify-center cursor-help">
                                  <Info className="w-4 h-4 text-muted-foreground hover:text-foreground transition-colors" />
                                </div>
                              </TooltipTrigger>
                              <TooltipContent side="left" className="max-w-[200px] text-xs z-[100]">
                                Cannot be used with image inputs. Disable Logo and Reference Images to activate.
                              </TooltipContent>
                            </Tooltip>
                          </TooltipProvider>
                        )}
                      </div>
                    );

                    return (
                      <DropdownMenuCheckboxItem
                        key={model.id}
                        checked={isChecked}
                        disabled={isDisabled}
                        onSelect={(e) => {
                          e.preventDefault();
                          if (!isDisabled) handleToggle(model.id);
                        }}
                        className={`py-2 ${isDisabled ? 'opacity-50 grayscale' : 'cursor-pointer'}`}
                      >
                        {itemContent}
                      </DropdownMenuCheckboxItem>
                    );
                  })}
                </DropdownMenuGroup>
                {groupIndex < unifiedGroups.length - 1 && <DropdownMenuSeparator />}
              </div>
            ))
          )}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}
