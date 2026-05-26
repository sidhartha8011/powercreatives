import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { X, MessageSquare, CornerDownRight, Send, Loader2, Image as ImageIcon } from 'lucide-react';
import type { CommentEntry, CommentStatus } from '../types';
import { isVideoAsset } from './ClientReviewPage';
import { useImageUpload } from '@/hooks/useImageUpload';
import { toast } from 'sonner';

// Re-export CommentEntry for consumers that import from this file
export type { CommentEntry } from '../types';

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
function statusClass(status: CommentStatus): string {
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
  isTeamMember?: boolean;
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
  isTeamMember = false,
  onClose,
  onThreadChange,
}: ClientCommentInspectorProps) {
  const [inputText, setInputText] = useState('');
  const [replyingToId, setReplyingToId] = useState<string | null>(null);
  const [draftAttachments, setDraftAttachments] = useState<string[]>([]);
  const [lightboxUrl, setLightboxUrl] = useState<string | null>(null);
  const drawerRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const scrollRef = useRef<HTMLDivElement>(null);

  const { isUploading, uploadImage } = useImageUpload({
    compress: true,
    maxWidth: 800,
    maxHeight: 800,
    quality: 0.75,
  });

  const handlePaste = useCallback(async (e: React.ClipboardEvent<HTMLTextAreaElement>) => {
    const items = e.clipboardData.items;
    for (let i = 0; i < items.length; i++) {
      if (items[i].type.indexOf('image') !== -1) {
        e.preventDefault();
        const file = items[i].getAsFile();
        if (!file) continue;

        const toastId = toast.loading('Uploading screenshot from clipboard...');
        try {
          const url = await uploadImage(file);
          setDraftAttachments((prev) => [...prev, url]);
          toast.success('Screenshot attached successfully', { id: toastId });
        } catch {
          toast.error('Failed to upload pasted screenshot', { id: toastId });
        }
      }
    }
  }, [uploadImage]);

  const isVideo = type === 'media' && isVideoAsset(asset);

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

  // Dynamic seen/unseen marking effect
  useEffect(() => {
    const userRole = isTeamMember ? 'team' : 'client';
    
    // Find unread comments that are not written by the current user role
    const unread = thread.filter((c) => {
      const isAuthorTeam = c.author === 'Team';
      const isCurrentTeam = isTeamMember;
      const isSelf = (isCurrentTeam && isAuthorTeam) || (!isCurrentTeam && !isAuthorTeam);
      if (isSelf) return false;
      return !c.readBy?.includes(userRole);
    });

    if (unread.length === 0) return;

    // After 1.5 seconds, mark all unread comments in this thread as read by current user role
    const timer = setTimeout(() => {
      const updated = thread.map((c) => {
        const isAuthorTeam = c.author === 'Team';
        const isCurrentTeam = isTeamMember;
        const isSelf = (isCurrentTeam && isAuthorTeam) || (!isCurrentTeam && !isAuthorTeam);
        if (isSelf) return c;

        const readArray = c.readBy || [];
        if (!readArray.includes(userRole)) {
          return { ...c, readBy: [...readArray, userRole] };
        }
        return c;
      });
      onThreadChange(asset.id, updated);
    }, 1500);

    return () => clearTimeout(timer);
  }, [thread, asset.id, isTeamMember, onThreadChange]);

  // Add a new comment (top-level or reply)
  const handleSubmitComment = useCallback(() => {
    const text = inputText.trim();
    if (!text && draftAttachments.length === 0) return;

    const userRole = isTeamMember ? 'team' : 'client';

    const entry: CommentEntry = {
      id: crypto.randomUUID(),
      author: authorName || (isTeamMember ? 'Team' : 'Client'),
      text,
      createdAt: new Date().toISOString(),
      status: 'New',
      parentId: replyingToId,
      attachments: draftAttachments.length > 0 ? draftAttachments : undefined,
      readBy: [userRole],
    };

    let updated = [...thread, entry];

    // Dynamic state transition on the root thread comment
    if (replyingToId) {
      const rootId = replyingToId;
      const newStatus = isTeamMember ? 'Team reply' : 'New';
      updated = updated.map((c) => {
        if (c.id === rootId) {
          return {
            ...c,
            status: newStatus,
            // Reset readBy so the OTHER role gets an unread notification trigger
            readBy: [userRole],
          };
        }
        return c;
      });
    }

    onThreadChange(asset.id, updated);
    setInputText('');
    setDraftAttachments([]);
    setReplyingToId(null);

    // Scroll to bottom after adding
    requestAnimationFrame(() => {
      scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
    });
  }, [inputText, replyingToId, authorName, thread, asset.id, onThreadChange, draftAttachments, isTeamMember]);

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
  const handleStatusChange = useCallback((commentId: string, newStatus: CommentStatus) => {
    const userRole = isTeamMember ? 'team' : 'client';
    const updated = thread.map((c) => c.id === commentId ? { ...c, status: newStatus, readBy: [userRole] } : c);
    onThreadChange(asset.id, updated);
  }, [thread, asset.id, onThreadChange, isTeamMember]);

  // Start replying to a specific comment
  const handleReply = useCallback((commentId: string) => {
    setReplyingToId(commentId);
    requestAnimationFrame(() => textareaRef.current?.focus());
  }, []);

  // Render a single comment card — all styling via CSS classes
  const renderComment = (comment: CommentEntry, isReply = false) => {
    const replies = repliesMap[comment.id] || [];

    const userRole = isTeamMember ? 'team' : 'client';
    const isAuthorTeam = comment.author === 'Team';
    const isCurrentTeam = isTeamMember;
    const isSelf = (isCurrentTeam && isAuthorTeam) || (!isCurrentTeam && !isAuthorTeam);
    const isUnread = !isSelf && !comment.readBy?.includes(userRole);

    return (
      <div key={comment.id}>
        <div className={`pcm-comment${isReply ? ' is-reply' : ''}${isUnread ? ' is-unread' : ''}`}>
          {/* Header row: author + status */}
          <div className="pcm-comment-header">
            <div className="pcm-comment-author-row">
              <span className="pcm-comment-author">{comment.author}</span>
              {isUnread && <span className="pcm-comment-unread-dot" title="Unread comment" />}
            </div>
            {!isReadOnly && !isReply && (
              <select
                value={comment.status}
                onChange={(e) => handleStatusChange(comment.id, e.target.value as CommentStatus)}
                className={`pcm-comment-status ${statusClass(comment.status)}`}
              >
                <option value="New">New</option>
                <option value="Team reply">Team reply</option>
                <option value="Done">Done</option>
              </select>
            )}
          </div>

          {/* Comment body text */}
          {comment.text && <p className="pcm-comment-text">{comment.text}</p>}

          {/* Comment attachments */}
          {comment.attachments && comment.attachments.length > 0 && (
            <div className="pcm-comment-attachments" onClick={(e) => e.stopPropagation()}>
              {comment.attachments.map((url, index) => (
                <div 
                  key={index} 
                  className="pcm-comment-attachment-thumb"
                  onClick={() => setLightboxUrl(url)}
                >
                  <img src={url} alt={`Attachment ${index + 1}`} />
                </div>
              ))}
            </div>
          )}

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
                onPaste={handlePaste}
                placeholder={replyingToId ? 'Write a reply... or paste image' : 'Add a comment... or paste image'}
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
                disabled={isUploading || (!inputText.trim() && draftAttachments.length === 0)}
                className="pcm-inspector-send"
                title="Send comment (⌘+Enter)"
              >
                <Send />
              </button>
            </div>

            {/* Loading attachment indicator */}
            {isUploading && (
              <div className="pcm-composer-loading-attachment">
                <Loader2 className="w-3.5 h-3.5 animate-spin text-[#2563eb] flex-shrink-0" />
                <span>Uploading pasted screenshot...</span>
              </div>
            )}

            {/* Draft attachments preview */}
            {draftAttachments.length > 0 && (
              <div className="pcm-composer-draft-attachments">
                {draftAttachments.map((url, index) => (
                  <div key={index} className="pcm-composer-draft-thumb">
                    <img src={url} alt="Draft screenshot" onClick={() => setLightboxUrl(url)} />
                    <button 
                      type="button" 
                      onClick={() => setDraftAttachments((prev) => prev.filter((_, idx) => idx !== index))}
                      className="pcm-composer-draft-thumb-remove"
                      title="Remove image"
                    >
                      <X className="w-3 h-3" />
                    </button>
                  </div>
                ))}
              </div>
            )}
            <div className="pcm-inspector-hint">⌘+Enter to send · Paste screenshots directly inside input</div>
          </div>
        )}
      </div>

      {/* ─── ATTACHMENT LIGHTBOX OVERLAY ─── */}
      {lightboxUrl && createPortal(
        <div
          className="pcm-lightbox"
          onClick={() => setLightboxUrl(null)}
          role="dialog"
          aria-label="Image preview"
          style={{ zIndex: 100000 }}
        >
          <img
            src={lightboxUrl}
            alt="Full size preview"
            className="pcm-lightbox-img"
            onClick={(e) => e.stopPropagation()}
          />
          <button
            type="button"
            className="pcm-lightbox-close"
            onClick={() => setLightboxUrl(null)}
            aria-label="Close preview"
          >
            <X className="w-5 h-5" />
          </button>
        </div>,
        document.body
      )}
    </div>
  );
}
