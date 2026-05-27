import { useMemo } from 'react';
import { Check, CheckCircle2, Clock, Loader2 } from 'lucide-react';

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
  
  /** Review period in milliseconds (4 days) */
  const REVIEW_PERIOD_MS = 4 * 24 * 60 * 60 * 1000;

  // Calculate dynamic due date and countdown
  const { formattedDueDate, remainingDaysText, urgencyClass } = useMemo(() => {
    try {
      const cleanDateStr = typeof createdAt === 'string' ? createdAt.replace(' ', 'T') : createdAt;
      const createdDate = new Date(cleanDateStr);
      if (isNaN(createdDate.getTime())) {
        return { formattedDueDate: 'Ingen deadline', remainingDaysText: '', urgencyClass: '' };
      }
      
      const dueDate = new Date(createdDate.getTime() + REVIEW_PERIOD_MS);
      const today = new Date();
      
      // Dynamic date formatting
      const formatter = new Intl.DateTimeFormat('sv-SE', { month: 'short', day: 'numeric' });
      const formattedDueDate = `Deadline ${formatter.format(dueDate)}`;
      
      // Calculate day difference
      const d1 = new Date(today.getFullYear(), today.getMonth(), today.getDate());
      const d2 = new Date(dueDate.getFullYear(), dueDate.getMonth(), dueDate.getDate());
      const diffTime = d2.getTime() - d1.getTime();
      const diffDays = Math.round(diffTime / (1000 * 60 * 60 * 24));
      
      let remainingDaysText = '';
      let urgencyClass = '';
      
      if (diffDays > 1) {
        remainingDaysText = `· ${diffDays} dagar kvar`;
      } else if (diffDays === 1) {
        remainingDaysText = `· 1 dag kvar`;
        urgencyClass = 'is-urgent';
      } else if (diffDays === 0) {
        remainingDaysText = `· Idag`;
        urgencyClass = 'is-urgent';
      } else {
        remainingDaysText = `· Försenad`;
        urgencyClass = 'is-overdue';
      }
      
      return { formattedDueDate, remainingDaysText, urgencyClass };
    } catch {
      return { formattedDueDate: 'Ingen deadline', remainingDaysText: '', urgencyClass: '' };
    }
  }, [createdAt]);

  const isAllApproved = totalCount > 0 && approvedCount === totalCount;
  const progressPercent = totalCount > 0 ? (approvedCount / totalCount) * 100 : 0;

  return (
    <div className="pcm-toolbar w-full sticky z-20 transition-all duration-200">
      <div className="pcm-toolbar-inner">
        
        {/* Filters / Tabs */}
        <div className="pcm-filters select-none" role="tablist">
          {(['images', 'videos', 'copy'] as const)
            .filter((filter) => counts[filter] > 0)
            .map((filter) => {
            const isActive = activeFilter === filter;
            const labelMap = {
              images: `Bilder · ${counts.images}`,
              videos: `Video · ${counts.videos}`,
              copy: `Text · ${counts.copy}`
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
          <span className={`pcm-bar-deadline ${urgencyClass}`} title="Response deadline">
            <b>{formattedDueDate}</b>
            <span className="countdown">{remainingDaysText}</span>
          </span>

          <span className="pcm-bar-divider" aria-hidden="true" />

          {/* Approve All / Confirm Submit — hidden in read-only mode */}
          {isReadOnly ? (
            <span className="pcm-bar-completed">
              <CheckCircle2 className="w-3.5 h-3.5" />
              Klar
            </span>
          ) : isAllApproved ? (
            <button
              type="button"
              disabled={isConfirmPending}
              onClick={onConfirmSubmit}
              className="pcm-confirm-btn cursor-pointer select-none"
            >
              {isConfirmPending ? (
                <><Loader2 className="w-3.5 h-3.5 animate-spin" /> Skickar...</>
              ) : (
                <><CheckCircle2 className="w-3.5 h-3.5" /> Bekräfta</>
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
              Godkänn alla
            </button>
          )}
        </div>

      </div>
    </div>
  );
}
