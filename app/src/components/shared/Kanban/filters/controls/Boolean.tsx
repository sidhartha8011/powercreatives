/**
 * Boolean filter control — single toggle pill.
 */

import { Button } from '@/components/ui/button';

import styles from '../filters.module.css';

export interface BooleanControlProps {
  label: string;
  toggleLabel?: string;
  value: boolean;
  onChange: (next: boolean) => void;
  ariaLabel?: string;
}

export function BooleanControl({
  label,
  toggleLabel,
  value,
  onChange,
  ariaLabel,
}: BooleanControlProps) {
  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      role="switch"
      aria-checked={value}
      aria-label={ariaLabel ?? label}
      onClick={() => onChange(!value)}
      className={`${styles.trigger} ${value ? styles.triggerActive : ''}`}
    >
      <span className={styles.triggerLabel}>{toggleLabel ?? label}</span>
    </Button>
  );
}
