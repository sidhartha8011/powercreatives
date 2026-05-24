import { useMemo, useState } from 'react';
import { Check, CheckCircle2, Clock, Send, Loader2 } from 'lucide-react';

interface ClientStatusToolbarProps {
  activeFilter: 'all' | 'images' | 'videos' | 'copy';
  onFilterChange: (filter: 'all' | 'images' | 'videos' | 'copy') => void;
  counts: {
    all: number;
    images: number;
    videos: number;
    copy: number;
  };
  approvedCount: number;
  totalCount: number;
  createdAt?: string | Date;
  onApproveAll: () => void;
  onConfirmSubmit: (clientName: string) => void;
  isSubmitting?: boolean;
  isConfirmPending?: boolean;
}

export function ClientStatusToolbar({
  activeFilter,
  onFilterChange,
  counts,
  approvedCount,
  totalCount,
  createdAt = new Date(),
  onApproveAll,
  onConfirmSubmit,
  isSubmitting = false,
  isConfirmPending = false
}: ClientStatusToolbarProps) {
  const [showNameInput, setShowNameInput] = useState(false);
  const [clientName, setClientName] = useState('');
  
  /** Review period in milliseconds (4 days) */
  const REVIEW_PERIOD_MS = 4 * 24 * 60 * 60 * 1000;

  // Calculate dynamic due date and countdown
  const { formattedDueDate, remainingDaysText, urgencyClass } = useMemo(() => {
    try {
      const cleanDateStr = typeof createdAt === 'string' ? createdAt.replace(' ', 'T') : createdAt;
      const createdDate = new Date(cleanDateStr);
      if (isNaN(createdDate.getTime())) {
        return { formattedDueDate: 'No deadline', remainingDaysText: '', urgencyClass: '' };
      }
      
      const dueDate = new Date(createdDate.getTime() + REVIEW_PERIOD_MS);
      const today = new Date();
      
      // Dynamic date formatting
      const formatter = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric' });
      const formattedDueDate = `Due ${formatter.format(dueDate)}`;
      
      // Calculate day difference
      const d1 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
      const d2 = new Date(dueDate.getFullYear(), dueDate.getMonth(), dueDate.getDate());
      const diffTime = d2.getTime() - d1.getTime();
      const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));
      
      let remainingDaysText = '';
      let urgencyClass = '';
      
      if (diffDays > 1) {
        remainingDaysText = `· ${diffDays} days left`;
      } else if (diffDays === 1) {
        remainingDaysText = `· 1 day left`;
        urgencyClass = 'is-urgent';
      } else if (diffDays === 0) {
        remainingDaysText = `· Due today`;
        urgencyClass = 'is-urgent';
      } else {
        remainingDaysText = `· Overdue`;
        urgencyClass = 'is-overdue';
      }
      
      return { formattedDueDate, remainingDaysText, urgencyClass };
    } catch {
      return { formattedDueDate: 'No deadline', remainingDaysText: '', urgencyClass: '' };
    }
  }, [createdAt]);

  const isAllApproved = totalCount > 0 && approvedCount === totalCount;
  const progressPercent = totalCount > 0 ? (approvedCount / totalCount) * 100 : 0;

  return (
    <div className="pcm-toolbar w-full sticky z-20 transition-all duration-200">
      <div className="pcm-toolbar-inner">
        
        {/* Filters / Tabs */}
        <div className="filters select-none" role="tablist">
          {(['images', 'videos', 'copy'] as const)
            .filter((filter) => counts[filter] > 0)
            .map((filter) => {
            const isActive = activeFilter === filter;
            const labelMap = {
              images: `Images · ${counts.images}`,
              videos: `Videos · ${counts.videos}`,
              copy: `Copy · ${counts.copy}`
            };

            return (
              <button
                key={filter}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => onFilterChange(isActive ? 'all' : filter)}
                className={`chip cursor-pointer ${isActive ? 'active' : ''}`}
              >
                {labelMap[filter]}
              </button>
            );
          })}
        </div>

        {/* Divider */}
        <span className="pcm-toolbar-divider" aria-hidden="true" />

        {/* Right Panel elements */}
        <div className="pcm-toolbar-right w-full md:w-auto">
          
          {/* Progress Indicator */}
          <div className="pcm-progress select-none">
            <span className="progress-count">
              {approvedCount} of {totalCount} approved
            </span>
            <div className="pcm-progress-bar">
              <div style={{ width: `${progressPercent}%` }} />
            </div>
          </div>

          {/* Context group (Status + Deadline) */}
          <div className="pcm-context-group mt-1 md:mt-0">
            {/* Pulsing Status Pill */}
            <span 
              className={`pcm-bar-status ${isAllApproved ? 'is-approved' : ''}`}
              title="Status"
            >
              <span className="dot" aria-hidden="true" />
              {isAllApproved ? ' Round approved' : ' Awaiting your review'}
            </span>

            {/* Deadline countdown */}
            <span className={`pcm-bar-deadline ${urgencyClass}`} title="Response deadline">
              <b>{formattedDueDate}</b>
              <span className="countdown">{remainingDaysText}</span>
            </span>
          </div>

          <span className="pcm-bar-divider" aria-hidden="true" />

          {/* Approve All / Confirm Submit */}
          {isAllApproved ? (
            <div className="pcm-confirm-group">
              {showNameInput && (
                <input
                  type="text"
                  value={clientName}
                  onChange={(e) => setClientName(e.target.value)}
                  placeholder="Your name"
                  className="pcm-confirm-name"
                  autoFocus
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' && clientName.trim()) {
                      onConfirmSubmit(clientName.trim());
                    }
                  }}
                />
              )}
              <button
                type="button"
                disabled={isConfirmPending}
                onClick={() => {
                  if (!showNameInput) {
                    setShowNameInput(true);
                    return;
                  }
                  if (!clientName.trim()) return;
                  onConfirmSubmit(clientName.trim());
                }}
                className="pcm-confirm-btn cursor-pointer select-none"
              >
                {isConfirmPending ? (
                  <><Loader2 className="w-3.5 h-3.5 animate-spin" /> Submitting...</>
                ) : showNameInput ? (
                  <><Send className="w-3.5 h-3.5" /> Confirm &amp; Send</>
                ) : (
                  <><CheckCircle2 className="w-3.5 h-3.5" /> Click to Confirm</>
                )}
              </button>
            </div>
          ) : (
            <button
              type="button"
              disabled={isSubmitting}
              onClick={onApproveAll}
              className="pcm-approve-all cursor-pointer select-none"
            >
              <Check className="w-3.5 h-3.5" />
              Approve all
            </button>
          )}
        </div>

      </div>
    </div>
  );
}
