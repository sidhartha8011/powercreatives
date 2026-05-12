/**
 * MODEL REGISTRY TABLE - WITH BULK SELECTION
 * 
 * Column order: [Checkbox] -> Model -> Tier -> Status -> On -> [6 capability checkboxes] -> Delete
 * 
 * Features:
 * - Individual row checkboxes for selection
 * - Select-all checkbox in header (selects visible/filtered models)
 * - Floating BulkActionBar when models are selected
 * - Search, filter, group-by-provider
 */

import { useState, useMemo, useCallback, useEffect } from "react";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { 
  RefreshCw, 
  Sparkles, 
  Info, 
  Search, 
  CheckCircle2, 
  ChevronDown,
  ChevronRight,
  X
} from "lucide-react";
import { Skeleton } from "@/components/ui/skeleton";
import { ModelRow } from "./ModelRow";
import { BulkActionBar } from "./BulkActionBar";
import { CAPABILITY_COLUMNS, type ModelRegistryTableProps } from "./types";
import type { ModelData, CostTier, ModuleType } from "@shared/types/models";
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from "@/components/ui/tooltip";

interface IntegrationInfo {
  provider: string;
  isActive: boolean;
}

// NOTE: All props are defined in types.ts ModelRegistryTableProps.
// The `integrations` prop is local to this component (not part of the shared contract).
interface LocalTableProps extends ModelRegistryTableProps {
  integrations?: IntegrationInfo[];
}

