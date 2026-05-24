import { useMemo } from 'react';
import { Check, CheckCircle2, Clock } from 'lucide-react';

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
  isSubmitting?: boolean;
}

export function ClientStatusToolbar({
  activeFilter,
  onFilterChange,
  counts,
  approvedCount,
  totalCount,
  createdAt = new Date(),
  onApproveAll,
  isSubmitting = false
}: ClientStatusToolbarProps) {
  
  // Calculate dynamic due date (4 days from creation) and countdown
  const { formattedDueDate, remainingDaysText, urgencyClass } = useMemo(() => {
    try {
      const createdDate = new Date(createdAt);
      if (isNaN(createdDate.getTime())) {
        return { formattedDueDate: 'Due soon', remainingDaysText: '', urgencyClass: '' };
      }
      
      const dueDate = new Date(createdDate.getTime() + 4 * 24 * 60 * 60 * 1000);
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
        urgencyClass = 'text-amber-600 font-semibold';
      } else if (diffDays === 0) {
        remainingDaysText = `· Due today`;
        urgencyClass = 'text-orange-600 font-semibold animate-pulse';
      } else {
        remainingDaysText = `· Overdue`;
        urgencyClass = 'text-red-600 font-semibold';
      }
      
      return { formattedDueDate, remainingDaysText, urgencyClass };
    } catch (e) {
      return { formattedDueDate: 'Due soon', remainingDaysText: '', urgencyClass: '' };
    }
  }, [createdAt]);

  const isAllApproved = totalCount > 0 && approvedCount === totalCount;
  const progressPercent = totalCount > 0 ? (approvedCount / totalCount) * 100 : 0;

  return (
    <div className="w-full sticky top-12 z-20 px-4 md:px-6 pt-5 pb-3">
      {/* Self-contained CSS stylesheet injection for modular sweep shimmer and pulses */}
      <style>{`
        @keyframes approveShimmer {
          0%   { background-position: 220% 0; }
          55%  { background-position: -120% 0; }
          100% { background-position: -120% 0; }
        }
        .btn-shimmer-sweep {
          background-size: 250% 100%;
          background-position: 220% 0;
          background-repeat: no-repeat;
          animation: approveShimmer 5.5s ease-in-out infinite;
        }
        @media (prefers-reduced-motion: reduce) {
          .btn-shimmer-sweep { animation: none; }
        }
      `}</style>

      <div className="max-w-5xl mx-auto border border-white/70 bg-white/65 backdrop-blur-xl shadow-lg rounded-2xl p-2.5 flex flex-col md:flex-row items-center gap-3 transition-all duration-200">
        
        {/* Filters / Tabs */}
        <div className="flex bg-muted/60 p-0.5 rounded-xl self-stretch md:self-auto gap-0.5" role="tablist">
          {(['all', 'images', 'videos', 'copy'] as const).map((filter) => {
            const isActive = activeFilter === filter;
            const labelMap = {
              all: `All · ${counts.all}`,
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
                onClick={() => onFilterChange(filter)}
                className={`flex-1 md:flex-none cursor-pointer px-3.5 py-1.5 text-xs font-semibold rounded-lg capitalize transition-all select-none ${
                  isActive
                    ? 'bg-white text-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground'
                }`}
              >
                {labelMap[filter]}
              </button>
            );
          })}
        </div>

        {/* Divider */}
        <div className="hidden md:block w-px h-6 bg-border/60 shrink-0" aria-hidden="true" />

        {/* Status indicator & Due Date */}
        <div className="flex flex-wrap items-center justify-center gap-3.5 mt-1 md:mt-0">
          
          {/* Pulsing Status Pill */}
          <div 
            className={`inline-flex items-center gap-2 px-3 py-1.5 border rounded-full text-xs font-medium transition-all ${
              isAllApproved
                ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-700'
                : 'bg-amber-500/10 border-amber-500/20 text-amber-700'
            }`}
          >
            <span className="relative flex h-2 w-2">
              <span 
                className={`animate-ping absolute inline-flex h-full w-full rounded-full opacity-75 ${
                  isAllApproved ? 'bg-emerald-400' : 'bg-amber-400'
                }`}
              />
              <span 
                className={`relative inline-flex rounded-full h-2 w-2 ${
                  isAllApproved ? 'bg-emerald-500' : 'bg-amber-500'
                }`}
              />
            </span>
            <span>{isAllApproved ? 'Round Approved' : 'Awaiting your review'}</span>
          </div>

          {/* Deadline text */}
          <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
            <Clock className="w-3.5 h-3.5 text-muted-foreground/60" />
            <span className={urgencyClass}>
              <b>{formattedDueDate}</b> <span className="text-muted-foreground/80">{remainingDaysText}</span>
            </span>
          </div>
        </div>

        {/* Spacer */}
        <div className="flex-grow" />

        {/* Progress & Action */}
        <div className="flex items-center justify-between md:justify-end gap-5 w-full md:w-auto mt-2 md:mt-0 px-2 md:px-0">
          
          {/* Progress Indicator */}
          <div className="flex items-center gap-2.5 shrink-0 select-none">
            <span className="text-xs font-semibold text-muted-foreground">
              {approvedCount} of {totalCount} approved
            </span>
            <div className="w-20 h-1 bg-muted rounded-full overflow-hidden shrink-0">
              <div 
                className="h-full bg-foreground rounded-full transition-all duration-500" 
                style={{ width: `${progressPercent}%` }}
              />
            </div>
          </div>

          <div className="h-4 w-px bg-border/60 hidden md:block" />

          {/* Shimmer Approve All Button */}
          <button
            type="button"
            disabled={isSubmitting}
            onClick={onApproveAll}
            className="btn-shimmer-sweep cursor-pointer inline-flex items-center gap-1.5 px-4 py-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white font-bold text-xs rounded-xl shadow-md shadow-emerald-600/10 border border-emerald-500/30 transition-all active:translate-y-0.5 select-none"
            style={{
              backgroundImage: 'linear-gradient(105deg, rgba(255,255,255,0) 35%, rgba(255,255,255,0.32) 47%, rgba(255,255,255,0.55) 50%, rgba(255,255,255,0.32) 53%, rgba(255,255,255,0) 65%)'
            }}
          >
            <CheckCircle2 className="w-3.5 h-3.5" />
            Approve All
          </button>
        </div>

      </div>
    </div>
  );
}
