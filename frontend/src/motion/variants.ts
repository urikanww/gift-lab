import type { Variants } from 'framer-motion';
import { duration, easing, springSnappy, tweenBase } from './transitions';

/**
 * Reusable Framer Motion variants. Every animation drives transform/opacity
 * only (GPU-friendly, no layout thrash, no CLS). Compose them with the
 * <Motion> / hooks in reducedMotion.ts so prefers-reduced-motion is honored.
 */

/** Fade + rise. The workhorse enter animation for content blocks. */
export const fadeInUp: Variants = {
  hidden: { opacity: 0, y: 12 },
  visible: { opacity: 1, y: 0, transition: tweenBase },
  exit: { opacity: 0, y: 8, transition: { duration: duration.fast, ease: easing.standard } },
};

/** Plain fade — use when vertical movement would fight the layout. */
export const fadeIn: Variants = {
  hidden: { opacity: 0 },
  visible: { opacity: 1, transition: tweenBase },
  exit: { opacity: 0, transition: { duration: duration.fast } },
};

/** Scale-in from slightly small — good for modals, popovers, cards. */
export const scaleIn: Variants = {
  hidden: { opacity: 0, scale: 0.96 },
  visible: { opacity: 1, scale: 1, transition: springSnappy },
  exit: { opacity: 0, scale: 0.97, transition: { duration: duration.fast, ease: easing.standard } },
};

/** Slide up from the bottom edge — for sheets/toasts. */
export const slideUp: Variants = {
  hidden: { opacity: 0, y: 24 },
  visible: { opacity: 1, y: 0, transition: springSnappy },
  exit: { opacity: 0, y: 16, transition: { duration: duration.fast } },
};

/**
 * Parent container that staggers children. Pair with `staggerItem` (or any of
 * the item variants above) on direct children and drive with the same
 * hidden/visible states.
 */
export const staggerContainer: Variants = {
  hidden: {},
  visible: {
    transition: { staggerChildren: 0.06, delayChildren: 0.04 },
  },
  exit: {
    transition: { staggerChildren: 0.03, staggerDirection: -1 },
  },
};

/** Default child for a staggerContainer (alias of fadeInUp semantics). */
export const staggerItem: Variants = {
  hidden: { opacity: 0, y: 14 },
  visible: { opacity: 1, y: 0, transition: tweenBase },
  exit: { opacity: 0, y: 8, transition: { duration: duration.fast } },
};

/** Page-level transition used by <PageTransition>/AnimatePresence in the shell. */
export const pageVariants: Variants = {
  hidden: { opacity: 0, y: 8 },
  visible: { opacity: 1, y: 0, transition: { duration: duration.base, ease: easing.out } },
  exit: { opacity: 0, y: -6, transition: { duration: duration.fast, ease: easing.standard } },
};
