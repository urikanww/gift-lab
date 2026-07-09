import { useLocation } from 'react-router-dom';
import { AnimatedOutlet } from './AnimatedOutlet';
import SiteHeader from './SiteHeader';
import SiteFooter from './SiteFooter';
import { cn } from '../ui';

export default function Layout() {
  // The product designer is a wide "studio" surface, so it opts out of the
  // site-wide readable content cap and runs to a wider studio width. Every
  // other route keeps the standard max-w-content chrome.
  const { pathname } = useLocation();
  const isStudio = pathname.startsWith('/design/');

  return (
    <div className="min-h-screen bg-bg">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-toast focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-fg"
      >
        Skip to content
      </a>

      <SiteHeader />

      <main
        id="main-content"
        className={cn(
          'mx-auto px-4 py-8 sm:px-6 sm:py-10',
          isStudio ? 'max-w-[1600px]' : 'max-w-content',
        )}
      >
        <AnimatedOutlet />
      </main>

      <SiteFooter />
    </div>
  );
}
