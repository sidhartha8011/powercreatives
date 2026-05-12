/**
 * SortableTableHead — Clickable table header cell with sort direction indicator.
 *
 * Renders a <TableHead> with an arrow icon that reflects the current sort state.
 * Designed to work with useSortableTable hook. Generic over column key type.
 *
 * Usage:
 *   <SortableTableHead<"name" | "createdAt">
 *     columnKey="name"
 *     label="Name"
 *     currentSortKey={sortKey}
 *     currentSortDir={sortDir}
 *     onToggle={toggleSort}
 *     style={{ width: "14%" }}
 *   />
 */

import { TableHead } from "@/components/ui/table";
import { ArrowDown, ArrowUp, ArrowUpDown } from "lucide-react";
import type { CSSProperties, ReactNode } from "react";
import type { SortDirection } from "@/hooks/useSortableTable";

interface SortableTableHeadProps<K extends string = string> {
  /** Unique key identifying this column (must match useSortableTable accessor keys) */
  columnKey: K;
  /** Display label */
  label: ReactNode;
  /** Currently active sort column from useSortableTable */
  currentSortKey: K | null;
  /** Current sort direction from useSortableTable */
  currentSortDir: SortDirection;
  /** toggleSort callback from useSortableTable */
  onToggle: (key: K) => void;
  /** Optional inline styles (e.g., width) */
  style?: CSSProperties;
  /** Optional className */
  className?: string;
}

export function SortableTableHead<K extends string = string>({
  columnKey,
  label,
  currentSortKey,
  currentSortDir,
  onToggle,
  style,
  className,
}: SortableTableHeadProps<K>) {
  const isActive = currentSortKey === columnKey;

  const Icon = isActive
    ? currentSortDir === "asc"
      ? ArrowUp
      : ArrowDown
    : ArrowUpDown;

  return (
    <TableHead style={style} className={className}>
      <button
        type="button"
        onClick={() => onToggle(columnKey)}
        className="flex items-center gap-1 text-left w-full group cursor-pointer select-none hover:text-foreground transition-colors"
      >
        <span>{label}</span>
        <Icon
          className={`w-3.5 h-3.5 shrink-0 transition-opacity ${
            isActive ? "opacity-100" : "opacity-0 group-hover:opacity-40"
          }`}
        />
      </button>
    </TableHead>
  );
}
