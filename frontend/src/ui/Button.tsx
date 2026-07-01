import { forwardRef, type ReactNode } from 'react';
import { motion, type HTMLMotionProps } from 'framer-motion';
import { cn } from './cn';
import { Spinner } from './Spinner';
import { useReducedMotionSafe, springSoft } from '../motion';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'outline' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

// Base on HTMLMotionProps so framer's drag/animation handler types win over the
// native DOM equivalents (avoids the onDrag signature clash).
export interface ButtonProps extends Omit<HTMLMotionProps<'button'>, 'children'> {
  variant?: ButtonVariant;
  size?: ButtonSize;
  /** Shows a spinner and disables the button. */
  loading?: boolean;
  /** Stretch to fill the container width. */
  fullWidth?: boolean;
  leadingIcon?: ReactNode;
  trailingIcon?: ReactNode;
  children?: ReactNode;
}

const base =
  'relative inline-flex items-center justify-center gap-2 font-medium select-none whitespace-nowrap ' +
  'rounded-md transition-colors duration-fast ease-standard ' +
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 ' +
  'focus-visible:ring-offset-bg disabled:opacity-50 disabled:pointer-events-none';

const variantClasses: Record<ButtonVariant, string> = {
  primary: 'bg-primary text-primary-fg hover:bg-primary-hover shadow-xs',
  secondary: 'bg-surface-2 text-fg hover:bg-border',
  ghost: 'bg-transparent text-fg hover:bg-surface-2',
  outline: 'bg-surface text-fg border border-border-strong hover:border-fg-subtle',
  danger: 'bg-danger text-white hover:opacity-90 shadow-xs',
};

const sizeClasses: Record<ButtonSize, string> = {
  sm: 'h-8 px-3 text-sm',
  md: 'h-10 px-4 text-base',
  lg: 'h-12 px-6 text-lg',
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
  {
    variant = 'primary',
    size = 'md',
    loading = false,
    fullWidth = false,
    leadingIcon,
    trailingIcon,
    disabled,
    className,
    children,
    type = 'button',
    ...rest
  },
  ref,
) {
  const animate = useReducedMotionSafe();
  const isDisabled = disabled || loading;

  return (
    <motion.button
      ref={ref}
      type={type}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      whileTap={animate && !isDisabled ? { scale: 0.97 } : undefined}
      transition={springSoft}
      className={cn(base, variantClasses[variant], sizeClasses[size], fullWidth && 'w-full', className)}
      {...rest}
    >
      {loading && <Spinner size={size === 'lg' ? 'md' : 'sm'} className="text-current" />}
      {!loading && leadingIcon}
      {children}
      {!loading && trailingIcon}
    </motion.button>
  );
});
