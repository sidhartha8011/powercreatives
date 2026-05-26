import { useState, useEffect, useRef } from 'react';
import { X, MessageSquare, CornerDownRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { CreativeAsset, isVideoAsset } from './ClientReviewPage';

interface ClientCommentInspectorProps {
  asset: CreativeAsset;
  type: 'media' | 'copy';
  comment?: string;
  status?: 'New' | 'Team reply' | 'Done';
  onClose: () => void;
  onSave: (text: string) => void;
  onRemove: () => void;
  onStatusChange: (status: 'New' | 'Team reply' | 'Done') => void;
}

export function ClientCommentInspector({
  asset,
  type,
  comment,
  status = 'New',
  onClose,
  onSave,
  onRemove,
  onStatusChange,
}: ClientCommentInspectorProps) {
  const [inputText, setInputText] = useState(comment || '');
  const drawerRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // Close on Escape key press
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [onClose]);

  // Handle click outside drawer to close
  const handleBackdropClick = (e: React.MouseEvent) => {
    if (drawerRef.current && !drawerRef.current.contains(e.target as Node)) {
      onClose();
    }
  };

  const handleSaveNote = () => {
    if (inputText.trim()) {
      onSave(inputText.trim());
    } else {
      onRemove();
    }
  };

  const handleReplyClick = () => {
    // Focus textarea to simulate starting a thread reply
    if (textareaRef.current) {
      textareaRef.current.focus();
    }
  };

  const isVideo = type === 'media' && isVideoAsset(asset);

  return (
    <div
      className="fixed inset-0 z-50 flex justify-end bg-slate-900/10 backdrop-blur-[2px] transition-all duration-300 animate-fade-in"
      onClick={handleBackdropClick}
    >
      <div
        ref={drawerRef}
        className="w-full max-w-[420px] h-full bg-white/70 backdrop-blur-xl border-l border-white/30 shadow-[0_0_40px_rgba(0,0,0,0.06)] flex flex-col justify-between transform translate-x-0 transition-transform duration-300 ease-out select-none"
      >
        {/* Drawer Header */}
        <div className="p-5 border-b border-slate-100 flex flex-col gap-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <MessageSquare className="w-4 h-4 text-slate-500" />
              <span className="font-semibold text-xs text-slate-800 uppercase tracking-wider">Comment Inspector</span>
            </div>
            <button
              onClick={onClose}
              className="w-5 h-5 rounded-full hover:bg-slate-100 flex items-center justify-center text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
              title="Close panel"
            >
              <X className="w-3.5 h-3.5" />
            </button>
          </div>

          {/* Mini Visual Preview Card inside Header */}
          <div className="p-3 bg-white/40 border border-slate-150/40 rounded-xl flex items-center gap-3">
            {type === 'media' && asset.url && (
              <div className="w-14 h-14 bg-slate-100 rounded-lg overflow-hidden shrink-0 border border-slate-100">
                {isVideo ? (
                  <video src={asset.url} className="w-full h-full object-cover" preload="metadata" />
                ) : (
                  <img src={asset.url} alt="asset preview" className="w-full h-full object-cover" />
                )}
              </div>
            )}
            <div className="flex-1 min-w-0">
              <div className="text-[10px] font-bold text-slate-400 uppercase tracking-widest">
                {type === 'media' ? (isVideo ? 'Video Ad' : 'Image Ad') : 'Ad Copy Variation'}
              </div>
              <div className="text-[12px] font-semibold text-slate-700 truncate mt-0.5">
                {type === 'media' ? (asset.name || 'Creative Asset') : (asset.headline || 'Primary ad copy text')}
              </div>
              {type === 'copy' && asset.body && (
                <div className="text-[10.5px] text-slate-400 truncate mt-0.5 italic">
                  "{asset.body}"
                </div>
              )}
            </div>
          </div>
        </div>

        {/* Scrollable Comments Thread */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">
          {!comment ? (
            <div className="h-full flex flex-col items-center justify-center text-center gap-2 py-20">
              <MessageSquare className="w-8 h-8 text-slate-200 stroke-[1.5]" />
              <div className="text-xs font-semibold text-slate-400">No feedback notes yet</div>
              <p className="text-[11px] text-slate-400/80 max-w-[200px] leading-relaxed">
                Add a comment in the composer below to note down tweaks.
              </p>
            </div>
          ) : (
            <div className="p-4 rounded-xl border border-slate-100 bg-white/45 shadow-[0_2px_8px_rgba(0,0,0,0.01)] flex flex-col gap-3">
              <div className="flex items-center justify-between">
                <span className="font-semibold text-xs text-slate-800">Reviewer Feedback</span>
                
                {/* Clean, minimalist Dropdown Status Selector */}
                <select
                  value={status}
                  onChange={(e) => onStatusChange(e.target.value as any)}
                  className="text-[9.5px] font-bold tracking-wide uppercase px-2.5 py-0.5 rounded-full border-none cursor-pointer outline-none transition-all appearance-none"
                  style={{
                    backgroundColor: status === 'New' ? '#eff6ff' : status === 'Team reply' ? '#fffbeb' : '#f0fdf4',
                    color: status === 'New' ? '#2563eb' : status === 'Team reply' ? '#d97706' : '#16a34a',
                    border: '1px solid currentColor',
                    borderWidth: '0.5px',
                  }}
                >
                  <option value="New">New</option>
                  <option value="Team reply">Team reply</option>
                  <option value="Done">Done</option>
                </select>
              </div>

              {/* Comment text body */}
              <p className="text-[12.5px] text-slate-600 font-normal leading-relaxed break-words">
                {comment}
              </p>

              {/* Timestamp & Reply trigger */}
              <div className="flex justify-between items-center text-[10.5px] text-slate-400 mt-1 pt-2 border-t border-slate-50/50">
                <span className="font-medium">Today</span>
                <button
                  onClick={handleReplyClick}
                  className="hover:text-slate-700 transition-colors font-semibold flex items-center gap-1 cursor-pointer"
                >
                  <CornerDownRight className="w-3 h-3" />
                  Reply in thread
                </button>
              </div>
            </div>
          )}
        </div>

        {/* Bottom borderless Text composer */}
        <div className="p-4 border-t border-slate-100 bg-white/30 backdrop-blur-md">
          <Textarea
            ref={textareaRef}
            value={inputText}
            onChange={(e) => setInputText(e.target.value)}
            placeholder="Write feedback tweaks... (e.g. replace tagline, adjust lighting...)"
            rows={3}
            className="text-xs bg-white/60 resize-none border border-slate-200 focus:border-slate-300 focus:ring-0 focus:ring-offset-0 placeholder:text-slate-400/90 rounded-lg p-2.5 shadow-sm"
          />
          <div className="flex justify-end gap-1.5 mt-3">
            {comment && (
              <Button
                size="sm"
                variant="ghost"
                className="h-7 text-[11px] font-medium text-red-500 hover:text-red-700 hover:bg-red-50 rounded-full px-3 mr-auto"
                onClick={() => {
                  onRemove();
                  setInputText('');
                }}
              >
                Delete Note
              </Button>
            )}
            <Button
              size="sm"
              variant="ghost"
              className="h-7 text-[11px] font-medium text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-full px-3"
              onClick={onClose}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-7 text-[11px] font-semibold bg-slate-900 text-white hover:bg-slate-800 rounded-full px-4"
              onClick={handleSaveNote}
            >
              Save Note
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
