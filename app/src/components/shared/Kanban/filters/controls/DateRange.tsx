/**
 * DateRange filter control — popover with two native date inputs.
 *
 * Uses native <input type="date"> rather than a calendar picker to stay
 * dependency-free and keyboard-friendly. Consumers wanting a fancy calendar
 * can swap in their own control by extending the registry.
 */

import { useState } from 'react';
import { CalendarRange, ChevronDown } from 'lucide-react';

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
  const summary = !active
    ? label
    : `${label}: ${from ?? '…'} → ${to ?? '…'}`;

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="sm"
          aria-label={ariaLabel ?? label}
          aria-haspopup="dialog"
          aria-expanded={open}
          className={`${styles.trigger} ${active ? styles.triggerActive : ''}`}
        >
          <CalendarRange className={styles.triggerIcon} aria-hidden="true" />
          <span className={styles.triggerLabel}>{summary}</span>
          <ChevronDown className={styles.triggerChevron} aria-hidden="true" />
        </Button>
      </PopoverTrigger>

      <PopoverContent
        align="start"
        sideOffset={6}
        className={styles.popoverContent}
      >
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
          <div className={styles.popoverFooter}>
            <button
              type="button"
              onClick={() => onChange({ from: undefined, to: undefined })}
              className={styles.popoverFooterAction}
            >
              Clear range
            </button>
          </div>
        )}
      </PopoverContent>
    </Popover>
  );
}
