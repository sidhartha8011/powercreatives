import { memo } from 'react';
import { SlidersHorizontal, Dices, Zap } from 'lucide-react';

export interface GlobalProductionParametersProps {
  /** The current number of angles (scenes) */
  angles?: number;
  /** Callback when angles changes */
  onAnglesChange?: (n: number) => void;
  /** Max allowed angles */
  maxAngles?: number;
  
  /** The current number of variations per engine */
  variations: number;
  /** Callback when variations changes */
  onVariationsChange: (n: number) => void;
  /** Max allowed variations */
  maxVariations?: number;
}

export const GlobalProductionParameters = memo(function GlobalProductionParameters({
  angles,
  onAnglesChange,
  maxAngles = 8,
  variations,
  onVariationsChange,
  maxVariations = 4,
}: GlobalProductionParametersProps) {
  const showAngles = angles !== undefined && onAnglesChange !== undefined;

  return (
    <section>
      <div className="flex items-center gap-2 mb-3">
        <SlidersHorizontal className="w-4 h-4 text-muted-foreground" />
        <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
          Production Parameters
        </h3>
      </div>
      <div className="space-y-4">
        {/* Angles */}
        {showAngles && (
          <div>
            <div className="flex items-center justify-between mb-2">
              <label className="text-xs text-muted-foreground flex items-center gap-1.5">
                <Dices className="w-3 h-3" />Angles (Scenes)
              </label>
              <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{angles}</span>
            </div>
            <input
              type="range" 
              min="1" 
              max={maxAngles} 
              value={angles}
              onChange={(e) => onAnglesChange(parseInt(e.target.value) || 1)}
              className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
            />
            <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
              <span>1</span><span>{maxAngles}</span>
            </div>
          </div>
        )}

        {/* Variations */}
        <div>
          <div className="flex items-center justify-between mb-2">
            <label className="text-xs text-muted-foreground flex items-center gap-1.5">
              <Zap className="w-3 h-3" />Images per Engine
            </label>
            <span className="text-xs font-mono bg-muted px-2 py-0.5 rounded">{variations}</span>
          </div>
          <input
            type="range" 
            min="1" 
            max={maxVariations} 
            value={variations}
            onChange={(e) => onVariationsChange(parseInt(e.target.value) || 1)}
            className="w-full h-1.5 bg-muted rounded-full appearance-none cursor-pointer accent-primary"
          />
          <div className="flex justify-between text-[10px] text-muted-foreground mt-1">
            <span>1</span><span>{maxVariations}</span>
          </div>
        </div>
      </div>
    </section>
  );
});
