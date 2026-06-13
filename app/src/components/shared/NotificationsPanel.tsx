/**
 * NotificationsPanel — right-side sheet listing approval-flow notifications.
 *
 * Items are role-scoped server-side (admins see all; users see events for
 * sets they own or brands granted via delivery assignments). Opening the
 * panel marks everything seen, which clears the red badge in the sidebar.
 *
 * Jump actions per item:
 *  - "Open board": the public review board deep link (comment anchored).
 *  - "Approvals": switches the SPA to the Approvals module.
 */

import { useEffect } from 'react';
import { MessageSquare, CheckCircle2, ExternalLink, KanbanSquare, BellOff } from 'lucide-react';

import { trpc } from '@/lib/trpc';
import { useApp } from '@/contexts/AppContext';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription,
} from '@/components/ui/sheet';

export interface NotificationItem {
  id: number;
  setId: number;
  brandId: number | null;
  type: 'comment' | 'approval' | string;
  title: string;
  excerpt: string;
  link: string;
  createdAt: string;
  isNew: boolean;
}

export function NotificationsPanel({
  open,
  onOpenChange,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { navigateToApprovalsWithSet } = useApp();
  const { data, refetch } = trpc.notifications.list.useQuery();
  const items: NotificationItem[] = Array.isArray(data?.items) ? data.items : [];

  const markSeen = trpc.notifications.markSeen.useMutation({
    onSuccess: () => refetch(),
  });

  // Opening the panel marks everything seen → badge clears.
  useEffect(() => {
    if (open) {
      markSeen.mutate({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      {/* overflow-hidden on the panel + a dedicated flex-1/min-h-0 scroll
          region below = the header stays put and the LIST scrolls. Putting
          overflow on the whole SheetContent (its default is flex-col h-full)
          clipped a long list instead of scrolling it — only the first few
          notifications were reachable. */}
      <SheetContent side="right" className="w-[380px] sm:max-w-[380px] overflow-hidden">
        <SheetHeader className="shrink-0">
          <SheetTitle>Notifications</SheetTitle>
          <SheetDescription>
            Comments and approvals on the approval sets you have access to.
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 min-h-0 overflow-y-auto px-4 pb-4 space-y-2">
          {items.length === 0 && (
            <div className="flex flex-col items-center gap-2 py-10 text-muted-foreground">
              <BellOff className="h-6 w-6" />
              <p className="text-sm">Nothing yet — comments and approvals will show up here.</p>
            </div>
          )}

          {items.map((n) => (
            <div
              key={n.id}
              className={`rounded-lg border p-3 ${n.isNew ? 'bg-blue-50/60 border-blue-200' : 'bg-background'}`}
            >
              <div className="flex items-start gap-2">
                {n.type === 'comment' ? (
                  <MessageSquare className="mt-0.5 h-4 w-4 shrink-0 text-blue-600" />
                ) : (
                  <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-green-600" />
                )}
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium leading-snug">{n.title}</p>
                  {n.excerpt && (
                    <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{n.excerpt}</p>
                  )}
                  <div className="mt-1 flex items-center gap-2">
                    <span className="text-[11px] text-muted-foreground">
                      {new Date(n.createdAt.replace(' ', 'T')).toLocaleString(undefined, {
                        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
                      })}
                    </span>
                    {n.isNew && <Badge variant="default" className="h-4 px-1.5 text-[10px]">New</Badge>}
                  </div>
                </div>
              </div>
              <div className="mt-2 flex gap-2">
                {n.link && (
                  <Button asChild size="sm" variant="outline" className="h-7 gap-1 text-xs">
                    <a href={n.link} target="_blank" rel="noopener noreferrer">
                      <ExternalLink className="h-3 w-3" /> Open board
                    </a>
                  </Button>
                )}
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-7 gap-1 text-xs"
                  onClick={() => {
                    // Opens the Approvals board pre-filtered to this set's card.
                    // Number(): wpdb serializes BIGINTs as strings — the board
                    // matches ids strictly.
                    navigateToApprovalsWithSet(Number(n.setId));
                    onOpenChange(false);
                  }}
                >
                  <KanbanSquare className="h-3 w-3" /> Approvals
                </Button>
              </div>
            </div>
          ))}
        </div>
      </SheetContent>
    </Sheet>
  );
}
