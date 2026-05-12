/**
 * KeywordPicker — Shared radio-button list for selecting a primary keyword.
 *
 * Used by CreateStrategyDialog (parent keyword) and SendToWriterDialog (primary keyword).
 * All non-selected keywords are implicitly "supporting".
 */

interface KeywordPickerProps {
  /** List of keyword strings to pick from */
  keywords: string[];
  /** Index of the currently selected keyword */
  selectedIndex: number;
  /** Callback when selection changes */
  onSelect: (index: number) => void;
  /** Label shown on the selected keyword badge */
  selectedLabel?: string;
  /** Label shown on non-selected keywords (null = hide) */
  unselectedLabel?: string | null;
  /** Max height of the scrollable list */
  maxHeight?: string;
}

export function KeywordPicker({
  keywords,
  selectedIndex,
  onSelect,
  selectedLabel = 'Primary',
  unselectedLabel = 'Supporting',
  maxHeight = '10rem',
}: KeywordPickerProps) {
  return (
    <div
      className="overflow-y-auto bg-background border rounded-md p-2 space-y-1"
      style={{ maxHeight }}
    >
      {keywords.map((kw, index) => (
        <label
          key={kw}
          className="flex items-center gap-2 cursor-pointer hover:bg-muted/50 p-1.5 rounded transition-colors select-none"
        >
          <input
            type="radio"
            name="keyword-picker"
            checked={selectedIndex === index}
            onChange={() => onSelect(index)}
            className="text-primary focus:ring-primary h-3.5 w-3.5"
          />
          <span className="text-sm font-medium">{kw}</span>
          <span className="text-[0.7rem] ml-auto">
            {selectedIndex === index ? (
              <span className="bg-primary text-primary-foreground px-2 py-0.5 rounded-sm">
                {selectedLabel}
              </span>
            ) : unselectedLabel ? (
              <span className="text-muted-foreground">{unselectedLabel}</span>
            ) : null}
          </span>
        </label>
      ))}
    </div>
  );
}
