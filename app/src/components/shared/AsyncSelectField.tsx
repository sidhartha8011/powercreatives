/**
 * AsyncSelectField — Generic async-loaded dropdown with loading/empty states.
 *
 * Eliminates copy-paste of Select + Loader2 + loading/empty patterns
 * across CreateStrategyDialog, SendToWriterDialog, etc.
 */

import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2 } from 'lucide-react';

export interface SelectOption {
  value: string;
  label: string;
}

interface AsyncSelectFieldProps {
  /** Field label displayed above the dropdown */
  label: string;
  /** Current value */
  value: string;
  /** Change callback */
  onChange: (value: string) => void;
  /** Options to display */
  options: SelectOption[];
  /** Whether data is still loading */
  isLoading: boolean;
  /** Placeholder shown when no value selected */
  placeholder?: string;
  /** Text for the "no selection" option (shown first). Null = don't show */
  noneLabel?: string | null;
  /** Message when options list is empty */
  emptyMessage?: string;
}

const UNSELECTED = '__none__';

export function AsyncSelectField({
  label,
  value,
  onChange,
  options,
  isLoading,
  placeholder = 'Select...',
  noneLabel = 'None',
  emptyMessage = 'No options available',
}: AsyncSelectFieldProps) {
  return (
    <div className="space-y-2">
      <Label>{label}</Label>
      <Select value={value || UNSELECTED} onValueChange={onChange}>
        <SelectTrigger className="w-full">
          <SelectValue placeholder={placeholder} />
        </SelectTrigger>
        <SelectContent>
          {noneLabel !== null && (
            <SelectItem value={UNSELECTED}>{noneLabel}</SelectItem>
          )}
          {isLoading ? (
            <div className="flex items-center justify-center p-2 text-sm text-muted-foreground">
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              Loading…
            </div>
          ) : options.length === 0 ? (
            <div className="p-2 text-sm text-muted-foreground text-center">
              {emptyMessage}
            </div>
          ) : (
            options.map((opt) => (
              <SelectItem key={opt.value} value={opt.value}>
                {opt.label}
              </SelectItem>
            ))
          )}
        </SelectContent>
      </Select>
    </div>
  );
}

export { UNSELECTED };
