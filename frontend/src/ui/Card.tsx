import { forwardRef, type HTMLAttributes } from 'react';
import { motion, type HTMLMotionProps } from 'framer-motion';
import { cn } from './cn';
import { useReducedMotionSafe, springSoft } from '../motion';

export interface CardProps extends HTMLAttributes<HTMLDivElement> {
  /** Adds hover lift + shadow. Use for clickable cards. */
  interactive?: boolean;
  padding?: 'none' | 'sm' | 'md' | 'lg';
}

const paddingClasses = {
  none: '',
  sm: 'p-3',
  md: 'p-5',
  lg: 'p-7',
} as const;

export const Card = forwardRef<HTMLDivElement, CardProps>(function Card(
  { interactive = false, padding = 'md', className, children, ...rest },
  ref,
) {
  const animate = useReducedMotionSafe();
  const classes = cn(
    'rounded-lg border border-border bg-surface text-fg shadow-card',
    interactive && 'cursor-pointer transition-shadow duration-base ease-standard',
    paddingClasses[padding],
    className,
  );

  if (interactive) {
    return (
      <motion.div
        ref={ref}
        whileHover={animate ? { y: -3, boxShadow: 'var(--shadow-md)' } : undefined}
        transition={springSoft}
        className={classes}
        {...(rest as HTMLMotionProps<'div'>)}
      >
        {children}
      </motion.div>
    );
  }

  return (
    <div ref={ref} className={classes} {...rest}>
      {children}
    </div>
  );
});

export function CardHeader({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('mb-3 flex flex-col gap-1', className)} {...rest} />;
}

export function CardTitle({ className, ...rest }: HTMLAttributes<HTMLHeadingElement>) {
  return <h3 className={cn('font-display text-xl', className)} {...rest} />;
}

export function CardDescription({ className, ...rest }: HTMLAttributes<HTMLParagraphElement>) {
  return <p className={cn('text-sm text-fg-muted', className)} {...rest} />;
}
