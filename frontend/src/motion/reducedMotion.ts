import { useReducedMotion } from 'framer-motion';

/**
 * True when the user has NOT requested reduced motion, i.e. it is safe to
 * animate. Thin, intention-revealing wrapper over framer's useReducedMotion so
 * call sites read as `const animate = useReducedMotionSafe()`.
 */
export function useReducedMotionSafe(): boolean {
  return !useReducedMotion();
}

/**
 * Returns variants unchanged when motion is allowed; otherwise strips them so
 * elements render in their final state with no transition. Use for one-off
 * variant objects that aren't already routed through <Motion>.
 */
export function withReducedMotion<T>(variants: T, animate: boolean): T | undefined {
  return animate ? variants : undefined;
}
