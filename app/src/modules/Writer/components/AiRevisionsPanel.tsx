/**
 * WRITER MODULE — Revisions Panel
 *
 * Right-side panel showing document revision history.
 * AI Chat has been removed — it will be implemented as a global popup in a future iteration.
 *
 * Architecture:
 * - Currently shows an empty state for revisions (placeholder for future revision tracking).
 * - Accepts an `onCollapse` callback to allow the parent layout to toggle this panel's visibility.
 * - Follows the same header pattern as ContextGenerationPanel for consistency.
 */

import { History, PanelLeftClose } from 'lucide-react';
import { colors, typography, SectionLabel } from '@/components/shared';

interface Props {
  onCollapse?: () => void;
}

export function AiRevisionsPanel({ onCollapse }: Props) {
  return (
    <div className="h-full flex flex-col overflow-hidden" style={{ background: colors.bgSurface }}>
      {/* ── Panel Header ────────────────────────────────── */}
      <div
        className="px-4 py-3 shrink-0 flex items-center justify-between"
        style={{ borderBottom: `1px solid ${colors.borderLight}`, background: colors.bgSurface }}
      >
        <div className="flex items-center gap-2">
          <SectionLabel>Revisions</SectionLabel>
        </div>
        <button
          onClick={onCollapse}
          style={{
            padding: 4,
            borderRadius: 4,
            background: 'transparent',
            border: 'none',
            cursor: 'pointer',
            color: colors.textMuted,
            transition: 'background-color 0.15s',
          }}
          title="Collapse Panel"
        >
          <PanelLeftClose style={{ width: 14, height: 14 }} />
        </button>
      </div>

      {/* ── Revisions Content ────────────────────────────── */}
      <div className="flex-1 overflow-y-auto p-4">
        <div className="flex flex-col items-center justify-center h-full text-center">
          <History style={{ width: 32, height: 32, color: colors.borderMedium, marginBottom: 8 }} />
          <span style={{ fontSize: typography.sm, color: colors.textMuted }}>No previous revisions</span>
          <span style={{ fontSize: typography.xs, color: colors.textFaint, marginTop: 4 }}>
            Revisions will appear here after each generation or edit
          </span>
        </div>
      </div>
    </div>
  );
}
