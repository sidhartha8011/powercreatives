import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { X, MessageSquare, CornerDownRight, Send } from 'lucide-react';

/* ─── Shared type for a single comment entry ─── */
export interface CommentEntry {
  id: string;
  author: string;
  text: string;
  createdAt: string;
  status: 'New' | 'Team reply' | 'Done';
  parentId: string | null;
}

/* ─── Helper: generate a short unique id ─── */
function uid(): string {
  return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);
}

/* ─── Helper: format ISO date to relative label ─── */
function relativeTime(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return 'Just now';
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  return `${days}d ago`;
}

/* ─── Helper: map status to CSS modifier class ─── */
function statusClass(status: string): string {
  if (status === 'New') return 'is-new';
  if (status === 'Team reply') return 'is-team-reply';
  return 'is-done';
}

/* ─── Props ─── */
interface ClientCommentInspectorProps {
  /** The asset being inspected */
  asset: { id: string; url?: string; name?: string; headline?: string; body?: string; mimeType?: string; [k: string]: unknown };
  type: 'media' | 'copy';
  /** Full comment thread for this asset */
  thread: CommentEntry[];
  /** Current viewer identity */
  authorName: string;
  isReadOnly?: boolean;
  onClose: () => void;
  /** Called with the full updated thread array whenever anything changes */
  onThreadChange: (assetId: string, thread: CommentEntry[]) => void;
}

/* ════════════════════════════════════════════════════════════════════════
 * ClientCommentInspector
 *
 * A right-sliding side panel that displays a chronological list of
 * comments for a single creative asset. All styling via pcm-inspector-*
 * CSS classes defined in the central <style> block of ClientReviewPage.
 * ZERO inline styles.
 * ════════════════════════════════════════════════════════════════════════ */
