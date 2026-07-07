/**
 * EntityCard — shared Notion-style detail-card shell.
 *
 * Domain-agnostic on purpose (same philosophy as the shared Kanban
 * primitive). Geometry does the design work: a tall fixed-height white card
 * (88vh — stately even when content is short) with ONE scroll context and a
 * single centered content column (~640px) framed by generous whitespace.
 * Modules compose it with <EntityCardTitle>, <PropertyTable> and
 * <EntityCardSection>; all data, mutations and domain sections stay inside
 * the consuming module.
 */

import type { ReactNode } from 'react';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/components/ui/dialog';

export interface EntityCardProps {
  open: boolean;
  /** Called when the card wants to close (overlay click, ✕, Esc). */
  onClose: () => void;
  /** Screen-reader name for the dialog (Radix requires a DialogTitle). */
  ariaTitle: string;
  /** Screen-reader description. */
  ariaDescription?: string;
  children: ReactNode;
}

export function EntityCard({ open, onClose, ariaTitle, ariaDescription, children }: EntityCardProps) {
  return (
    <Dialog open={open} onOpenChange={(o) => { if (!o) onClose(); }}>
      {/* Fixed height + one scroll context — inner tables never scroll. */}
      <DialogContent className="block h-[88vh] overflow-y-auto rounded-xl bg-white p-0 sm:max-w-[880px]">
        <DialogTitle className="sr-only">{ariaTitle}</DialogTitle>
        {ariaDescription && <DialogDescription className="sr-only">{ariaDescription}</DialogDescription>}
        {/* The whitespace IS the design: narrow centered column, big top padding. */}
        <div className="mx-auto max-w-[640px] px-8 pb-16 pt-14">
          {children}
        </div>
      </DialogContent>
    </Dialog>
  );
}
