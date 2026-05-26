import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { X, MessageSquare, CornerDownRight, Send } from 'lucide-react';
import { Textarea } from '@/components/ui/textarea';

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
 * comments for a single creative asset. Supports:
 *  - Adding new top-level comments
 *  - Replying in-thread (parentId linking)
 *  - Per-comment status (New / Team reply / Done)
 *  - Deleting individual comments
 *  - Real ISO timestamps with relative display
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

  // Render a single comment card
  const renderComment = (comment: CommentEntry, isReply = false) => {
    const replies = repliesMap[comment.id] || [];
    return (
      <div key={comment.id} className={isReply ? 'ml-6 mt-2' : ''}>
        <div
          className="p-3.5 rounded-xl flex flex-col gap-2"
          style={{
            border: `1px solid ${isReply ? 'rgba(0,0,0,0.04)' : 'rgba(0,0,0,0.06)'}`,
            background: isReply ? 'rgba(0,0,0,0.015)' : 'rgba(255,255,255,0.8)',
            boxShadow: '0 1px 4px rgba(0,0,0,0.03)',
          }}
        >
          {/* Header row: author + status */}
          <div className="flex items-center justify-between">
            <span className="font-semibold text-[11.5px]" style={{ color: 'var(--ink)' }}>{comment.author}</span>
            {!isReadOnly && (
              <select
                value={comment.status}
                onChange={(e) => handleStatusChange(comment.id, e.target.value as any)}
                className="text-[9px] font-bold tracking-wide uppercase px-2 py-0.5 rounded-full cursor-pointer outline-none transition-all appearance-none"
                style={{
                  fontFamily: 'inherit',
                  backgroundColor: comment.status === 'New' ? '#eff6ff' : comment.status === 'Team reply' ? '#fffbeb' : '#f0fdf4',
                  color: comment.status === 'New' ? '#2563eb' : comment.status === 'Team reply' ? '#d97706' : '#16a34a',
                  border: `0.5px solid ${comment.status === 'New' ? '#bfdbfe' : comment.status === 'Team reply' ? '#fde68a' : '#bbf7d0'}`,
                }}
              >
                <option value="New">New</option>
                <option value="Team reply">Team reply</option>
                <option value="Done">Done</option>
              </select>
            )}
          </div>

          {/* Comment body text */}
          <p className="text-[12.5px] leading-relaxed break-words whitespace-pre-wrap" style={{ color: 'var(--ink-2)' }}>{comment.text}</p>

          {/* Footer: timestamp + actions */}
          <div
            className="flex items-center justify-between text-[10px] pt-1.5"
            style={{ color: 'var(--ink-4)', borderTop: '1px solid rgba(0,0,0,0.03)' }}
          >
            <span className="font-medium">{relativeTime(comment.createdAt)}</span>
            <div className="flex items-center gap-2">
              {!isReadOnly && (
                <button
                  onClick={() => handleReply(comment.id)}
                  className="flex items-center gap-1 px-2.5 py-0.5 active:scale-95 transition-all rounded-full font-semibold cursor-pointer"
                  style={{
                    background: 'rgba(0,0,0,0.035)',
                    color: 'var(--ink-3)',
                    border: '0.5px solid rgba(0,0,0,0.04)',
                  }}
                >
                  <CornerDownRight className="w-2.5 h-2.5" />
                  Reply
                </button>
              )}
              {!isReadOnly && (
                <button
                  onClick={() => handleDelete(comment.id)}
                  className="w-4 h-4 rounded-full flex items-center justify-center transition-all cursor-pointer"
                  style={{ color: 'rgba(0,0,0,0.15)' }}
                  title="Delete comment"
                >
                  <X className="w-2.5 h-2.5" />
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
    <div
      className="fixed inset-0 z-50 flex justify-end transition-all duration-300 animate-fade-in"
      onClick={handleBackdropClick}
      style={{
        /* ── Match client review page font + palette exactly ── */
        fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
        WebkitFontSmoothing: 'antialiased',
        MozOsxFontSmoothing: 'grayscale',
        /* Warm ink palette from the review page */
        ['--ink' as any]: '#1d1d1f',
        ['--ink-2' as any]: '#4a4239',
        ['--ink-3' as any]: '#6f6a64',
        ['--ink-4' as any]: '#8a7d6d',
        ['--line' as any]: 'rgba(0,0,0,0.06)',
        backgroundColor: 'rgba(0,0,0,0.08)',
      }}
    >
      <div
        ref={drawerRef}
        className="w-full max-w-[420px] h-full flex flex-col transform translate-x-0 transition-transform duration-300 ease-out select-none"
        style={{
          background: 'rgba(255,255,255,0.97)',
          backdropFilter: 'blur(40px)',
          WebkitBackdropFilter: 'blur(40px)',
          borderLeft: '1px solid rgba(0,0,0,0.06)',
          boxShadow: '0 0 50px rgba(0,0,0,0.1)',
        }}
      >
        {/* ─── Header ─── */}
        <div className="p-5 flex flex-col gap-4 shrink-0" style={{ borderBottom: '1px solid var(--line)' }}>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <MessageSquare className="w-4 h-4" style={{ color: 'var(--ink-3)' }} />
              <span className="font-semibold text-xs uppercase" style={{ color: 'var(--ink)', letterSpacing: '0.08em' }}>Comments</span>
              {thread.length > 0 && (
                <span
                  className="ml-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                  style={{ color: 'var(--ink-4)', background: 'rgba(0,0,0,0.04)' }}
                >
                  {thread.length}
                </span>
              )}
            </div>
            <button
              onClick={onClose}
              className="w-6 h-6 rounded-full flex items-center justify-center transition-colors cursor-pointer"
              style={{ color: 'var(--ink-4)' }}
              title="Close panel"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {/* Mini asset preview */}
          <div
            className="p-3 rounded-xl flex items-center gap-3"
            style={{ background: 'rgba(255,255,255,0.6)', border: '1px solid var(--line)' }}
          >
            {type === 'media' && asset.url && (
              <div
                className="w-12 h-12 rounded-lg overflow-hidden shrink-0"
                style={{ background: 'rgba(0,0,0,0.03)', border: '1px solid var(--line)' }}
              >
                {isVideo ? (
                  <video src={asset.url} className="w-full h-full object-cover" preload="metadata" />
                ) : (
                  <img src={asset.url} alt="preview" className="w-full h-full object-cover" />
                )}
              </div>
            )}
            <div className="flex-1 min-w-0">
              <div className="text-[10px] font-bold uppercase" style={{ color: 'var(--ink-4)', letterSpacing: '0.1em' }}>
                {type === 'media' ? (isVideo ? 'Video' : 'Image') : 'Ad Copy'}
              </div>
              <div className="text-[12px] font-semibold truncate mt-0.5" style={{ color: 'var(--ink)' }}>
                {type === 'media' ? (asset.name || 'Creative Asset') : (asset.headline || 'Ad Copy')}
              </div>
            </div>
          </div>
        </div>

        {/* ─── Scrollable Thread ─── */}
        <div ref={scrollRef} className="flex-1 overflow-y-auto p-5 space-y-3">
          {topLevel.length === 0 ? (
            <div className="h-full flex flex-col items-center justify-center text-center gap-2 py-20">
              <MessageSquare className="w-8 h-8 stroke-[1.5]" style={{ color: 'rgba(0,0,0,0.1)' }} />
              <div className="text-xs font-semibold" style={{ color: 'var(--ink-4)' }}>No comments yet</div>
              <p className="text-[11px] max-w-[200px] leading-relaxed" style={{ color: 'var(--ink-4)', opacity: 0.8 }}>
                Start a conversation by adding a comment below.
              </p>
            </div>
          ) : (
            topLevel.map((c) => renderComment(c))
          )}
        </div>

        {/* ─── Composer ─── */}
        {!isReadOnly && (
          <div className="p-4 shrink-0" style={{ borderTop: '1px solid var(--line)', background: 'rgba(255,255,255,0.5)', backdropFilter: 'blur(20px)' }}>
            {/* Reply-to indicator */}
            {replyingToComment && (
              <div
                className="flex items-center gap-2 mb-2 px-2 py-1.5 rounded-lg text-[10.5px]"
                style={{ background: 'rgba(37,99,235,0.06)', border: '1px solid rgba(37,99,235,0.12)' }}
              >
                <CornerDownRight className="w-3 h-3 shrink-0" style={{ color: 'rgba(37,99,235,0.5)' }} />
                <span className="font-medium truncate" style={{ color: 'rgba(37,99,235,0.8)' }}>
                  Replying to <strong>{replyingToComment.author}</strong>
                </span>
                <button
                  onClick={() => setReplyingToId(null)}
                  className="ml-auto cursor-pointer"
                  style={{ color: 'rgba(37,99,235,0.5)' }}
                >
                  <X className="w-3 h-3" />
                </button>
              </div>
            )}

            <div className="flex gap-2">
              <Textarea
                ref={textareaRef}
                value={inputText}
                onChange={(e) => setInputText(e.target.value)}
                placeholder={replyingToId ? 'Write a reply...' : 'Add a comment...'}
                rows={2}
                className="flex-1 text-xs resize-none rounded-lg p-2.5"
                style={{
                  fontFamily: 'inherit',
                  background: 'white',
                  border: '1px solid rgba(0,0,0,0.08)',
                  color: 'var(--ink)',
                  outline: 'none',
                }}
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
                className="self-end h-9 w-9 rounded-full text-white flex items-center justify-center transition-all cursor-pointer disabled:cursor-not-allowed shrink-0"
                style={{
                  background: inputText.trim() ? 'var(--ink)' : 'rgba(0,0,0,0.15)',
                }}
                title="Send comment (⌘+Enter)"
              >
                <Send className="w-3.5 h-3.5" />
              </button>
            </div>
            <div className="text-[9.5px] mt-1.5 text-right" style={{ color: 'var(--ink-4)' }}>⌘+Enter to send</div>
          </div>
        )}
      </div>
    </div>
  );
}
