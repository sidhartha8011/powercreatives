/**
 * KanbanErrorBoundary — catches render-time exceptions thrown by columns,
 * cards, or the toolbar.
 *
 * A misbehaving consumer card (e.g. accessing a deeply nested optional
 * field that's actually missing) must not crash the entire Approvals tab.
 * The boundary swaps the affected subtree for a recoverable error UI and
 * logs the underlying exception so the developer can fix it.
 */

import { Component, type ErrorInfo, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
  /** Custom fallback. Receives the caught error. */
  fallback?: (error: Error) => ReactNode;
  /** Optional error sink for telemetry. */
  onError?: (error: Error, info: ErrorInfo) => void;
}

interface State {
  error: Error | null;
}

export class KanbanErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Console — never throw further. The console line is the developer's
    // signal that a card broke and needs fixing.
    // eslint-disable-next-line no-console
    console.error('[KanbanErrorBoundary]', error, info);
    this.props.onError?.(error, info);
  }

  render(): ReactNode {
    if (this.state.error) {
      if (this.props.fallback) return this.props.fallback(this.state.error);
      return (
        <div
          role="alert"
          style={{
            padding: '16px',
            border: '1px solid var(--pck-border, #e8e8e6)',
            borderRadius: '8px',
            background: 'var(--pck-bg-card, #fff)',
            color: 'var(--pck-text, #1f1f1f)',
            fontSize: '13px',
            fontFamily: 'var(--pck-font-family, system-ui)',
          }}
        >
          <strong>Kanban failed to render.</strong>
          <div style={{ marginTop: 6, color: 'var(--pck-text-muted, #6b6b6b)' }}>
            {this.state.error.message}
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
