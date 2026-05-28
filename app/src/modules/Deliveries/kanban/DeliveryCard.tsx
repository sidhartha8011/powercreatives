/**
 * DeliveryCard — Kanban card body for a single delivery.
 *
 * Interaction model:
 *   - Click the card body → opens the edit dialog.
 *   - Drag the card       → triggers a column-to-column move (handled by
 *                            the shared KanbanBoard + onItemMove callback).
 *   - Hover               → reveals a Menu (⋯) button with Edit / Delete.
 *
 * The card chrome stays deliberately minimal — name + client + relative
 * timestamp. Deeper detail belongs to the dialog, not the card.
 */

import { useCallback, type KeyboardEvent, type MouseEvent } from 'react';
import { MoreHorizontal, Pencil, Trash2, Building2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

import type { Delivery } from '../types';

export interface DeliveryCardProps {
  delivery: Delivery;
  onEdit: (delivery: Delivery) => void;
  /** Request a single-card delete. Parent owns the confirmation dialog. */
  onRequestDelete: (delivery: Delivery) => void;
}

/**
 * Format a timestamp as a short relative-ish string ("Today",
 * "Yesterday", "3 days ago", or an absolute date for older entries).
 * Keeps cards single-line and signals freshness without locale noise.
 */
function formatRelative(iso: string): string {
  const then = new Date(iso);
  if (Number.isNaN(then.getTime())) return '';

  const now = Date.now();
  const ms = now - then.getTime();
  const day = 1000 * 60 * 60 * 24;
  const days = Math.floor(ms / day);

  if (days <= 0) return 'Today';
  if (days === 1) return 'Yesterday';
  if (days < 7) return `${days} days ago`;
  return then.toLocaleDateString('sv-SE', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

export function DeliveryCard({
  delivery,
  onEdit,
  onRequestDelete,
}: DeliveryCardProps) {
  const handleCardClick = useCallback(() => onEdit(delivery), [onEdit, delivery]);

  const handleKeyDown = useCallback(
    (event: KeyboardEvent<HTMLElement>) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        onEdit(delivery);
      }
    },
    [onEdit, delivery]
  );

  const stop = useCallback((e: MouseEvent) => e.stopPropagation(), []);

  const handleEdit = useCallback(
    (e: Event | MouseEvent) => {
      e.stopPropagation();
      onEdit(delivery);
    },
    [onEdit, delivery]
  );

  const handleDelete = useCallback(
    (e: Event | MouseEvent) => {
      e.stopPropagation();
      onRequestDelete(delivery);
    },
    [onRequestDelete, delivery]
  );

  const client = delivery.clientName?.trim();

  return (
    <article
      className="group bg-white border border-slate-200 rounded-lg p-3 cursor-pointer hover:shadow-sm hover:border-slate-300 transition-colors focus:outline-none focus:ring-2 focus:ring-primary/40"
      role="link"
      tabIndex={0}
      aria-label={`Edit delivery ${delivery.name}`}
      onClick={handleCardClick}
      onKeyDown={handleKeyDown}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <h3 className="text-sm font-semibold text-slate-900 truncate">
            {delivery.name}
          </h3>
          {client ? (
            <p className="mt-0.5 text-xs text-slate-500 flex items-center gap-1 truncate">
              <Building2 className="w-3 h-3 shrink-0" aria-hidden="true" />
              <span className="truncate">{client}</span>
            </p>
          ) : null}
        </div>

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className="h-7 w-7 shrink-0 opacity-0 group-hover:opacity-100 focus:opacity-100 data-[state=open]:opacity-100 transition-opacity"
              onClick={stop}
              aria-label={`Actions for ${delivery.name}`}
            >
              <MoreHorizontal className="w-4 h-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" onClick={stop}>
            <DropdownMenuItem onSelect={handleEdit}>
              <Pencil className="w-3.5 h-3.5 mr-2" aria-hidden="true" />
              Edit
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={handleDelete}
              className="text-destructive focus:text-destructive"
            >
              <Trash2 className="w-3.5 h-3.5 mr-2" aria-hidden="true" />
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <div className="mt-2 text-[11px] text-slate-400">
        Updated {formatRelative(delivery.updatedAt)}
      </div>
    </article>
  );
}
