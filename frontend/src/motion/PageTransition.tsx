import { motion } from 'framer-motion';
import type { ReactNode } from 'react';
import { pageVariants } from './variants';
import { useReducedMotionSafe } from './reducedMotion';

/**
 * Wrap a route's content in this so it participates in the shell's
 * <AnimatePresence> page-transition system. The shell keys the presence on the
 * pathname; each page just needs to render <PageTransition>…</PageTransition>
 * at its root. Honors prefers-reduced-motion (renders static).
 */
export function PageTransition({ children }: { children: ReactNode }) {
  const allow = useReducedMotionSafe();

  if (!allow) {
    return <div>{children}</div>;
  }

  return (
    <motion.div variants={pageVariants} initial="hidden" animate="visible" exit="exit">
      {children}
    </motion.div>
  );
}
