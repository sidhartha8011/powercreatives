import { useMemo } from 'react';
import { ArrowDown, ArrowUp, Check, Link2, Loader2, X } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import type { InterlinkProposal } from './types';

/** Title plus its path — the path is what tells two same-titled pages apart. */
function PageRef({ title, path }: { title: string; path: string }) {
  return (
    <span className="min-w-0">
      <span className="truncate font-medium text-foreground" title={title}>{title}</span>
      {path && <span className="ml-1 text-muted-foreground" title={path}>{path}</span>}
    </span>
  );
}

/**
 * Proposed internal links, reviewed in a dialog.
 *
 * Approve writes the link; Reject drops it from the list. Nothing touches a
 * page until Approve — same contract as every other staged value in this table.
 *
 * Two things this deliberately does NOT do:
 *  - It does not hide the links it could not make. A refusal ("no safe
 *    occurrence of …") is the generator declining to invent copy, and the user
 *    needs to see it — silently dropping those rows is how a run comes to look
 *    like it skipped pages (card 7).
 *  - It does not identify a page by title alone. Titles repeat across a post
 *    and a page, which renders as "X → X" and reads like a self-link bug.
 */
export function InterlinkPanel({
  proposals,
  applyingKey,
  onAccept,
  onReject,
  onAcceptAll,
  onClose,
}: {
  proposals: InterlinkProposal[];
  /** `${sourceId}:${targetId}` currently being written, if any. */
  applyingKey: string | null;
  onAccept: (p: InterlinkProposal) => void;
  onReject: (p: InterlinkProposal) => void;
  onAcceptAll: () => void;
  onClose: () => void;
}) {
  const ready = useMemo(() => proposals.filter((p) => p.anchor !== ''), [proposals]);
  const refused = useMemo(() => proposals.filter((p) => p.anchor === ''), [proposals]);
  const busy = applyingKey !== null;

  return (
    <Dialog open onOpenChange={(o) => { if (!o && !busy) onClose(); }}>
      <DialogContent className="sm:max-w-[min(900px,95vw)] max-h-[85vh] overflow-hidden flex flex-col">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Link2 className="w-5 h-5 text-primary" /> Proposed internal links
          </DialogTitle>
          <DialogDescription>
            {ready.length > 0
              ? `${ready.length} ready to add${refused.length > 0 ? `, ${refused.length} not possible` : ''}. Nothing is written until you approve it.`
              : refused.length > 0
                ? `None of these ${refused.length} can be added yet — see why below.`
                : 'Nothing to propose from the current hierarchy.'}
          </DialogDescription>
        </DialogHeader>

        <div className="flex-1 min-h-0 space-y-4 overflow-y-auto">
          {proposals.length === 0 && (
            <p className="text-sm text-muted-foreground">
              Set a parent on at least one page, then generate.
            </p>
          )}

          {ready.length > 0 && (
            <div className="space-y-2">
              {ready.map((p) => {
                const key = `${p.sourceId}:${p.targetId}`;
                const rowBusy = applyingKey === key;
                return (
                  <div key={key} className="rounded-md border border-border bg-background p-2.5">
                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                      {p.direction === 'up'
                        ? <ArrowUp className="size-3 shrink-0" />
                        : <ArrowDown className="size-3 shrink-0" />}
                      <PageRef title={p.sourceTitle} path={p.sourcePath} />
                      <span aria-hidden="true" className="shrink-0">→</span>
                      <PageRef title={p.targetTitle} path={p.targetPath} />
                    </div>
                    <p className="mt-1.5 text-sm text-foreground">
                      Link the words <span className="font-medium">“{p.anchor}”</span> on{' '}
                      {p.sourceTitle} to {p.targetTitle}.
                    </p>
                    <div className="mt-2 flex items-center gap-1.5">
                      <Button type="button" size="xs" variant="success" onClick={() => onAccept(p)} disabled={busy}>
                        {rowBusy ? <Loader2 className="animate-spin" /> : <Check />} Approve
                      </Button>
                      <Button type="button" size="xs" variant="outline" onClick={() => onReject(p)} disabled={busy}>
                        <X /> Reject
                      </Button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}

          {refused.length > 0 && (
            <div className="space-y-1.5">
              <p className="text-xs font-medium text-muted-foreground">
                Not possible right now — an internal link only wraps wording that is already on the
                page, so these have nothing to link.
              </p>
              {refused.map((p) => (
                <div
                  key={`${p.sourceId}:${p.targetId}`}
                  className="rounded-md border border-border bg-muted/40 px-2.5 py-2 text-xs"
                >
                  <div className="flex items-center gap-1.5 text-muted-foreground">
                    <PageRef title={p.sourceTitle} path={p.sourcePath} />
                    <span aria-hidden="true" className="shrink-0">→</span>
                    <PageRef title={p.targetTitle} path={p.targetPath} />
                  </div>
                  <p className="mt-1 text-muted-foreground">{p.reason}</p>
                </div>
              ))}
            </div>
          )}
        </div>

        <DialogFooter className="gap-2">
          <Button variant="ghost" onClick={onClose} disabled={busy}>Close</Button>
          {ready.length > 0 && (
            <Button onClick={onAcceptAll} disabled={busy} className="gap-2">
              {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Check className="w-4 h-4" />}
              Approve all ({ready.length})
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
