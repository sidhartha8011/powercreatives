import { useState, useCallback } from 'react';
import { CheckCircle2, MessageSquare, Check, X, Video, Image as ImageIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

export interface CreativeAsset {
  id: string;
  url?: string;
  body?: string;
  headline?: string;
  description?: string;
  cta?: string;
  mimeType?: string;
  [key: string]: any;
}

interface CreativeAssetCardProps {
  asset: CreativeAsset;
  type: 'media' | 'copy';
  isApproved: boolean;
  comment?: string;
  onApprove: (id: string) => void;
  onCommentSave: (id: string, text: string) => void;
  onCommentRemove: (id: string) => void;
  brandLogoUrl?: string | null;
  brandName?: string;
  pairedMediaUrl?: string | null;
  isSubmitted?: boolean;
}

export function CreativeAssetCard({
  asset,
  type,
  isApproved,
  comment,
  onApprove,
  onCommentSave,
  onCommentRemove,
  brandLogoUrl,
  brandName = 'Brand',
  pairedMediaUrl,
  isSubmitted = false
}: CreativeAssetCardProps) {
  const [isEditingComment, setIsEditingComment] = useState(false);
  const [tempCommentText, setTempCommentText] = useState(comment || '');

  // Detect video content by MIME type or URL extension
  const isVideo = type === 'media' && (
    asset.mimeType?.startsWith('video/') ||
    asset.url?.endsWith('.mp4') ||
    asset.url?.endsWith('.mov') ||
    asset.url?.endsWith('.webm')
  );

  const handleToggleApprove = useCallback(() => {
    if (isSubmitted) return;
    onApprove(asset.id);
  }, [asset.id, onApprove, isSubmitted]);

  const handleOpenComment = useCallback(() => {
    if (isSubmitted) return;
    setTempCommentText(comment || '');
    setIsEditingComment(true);
  }, [comment, isSubmitted]);

  const handleSave = useCallback(() => {
    if (tempCommentText.trim()) {
      onCommentSave(asset.id, tempCommentText.trim());
    } else {
      onCommentRemove(asset.id);
    }
    setIsEditingComment(false);
  }, [asset.id, tempCommentText, onCommentSave, onCommentRemove]);

  const handleCancel = useCallback(() => {
    setIsEditingComment(false);
    setTempCommentText(comment || '');
  }, [comment]);

  return (
    <div className={`pcm-card ${isApproved ? 'approved' : ''} ${type === 'copy' ? 'copy' : ''} relative`}>
      
      {/* ─── MEDIA CARD LAYOUT ─── */}
      {type === 'media' && (
        <div className="pcm-card-media select-none">
          {isVideo ? (
            <video 
              src={asset.url} 
              controls 
              className="w-full h-full object-cover"
              preload="metadata"
            />
          ) : (
            <img
              src={asset.url}
              alt="Creative creative mockup"
            />
          )}

          {/* Asset Badge */}
          <span className="pcm-badge">
            {isVideo ? (
              <>
                <Video className="w-2.5 h-2.5 fill-current" />
                Video
              </>
            ) : (
              <>
                <ImageIcon className="w-2.5 h-2.5" />
                Image
              </>
            )}
          </span>
          
          {isVideo && <span className="pcm-duration">0:15</span>}
        </div>
      )}

      {/* ─── AD COPY CARD LAYOUT (Social Ad Mockup) ─── */}
      {type === 'copy' && (
        <div className="pcm-copy-body select-none">
          <div className="pcm-copy-platform">Meta Feed · Primary Text</div>
          <div className="pcm-copy-headline">{asset.headline || 'Creative Headline'}</div>
          <p className="pcm-copy-text">{asset.body}</p>
          
          <div className="pcm-copy-meta">
            <span className="pcm-kv"><b>CTA</b> · {asset.cta || 'Shop Now'}</span>
            <span className="pcm-kv"><b>Tone</b> · Confident, warm</span>
          </div>
        </div>
      )}

      {/* Card Info Details (Media title / specs) */}
      {type === 'media' && (
        <div className="pcm-card-body select-none">
          <div className="pcm-typestrip">
            Meta Feed · 4&times;5 <span className="dot" /> 2400&times;3000
          </div>
          <h3 className="pcm-card-title">
            {asset.name || 'Creative Asset'}
          </h3>
        </div>
      )}

      {/* Comment Display Section (Feedback) */}
      {comment && !isEditingComment && (
        <div className="px-3.5 pb-3 z-10">
          <div className="p-2.5 rounded-xl border border-amber-200/50 border-l-4 border-l-amber-500 bg-amber-50/50 text-amber-900/90 italic text-xs flex justify-between items-start gap-3 shadow-inner">
            <span className="min-w-0 break-words flex-1 leading-relaxed">"{comment}"</span>
            {!isSubmitted && (
              <button
                type="button"
                onClick={() => onCommentRemove(asset.id)}
                className="text-[10px] font-bold hover:underline shrink-0 text-red-500 cursor-pointer"
              >
                Remove
              </button>
            )}
          </div>
        </div>
      )}

      {/* Comment Inline Composer Editor */}
      {isEditingComment && (
        <div className="px-3.5 pb-3.5 pt-1.5 border-t border-border/30 bg-muted/20 z-10">
          <label className="block text-[10px] font-bold uppercase tracking-wider text-muted-foreground mb-1.5">
            Leave tweak feedback note
          </label>
          <Textarea
            value={tempCommentText}
            onChange={(e) => setTempCommentText(e.target.value)}
            placeholder="Type your feedback tweaks here... (e.g. adjust brightness, replace CTA text...)"
            rows={3}
            className="text-xs bg-white resize-none"
            autoFocus
          />
          <div className="flex justify-end gap-1.5 mt-2">
            <Button
              size="sm"
              variant="ghost"
              className="h-7 text-[11px] font-semibold"
              onClick={handleCancel}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-7 text-[11px] font-bold bg-slate-900 hover:bg-slate-800"
              onClick={handleSave}
            >
              Save Note
            </Button>
          </div>
        </div>
      )}

      {/* ─── CARD FOOTER ACTIONS ─── */}
      {!isEditingComment && (
        <div className="pcm-card-actions select-none z-10">
          <button
            type="button"
            disabled={isSubmitted}
            className="pcm-btn pcm-btn-approve"
            onClick={handleToggleApprove}
          >
            <CheckCircle2 className="w-[13px] h-[13px]" />
            Approve
          </button>
          
          <button
            type="button"
            disabled={isSubmitted}
            className="pcm-btn pcm-btn-comment"
            onClick={handleOpenComment}
          >
            <MessageSquare className="w-[13px] h-[13px]" />
            Comment
          </button>

          {/* Mini pulse awaiting indicator */}
          <span className="pcm-status">
            <span className="pulse" />
            {isApproved ? 'Approved' : 'Awaiting'}
          </span>
        </div>
      )}
    </div>
  );
}
