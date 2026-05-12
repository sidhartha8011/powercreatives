/**
 * TAB BAR
 * 
 * Pill-shaped icon tab bar for switching between Detail and Variations views.
 * Floats centered above the popup content.
 */

import { Image, LayoutGrid } from "lucide-react";
import { cn } from "@/lib/utils";
import type { DetailViewTab } from "./types";

interface TabBarProps {
  activeTab: DetailViewTab;
  onTabChange: (tab: DetailViewTab) => void;
  variationCount: number;
}

export function TabBar({ activeTab, onTabChange, variationCount }: TabBarProps) {
  return (
    <div className="flex justify-center py-3">
      <div className="inline-flex items-center gap-1 p-1 rounded-full bg-neutral-100 border border-neutral-200">
        <TabButton
          active={activeTab === "detail"}
          onClick={() => onTabChange("detail")}
          label="Detail view"
        >
          <Image className="h-4 w-4" />
        </TabButton>

        <TabButton
          active={activeTab === "variations"}
          onClick={() => onTabChange("variations")}
          label="Variations"
          badge={variationCount > 0 ? variationCount : undefined}
        >
          <LayoutGrid className="h-4 w-4" />
        </TabButton>
      </div>
    </div>
  );
}

function TabButton({
  active,
  onClick,
  label,
  badge,
  children,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
  badge?: number;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      className={cn(
        "relative flex items-center justify-center h-9 w-12 rounded-full transition-all duration-200",
        active
          ? "bg-white text-neutral-900 shadow-sm"
          : "text-neutral-500 hover:text-neutral-700 hover:bg-neutral-50"
      )}
    >
      {children}
      {badge !== undefined && (
        <span className="absolute -top-1 -right-1 flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-blue-500 text-white text-[10px] font-medium">
          {badge}
        </span>
      )}
    </button>
  );
}
