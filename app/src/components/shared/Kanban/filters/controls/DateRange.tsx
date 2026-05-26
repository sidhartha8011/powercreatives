/**
 * DateRange filter control — shadcn Popover with two native date inputs.
 *
 * Native <input type="date"> chosen over a fancy calendar to stay light and
 * keyboard-first. The popover chrome (bg, border, shadow) comes from the
 * shared shadcn theme.
 */

import { useState } from 'react';
import { CalendarRange, ChevronsUpDown } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';

import styles from '../filters.module.css';

export interface DateRangeControlProps {
  label: string;
  from?: string;
  to?: string;
  onChange: (next: { from?: string; to?: string }) => void;
  ariaLabel?: string;
}

export function DateRangeControl({
  label,
  from,
  to,
  onChange,
  ariaLabel,
}: DateRangeControlProps) {
  const [open, setOpen] = useState(false);

  const active = Boolean(from || to);
  const summary = !active ? label : `${label}: ${from ?? '…'} → ${to ?? '…'}`;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          role="combobox"
          aria-expanded={open}
          aria-label={ariaLabel ?? label}
          className={cn(styles.trigger, active && styles.triggerActive)}
        >
          <CalendarRange className="h-3 w-3 opacity-60 shrink-0" aria-hidden="true" />
          <span className={styles.triggerLabel}>{summary}</span>
          <ChevronsUpDown className="h-3 w-3 opacity-60 shrink-0" aria-hidden="true" />
        </Button>
      </PopoverTrigger>

      <PopoverContent className="p-0 w-[240px]" align="start">
        <div className={styles.dateRangeBody}>
          <label className={styles.dateRangeField}>
            <span className={styles.dateRangeLabel}>From</span>
            <input
              type="date"
              value={from ?? ''}
              onChange={(e) => onChange({ from: e.target.value || undefined, to })}
              className={styles.dateRangeInput}
            />
          </label>
          <label className={styles.dateRangeField}>
            <span className={styles.dateRangeLabel}>To</span>
            <input
              type="date"
              value={to ?? ''}
              onChange={(e) => onChange({ from, to: e.target.value || undefined })}
              className={styles.dateRangeInput}
            />
          </label>
        </div>

        {active && (
          <div className="border-t px-2 py-1.5 text-right">
            <button
              type="button"
              onClick={() => onChange({ from: undefined, to: undefined })}
              className="text-[11px] font-medium text-muted-foreground hover:text-foreground transition-colors px-1.5 py-0.5 rounded"
            >
              Clear range
            </button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}
