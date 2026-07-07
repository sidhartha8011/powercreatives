/**
 * EntityCard — shared Notion-style detail-card shell.
 *
 * Domain-agnostic on purpose (same philosophy as the shared Kanban
 * primitive): a wide white Dialog with ONE scroll context, 32px padding and
 * no footer. Modules compose it with <EntityCardTitle>, <PropertyTable> and
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
      {/* One scroll context for the whole card — inner tables never scroll. */}
      <DialogContent className="sm:max-w-5xl bg-white max-h-[90vh] overflow-y-auto p-8 block">
        <DialogTitle className="sr-only">{ariaTitle}</DialogTitle>
        {ariaDescription && <DialogDescription className="sr-only">{ariaDescription}</DialogDescription>}
        {children}
      </DialogContent>
    </Dialog>
  );
}
