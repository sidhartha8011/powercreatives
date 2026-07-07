/**
 * EntityCardTitle — inline-editable document title for an EntityCard.
 *
 * Reads as text at rest, reveals a subtle background on hover (discoverable
 * without instruction), commits on blur or Enter. An empty commit restores
 * the previous value — the title is required by contract. Shows a transient
 * "Saved ✓" whisper while `saved` is true (driven by the consumer's
 * auto-save flash).
 */

import { useEffect, useState } from 'react';
import { Check } from 'lucide-react';

import { Input } from '@/components/ui/input';

export interface EntityCardTitleProps {
  value: string;
  placeholder: string;
  /** Persist a changed, non-empty title. Not called when unchanged/empty. */
  onSave: (next: string) => void;
  /** Transient save indicator (consumer flashes this true for ~1.5s). */
  saved?: boolean;
  autoFocus?: boolean;
}

export function EntityCardTitle({ value, placeholder, onSave, saved = false, autoFocus = false }: EntityCardTitleProps) {
  const [draft, setDraft] = useState(value);

  // Follow external changes (e.g. the card opens on another entity).
  useEffect(() => setDraft(value), [value]);

  const commit = () => {
    const trimmed = draft.trim();
    if (trimmed.length === 0) {
      setDraft(value); // required field — restore instead of saving empty
      return;
    }
    if (trimmed !== value) onSave(trimmed);
  };

  return (
    <div className="mb-8 flex items-center gap-3">
      <Input
        value={draft}
        onChange={(e) => setDraft(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault(); // commit, never submit a surrounding form
            (e.target as HTMLInputElement).blur();
          }
        }}
        placeholder={placeholder}
        aria-label={placeholder}
        autoFocus={autoFocus}
        maxLength={256}
        className="h-auto rounded-md border-none bg-transparent px-1.5 py-1 !text-2xl font-bold tracking-tight shadow-none cursor-text hover:bg-slate-50 focus-visible:ring-1"
      />
      <span
        aria-live="polite"
        className={`flex shrink-0 items-center gap-1 text-[11px] text-muted-foreground transition-opacity duration-300 ${saved ? 'opacity-100' : 'opacity-0'}`}
      >
        <Check className="h-3 w-3" /> Saved
      </span>
    </div>
  );
}
