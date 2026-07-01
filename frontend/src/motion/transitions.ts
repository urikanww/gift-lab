import type { Transition } from 'framer-motion';

/**
 * Duration + easing tokens for Framer Motion, kept numerically in sync with the
 * CSS motion tokens in index.css (--dur-*, --ease-*). Framer needs JS values,
 * so these are the canonical JS mirror.
 */
export const duration = {
  instant: 0.08,
  fast: 0.14,
  base: 0.22,
  slow: 0.36,
  slower: 0.56,
} as const;

/** Cubic-bezier control points matching the CSS easing tokens. */
export const easing = {
  standard: [0.2, 0, 0, 1],
  emphasized: [0.2, 0, 0, 1.2],
  out: [0.16, 1, 0.3, 1],
  inOut: [0.65, 0, 0.35, 1],
} as const;

/** Default tween for most enter/exit transitions. */
export const tweenBase: Transition = {
  duration: duration.base,
  ease: easing.standard,
};

/** Snappier tween for hover/press micro-interactions. */
export const tweenFast: Transition = {
  duration: duration.fast,
  ease: easing.standard,
};

/** Softer, longer tween for large surfaces (page/hero). */
export const tweenOut: Transition = {
  duration: duration.slow,
  ease: easing.out,
};

/** Spring for interactive, physical feel (buttons, popovers, drag). */
export const springSoft: Transition = {
  type: 'spring',
  stiffness: 380,
  damping: 30,
  mass: 0.8,
};

/** Snappier spring for small pop-in elements (badges, toasts). */
export const springSnappy: Transition = {
  type: 'spring',
  stiffness: 520,
  damping: 32,
  mass: 0.6,
};
