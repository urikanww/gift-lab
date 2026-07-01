import { forwardRef } from 'react';
import { motion, type HTMLMotionProps } from 'framer-motion';
import { useReducedMotionSafe } from './reducedMotion';

/**
 * Reduced-motion-aware motion.div.
 *
 * Behaves like framer's <motion.div> but, when the user prefers reduced motion,
 * drops variants/animation props so the element renders statically in its
 * final state (no transform, no transition) while still mounting normally.
 * Prefer this over raw <motion.div> for shared UI so a11y is baked in.
 */
export const Motion = forwardRef<HTMLDivElement, HTMLMotionProps<'div'>>(function Motion(
  { variants, initial, animate, exit, whileHover, whileTap, transition, ...rest },
  ref,
) {
  const allow = useReducedMotionSafe();

  if (!allow) {
    // Strip all motion props; render in the resolved/visible state.
    return <motion.div ref={ref} {...rest} />;
  }

  return (
    <motion.div
      ref={ref}
      variants={variants}
      initial={initial}
      animate={animate}
      exit={exit}
      whileHover={whileHover}
      whileTap={whileTap}
      transition={transition}
      {...rest}
    />
  );
});
