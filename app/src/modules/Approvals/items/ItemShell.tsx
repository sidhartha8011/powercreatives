/**
 * ItemShell — the chrome around every item in a card: approve, comment, copy,
 * download, delete.
 *
 * Written ONCE. Each type declares which of these actions it supports and the
 * shell renders exactly those — so a control can never appear for something the
 * type cannot do. That is the defect this removes: a document showed a copy
 * button that read `asset.body`, a field documents do not have, and silently
 * copied an empty string.
 *
 * The shell owns no type knowledge. It asks the registry.
 */

import type { ReactNode } from 'react';
import { CheckCircle2, Copy, Download, MessageSquare, Pencil, Trash2 } from 'lucide-react';

import { itemTypeMeta, type ApprovalItem, type ItemAction, type ItemContext } from './registry';

export interface ItemShellProps {
  item: ApprovalItem;
  ctx: ItemContext;
  isApproved: boolean;
  commentCount: number;
  hasNewComment?: boolean;
  isEditing?: boolean;
  onApprove: (id: string) => void;
  onComment: (id: string) => void;
  onEdit?: (id: string) => void;
  onDelete?: (id: string) => void;
  onCopy?: (id: string) => void;
  onDownload?: (id: string) => void;
  /** The type's own body. */
  children: ReactNode;
}

export function ItemShell({
  item,
  ctx,
  isApproved,
  commentCount,
  hasNewComment = false,
  isEditing = false,
  onApprove,
  onComment,
  onEdit,
  onDelete,
  onCopy,
  onDownload,
  children,
}: ItemShellProps) {
  const meta = itemTypeMeta(item.type);

  /**
   * An action shows only when the TYPE supports it AND the viewer may do it.
   * Both halves matter: the type says what is possible, the context says what
   * this person is allowed to do right now on a card in this lane.
   */
  const shows = (action: ItemAction): boolean => {
    if (!meta.actions.includes(action)) return false;
    if (action === 'approve') return ctx.can.approve;
    if (action === 'comment') return ctx.can.comment;
    if (action === 'edit') return ctx.can.edit;
    if (action === 'delete') return ctx.can.delete;
    return true; // copy / download are read-only
  };

  return (
    <article className={`pcm-item ${isApproved ? 'is-approved' : ''}`} data-item-id={item.id} data-item-type={meta.id}>
      <header className="pcm-item-head">
        <span className="pcm-item-type">
          <meta.icon className="pcm-item-type-icon" aria-hidden="true" />
          {meta.label}
        </span>
        <span className={`pcm-item-status ${isApproved ? 'is-approved' : ''}`}>
          {isApproved ? 'Approved' : 'Awaiting'}
        </span>
      </header>

      <div className="pcm-item-body">{children}</div>

      <footer className="pcm-item-actions">
        {shows('approve') && (
          <button
            type="button"
            className={`pcm-item-btn pcm-item-btn-approve ${isApproved ? 'is-approved' : ''}`}
            onClick={() => onApprove(item.id)}
          >
            <CheckCircle2 className="pcm-item-btn-icon" aria-hidden="true" />
            {isApproved ? 'Approved' : 'Approve'}
          </button>
        )}

        {shows('edit') && onEdit && (
          <button
            type="button"
            className={`pcm-item-btn ${isEditing ? 'is-active' : ''}`}
            onClick={() => onEdit(item.id)}
            aria-pressed={isEditing}
          >
            <Pencil className="pcm-item-btn-icon" aria-hidden="true" />
            {isEditing ? 'Done' : 'Edit'}
          </button>
        )}

        {shows('comment') && (
          <button type="button" className="pcm-item-btn" onClick={() => onComment(item.id)}>
            <MessageSquare className="pcm-item-btn-icon" aria-hidden="true" />
            Comment
            {commentCount > 0 && (
              <span className={`pcm-item-count ${hasNewComment ? 'is-new' : ''}`}>{commentCount}</span>
            )}
          </button>
        )}

        <span className="pcm-item-actions-spacer" />

        {shows('copy') && onCopy && (
          <button
            type="button"
            className="pcm-item-btn pcm-item-btn-icon-only"
            onClick={() => onCopy(item.id)}
            aria-label={`Copy ${meta.label.toLowerCase()} text`}
            title="Copy text"
          >
            <Copy className="pcm-item-btn-icon" aria-hidden="true" />
          </button>
        )}

        {shows('download') && onDownload && (
          <button
            type="button"
            className="pcm-item-btn pcm-item-btn-icon-only"
            onClick={() => onDownload(item.id)}
            aria-label="Download"
            title="Download"
          >
            <Download className="pcm-item-btn-icon" aria-hidden="true" />
          </button>
        )}

        {shows('delete') && onDelete && (
          <button
            type="button"
            className="pcm-item-btn pcm-item-btn-icon-only pcm-item-btn-danger"
            onClick={() => onDelete(item.id)}
            aria-label={`Remove ${meta.label.toLowerCase()}`}
            title="Remove"
          >
            <Trash2 className="pcm-item-btn-icon" aria-hidden="true" />
          </button>
        )}
      </footer>
    </article>
  );
}
