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

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

import type { ApprovalSet } from '../types';

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
        className="sm:max-w-[95vw] w-[95vw] h-[90vh] p-0 flex flex-col gap-0 overflow-hidden border-0"
        showCloseButton
      >
        <DialogHeader className="px-5 py-3 shrink-0 flex-row items-center justify-between space-y-0">
          <div className="min-w-0 flex-1">
            <DialogTitle className="text-sm font-semibold truncate">
              {set.snapshot.brandName ? `${set.snapshot.brandName} / ` : ''}
              {set.name}
            </DialogTitle>
            <DialogDescription className="text-xs truncate">
              Client preview — exactly what the recipient sees.
            </DialogDescription>
          </div>
          <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground transition-colors px-2 py-1 rounded"
          >
            <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
            Open in new tab
          </a>
        </DialogHeader>

        <div className="flex-1 min-h-0 bg-muted/30">
          <iframe
            src={url}
            title={`Preview: ${set.name}`}
            className="w-full h-full border-0"
            sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
          />
        </div>
      </DialogContent>
    </Dialog>
  );
}