export function ClientCommentInspector({
  asset,
  type,
  thread,
  authorName,
  isReadOnly = false,
  onClose,
  onThreadChange,
}: ClientCommentInspectorProps) {
  const [inputText, setInputText] = useState('');
  const [replyingToId, setReplyingToId] = useState<string | null>(null);
  const drawerRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const isVideo = type === 'media' && !!(
    asset.mimeType?.startsWith('video/') ||
    asset.url?.endsWith('.mp4') ||
    asset.url?.endsWith('.mov') ||
    asset.url?.endsWith('.webm')
  );

  // Separate top-level comments from replies
  const topLevel = useMemo(() => thread.filter((c) => !c.parentId), [thread]);
  const repliesMap = useMemo(() => {
    const map: Record<string, CommentEntry[]> = {};
    thread.forEach((c) => {
      if (c.parentId) {
        if (!map[c.parentId]) map[c.parentId] = [];
        map[c.parentId].push(c);
      }
    });
    return map;
  }, [thread]);

  // Close on Escape
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  // Backdrop click
  const handleBackdropClick = useCallback((e: React.MouseEvent) => {
    if (drawerRef.current && !drawerRef.current.contains(e.target as Node)) {
      onClose();
    }
  }, [onClose]);

  // Add a new comment (top-level or reply)
  const handleSubmitComment = useCallback(() => {
    const text = inputText.trim();
    if (!text) return;

    const entry: CommentEntry = {
      id: uid(),
      author: authorName || 'Client',
      text,
      createdAt: new Date().toISOString(),
      status: 'New',
      parentId: replyingToId,
    };

    const updated = [...thread, entry];
    onThreadChange(asset.id, updated);
    setInputText('');
    setReplyingToId(null);

    // Scroll to bottom after adding
    requestAnimationFrame(() => {
      scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
    });
  }, [inputText, replyingToId, authorName, thread, asset.id, onThreadChange]);

  // Delete a single comment (and its replies)
  const handleDelete = useCallback((commentId: string) => {
    const idsToRemove = new Set<string>();
    idsToRemove.add(commentId);
    // Also remove child replies
    thread.forEach((c) => { if (c.parentId === commentId) idsToRemove.add(c.id); });
    const updated = thread.filter((c) => !idsToRemove.has(c.id));
    onThreadChange(asset.id, updated);
  }, [thread, asset.id, onThreadChange]);

  // Change status on a single comment
  const handleStatusChange = useCallback((commentId: string, newStatus: 'New' | 'Team reply' | 'Done') => {
    const updated = thread.map((c) => c.id === commentId ? { ...c, status: newStatus } : c);
    onThreadChange(asset.id, updated);
  }, [thread, asset.id, onThreadChange]);

  // Start replying to a specific comment
  const handleReply = useCallback((commentId: string) => {
    setReplyingToId(commentId);
    requestAnimationFrame(() => textareaRef.current?.focus());
  }, []);

  // Render a single comment card — all styling via CSS classes
  const renderComment = (comment: CommentEntry, isReply = false) => {
    const replies = repliesMap[comment.id] || [];
    return (
      <div key={comment.id}>
        <div className={`pcm-comment${isReply ? ' is-reply' : ''}`}>
          {/* Header row: author + status */}
          <div className="pcm-comment-header">
            <span className="pcm-comment-author">{comment.author}</span>
            {!isReadOnly && (
              <select
                value={comment.status}
                onChange={(e) => handleStatusChange(comment.id, e.target.value as any)}
                className={`pcm-comment-status ${statusClass(comment.status)}`}
              >
                <option value="New">New</option>
                <option value="Team reply">Team reply</option>
                <option value="Done">Done</option>
              </select>
            )}
          </div>

          {/* Comment body text */}
          <p className="pcm-comment-text">{comment.text}</p>

          {/* Footer: timestamp + actions */}
          <div className="pcm-comment-footer">
            <span className="pcm-comment-time">{relativeTime(comment.createdAt)}</span>
            <div className="pcm-comment-actions">
              {!isReadOnly && (
                <button onClick={() => handleReply(comment.id)} className="pcm-comment-reply-btn">
                  <CornerDownRight />
                  Reply
                </button>
              )}
              {!isReadOnly && (
                <button onClick={() => handleDelete(comment.id)} className="pcm-comment-delete-btn" title="Delete comment">
                  <X />
                </button>
              )}
            </div>
          </div>
        </div>

        {/* Render nested replies */}
        {replies.map((reply) => renderComment(reply, true))}
      </div>
    );
  };

  // Find the comment being replied to (for composer label)
  const replyingToComment = replyingToId ? thread.find((c) => c.id === replyingToId) : null;

  return (
    <div className="pcm-inspector-backdrop" onClick={handleBackdropClick}>
      <div ref={drawerRef} className="pcm-inspector-drawer">

        {/* ─── Header ─── */}
        <div className="pcm-inspector-header">
          <div className="pcm-inspector-header-row">
            <div className="pcm-inspector-title">
              <MessageSquare />
              <span>Comments</span>
              {thread.length > 0 && (
                <span className="pcm-inspector-count">{thread.length}</span>
              )}
            </div>
            <button onClick={onClose} className="pcm-inspector-close" title="Close panel">
              <X />
            </button>
          </div>

          {/* Mini asset preview */}
          <div className="pcm-inspector-preview">
            {type === 'media' && asset.url && (
              <div className="pcm-inspector-thumb">
                {isVideo ? (
                  <video src={asset.url} preload="metadata" />
                ) : (
                  <img src={asset.url} alt="preview" />
                )}
              </div>
            )}
            <div>
              <div className="pcm-inspector-asset-type">
                {type === 'media' ? (isVideo ? 'Video' : 'Image') : 'Ad Copy'}
              </div>
              <div className="pcm-inspector-asset-name">
                {type === 'media' ? (asset.name || 'Creative Asset') : (asset.headline || 'Ad Copy')}
              </div>
            </div>
          </div>
        </div>

        {/* ─── Scrollable Thread ─── */}
        <div ref={scrollRef} className="pcm-inspector-thread">
          {topLevel.length === 0 ? (
            <div className="pcm-inspector-empty">
              <MessageSquare />
              <div className="pcm-inspector-empty-title">No comments yet</div>
              <p className="pcm-inspector-empty-text">
                Start a conversation by adding a comment below.
              </p>
            </div>
          ) : (
            topLevel.map((c) => renderComment(c))
          )}
        </div>

        {/* ─── Composer ─── */}
        {!isReadOnly && (
          <div className="pcm-inspector-composer">
            {/* Reply-to indicator */}
            {replyingToComment && (
              <div className="pcm-reply-indicator">
                <CornerDownRight />
                <span className="pcm-reply-indicator-text">
                  Replying to <strong>{replyingToComment.author}</strong>
                </span>
                <button onClick={() => setReplyingToId(null)} className="pcm-reply-indicator-close">
                  <X />
                </button>
              </div>
            )}

            <div className="pcm-inspector-input-row">
              <textarea
                ref={textareaRef}
                value={inputText}
                onChange={(e) => setInputText(e.target.value)}
                placeholder={replyingToId ? 'Write a reply...' : 'Add a comment...'}
                rows={2}
                className="pcm-inspector-textarea"
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                    e.preventDefault();
                    handleSubmitComment();
                  }
                }}
              />
              <button
                onClick={handleSubmitComment}
                disabled={!inputText.trim()}
                className="pcm-inspector-send"
                title="Send comment (⌘+Enter)"
              >
                <Send />
              </button>
            </div>
            <div className="pcm-inspector-hint">⌘+Enter to send</div>
          </div>
        )}
      </div>
    </div>
  );
}
