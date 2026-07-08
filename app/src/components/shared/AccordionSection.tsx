/**
 * ACCORDION SECTION — Shared Component
 *
 * A reusable collapsible card container designed to provide consistent visual hierarchy
 * for sidebar section groups across all creative modules (Video, Copy, Image, Ads).
 *
 * Utilizes standard Tailwind classes and Lucide icons for high-performance rendering.
 */

import { useState } from 'react';
import { ChevronRight } from 'lucide-react';

interface AccordionSectionProps {
  title: string;
  icon?: React.ReactNode;
  defaultOpen?: boolean;
  badge?: string;
  children: React.ReactNode;
  headerAction?: React.ReactNode;
  disabled?: boolean;
}

export function AccordionSection({
  title,
  icon,
  defaultOpen = false,
  badge,
  children,
  headerAction,
  disabled = false,
}: AccordionSectionProps) {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  const handleToggle = () => {
    if (disabled) return;
    setIsOpen(!isOpen);
  };

  const showContent = isOpen && !disabled;

  return (
    <div className={`rounded-lg overflow-hidden border border-border bg-card transition-opacity duration-200 ${disabled ? 'opacity-60' : ''}`}>
      <div className={`w-full flex items-center justify-between px-3 py-2.5 ${disabled ? 'bg-muted/5' : 'hover:bg-muted/10'}`}>
        <button
          type="button"
          disabled={disabled}
          onClick={handleToggle}
          className={`flex-1 flex items-center justify-between text-left ${disabled ? 'cursor-not-allowed' : 'cursor-pointer'}`}
        >
          <div className="flex items-center gap-2 min-w-0">
            {icon && <span className="text-muted-foreground">{icon}</span>}
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground truncate">
              {title}
            </span>
            {badge && (
              <span className="text-[10px] font-medium text-muted-foreground/80 px-1.5 py-0.5 rounded bg-muted">
                {badge}
              </span>
            )}
          </div>
          {!disabled && (
            <ChevronRight
              className={`w-3.5 h-3.5 text-muted-foreground transition-transform duration-200 mr-3 ${
                isOpen ? 'rotate-90' : 'rotate-0'
              }`}
            />
          )}
        </button>
        {headerAction && (
          <div className="flex items-center shrink-0">
            {headerAction}
          </div>
        )}
      </div>

      {showContent && (
        <div className="px-3 pb-3 pt-2 space-y-4 border-t border-border bg-background">
          {children}
        </div>
      )}
    </div>
  );
}
