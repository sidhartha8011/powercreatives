import { useState, useCallback } from 'react';
import { CheckCircle2, MessageSquare, Check, X } from 'lucide-react';
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
    <div
      className={`group rounded-2xl overflow-hidden bg-white/70 backdrop-blur-md border transition-all duration-300 flex flex-col relative ${
        isApproved
          ? 'border-emerald-500 bg-gradient-to-b from-emerald-500/5 to-white/70 shadow-emerald-500/5 shadow-md'
          : 'border-border/60 shadow-sm shadow-slate-100 hover:translate-y-[-2px] hover:shadow-md'
      }`}
    >
      {/* ─── MEDIA CARD LAYOUT ─── */}
      {type === 'media' && (
        <div className="relative aspect-video bg-black overflow-hidden select-none">
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
              className="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-103"
            />
          )}

          {/* Asset Badge */}
          <span className="absolute left-3 top-3 bg-black/60 backdrop-blur-md border border-white/10 text-white rounded-full px-2.5 py-1 text-[10px] font-semibold tracking-wide uppercase flex items-center gap-1.5 shadow-sm">
            {isVideo ? (
              <>
                <svg className="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                Video
              </>
            ) : (
              <>
                <svg className="w-3 h-3 text-white" fill="none" stroke="currentColor" strokeWidth="2.2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                Image
              </>
            )}
          </span>

          {/* Approved Checked State Overlay */}
          {isApproved && (
            <div className="absolute inset-0 bg-emerald-500/10 backdrop-blur-xs flex items-center justify-center pointer-events-none transition-all duration-300">
              <span className="bg-emerald-500 border border-emerald-400 text-white rounded-full px-3.5 py-1.5 flex items-center gap-1 text-[11px] font-bold shadow-md shadow-emerald-500/20">
                <Check className="w-4 h-4" /> Approved
              </span>
            </div>
          )}
        </div>
      )}

      {/* ─── AD COPY CARD LAYOUT (Social Ad Mockup) ─── */}
      {type === 'copy' && (
        <div className="flex flex-col flex-1">
          {/* Ad Mockup Brand Header */}
          <div className="p-3.5 flex items-center gap-3 border-b border-border/40 select-none">
            {brandLogoUrl ? (
              <img
                src={brandLogoUrl}
                alt="Brand logo"
                className="w-8 h-8 rounded-full border border-border/80 bg-white object-contain"
              />
            ) : (
              <div className="w-8 h-8 rounded-full bg-gradient-to-br from-indigo-400 to-purple-500 border border-white/20 flex items-center justify-center font-bold text-white text-xs">
                {brandName[0] || 'C'}
              </div>
            )}
            <div>
              <span className="text-[12px] font-bold block leading-tight text-foreground/90">
                {brandName}
              </span>
              <span className="text-[10px] text-muted-foreground font-medium block">
                Sponsored
              </span>
            </div>
          </div>

          {/* Ad Text / Body */}
          <div className="px-3.5 py-2.5 text-xs text-slate-800 leading-relaxed font-sans flex-1 bg-white/40">
            <p className="whitespace-pre-wrap">{asset.body}</p>
          </div>

          {/* Paired Mockup Media Asset */}
          {pairedMediaUrl && (
            <div className="relative aspect-video bg-black overflow-hidden border-t border-b border-border/40 select-none">
              <img
                src={pairedMediaUrl}
                alt="Ad creative visual"
                className="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-102"
              />
              {isApproved && (
                <div className="absolute inset-0 bg-emerald-500/10 backdrop-blur-xs flex items-center justify-center pointer-events-none">
                  <span className="bg-emerald-500 border border-emerald-400 text-white rounded-full px-3.5 py-1.5 flex items-center gap-1 text-[11px] font-bold shadow-md shadow-emerald-500/20">
                    <Check className="w-3.5 h-3.5" /> Approved
                  </span>
                </div>
              )}
            </div>
          )}

          {/* Ad Headline & CTA bottom footer (Facebook layout) */}
          <div className="p-3.5 flex items-center justify-between bg-slate-50 border-b border-border/30 select-none">
            <div className="flex-1 min-w-0 pr-3">
              <span className="text-[9px] uppercase tracking-wider block font-semibold text-muted-foreground truncate mb-0.5">
                {asset.description || 'DYNAMIC PREVIEW'}
              </span>
              <span className="text-xs font-bold block truncate text-slate-800 leading-tight">
                {asset.headline}
              </span>
            </div>
            {asset.cta && (
              <span className="text-[10px] font-bold uppercase px-3 py-1.5 border border-border bg-white rounded-md select-none text-slate-700 shadow-sm leading-none">
                {asset.cta}
              </span>
            )}
          </div>
        </div>
      )}

      {/* Card Info Details (Media title / specs) */}
      {type === 'media' && (
        <div className="p-3.5 pb-2 flex-grow select-none">
          <div className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground/80">
            <span>Meta Feed</span>
            <span className="w-1 h-1 bg-muted-foreground/40 rounded-full" />
            <span>4&times;5 Ratio</span>
          </div>
          <h3 className="mt-1 text-xs font-bold text-foreground truncate">
            {asset.name || 'Creative Asset'}
          </h3>
        </div>
      )}

      {/* Comment Display Section (Feedback) */}
      {comment && !isEditingComment && (
        <div className="px-3.5 pb-3">
          <div 
            className="p-2.5 rounded-xl border italic text-xs flex justify-between items-start gap-3 shadow-inner"
            style={{ background: '#fffbeb', borderColor: '#fef3c7', borderLeft: '3.5px solid #f59e0b', color: '#92400e' }}
          >
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
        <div className="px-3.5 pb-3.5 pt-1.5 border-t border-border/30 bg-muted/20">
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
        <div className="p-3 bg-white/40 flex items-center justify-between border-t border-border/40 select-none">
          <div className="flex items-center gap-1.5">
            <Button
              type="button"
              disabled={isSubmitted}
              size="sm"
              variant={isApproved ? "default" : "outline"}
              className={`h-8 gap-1.5 text-[11px] font-bold transition-all shadow-xs cursor-pointer select-none ${
                isApproved 
                  ? 'bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-600' 
                  : 'hover:bg-muted/70 hover:text-foreground text-slate-700'
              }`}
              onClick={handleToggleApprove}
            >
              <CheckCircle2 className="w-3.5 h-3.5" />
              {isApproved ? 'Approved' : `Approve ${type === 'media' ? 'Media' : 'Copy'}`}
            </Button>
            
            <Button
              type="button"
              disabled={isSubmitted}
              size="sm"
              variant="outline"
              className="h-8 gap-1 text-[11px] font-semibold text-slate-600 hover:bg-muted/70 shadow-xs cursor-pointer select-none"
              onClick={handleOpenComment}
            >
              <MessageSquare className="w-3.5 h-3.5" />
              {comment ? 'Edit Note' : 'Add Note'}
            </Button>
          </div>

          {/* Mini pulse awaiting indicator */}
          <span className="text-[10px] text-muted-foreground/70 font-semibold inline-flex items-center gap-1 leading-none select-none mr-1">
            <span className={`w-1.5 h-1.5 rounded-full ${isApproved ? 'bg-emerald-500' : 'bg-slate-300 animate-pulse'}`} />
            {isApproved ? 'Approved' : 'Awaiting'}
          </span>
        </div>
      )}
    </div>
  );
}
