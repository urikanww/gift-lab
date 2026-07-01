/**
 * Gift-Lab UI primitives — public API.
 *
 * All primitives ship with baked-in motion (reduced-motion aware) and WCAG 2.1
 * AA a11y. Import from here:
 *
 *   import { Button, Card, Modal, useToast } from '@/ui';  // (relative in this repo)
 */
export { cn } from './cn';
export type { ClassValue } from './cn';

export { ThemeProvider, useTheme } from './ThemeProvider';
export type { Theme } from './ThemeProvider';

export { Button } from './Button';
export type { ButtonProps, ButtonVariant, ButtonSize } from './Button';

export { Input } from './Input';
export type { InputProps } from './Input';

export { Select } from './Select';
export type { SelectProps, SelectOption } from './Select';

export { Badge } from './Badge';
export type { BadgeProps, BadgeTone, BadgeSize } from './Badge';

export { Card, CardHeader, CardTitle, CardDescription } from './Card';
export type { CardProps } from './Card';

export { Modal } from './Modal';
export type { ModalProps } from './Modal';

export { ToastProvider, useToast } from './Toast';
export type { ToastOptions, ToastTone } from './Toast';

export { Skeleton, SkeletonText } from './Skeleton';
export type { SkeletonProps } from './Skeleton';

export { EmptyState } from './EmptyState';
export type { EmptyStateProps } from './EmptyState';

export { Tooltip } from './Tooltip';
export type { TooltipProps } from './Tooltip';

export { Spinner } from './Spinner';
export type { SpinnerProps, SpinnerSize } from './Spinner';
