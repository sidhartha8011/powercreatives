import { memo, useRef } from 'react';
import { X } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import type { BrandAsset } from '@/shared/brandTypes';

export interface BrandAssetGridItem {
    id: string;
    url: string;
}

export interface BrandAssetGridProps {
    title: string;
    items: BrandAssetGridItem[];
    brandAssets?: BrandAsset[]; // Used for "From Brand" picker
    isActive: boolean;
    isUploading?: boolean;
    onToggle: (checked: boolean) => void;
    onPick?: (url: string) => void;
    onUpload: (file: File) => void;
    onRemove: (id: string) => void;
}

export const BrandAssetGrid = memo(function BrandAssetGrid({
    title,
    items,
    brandAssets = [],
    isActive,
    isUploading,
    onToggle,
    onPick,
    onUpload,
    onRemove,
}: BrandAssetGridProps) {
    const fileInputRef = useRef<HTMLInputElement>(null);

    const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            onUpload(file);
        }
        // Reset input to allow selecting the same file again
        e.target.value = '';
    };

    return (
        <div className="flex flex-col gap-2 p-2.5 rounded bg-muted/20 border border-border">
            <div className="flex items-center justify-between">
                <span className="text-xs font-medium text-muted-foreground">{title}</span>
                <Switch
                    checked={isActive}
                    onCheckedChange={onToggle}
                />
            </div>
            
            <div className={`transition-opacity ${!isActive ? 'opacity-40 grayscale pointer-events-none' : ''}`}>
                <div className="flex items-center gap-3 mb-2">
                    {onPick && brandAssets.length > 0 && (
                        <Popover>
                            <PopoverTrigger asChild>
                                <button type="button" className="text-[10px] font-medium text-muted-foreground hover:text-primary hover:underline transition-colors">
                                    From Brand
                                </button>
                            </PopoverTrigger>
                            <PopoverContent className="w-52 p-2" side="right" align="start">
                                <p className="text-[10px] text-muted-foreground mb-1.5">Select from brand assets</p>
                                <div className="grid grid-cols-3 gap-1.5">
                                    {brandAssets.map((a) => (
                                        <button
                                            key={a.fileKey}
                                            type="button"
                                            onClick={() => onPick(a.url)}
                                            className="rounded border border-border hover:border-primary/50 overflow-hidden transition-colors"
                                            title={a.filename}
                                        >
                                            <img src={a.url} alt={a.filename} className="w-full h-12 object-contain bg-muted/30" />
                                        </button>
                                    ))}
                                </div>
                            </PopoverContent>
                        </Popover>
                    )}
                    
                    <button
                        type="button"
                        onClick={() => fileInputRef.current?.click()}
                        disabled={isUploading}
                        className="text-[10px] font-medium text-primary hover:underline disabled:opacity-50 disabled:hover:no-underline"
                    >
                        {isUploading ? 'Uploading...' : '+ Add'}
                    </button>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/*"
                        className="hidden"
                        onChange={handleFileChange}
                    />
                </div>

                {items.length > 0 ? (
                    <div className="flex flex-wrap gap-2">
                        {items.map((item) => (
                            <div key={item.id} className="relative group">
                                <img
                                    src={item.url}
                                    alt="Asset"
                                    className="w-10 h-10 object-cover rounded border border-border bg-background"
                                />
                                <button
                                    type="button"
                                    onClick={() => onRemove(item.id)}
                                    className="absolute -top-1.5 -right-1.5 w-4 h-4 bg-destructive text-destructive-foreground rounded-full flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity"
                                    title="Remove"
                                >
                                    <X className="w-2.5 h-2.5" />
                                </button>
                            </div>
                        ))}
                    </div>
                ) : (
                    <span className="text-[10px] text-muted-foreground italic block mt-1">
                        No assets selected.
                    </span>
                )}
            </div>
        </div>
    );
});
