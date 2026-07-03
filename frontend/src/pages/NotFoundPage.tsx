import { EmptyState, LinkButton } from '../ui';

/**
 * 404 fallback for unknown routes. Previously unknown URLs silently redirected
 * to home, which hid broken links and confused users. Now we say so plainly and
 * offer a way back.
 */
export default function NotFoundPage() {
  return (
    <EmptyState
      title="Page not found"
      description="The page you’re looking for doesn’t exist or may have moved."
      action={<LinkButton to="/">Back to home</LinkButton>}
    />
  );
}
