/**
 * SECTION PANEL
 * 
 * Reusable section wrapper for action panels.
 * Provides consistent spacing and optional title.
 * PowerKeys-consistent compact design.
 */

import { cn } from "@/lib/utils";

interface SectionPanelProps {
  title?: string;
  className?: string;
  children: React.ReactNode;
}

export function SectionPanel({ title, className, children }: SectionPanelProps) {
  return (
    <div className={cn("space-y-2", className)}>
      {title && (
        <h4 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
          {title}
        </h4>
      )}
      {children}
    </div>
  );
}
