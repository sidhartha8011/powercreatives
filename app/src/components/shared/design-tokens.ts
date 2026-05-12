/**
 * CREATIVE MACHINE — Design Tokens
 *
 * Single source of truth for the PowerKeys visual language.
 * Every shared component and every module MUST import from here
 * instead of hardcoding hex values or pixel sizes.
 *
 * The sidebar is the canonical reference for this palette.
 */

// ─── Colors ───────────────────────────────────────────────
export const colors = {
  /** Primary brand blue — active nav items, links, CTAs */
  primary: '#007bff',
  /** Light blue tint — active backgrounds, hover highlights */
  primaryLight: '#e7f5ff',

  /** Main text — headings, strong labels */
  text: '#1a1a1a',
  /** Secondary text — descriptions, sub-labels */
  textSecondary: '#555',
  /** Muted text — placeholders, metadata, disabled */
  textMuted: '#888',
  /** Faint text — timestamps, footnotes */
  textFaint: '#999',
  /** Lightest text — decorative separators */
  textGhost: '#ccc',

  /** Default border — cards, inputs, dividers */
  border: '#e5e7eb',
  /** Subtle border — inner separators */
  borderLight: '#eee',
  /** Stronger border — focused inputs */
  borderMedium: '#ced4da',

  /** Page background — content area canvas */
  bgPage: '#f4f6f8',
  /** Card / panel surface — cards float on bgPage */
  bgSurface: '#fff',
  /** Sidebar / control surface background */
  bgMuted: '#fff',
  /** Hover state for nav / list items */
  bgHover: '#f1f3f5',

  /** Success */
  success: '#16a34a',
  successLight: '#dcfce7',
  successBorder: '#bbf7d0',

  /** Warning */
  warning: '#ca8a04',
  warningLight: '#fef3c7',
  warningText: '#92400e',

  /** Danger / destructive */
  danger: '#ef4444',
  dangerLight: '#fee2e2',

  /** Accent purple (AI features) */
  accent: '#7c3aed',
  accentLight: '#f5f3ff',
} as const;

// ─── Typography ───────────────────────────────────────────
export const typography = {
  /** Font family — matches body and all inputs */
  fontFamily: "'Inter', ui-sans-serif, system-ui, sans-serif",

  /** Sidebar nav items, pill buttons, small UI */
  sm: '0.85rem',
  /** Tiny labels, badges, metadata */
  xs: '0.75rem',
  /** Extra-tiny — section labels, counters */
  xxs: '0.7rem',
  /** Micro — 10px uppercase labels */
  micro: '0.625rem',

  /** Module titles */
  title: '1.25rem',
  /** Section headings */
  heading: '1.1rem',
  /** Card titles, body emphasis */
  body: '0.9rem',

  /** Weight: normal content */
  regular: 400,
  /** Weight: nav items, labels */
  medium: 500,
  /** Weight: active nav, section titles */
  semibold: 600,
  /** Weight: headings, logo */
  bold: 700,
} as const;

// ─── Spacing ──────────────────────────────────────────────
export const spacing = {
  /** Pill button padding (sidebar nav reference) */
  pillPx: '14px',
  pillPy: '6px',

  /** Standard card padding */
  cardPadding: '1rem',
  /** Module content padding */
  modulePadding: '1.25rem',

  /** Icon size — sidebar nav, pill buttons */
  iconSm: '1.2rem',
  /** Icon size — card actions */
  iconXs: '0.875rem',

  /** Standard gap between items */
  gap: '8px',
  /** Tight gap — within a button */
  gapTight: '6px',

  /** Border radius — cards, inputs */
  radius: '8px',
  /** Border radius — pill shape */
  radiusPill: '999px',
  /** Border radius — small elements */
  radiusSm: '6px',
} as const;

// ─── Shadows ──────────────────────────────────────────────
export const shadows = {
  /** Subtle card shadow */
  card: '0 1px 3px rgba(0, 0, 0, 0.05)',
  /** Dropdown / popover shadow */
  dropdown: '0 4px 12px rgba(0, 0, 0, 0.1)',
  /** Active pill shadow */
  pillActive: '0 1px 2px rgba(0, 0, 0, 0.06)',
} as const;

// ─── Status Colors ────────────────────────────────────────
// Semantic status indicators used primarily by Writer module.
// Available app-wide for any module needing status visualization.
export const statusColors = {
  /** Draft status — yellow/amber */
  draft:  { bg: '#fff9db', border: '#ffe066', text: '#f59f00' },
  /** Review status — red/coral */
  review: { bg: '#fff5f5', border: '#ffc9c9', text: '#e03131' },
  /** Ready status — green */
  ready:  { bg: '#ebfbee', border: '#b2f2bb', text: '#2f9e44' },
  /** Published status — blue (same as primary) */
  published: { bg: '#e7f5ff', border: '#b8daff', text: '#007bff' },
} as const;

/** All valid status keys */
export type StatusKey = keyof typeof statusColors;

// ─── Convenience re-export ────────────────────────────────
const tokens = { colors, typography, spacing, shadows, statusColors } as const;
export default tokens;
