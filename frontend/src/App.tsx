import { Suspense, lazy, useEffect } from 'react';
import { BrowserRouter, Navigate, Outlet, Route, Routes, useParams } from 'react-router-dom';
import Layout from './components/Layout';
import ErrorBoundary from './components/ErrorBoundary';
import ProtectedRoute from './components/ProtectedRoute';
import RoleLayout from './components/RoleLayout';
import { useAuthStore } from './stores/authStore';
import { useQuoteStore } from './stores/quoteStore';
import { Spinner, ThemeProvider, ToastProvider } from './ui';

// Route components are code-split so the heavy designer (fabric.js) and the
// staff-only console pages never land in the initial entry chunk. Each route is
// fetched on demand behind the shared <Suspense> fallback below.
const HomePage = lazy(() => import('./pages/HomePage'));
const CataloguePage = lazy(() => import('./pages/CataloguePage'));
const ProductDetailPage = lazy(() => import('./pages/ProductDetailPage'));
const ProductDesignerPage = lazy(() => import('./pages/ProductDesignerPage'));
const CartPage = lazy(() => import('./pages/CartPage'));
const CheckoutPage = lazy(() => import('./pages/CheckoutPage'));
const TrackPage = lazy(() => import('./pages/TrackPage'));
const BrandKitPage = lazy(() => import('./pages/BrandKitPage'));
const KitBuilderPage = lazy(() => import('./pages/KitBuilderPage'));
const QuoteListPage = lazy(() => import('./pages/QuoteListPage'));
const QuoteDetailPage = lazy(() => import('./pages/QuoteDetailPage'));
const ProductionQueuePage = lazy(() => import('./pages/ProductionQueuePage'));
const ProcurementPage = lazy(() => import('./pages/ProcurementPage'));
const ReorderBuyListPage = lazy(() => import('./pages/ReorderBuyListPage'));
const CatalogueAdminPage = lazy(() => import('./pages/CatalogueAdminPage'));
const ProductAdminPage = lazy(() => import('./pages/ProductAdminPage'));
const ProductAdminCreatePage = lazy(() => import('./pages/ProductAdminCreatePage'));
const ProductAdminDetailPage = lazy(() => import('./pages/ProductAdminDetailPage'));
const PricingAdminPage = lazy(() => import('./pages/PricingAdminPage'));
const UserAdminPage = lazy(() => import('./pages/UserAdminPage'));
const UserAdminCreatePage = lazy(() => import('./pages/UserAdminCreatePage'));
const UserAdminDetailPage = lazy(() => import('./pages/UserAdminDetailPage'));
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const LoginPage = lazy(() => import('./pages/LoginPage'));
const RegisterPage = lazy(() => import('./pages/RegisterPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));

function RedirectCatalogueToProduct() {
  const { id } = useParams();
  return <Navigate to={`/products/${id}`} replace />;
}

/** Shared fallback for lazily-loaded routes. */
function RouteFallback() {
  return (
    <div className="flex min-h-[40vh] w-full items-center justify-center text-fg-muted" role="status">
      <Spinner size="lg" label="Loading…" />
    </div>
  );
}

/**
 * Route-level boundary rendered inside each shell. A crash in one route falls
 * back here - keeping the surrounding layout (header/nav) intact - instead of
 * bubbling to the app-root boundary and blanking the whole shell. Also provides
 * the <Suspense> fallback while the lazy route chunk loads.
 */
function RouteBoundary() {
  return (
    <ErrorBoundary>
      <Suspense fallback={<RouteFallback />}>
        <Outlet />
      </Suspense>
    </ErrorBoundary>
  );
}

export default function App() {
  const { user, status, fetchUser } = useAuthStore();
  const { subscribeCompany, unsubscribeCompany } = useQuoteStore();

  useEffect(() => {
    void fetchUser();
  }, [fetchUser]);

  // Subscribe a buyer to their company's realtime channel once known. Depend on
  // the primitive company_id, NOT the whole user object - the user reference
  // changes on every fetchUser/login, which would needlessly tear down and
  // re-establish the private-channel subscription (and drop events in the gap).
  const companyId = user?.company_id ?? null;
  useEffect(() => {
    if (companyId !== null) {
      subscribeCompany(companyId);
    }
    return () => unsubscribeCompany();
  }, [companyId, subscribeCompany, unsubscribeCompany]);

  if (status === 'idle') return null;

  return (
    <ThemeProvider>
      <ToastProvider>
        <BrowserRouter
          future={{ v7_startTransition: true, v7_relativeSplatPath: true }}
        >
          <Routes>
        <Route path="/" element={<Layout />}>
          <Route element={<RouteBoundary />}>
            <Route index element={<HomePage />} />
            <Route path="products" element={<CataloguePage />} />
            <Route path="products/:id" element={<ProductDetailPage />} />
            <Route path="design/:id" element={<ProductDesignerPage />} />
            <Route path="cart" element={<CartPage />} />
            <Route path="checkout" element={<CheckoutPage />} />
            <Route path="track" element={<TrackPage />} />
            <Route path="kits" element={<KitBuilderPage />} />
            <Route path="login" element={<LoginPage />} />
            <Route path="register" element={<RegisterPage />} />
            <Route path="catalogue" element={<Navigate to="/products" replace />} />
            <Route path="catalogue/:id" element={<RedirectCatalogueToProduct />} />
            <Route path="*" element={<NotFoundPage />} />
          </Route>
        </Route>

          {/* Authenticated area under ONE RoleLayout parent (staff → console shell,
              buyers → shopfront). Keeping every staff destination under this single
              parent means StaffLayout mounts once per session - navigating between
              /quotes and /dashboard no longer remounts the shell or refetches the
              dashboard. Staff-only pages carry their own staffOnly guard so buyers
              (who resolve to Layout here) are redirected away. */}
          <Route
            element={
              <ProtectedRoute>
                <RoleLayout />
              </ProtectedRoute>
            }
          >
            <Route element={<RouteBoundary />}>
              <Route path="quotes" element={<QuoteListPage />} />
              <Route path="quotes/:id" element={<QuoteDetailPage />} />
              <Route path="brand-kit" element={<BrandKitPage />} />
              <Route path="dashboard" element={<ProtectedRoute staffOnly><DashboardPage /></ProtectedRoute>} />
              <Route path="production-queue" element={<ProtectedRoute staffOnly><ProductionQueuePage /></ProtectedRoute>} />
              <Route path="procurement" element={<ProtectedRoute staffOnly><ProcurementPage /></ProtectedRoute>} />
              <Route path="reorders" element={<ProtectedRoute staffOnly><ReorderBuyListPage /></ProtectedRoute>} />
              <Route path="catalogue-admin" element={<ProtectedRoute staffOnly><CatalogueAdminPage /></ProtectedRoute>} />
              <Route path="product-admin" element={<ProtectedRoute staffOnly><ProductAdminPage /></ProtectedRoute>} />
              <Route path="product-admin/new" element={<ProtectedRoute staffOnly><ProductAdminCreatePage /></ProtectedRoute>} />
              <Route path="product-admin/:id" element={<ProtectedRoute staffOnly><ProductAdminDetailPage /></ProtectedRoute>} />
              <Route path="pricing-admin" element={<ProtectedRoute superadminOnly><PricingAdminPage /></ProtectedRoute>} />
              <Route path="user-admin" element={<ProtectedRoute staffOnly><UserAdminPage /></ProtectedRoute>} />
              <Route path="user-admin/new" element={<ProtectedRoute staffOnly><UserAdminCreatePage /></ProtectedRoute>} />
              <Route path="user-admin/:id" element={<ProtectedRoute staffOnly><UserAdminDetailPage /></ProtectedRoute>} />
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
      </ToastProvider>
    </ThemeProvider>
  );
}
