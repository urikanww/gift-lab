import { cloneElement, useId, useState, type ReactElement, type ReactNode } from 'react';
import { AnimatePresence, motion } from 'framer-motion';
import { cn } from './cn';
import { useReducedMotionSafe } from '../motion';

type Side = 'top' | 'bottom' | 'left' | 'right';

export interface TooltipProps {
  content: ReactNode;
  side?: Side;
  /** Single focusable/hoverable trigger element. */
  children: ReactElement<{
    'aria-describedby'?: string;
    onMouseEnter?: () => void;
    onMouseLeave?: () => void;
    onFocus?: () => void;
    onBlur?: () => void;
  }>;
}

const sideClasses: Record<Side, string> = {
  top: 'bottom-full left-1/2 -translate-x-1/2 mb-2',
  bottom: 'top-full left-1/2 -translate-x-1/2 mt-2',
  left: 'right-full top-1/2 -translate-y-1/2 mr-2',
  right: 'left-full top-1/2 -translate-y-1/2 ml-2',
};

/**
 * Lightweight tooltip driven by hover AND keyboard focus (WCAG 1.4.13 aware).
 * The trigger is described by the tooltip via aria-describedby. Content should
 * be supplementary - never the only way to get critical information.
 */
export function Tooltip({ content, side = 'top', children }: TooltipProps) {
  const [open, setOpen] = useState(false);
  const animate = useReducedMotionSafe();
  const id = useId();

  const trigger = cloneElement(children, {
    'aria-describedby': open ? id : undefined,
    onMouseEnter: () => setOpen(true),
    onMouseLeave: () => setOpen(false),
    onFocus: () => setOpen(true),
    onBlur: () => setOpen(false),
  });

  return (
    <span className="relative inline-flex">
      {trigger}
      <AnimatePresence>
        {open && (
          <motion.span
            role="tooltip"
            id={id}
            initial={animate ? { opacity: 0, scale: 0.94 } : false}
            animate={{ opacity: 1, scale: 1 }}
            exit={animate ? { opacity: 0, scale: 0.94 } : undefined}
            transition={{ duration: 0.12 }}
            className={cn(
              'pointer-events-none absolute z-tooltip w-max max-w-xs rounded-md bg-ink-900 px-2.5 py-1.5 ' +
                'text-xs font-medium text-ink-0 shadow-md',
              sideClasses[side],
            )}
          >
            {content}
          </motion.span>
        )}
      </AnimatePresence>
    </span>
  );
}
