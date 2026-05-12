/**
 * ImageResultsGrid — Results area for the Image module
 *
 * Renders:
 * - Version tabs (horizontal scroll)
 * - Per-model sections with horizontal image scroll
 * - Individual image tiles: processing / failed / complete states
 * - Hover overlay with download button
 * - Empty state when no generation has been run
 *
 * Pure presentational component — all state and callbacks come from props.
 */

import { memo } from 'react';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/shared/EmptyState';
import {
    Sparkles, Loader2, AlertCircle, Download,
} from 'lucide-react';
import type { AdVersion, GeneratedAsset } from '@/types';

// ============================================================================
// Types
// ============================================================================

interface ModelInfo {
    id: string;
    name: string;
    provider: string;
}

interface ImageResultsGridProps {
    adVersions: AdVersion[];
    activeTab: string | null;
    onTabChange: (id: string) => void;
    selectedModels: string[];
    assetsByModel: Record<string, GeneratedAsset[]>;
    selectedAsset: GeneratedAsset | null;
    onAssetClick: (asset: GeneratedAsset) => void;
    onDownload: (url: string, filename: string) => void;
    getModelInfo: (modelId: string) => ModelInfo;
}

// ============================================================================
// Component
// ============================================================================

export const ImageResultsGrid = memo(function ImageResultsGrid({
    adVersions,
    activeTab,
    onTabChange,
    selectedModels,
    assetsByModel,
    selectedAsset,
    onAssetClick,
    onDownload,
    getModelInfo,
}: ImageResultsGridProps) {
    return (
        <main className="flex-1 flex flex-col overflow-hidden">
            {/* Version Tabs */}
            {adVersions.length > 0 && (
                <div className="shrink-0 border-b border-border bg-muted/20">
                    <div className="flex overflow-x-auto">
                        {adVersions.map((version) => (
                            <button
                                key={version.id}
                                onClick={() => onTabChange(version.id)}
                                className={`px-6 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition-colors ${activeTab === version.id
                                        ? 'border-primary text-primary bg-background'
                                        : 'border-transparent text-muted-foreground hover:text-foreground hover:bg-background/50'
                                    }`}
                            >
                                {version.name}
                            </button>
                        ))}
                    </div>
                </div>
            )}

            {/* Results */}
            <div className="flex-1 overflow-y-auto p-6">
                {adVersions.length === 0 ? (
                    <div className="h-full flex items-center justify-center">
                        <EmptyState
                            icon={<Sparkles className="w-12 h-12" />}
                            title="Ready to create"
                            description="Enter a product brief and click 'Launch Production' to generate AI-powered ad creatives"
                        />
                    </div>
                ) : (
                    <div className="space-y-8">
                        {selectedModels.map((modelId) => {
                            const modelInfo = getModelInfo(modelId);
                            const modelAssets = assetsByModel[modelId] ?? [];
                            const completed = modelAssets.filter((a) => a.status === 'complete').length;

                            return (
                                <section key={modelId} className="space-y-3">
                                    {/* Model Header */}
                                    <div className="flex items-center gap-3 pb-2 border-b border-border">
                                        <div>
                                            <h3 className="text-sm font-semibold">{modelInfo.name}</h3>
                                            <p className="text-xs text-muted-foreground capitalize">{modelInfo.provider}</p>
                                        </div>
                                        <div className="ml-auto text-xs text-muted-foreground">
                                            {completed} / {modelAssets.length} generated
                                        </div>
                                    </div>

                                    {/* Horizontal image scroll */}
                                    <div className="flex gap-4 overflow-x-auto pb-2">
                                        {modelAssets.length === 0 ? (
                                            <div className="flex-shrink-0 w-48 h-48 rounded-lg border border-dashed border-border flex items-center justify-center bg-muted/30">
                                                <span className="text-xs text-muted-foreground">No images yet</span>
                                            </div>
                                        ) : (
                                            modelAssets.map((asset) => (
                                                <ImageTile
                                                    key={asset.id}
                                                    asset={asset}
                                                    isSelected={selectedAsset?.id === asset.id}
                                                    onClick={() => onAssetClick(asset)}
                                                    onDownload={onDownload}
                                                />
                                            ))
                                        )}
                                    </div>
                                </section>
                            );
                        })}
                    </div>
                )}
            </div>
        </main>
    );
});

// ============================================================================
// ImageTile — Single image card with state-based rendering
// ============================================================================

interface ImageTileProps {
    asset: GeneratedAsset;
    isSelected: boolean;
    onClick: () => void;
    onDownload: (url: string, filename: string) => void;
}

const ImageTile = memo(function ImageTile({ asset, isSelected, onClick, onDownload }: ImageTileProps) {
    return (
        <div
            className={`flex-shrink-0 relative w-48 h-48 rounded-lg overflow-hidden border transition-all cursor-pointer ${isSelected
                    ? 'border-primary ring-2 ring-primary/20'
                    : 'border-border hover:border-primary/50'
                }`}
            onClick={onClick}
        >
            {asset.status === 'processing' && (
                <div className="absolute inset-0 flex flex-col items-center justify-center bg-muted">
                    <Loader2 className="w-8 h-8 text-primary animate-spin mb-2" />
                    <span className="text-xs text-muted-foreground">Generating...</span>
                </div>
            )}

            {asset.status === 'failed' && (
                <div className="absolute inset-0 flex flex-col items-center justify-center bg-muted p-2">
                    <AlertCircle className="w-6 h-6 text-destructive mb-1" />
                    <span className="text-xs text-destructive font-medium">Failed</span>
                    {asset.errorMessage && (
                        <p
                            className="text-[10px] text-muted-foreground text-center mt-1 line-clamp-3 px-1"
                            title={asset.errorMessage}
                        >
                            {asset.errorMessage.length > 80 ? asset.errorMessage.slice(0, 80) + '…' : asset.errorMessage}
                        </p>
                    )}
                </div>
            )}

            {asset.status === 'complete' && asset.url && (
                <>
                    <img src={asset.url} alt={asset.prompt} className="w-full h-full object-cover" />

                    {/* Download hover overlay */}
                    <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent opacity-0 hover:opacity-100 transition-opacity pointer-events-none">
                        <div className="absolute bottom-0 left-0 right-0 p-3 pointer-events-auto">
                            <Button
                                size="sm"
                                variant="secondary"
                                className="h-7 text-xs gap-1 w-full"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    e.preventDefault();
                                    onDownload(asset.url!, `${asset.modelName}-${asset.id}.png`);
                                }}
                            >
                                <Download className="w-3 h-3" />Download
                            </Button>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
});
