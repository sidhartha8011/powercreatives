import { useMemo } from 'react';
import { Check, CheckCircle2, Clock, Loader2 } from 'lucide-react';

interface ClientStatusToolbarProps {
  activeFilter: 'all' | 'images' | 'videos' | 'copy' | 'articles' | 'custom';
  onFilterChange: (filter: 'all' | 'images' | 'videos' | 'copy' | 'articles' | 'custom') => void;
  counts: {
    all: number;
    images: number;
    videos: number;
    copy: number;
    articles: number;
    custom: number;
  };
  approvedCount: number;
  totalCount: number;
  createdAt?: string | Date;
  onApproveAll: () => void;
  onConfirmSubmit: () => void;
  isSubmitting?: boolean;
  isConfirmPending?: boolean;
  isReadOnly?: boolean;
  isSaving?: boolean;
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
  isConfirmPending = false,
  isReadOnly = false,
  isSaving = false
}: ClientStatusToolbarProps) {
  
  /**
   * Review window, in days, before the client board shows a due date.
   *
   * This was a hardcoded 4-day literal presented to the client as a real
   * deadline nobody had agreed to — a business rule living in a component.
   * It now reads a hub-supplied value and only renders a deadline when one is
   * actually configured: no setting, no invented promise.
   */
  const reviewWindowDays = Number(
    (window as unknown as { pcmConfig?: { approvalsReviewWindowDays?: number } })
      .pcmConfig?.approvalsReviewWindowDays ?? 0
  );
  const REVIEW_PERIOD_MS = reviewWindowDays * 24 * 60 * 60 * 1000;

  // Calculate dynamic due date and countdown
  const { formattedDueDate, remainingDaysText, urgencyClass } = useMemo(() => {
    try {
      // No configured window → no deadline is shown at all. Silence is honest;
      // an invented date is not.
      if (!Number.isFinite(reviewWindowDays) || reviewWindowDays <= 0) {
        return { formattedDueDate: '', remainingDaysText: '', urgencyClass: '' };
      }
      const cleanDateStr = typeof createdAt === 'string' ? createdAt.replace(' ', 'T') : createdAt;
      const createdDate = new Date(cleanDateStr);
      if (isNaN(createdDate.getTime())) {
        return { formattedDueDate: '', remainingDaysText: '', urgencyClass: '' };
      }
      
      const dueDate = new Date(createdDate.getTime() + REVIEW_PERIOD_MS);
      const today = new Date();
      
      // Dynamic date formatting
      const formatter = new Intl.DateTimeFormat('sv-SE', { month: 'short', day: 'numeric' });
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
        remainingDaysText = `· Today`;
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
        <div className="pcm-filters select-none" role="tablist">
          {(['images', 'videos', 'copy', 'articles', 'custom'] as const)
            .filter((filter) => counts[filter] > 0)
            .map((filter) => {
            const isActive = activeFilter === filter;
            const labelMap = {
              images: `Images · ${counts.images}`,
              videos: `Video · ${counts.videos}`,
              copy: `Copy · ${counts.copy}`,
              articles: `Articles · ${counts.articles}`,
              custom: `Custom · ${counts.custom}`,
            };

            return (
              <button
                key={filter}
                type="button"
                role="tab"
                aria-selected={isActive}
                onClick={() => onFilterChange(isActive ? 'all' : filter)}
                className={`pcm-chip cursor-pointer ${isActive ? 'active' : ''}`}
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
          {/* Deadline countdown */}
          {formattedDueDate && (
            <span className={`pcm-bar-deadline ${urgencyClass}`} title="Response deadline">
              <b>{formattedDueDate}</b>
              <span className="countdown">{remainingDaysText}</span>
            </span>
          )}

          <span className="pcm-bar-divider" aria-hidden="true" />

          {/* Approve All / Confirm Submit — hidden in read-only mode */}
          {isReadOnly ? (
            <span className="pcm-bar-completed">
              <CheckCircle2 className="w-3.5 h-3.5" />Done</span>
          ) : isAllApproved ? (
            <button
              type="button"
              disabled={isConfirmPending}
              onClick={onConfirmSubmit}
              className="pcm-confirm-btn cursor-pointer select-none"
            >
              {isConfirmPending ? (
                <><Loader2 className="w-3.5 h-3.5 animate-spin" /> Sending...</>
              ) : (
                <><CheckCircle2 className="w-3.5 h-3.5" /> Confirm</>
              )}
            </button>
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
