import { Component, type ErrorInfo, type ReactNode } from 'react';

interface ErrorBoundaryProps {
  children: ReactNode;
  /**
   * Optional custom fallback. When omitted, a friendly default (styled with the
   * design tokens) is rendered. Receives a `reset` callback so the fallback can
   * attempt to re-render the subtree without a full reload.
   */
  fallback?: (error: Error, reset: () => void) => ReactNode;
}

interface ErrorBoundaryState {
  error: Error | null;
}

/**
 * Class-based error boundary — the only way to catch render/lifecycle throws in
 * React. Without one, any component throwing during render blanks the whole SPA
 * to a white screen. Mounted both at the app root (main.tsx) and per-shell (so a
 * single route crash keeps the surrounding layout intact).
 */
export default class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Surface the crash so it lands in logs / error reporting rather than
    // silently vanishing behind the fallback.
    console.error('ErrorBoundary caught an error:', error, info.componentStack);
  }

  reset = (): void => {
    this.setState({ error: null });
  };

  render(): ReactNode {
    const { error } = this.state;
    if (!error) return this.props.children;

    if (this.props.fallback) return this.props.fallback(error, this.reset);

    return <DefaultErrorFallback onReset={this.reset} />;
  }
}

function DefaultErrorFallback({ onReset }: { onReset: () => void }) {
  return (
    <div
      role="alert"
      className="flex min-h-[60vh] w-full flex-col items-center justify-center gap-4 bg-bg px-6 py-16 text-center text-fg"
    >
      <h1 className="font-display text-2xl text-fg sm:text-3xl">Something went wrong</h1>
      <p className="max-w-md text-sm text-fg-muted">
        An unexpected error stopped this page from loading. You can try again, or head back to the
        home page.
      </p>
      <div className="mt-2 flex flex-wrap items-center justify-center gap-3">
        <button
          type="button"
          onClick={() => window.location.reload()}
          className="inline-flex min-h-[44px] items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-fg transition-colors hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          Reload page
        </button>
        <a
          href="/"
          onClick={onReset}
          className="inline-flex min-h-[44px] items-center justify-center rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-fg transition-colors hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          Back home
        </a>
      </div>
    </div>
  );
}
