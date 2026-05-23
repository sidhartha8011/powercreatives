import React from "react";
import { colors } from "./design-tokens";

interface PanelHeaderProps {
  title: string;
  rightElement?: React.ReactNode;
  className?: string;
  onClick?: () => void;
  isCollapsed?: boolean;
}

export function PanelHeader({ title, rightElement, className = "", onClick, isCollapsed }: PanelHeaderProps) {
  const Component = onClick ? "button" : "div";
  
  return (
    <Component
      type={onClick ? "button" : undefined}
      onClick={onClick}
      className={`flex w-full items-center justify-between px-3 py-2.5 border-b transition-colors ${onClick ? 'cursor-pointer' : ''} ${className}`}
      style={{ 
        backgroundColor: 'var(--sidebar-section-bg)',
        borderColor: 'var(--border)',
        borderRadius: onClick ? (isCollapsed ? "0.5rem" : "0.5rem 0.5rem 0 0") : undefined
      }}
      onMouseEnter={onClick ? (e: any) => e.currentTarget.style.backgroundColor = 'var(--sidebar-section-hover)' : undefined}
      onMouseLeave={onClick ? (e: any) => e.currentTarget.style.backgroundColor = 'var(--sidebar-section-bg)' : undefined}
    >
      <span
        className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground"
      >
        {title}
      </span>
      {rightElement && <div className="flex items-center gap-2">{rightElement}</div>}
    </Component>
  );
}
