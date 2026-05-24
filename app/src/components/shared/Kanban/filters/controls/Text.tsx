/**
 * Text filter control — debounced inline text input.
 *
 * Debounce protects the filter pipeline from running on every keystroke
 * (250ms default). Local input state stays unfiltered for snappy typing
 * while the committed query value drives the actual filter.
 */

import { useEffect, useRef, useState } from 'react';
import { Search, X } from 'lucide-react';

import styles from '../filters.module.css';

export interface TextControlProps {
  label: string;
  query: string;
  placeholder?: string;
  onChange: (next: string) => void;
  debounceMs?: number;
  ariaLabel?: string;
}

export function TextControl({
  label,
  query,
  placeholder,
  onChange,
  debounceMs = 250,
  ariaLabel,
}: TextControlProps) {
  const [local, setLocal] = useState(query);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Keep local in sync if the upstream value changes externally (URL load,
  // clearAll, etc.). Skip if we're mid-debounce of our own change.
  useEffect(() => {
    if (timer.current) return;
    setLocal(query);
  }, [query]);

  useEffect(() => () => {
    if (timer.current) clearTimeout(timer.current);
  }, []);

  const commit = (value: string) => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      timer.current = null;
      onChange(value);
    }, debounceMs);
  };

  const handleChange = (value: string) => {
    setLocal(value);
    commit(value);
  };

  const handleClear = () => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = null;
    setLocal('');
    onChange('');
  };

  return (
    <div className={`${styles.textControl} ${local ? styles.triggerActive : ''}`}>
      <Search className={styles.textControlIcon} aria-hidden="true" />
      <input
        type="search"
        value={local}
        onChange={(e) => handleChange(e.target.value)}
        placeholder={placeholder ?? label}
        aria-label={ariaLabel ?? label}
        className={styles.textControlInput}
      />
      {local && (
        <button
          type="button"
          onClick={handleClear}
          aria-label={`Clear ${label}`}
          className={styles.textControlClear}
        >
          <X className="h-3 w-3" aria-hidden="true" />
        </button>
      )}
    </div>
  );
}
