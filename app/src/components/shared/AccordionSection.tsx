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
}

export function AccordionSection({
  title,
  icon,
  defaultOpen = false,
  badge,
  children,
}: AccordionSectionProps) {
  const [isOpen, setIsOpen] = useState(defaultOpen);

  return (
    <div className="rounded-lg overflow-hidden border border-border bg-card">
      <button
        type="button"
        onClick={() => setIsOpen(!isOpen)}
        className="w-full flex items-center justify-between px-3 py-2.5 text-left transition-colors hover:bg-muted/30"
      >
        <div className="flex items-center gap-2">
          {icon && <span className="text-muted-foreground">{icon}</span>}
          <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            {title}
          </span>
          {badge && (
            <span className="text-[10px] font-medium text-muted-foreground/80 px-1.5 py-0.5 rounded bg-muted">
              {badge}
            </span>
          )}
        </div>
        <ChevronRight
          className={`w-3.5 h-3.5 text-muted-foreground transition-transform duration-200 ${
            isOpen ? 'rotate-90' : 'rotate-0'
          }`}
        />
      </button>

      {isOpen && (
        <div className="px-3 pb-3 pt-2 space-y-4 border-t border-border bg-background">
          {children}
        </div>
      )}
    </div>
  );
}
