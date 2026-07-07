/**
 * EntityCardSection — a titled block inside an EntityCard.
 *
 * Whisper header (11px uppercase, muted) with an optional right-aligned
 * action slot; content below. Spacing scale: 32px between sections, 8px
 * header→content — enforced here so consumers can't drift.
 */

import type { ReactNode } from 'react';

import { CARD_SPACE, CARD_TYPE } from './cardTokens';

export interface EntityCardSectionProps {
  title: string;
  /** Optional right-aligned header action (e.g. a ghost "+ Add" button). */
  action?: ReactNode;
  children: ReactNode;
}

export function EntityCardSection({ title, action, children }: EntityCardSectionProps) {
  return (
    <section className={CARD_SPACE.SECTION_GAP}>
      <div className={`flex items-center justify-between ${CARD_SPACE.SECTION_HEADER_GAP}`}>
        <h3 className={CARD_TYPE.SECTION}>{title}</h3>
        {action}
      </div>
      {children}
    </section>
  );
}
