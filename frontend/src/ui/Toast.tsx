import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { cn } from './cn';
import { useReducedMotionSafe, slideUp } from '../motion';

export type ToastTone = 'neutral' | 'success' | 'danger' | 'warning' | 'info';

export interface ToastOptions {
  title: string;
  description?: string;
  tone?: ToastTone;
  /** Auto-dismiss delay in ms. Set 0 to require manual dismissal. */
  duration?: number;
}

interface ToastItem extends Required<Pick<ToastOptions, 'title' | 'tone'>> {
  id: number;
  description?: string;
  duration: number;
}

interface ToastContextValue {
  toast: (opts: ToastOptions) => number;
  dismiss: (id: number) => void;
}

const ToastContext = createContext<ToastContextValue | null>(null);

const toneAccent: Record<ToastTone, string> = {
  neutral: 'border-l-fg-subtle',
  success: 'border-l-success',
  danger: 'border-l-danger',
  warning: 'border-l-warning',
  info: 'border-l-info',
};

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const idRef = useRef(0);
  const animate = useReducedMotionSafe();

  const dismiss = useCallback((id: number) => {
    setToasts((list) => list.filter((t) => t.id !== id));
  }, []);

  const toast = useCallback(
    (opts: ToastOptions) => {
      const id = ++idRef.current;
      const item: ToastItem = {
        id,
        title: opts.title,
        description: opts.description,
        tone: opts.tone ?? 'neutral',
        duration: opts.duration ?? 5000,
      };
      setToasts((list) => [...list, item]);
      if (item.duration > 0) {
        window.setTimeout(() => dismiss(id), item.duration);
      }
      return id;
    },
    [dismiss],
  );

  const value = useMemo(() => ({ toast, dismiss }), [toast, dismiss]);

  return (
    <ToastContext.Provider value={value}>
      {children}
      {createPortal(
        <div
          className="pointer-events-none fixed bottom-0 right-0 z-toast flex w-full max-w-sm flex-col gap-2 p-4"
          role="region"
          aria-label="Notifications"
        >
          <AnimatePresence initial={false}>
            {toasts.map((t) => (
              <motion.div
                key={t.id}
                layout={animate}
                variants={animate ? slideUp : undefined}
                initial={animate ? 'hidden' : false}
                animate="visible"
                exit={animate ? 'exit' : undefined}
                role={t.tone === 'danger' ? 'alert' : 'status'}
                aria-live={t.tone === 'danger' ? 'assertive' : 'polite'}
                className={cn(
                  'pointer-events-auto flex items-start gap-3 rounded-lg border border-l-4 border-border bg-surface p-4 shadow-lg',
                  toneAccent[t.tone],
                )}
              >
                <div className="flex-1">
                  <p className="text-sm font-semibold text-fg">{t.title}</p>
                  {t.description && <p className="mt-0.5 text-sm text-fg-muted">{t.description}</p>}
                </div>
                <button
                  type="button"
                  onClick={() => dismiss(t.id)}
                  aria-label="Dismiss notification"
                  className="rounded-sm p-1 text-fg-subtle transition-colors hover:text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                  <svg viewBox="0 0 20 20" className="h-3.5 w-3.5" fill="none" aria-hidden="true">
                    <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
                  </svg>
                </button>
              </motion.div>
            ))}
          </AnimatePresence>
        </div>,
        document.body,
      )}
    </ToastContext.Provider>
  );
}

/** Access the toast API: `const { toast } = useToast()`. */
export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used within a <ToastProvider>.');
  return ctx;
}

const NOOP_TOAST: ToastContextValue = { toast: () => -1, dismiss: () => {} };

/**
 * Like {@link useToast} but degrades to a no-op when rendered outside a
 * <ToastProvider> instead of throwing. Use in pages whose notifications are a
 * nice-to-have (e.g. a "sample added" confirmation) so they stay renderable in
 * isolation (tests, storybook) without every harness wiring up the provider.
 */
export function useOptionalToast(): ToastContextValue {
  return useContext(ToastContext) ?? NOOP_TOAST;
}