export function ModelRegistryTable({
  models,
  isLoading = false,
  onToggleCapability,
  onChangeTier,
  onConfirmStatus,
  onToggleEnabled,
  onSyncModels,
  isSyncing = false,
  onAutoDetect,
  isAutoDetecting = false,
  onUpdateName,
  onConfirmAllSuggested,
  onDeleteModel,
  isConfirmingAll = false,
  integrations = [],
  onToggleModule,
  onBulkEnable,
  onBulkDisable,
  onBulkDelete,
  onBulkChangeTier,
  onBulkToggleModule,
  isBulkProcessing = false,
  onClearSelectionRef,
}: LocalTableProps) {
  // Build provider -> isActive lookup
  const providerActiveMap = useMemo(() => {
    const map: Record<string, boolean> = {};
    for (const integ of integrations) {
      if (map[integ.provider] === undefined || integ.isActive) {
        map[integ.provider] = integ.isActive;
      }
    }
    return map;
  }, [integrations]);

  // Search and filter state
  const [searchQuery, setSearchQuery] = useState("");
  const [providerFilter, setProviderFilter] = useState<string>("all");
  const [capabilityFilter, setCapabilityFilter] = useState<string>("all");
  const [tierFilter, setTierFilter] = useState<string>("all");
  
  // Provider grouping state
  const [groupByProvider, setGroupByProvider] = useState(false);
  const [collapsedProviders, setCollapsedProviders] = useState<Set<string>>(new Set());
  
  // Delete confirmation state (single model)
  const [deleteConfirmId, setDeleteConfirmId] = useState<number | null>(null);
  const modelToDelete = models.find(m => m.id === deleteConfirmId);

  // ============================================
  // BULK SELECTION STATE
  // ============================================
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

  // Determine if bulk actions are available
  const hasBulkActions = !!(onBulkEnable && onBulkDisable && onBulkDelete && onBulkChangeTier && onBulkToggleModule);

  // Get unique providers for filter dropdown
  const providers = useMemo(() => {
    const unique = Array.from(new Set(models.map(m => m.provider)));
    return unique.sort();
  }, [models]);

  // Filter models based on search and filters
  const filteredModels = useMemo(() => {
    return models.filter(model => {
      const searchLower = searchQuery.toLowerCase();
      const matchesSearch = !searchQuery || 
        model.originalName.toLowerCase().includes(searchLower) ||
        (model.customName && model.customName.toLowerCase().includes(searchLower)) ||
        model.provider.toLowerCase().includes(searchLower) ||
        model.modelId.toLowerCase().includes(searchLower);
      
      const matchesProvider = providerFilter === "all" || model.provider === providerFilter;
      
      const matchesCapability = capabilityFilter === "all" || (() => {
        switch (capabilityFilter) {
          case "can_generate_image": return model.canGenerateImage;
          case "can_edit_image": return model.canEditImage;
          case "can_generate_video": return model.canGenerateVideo;
          case "can_edit_video": return model.canEditVideo;
          case "can_generate_text": return model.canGenerateText;
          case "can_vision": return model.canVision;
          default: return true;
        }
      })();
      
      const matchesTier = tierFilter === "all" || model.costTier === tierFilter;
      
      return matchesSearch && matchesProvider && matchesCapability && matchesTier;
    });
  }, [models, searchQuery, providerFilter, capabilityFilter, tierFilter]);

  // Clean up selection when models change (remove IDs that no longer exist)
  const filteredIds = useMemo(() => new Set(filteredModels.map(m => m.id)), [filteredModels]);
  
  // Only count selected items that are currently visible
  const visibleSelectedIds = useMemo(() => {
    const visible = new Set<number>();
    Array.from(selectedIds).forEach(id => {
      if (filteredIds.has(id)) visible.add(id);
    });
    return visible;
  }, [selectedIds, filteredIds]);

  // Select-all state for the header checkbox
  const allVisibleSelected = filteredModels.length > 0 && visibleSelectedIds.size === filteredModels.length;
  const someVisibleSelected = visibleSelectedIds.size > 0 && visibleSelectedIds.size < filteredModels.length;

  // Group models by provider
  const modelsByProvider = useMemo(() => {
    const grouped: Record<string, ModelData[]> = {};
    filteredModels.forEach(model => {
      if (!grouped[model.provider]) {
        grouped[model.provider] = [];
      }
      grouped[model.provider].push(model);
    });
    return grouped;
  }, [filteredModels]);

  // Count AI-suggested models
  const aiSuggestedCount = useMemo(() => 
    models.filter(m => m.status === "ai_suggested").length
  , [models]);

  const toggleProviderCollapse = useCallback((provider: string) => {
    setCollapsedProviders(prev => {
      const next = new Set(prev);
      if (next.has(provider)) {
        next.delete(provider);
      } else {
        next.add(provider);
      }
      return next;
    });
  }, []);

  const clearFilters = useCallback(() => {
    setSearchQuery("");
    setProviderFilter("all");
    setCapabilityFilter("all");
    setTierFilter("all");
  }, []);

  const hasActiveFilters = searchQuery || providerFilter !== "all" || capabilityFilter !== "all" || tierFilter !== "all";

  // ============================================
  // SELECTION HANDLERS
  // ============================================
  const handleSelectAll = useCallback((checked: boolean) => {
    if (checked) {
      setSelectedIds(new Set(filteredModels.map(m => m.id)));
    } else {
      setSelectedIds(new Set());
    }
  }, [filteredModels]);

  const handleSelectOne = useCallback((modelId: number, selected: boolean) => {
    setSelectedIds(prev => {
      const next = new Set(prev);
      if (selected) {
        next.add(modelId);
      } else {
        next.delete(modelId);
      }
      return next;
    });
  }, []);

  const clearSelection = useCallback(() => {
    setSelectedIds(new Set());
  }, []);

  // Expose clearSelection to parent via ref (for hook-driven clear on bulk success)
  useEffect(() => {
    if (onClearSelectionRef) {
      onClearSelectionRef.current = clearSelection;
    }
    return () => {
      if (onClearSelectionRef) {
        onClearSelectionRef.current = null;
      }
    };
  }, [onClearSelectionRef, clearSelection]);

  // ============================================
  // BULK ACTION HANDLERS
  // ============================================
  const selectedIdsArray = useMemo(() => Array.from(visibleSelectedIds), [visibleSelectedIds]);

  // NOTE: clearSelection is NOT called here. The hook's onSuccess callback
  // handles clearing selection after the mutation succeeds. This ensures
  // the user can retry if the mutation fails without re-selecting models.
  const handleBulkEnable = useCallback(() => {
    onBulkEnable?.(selectedIdsArray);
  }, [onBulkEnable, selectedIdsArray]);

  const handleBulkDisable = useCallback(() => {
    onBulkDisable?.(selectedIdsArray);
  }, [onBulkDisable, selectedIdsArray]);

  const handleBulkDelete = useCallback(() => {
    onBulkDelete?.(selectedIdsArray);
  }, [onBulkDelete, selectedIdsArray]);

  const handleBulkChangeTier = useCallback((tier: CostTier) => {
    onBulkChangeTier?.(selectedIdsArray, tier);
  }, [onBulkChangeTier, selectedIdsArray]);

  const handleBulkToggleModule = useCallback((module: ModuleType, enabled: boolean) => {
    onBulkToggleModule?.(selectedIdsArray, module, enabled);
  }, [onBulkToggleModule, selectedIdsArray]);

  // ============================================
  // RENDER HELPERS
  // ============================================
  const renderTableHeaders = () => (
    <TableRow className="bg-muted/50">
      {hasBulkActions && (
        <TableHead style={{width:'3%'}} className="text-center">
          <Checkbox
            checked={allVisibleSelected}
            // Use indeterminate-like styling via data attribute
            {...(someVisibleSelected ? { "data-state": "indeterminate" } : {})}
            onCheckedChange={(checked) => handleSelectAll(checked === true)}
            className="mx-auto"
          />
        </TableHead>
      )}
      <TableHead style={{width: hasBulkActions ? '16%' : '18%'}}>Model</TableHead>
      <TableHead style={{width:'8%'}}>Tier</TableHead>
      <TableHead style={{width:'9%'}}>Status</TableHead>
      <TableHead className="text-center" style={{width:'5%'}}>On</TableHead>
      {CAPABILITY_COLUMNS.map((col) => (
        <TableHead key={col.key} className="text-center" style={{width:'7%'}}>
          <Tooltip>
            <TooltipTrigger asChild>
              <span className="text-xs cursor-help">{col.shortLabel}</span>
            </TooltipTrigger>
            <TooltipContent>
              <p className="text-xs">{col.label}</p>
            </TooltipContent>
          </Tooltip>
        </TableHead>
      ))}
      <TableHead style={{width:'2%'}}></TableHead>
    </TableRow>
  );

  const renderModelRow = (model: ModelData) => (
    <ModelRow
      key={model.id}
      model={model}
      onToggleCapability={(capability, enabled) =>
        onToggleCapability(model.id, capability, enabled)
      }
      onChangeTier={(tier) => onChangeTier(model.id, tier)}
      onConfirmStatus={() => onConfirmStatus(model.id)}
      onToggleEnabled={(enabled) => onToggleEnabled(model.id, enabled)}
      onUpdateName={onUpdateName ? (name) => onUpdateName(model.id, name) : undefined}
      onDelete={onDeleteModel ? () => setDeleteConfirmId(model.id) : undefined}
      isIntegrationActive={providerActiveMap[model.provider] ?? false}
      onToggleModule={onToggleModule ? (module, enabled) => onToggleModule(model.id, module, enabled) : undefined}
      isSelected={selectedIds.has(model.id)}
      onSelect={hasBulkActions ? (selected) => handleSelectOne(model.id, selected) : undefined}
    />
  );

  if (isLoading) {
    return <ModelRegistryTableSkeleton />;
  }

  return (
    <div className="space-y-4 w-full">
      {/* Header with action buttons */}
      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-medium">Model Registry</h3>
          <Tooltip>
            <TooltipTrigger asChild>
              <Info className="w-4 h-4 text-muted-foreground cursor-help" />
            </TooltipTrigger>
            <TooltipContent className="max-w-xs">
              <p className="text-xs">
                Manage all your AI models in one place. Configure capabilities, 
                pricing tiers, and enable/disable models.
              </p>
            </TooltipContent>
          </Tooltip>
        </div>
        
        <div className="flex items-center gap-2">
          {aiSuggestedCount > 0 && onConfirmAllSuggested && (
            <Button
              variant="outline"
              size="sm"
              onClick={onConfirmAllSuggested}
              disabled={isConfirmingAll}
              className="text-green-600 border-green-200 hover:bg-green-50"
            >
              <CheckCircle2 className={`w-4 h-4 mr-2 ${isConfirmingAll ? "animate-pulse" : ""}`} />
              Confirm All ({aiSuggestedCount})
            </Button>
          )}
          
          <Button
            variant="outline"
            size="sm"
            onClick={onAutoDetect}
            disabled={isAutoDetecting || models.length === 0}
          >
            <Sparkles className={`w-4 h-4 mr-2 ${isAutoDetecting ? "animate-pulse" : ""}`} />
            Auto-Detect
          </Button>
          <Button
            variant="outline"
            size="sm"
            onClick={onSyncModels}
            disabled={isSyncing}
          >
            <RefreshCw className={`w-4 h-4 mr-2 ${isSyncing ? "animate-spin" : ""}`} />
            Sync Models
          </Button>
        </div>
      </div>

      {/* Search and Filters */}
      {models.length > 0 && (
        <div className="flex flex-wrap items-center gap-3">
          <div className="relative flex-1 min-w-[200px] max-w-[300px]">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
            <Input
              placeholder="Search models..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-9 h-9"
            />
          </div>
          
          <Select value={providerFilter} onValueChange={setProviderFilter}>
            <SelectTrigger className="w-[140px] h-9">
              <SelectValue placeholder="Provider" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Providers</SelectItem>
              {providers.map(provider => (
                <SelectItem key={provider} value={provider}>
                  {provider}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          
          <Select value={capabilityFilter} onValueChange={setCapabilityFilter}>
            <SelectTrigger className="w-[150px] h-9">
              <SelectValue placeholder="Capability" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Capabilities</SelectItem>
              {CAPABILITY_COLUMNS.map(col => (
                <SelectItem key={col.key} value={col.key}>
                  {col.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          
          <Select value={tierFilter} onValueChange={setTierFilter}>
            <SelectTrigger className="w-[120px] h-9">
              <SelectValue placeholder="Tier" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Tiers</SelectItem>
              <SelectItem value="budget">Budget</SelectItem>
              <SelectItem value="standard">Standard</SelectItem>
              <SelectItem value="premium">Premium</SelectItem>
            </SelectContent>
          </Select>
          
          {hasActiveFilters && (
            <Button
              variant="ghost"
              size="sm"
              onClick={clearFilters}
              className="h-9 px-2 text-muted-foreground"
            >
              <X className="w-4 h-4 mr-1" />
              Clear
            </Button>
          )}
          
          <div className="flex items-center gap-2 ml-auto">
            <Checkbox
              id="group-by-provider"
              checked={groupByProvider}
              onCheckedChange={(checked) => setGroupByProvider(checked === true)}
            />
            <label htmlFor="group-by-provider" className="text-xs text-muted-foreground cursor-pointer">
              Group by provider
            </label>
          </div>
        </div>
      )}

      {/* Results count */}
      {models.length > 0 && hasActiveFilters && (
        <p className="text-xs text-muted-foreground">
          Showing {filteredModels.length} of {models.length} models
        </p>
      )}

      {/* Table */}
      {models.length === 0 ? (
        <EmptyState onSync={onSyncModels} isSyncing={isSyncing} />
      ) : filteredModels.length === 0 ? (
        <div className="border rounded-lg p-8 text-center">
          <p className="text-sm text-muted-foreground mb-2">No models match your filters</p>
          <Button variant="outline" size="sm" onClick={clearFilters}>
            Clear Filters
          </Button>
        </div>
      ) : groupByProvider ? (
        <div className="space-y-3">
          {Object.entries(modelsByProvider).map(([provider, providerModels]) => (
            <div key={provider} className="border rounded-lg overflow-hidden">
              <button
                onClick={() => toggleProviderCollapse(provider)}
                className="w-full flex items-center justify-between px-4 py-2 bg-muted/50 hover:bg-muted/70 transition-colors"
              >
                <div className="flex items-center gap-2">
                  {collapsedProviders.has(provider) ? (
                    <ChevronRight className="w-4 h-4 text-muted-foreground" />
                  ) : (
                    <ChevronDown className="w-4 h-4 text-muted-foreground" />
                  )}
                  <span className="text-sm font-medium capitalize">{provider}</span>
                </div>
                <span className="text-xs text-muted-foreground">
                  {providerModels.length} models
                </span>
              </button>
              
              {!collapsedProviders.has(provider) && (
                <div>
                  <Table className="w-full table-fixed">
                    <TableHeader>
                      {renderTableHeaders()}
                    </TableHeader>
                    <TableBody>
                      {providerModels.map(renderModelRow)}
                    </TableBody>
                  </Table>
                </div>
              )}
            </div>
          ))}
        </div>
      ) : (
        <div className="border rounded-lg w-full overflow-hidden">
          <Table className="w-full table-fixed">
            <TableHeader>
              {renderTableHeaders()}
            </TableHeader>
            <TableBody>
              {filteredModels.map(renderModelRow)}
            </TableBody>
          </Table>
        </div>
      )}
      
      {/* Legend */}
      {models.length > 0 && (
        <div className="flex items-center gap-4 text-xs text-muted-foreground">
          <span className="flex items-center gap-1">
            <span className="w-2 h-2 rounded-full bg-green-400" />
            Connected
          </span>
          <span className="flex items-center gap-1">
            <span className="w-2 h-2 rounded-full bg-red-400" />
            Disconnected
          </span>
        </div>
      )}

      {/* Bulk Action Bar (floating) */}
      {hasBulkActions && (
        <BulkActionBar
          selectedCount={visibleSelectedIds.size}
          totalCount={filteredModels.length}
          onClearSelection={clearSelection}
          onBulkEnable={handleBulkEnable}
          onBulkDisable={handleBulkDisable}
          onBulkDelete={handleBulkDelete}
          onBulkChangeTier={handleBulkChangeTier}
          onBulkToggleModule={handleBulkToggleModule}
          isProcessing={isBulkProcessing}
        />
      )}

      {/* Delete Confirmation Dialog (single model) */}
      <AlertDialog open={deleteConfirmId !== null} onOpenChange={(open) => !open && setDeleteConfirmId(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Model</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{modelToDelete?.customName || modelToDelete?.originalName}"? 
              This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => {
                if (deleteConfirmId && onDeleteModel) {
                  onDeleteModel(deleteConfirmId);
                  setDeleteConfirmId(null);
                }
              }}
              className="bg-red-600 hover:bg-red-700"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

function ModelRegistryTableSkeleton() {
  return (
    <div className="space-y-4 w-full">
      <div className="flex items-center justify-between">
        <Skeleton className="h-5 w-32" />
        <div className="flex gap-2">
          <Skeleton className="h-9 w-28" />
          <Skeleton className="h-9 w-28" />
        </div>
      </div>
      <div className="flex gap-3">
        <Skeleton className="h-9 w-[300px]" />
        <Skeleton className="h-9 w-[140px]" />
        <Skeleton className="h-9 w-[150px]" />
        <Skeleton className="h-9 w-[120px]" />
      </div>
      <div className="border rounded-lg w-full">
        <Table className="w-full table-fixed">
          <TableHeader>
            <TableRow className="bg-muted/50">
              <TableHead style={{width:'3%'}}></TableHead>
              <TableHead style={{width:'16%'}}>Model</TableHead>
              <TableHead style={{width:'8%'}}>Tier</TableHead>
              <TableHead style={{width:'9%'}}>Status</TableHead>
              <TableHead className="text-center" style={{width:'5%'}}>On</TableHead>
              {CAPABILITY_COLUMNS.map((col) => (
                <TableHead key={col.key} className="text-center" style={{width:'7%'}}>
                  <span className="text-xs">{col.shortLabel}</span>
                </TableHead>
              ))}
              <TableHead style={{width:'2%'}}></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {[1, 2, 3, 4, 5].map((i) => (
              <TableRow key={i}>
                <TableCell><Skeleton className="h-5 w-5 mx-auto" /></TableCell>
                <TableCell><Skeleton className="h-10 w-full" /></TableCell>
                <TableCell><Skeleton className="h-7 w-24" /></TableCell>
                <TableCell><Skeleton className="h-6 w-20" /></TableCell>
                <TableCell className="text-center"><Skeleton className="h-5 w-10 mx-auto" /></TableCell>
                {CAPABILITY_COLUMNS.map((col) => (
                  <TableCell key={col.key} className="text-center">
                    <Skeleton className="h-5 w-5 mx-auto" />
                  </TableCell>
                ))}
                <TableCell><Skeleton className="h-8 w-8" /></TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </div>
  );
}

function EmptyState({ onSync, isSyncing }: { onSync: () => void; isSyncing: boolean }) {
  return (
    <div className="border rounded-lg p-8 text-center">
      <div className="mx-auto w-12 h-12 rounded-full bg-muted flex items-center justify-center mb-4">
        <RefreshCw className="w-6 h-6 text-muted-foreground" />
      </div>
      <h3 className="text-sm font-medium mb-1">No models in registry</h3>
      <p className="text-xs text-muted-foreground mb-4">
        Sync models from your integrations to configure their capabilities and pricing.
      </p>
      <Button variant="outline" size="sm" onClick={onSync} disabled={isSyncing}>
        <RefreshCw className={`w-4 h-4 mr-2 ${isSyncing ? "animate-spin" : ""}`} />
        Sync Models
      </Button>
    </div>
  );
}
