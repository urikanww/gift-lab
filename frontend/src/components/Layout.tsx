import { AnimatedOutlet } from './AnimatedOutlet';
import SiteHeader from './SiteHeader';

export default function Layout() {
  return (
    <div className="min-h-screen bg-bg">
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-toast focus:rounded-md focus:bg-primary focus:px-4 focus:py-2 focus:text-primary-fg"
      >
        Skip to content
      </a>

      <SiteHeader />

      <main id="main-content" className="mx-auto max-w-content px-4 py-8 sm:px-6 sm:py-10">
        <AnimatedOutlet />
      </main>

      {/* <SiteFooter /> — added in Task 4 */}
    </div>
  );
}
