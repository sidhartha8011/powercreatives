import React from "react";

interface PanelHeaderProps {
  title: string;
  rightElement?: React.ReactNode;
  className?: string;
  onClick?: () => void;
  isCollapsed?: boolean;
}

/**
 * Shared section header for sidebar panels.
 * Uses Tailwind classes exclusively — no inline styles.
 */
export function PanelHeader({ title, rightElement, className = "", onClick, isCollapsed }: PanelHeaderProps) {
  const Component = onClick ? "button" : "div";

  /* Border radius depends on collapsed state for clickable headers */
  const radiusClass = onClick
    ? (isCollapsed ? 'rounded-lg' : 'rounded-t-lg')
    : '';

  return (
    <Component
      type={onClick ? "button" : undefined}
      onClick={onClick}
      className={`flex w-full items-center justify-between px-3 py-2.5 border-b border-border transition-colors bg-[var(--sidebar-section-bg)] ${onClick ? 'cursor-pointer hover:bg-[var(--sidebar-section-hover)]' : ''} ${radiusClass} ${className}`}
    >
      <span className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
        {title}
      </span>
      {rightElement && <div className="flex items-center gap-2">{rightElement}</div>}
    </Component>
  );
}
