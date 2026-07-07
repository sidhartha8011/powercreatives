/**
 * EntityCardSection — a titled block inside an EntityCard.
 *
 * Whisper header (11px uppercase, muted) with an optional right-aligned
 * action slot; content below. Spacing scale: 32px between sections, 8px
 * header→content — enforced here so consumers can't drift.
 */

import type { ReactNode } from 'react';

export interface EntityCardSectionProps {
  title: string;
  /** Optional right-aligned header action (e.g. a ghost "+ Add" button). */
  action?: ReactNode;
  children: ReactNode;
}

export function EntityCardSection({ title, action, children }: EntityCardSectionProps) {
  return (
    <section className="mt-8">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{title}</h3>
        {action}
      </div>
      {children}
    </section>
  );
}
