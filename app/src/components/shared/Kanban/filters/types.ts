/**
 * Kanban Filter Engine — Public Types
 *
 * Filters and sorts are declarations, not components. A consumer module
 * declares an array of FilterDefinition / SortDefinition and the toolbar
 * renders the UI generically. Adding a new filter = one entry. Adding a new
 * control kind = one new control component + one entry in the registry.
 *
 * No domain knowledge leaks into this file.
 */

/**
 * Discriminated union of supported control shapes. Add a new variant here +
 * a matching control component to extend the system.
 */
export type FilterControlSpec =
  | {
      kind: 'searchableSelect';
      /** Placeholder shown in the embedded search input. */
      searchPlaceholder?: string;
      /** Cap suggestion list (e.g. for very large brands lists). */
      maxOptions?: number;
    }
  | {
      kind: 'boolean';
      /** Label shown next to the toggle. */
      toggleLabel?: string;
    }
  | {
      kind: 'dateRange';
    }
  | {
      kind: 'text';
      placeholder?: string;
    };

/**
 * Runtime value for each control kind. Stored in the FilterState map keyed
 * by filter id.
 */
export type FilterValue =
  | { kind: 'searchableSelect'; selected: string[] }
  | { kind: 'boolean'; value: boolean }
  | { kind: 'dateRange'; from?: string; to?: string }
  | { kind: 'text'; query: string };

export type FilterState = Record<string, FilterValue | undefined>;

/**
 * A filter declaration. Bound to a domain type via generic T. `getValue`
 * exposes the comparable attribute(s) for matching. `getOptions` is optional
 * — when omitted for searchableSelect, options are derived as unique
 * non-null getValue() results.
 */
export interface FilterDefinition<T> {
  id: string;
  label: string;
  control: FilterControlSpec;
  /**
   * Returns the value(s) to compare against the filter input. May return an
   * array for items with multiple values (e.g. tags). May return null/
   * undefined for missing data — filtered as "no value".
   */
  getValue: (item: T) => string | string[] | null | undefined;
  /**
   * Optional bespoke option list (label + value + count). When omitted the
   * engine derives unique options from getValue().
   */
  getOptions?: (items: ReadonlyArray<T>) => Array<FilterOption>;
}

export interface FilterOption {
  value: string;
  label: string;
  /** Pre-computed count of matching items. Renders as a badge in the UI. */
  count?: number;
}

/**
 * Sort declaration. The compare function follows Array.prototype.sort
 * conventions. The engine never sorts in-place.
 */
export interface SortDefinition<T> {
  id: string;
  label: string;
  compare: (a: T, b: T) => number;
}
