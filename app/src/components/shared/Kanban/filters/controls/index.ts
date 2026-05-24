/**
 * Control registry.
 *
 * Adding a new FilterControlSpec.kind: import the component and add a case
 * in the toolbar's switch statement. Lives here so the registry is the
 * single point of truth.
 */

export { SearchableSelect } from './SearchableSelect';
export type { SearchableSelectProps } from './SearchableSelect';

export { BooleanControl } from './Boolean';
export type { BooleanControlProps } from './Boolean';

export { DateRangeControl } from './DateRange';
export type { DateRangeControlProps } from './DateRange';

export { TextControl } from './Text';
export type { TextControlProps } from './Text';
