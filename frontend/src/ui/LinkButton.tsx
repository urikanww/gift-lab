import type { ReactNode } from 'react';
import { Link, type LinkProps } from 'react-router-dom';
import { cn } from './cn';
import { buttonClasses, type ButtonVariant, type ButtonSize } from './Button';

export interface LinkButtonProps extends Omit<LinkProps, 'className'> {
  to: LinkProps['to'];
  variant?: ButtonVariant;
  size?: ButtonSize;
  className?: string;
  children?: ReactNode;
}

/**
 * A react-router <Link> styled to read as a <Button>. Shares buttonClasses so
 * CTA links stay pixel-identical to real buttons (same focus-visible ring +
 * a11y) without forking styling across pages.
 */
export function LinkButton({ to, variant = 'primary', size = 'md', className, children, ...rest }: LinkButtonProps) {
  return (
    <Link to={to} className={cn(buttonClasses({ variant, size }), className)} {...rest}>
      {children}
    </Link>
  );
}
