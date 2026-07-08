import { useLocation, useOutlet } from 'react-router-dom';
import { AnimatePresence, motion } from 'framer-motion';
import { pageVariants, useReducedMotionSafe } from '../motion';

/**
 * Drop-in replacement for <Outlet> that animates route changes.
 *
 * The rendered child element is snapshotted per-location so AnimatePresence can
 * cross-fade the outgoing and incoming pages. Individual pages do NOT need to
 * do anything - the shell provides the transition. (Pages may still use
 * <PageTransition> internally for finer control if desired.)
 *
 * Honors prefers-reduced-motion: renders the current outlet statically.
 */
export function AnimatedOutlet() {
  const location = useLocation();
  const outlet = useOutlet();
  const animate = useReducedMotionSafe();

  if (!animate) {
    return <>{outlet}</>;
  }

  return (
    <AnimatePresence mode="wait" initial={false}>
      <motion.div
        key={location.pathname}
        variants={pageVariants}
        initial="hidden"
        animate="visible"
        exit="exit"
      >
        {outlet}
      </motion.div>
    </AnimatePresence>
  );
}
