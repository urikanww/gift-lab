import { useCallback, useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { cn } from './cn';
import { Button } from './Button';
import { useReducedMotionSafe, scaleIn, fadeIn, tweenBase } from '../motion';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children?: ReactNode;
  footer?: ReactNode;
  /** Max width preset. */
  size?: 'sm' | 'md' | 'lg';
  /** Hide the default close (×) button. */
  hideClose?: boolean;
}

const sizeClasses = {
  sm: 'max-w-sm',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
} as const;

const FOCUSABLE =
  'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Accessible dialog: role="dialog" aria-modal, labelled by title, Escape to
 * close, click-outside to close, focus trapped within, body scroll locked,
 * focus restored to the trigger on close. Animates via AnimatePresence.
 */
export function Modal({ open, onClose, title, description, children, footer, size = 'md', hideClose = false }: ModalProps) {
  const animate = useReducedMotionSafe();
  const panelRef = useRef<HTMLDivElement>(null);
  const previouslyFocused = useRef<HTMLElement | null>(null);
  const titleId = useId();
  const descId = useId();

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        onClose();
        return;
      }
      if (e.key === 'Tab') {
        const nodes = panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
        if (!nodes || nodes.length === 0) {
          e.preventDefault();
          return;
        }
        const first = nodes[0];
        const last = nodes[nodes.length - 1];
        const active = document.activeElement;
        if (e.shiftKey && active === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && active === last) {
          e.preventDefault();
          first.focus();
        }
      }
    },
    [onClose],
  );

  useEffect(() => {
    if (!open) return;
    previouslyFocused.current = document.activeElement as HTMLElement | null;
    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';

    // Focus the panel (or first focusable) after mount.
    const raf = requestAnimationFrame(() => {
      const nodes = panelRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
      (nodes && nodes.length ? nodes[0] : panelRef.current)?.focus();
    });

    return () => {
      cancelAnimationFrame(raf);
      document.body.style.overflow = overflow;
      previouslyFocused.current?.focus?.();
    };
  }, [open]);

  return createPortal(
    <AnimatePresence>
      {open && (
        <div className="fixed inset-0 z-modal flex items-center justify-center p-4" onKeyDown={handleKeyDown}>
          <motion.div
            className="absolute inset-0 bg-ink-900/50 backdrop-blur-sm"
            onClick={onClose}
            variants={animate ? fadeIn : undefined}
            initial={animate ? 'hidden' : false}
            animate="visible"
            exit={animate ? 'exit' : undefined}
            aria-hidden="true"
          />
          <motion.div
            ref={panelRef}
            role="dialog"
            aria-modal="true"
            aria-labelledby={titleId}
            aria-describedby={description ? descId : undefined}
            tabIndex={-1}
            variants={animate ? scaleIn : undefined}
            initial={animate ? 'hidden' : false}
            animate="visible"
            exit={animate ? 'exit' : undefined}
            transition={tweenBase}
            className={cn(
              'relative z-modal w-full rounded-xl border border-border bg-surface shadow-lg focus:outline-none',
              sizeClasses[size],
            )}
          >
            <div className="flex items-start justify-between gap-4 p-6 pb-2">
              <div className="flex flex-col gap-1">
                <h2 id={titleId} className="font-display text-2xl">
                  {title}
                </h2>
                {description && (
                  <p id={descId} className="text-sm text-fg-muted">
                    {description}
                  </p>
                )}
              </div>
              {!hideClose && (
                <Button variant="ghost" size="sm" aria-label="Close dialog" onClick={onClose} className="-mr-2 -mt-1 px-2">
                  <CloseIcon />
                </Button>
              )}
            </div>
            {children && <div className="px-6 py-3 text-base text-fg">{children}</div>}
            {footer && <div className="flex justify-end gap-2 p-6 pt-3">{footer}</div>}
          </motion.div>
        </div>
      )}
    </AnimatePresence>,
    document.body,
  );
}

function CloseIcon() {
  return (
    <svg viewBox="0 0 20 20" className="h-4 w-4" fill="none" aria-hidden="true">
      <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" />
    </svg>
  );
}
