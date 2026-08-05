/**
 * PreviewDialog — in-app preview of the public client board.
 *
 * Renders the actual public review URL inside an iframe in a shadcn
 * Dialog, so internal team members can preview a set's client-facing
 * appearance without leaving the dashboard or opening a new tab. The
 * iframe shows the exact same UI the client sees (read-only — no
 * mutations possible from this dialog).
 */

import { ExternalLink } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogTitle,
} from '@/components/ui/dialog';

import type { ApprovalSet } from '../types';
import { ClientReviewPage } from '../components/ClientReviewPage';

export interface PreviewDialogProps {
  set: ApprovalSet | null;
  url: string | null;
  onClose: () => void;
}

export function PreviewDialog({ set, url, onClose }: PreviewDialogProps) {
  if (!set || !url) return null;

  return (
    <Dialog open onOpenChange={(open) => { if (!open) onClose(); }}>
      <DialogContent
        className="sm:max-w-[95vw] w-[95vw] h-[90vh] p-0 flex flex-col gap-0 overflow-hidden"
        showCloseButton
      >
        {/* Visible header removed per UX. Title + description kept in
            sr-only form because Radix UI's Dialog requires both for
            accessibility (screen-reader announcement + WCAG compliance). */}
        <DialogTitle className="sr-only">
          {/* Optional-chained: a BOARD row carries no `snapshot` at all. The list
              query omits it deliberately (longtext, embedded images), and it no
              longer ships a misleading empty one either — so an unguarded
              `set.snapshot.brandName` throws on every card click. */}
          {set.snapshot?.brandName ? `${set.snapshot.brandName} / ` : ''}
          {set.name}
        </DialogTitle>
        <DialogDescription className="sr-only">
          Client preview — exactly what the recipient sees.
        </DialogDescription>

        {/* "Open in new tab" — positioned to the left of the auto-rendered
            X close button (which sits at absolute top-4 right-4). */}
        <a
          href={url}
          target="_blank"
          rel="noopener noreferrer"
          className="absolute top-4 right-12 z-50 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors px-2 py-1 rounded"
        >
          <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
          Open in new tab
        </a>

        {/* The client view rendered DIRECTLY, not through an iframe.

            It used to be `<iframe src={url}>`. An iframe is a separate document,
            so anything opened inside it — the asset viewer and its
            `backdrop-filter` — is bounded by the iframe's rectangle. That is why
            the blur covered only the frame instead of the screen: not a CSS
            value to tune, but a containment boundary that should not have been
            there.

            It also loaded the entire SPA a second time inside the modal, over
            HTTP, on a host where a page load takes ~45s.

            `ClientReviewPage` is already a component that takes a token and
            renders the client's view. Rendering it here shows exactly what the
            iframe showed, minus the nested document — so the viewer portals to
            the real page and the blur covers everything behind it. */}
        <div className="flex-1 min-h-0 overflow-y-auto bg-muted/30">
          <ClientReviewPage token={set.token} />
        </div>
      </DialogContent>
    </Dialog>
  );
}
