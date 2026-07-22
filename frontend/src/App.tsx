import { Suspense, lazy, useEffect } from 'react';
import {
  BrowserRouter,
  Navigate,
  Outlet,
  Route,
  Routes,
  useLocation,
  useNavigationType,
  useParams,
} from 'react-router-dom';
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
const TrackViewPage = lazy(() => import('./pages/TrackViewPage'));
const GiftIdeasPage = lazy(() => import('./pages/GiftIdeasPage'));
const QuoteListPage = lazy(() => import('./pages/QuoteListPage'));
const AddressBookPage = lazy(() => import('./pages/AddressBookPage'));
const BuyerDashboardPage = lazy(() => import('./pages/BuyerDashboardPage'));
const QuoteDetailPage = lazy(() => import('./pages/QuoteDetailPage'));
const ProductionQueuePage = lazy(() => import('./pages/ProductionQueuePage'));
const ProcurementPage = lazy(() => import('./pages/ProcurementPage'));
const ReorderBuyListPage = lazy(() => import('./pages/ReorderBuyListPage'));
const CatalogueAdminPage = lazy(() => import('./pages/CatalogueAdminPage'));
const BlankRecommendationPage = lazy(() => import('./pages/BlankRecommendationPage'));
const ProductAdminPage = lazy(() => import('./pages/ProductAdminPage'));
const ProductAdminCreatePage = lazy(() => import('./pages/ProductAdminCreatePage'));
const ProductAdminDetailPage = lazy(() => import('./pages/ProductAdminDetailPage'));
const PricingAdminPage = lazy(() => import('./pages/PricingAdminPage'));
const NotificationSettingsPage = lazy(() => import('./pages/NotificationSettingsPage'));
const UserAdminPage = lazy(() => import('./pages/UserAdminPage'));
const UserAdminCreatePage = lazy(() => import('./pages/UserAdminCreatePage'));
const UserAdminDetailPage = lazy(() => import('./pages/UserAdminDetailPage'));
const DashboardPage = lazy(() => import('./pages/DashboardPage'));
const LoginPage = lazy(() => import('./pages/LoginPage'));
const RegisterPage = lazy(() => import('./pages/RegisterPage'));
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'));
const CylinderCalibrationSpike = lazy(() => import('./pages/CylinderCalibrationSpike'));

function RedirectCatalogueToProduct() {
  const { id } = useParams();
  return <Navigate to={`/products/${id}`} replace />;
}

/**
 * Reset scroll to the top on forward navigation (PUSH/REPLACE) so a new page -
 * e.g. a product opened from the bottom of the grid - starts at the top, not
 * mid-footer. POP (browser back) is left alone so returning to the catalogue
 * keeps the previous scroll position. Search-param-only changes (filters,
 * pagination) don't change pathname, so they never trigger a jump.
 */
function ScrollToTop() {
  const { pathname } = useLocation();
  const navType = useNavigationType();
  useEffect(() => {
    if (navType !== 'POP') window.scrollTo(0, 0);
  }, [pathname, navType]);
  return null;
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
          <ScrollToTop />
          <Routes>
        {/* TEMP spike route (throwaway): standalone, no Layout/auth guard so it
            loads without login. Remove when the calibration work is done. */}
        <Route
          path="/spike/cylinder"
          element={
            <Suspense fallback={<RouteFallback />}>
              <CylinderCalibrationSpike />
            </Suspense>
          }
        />
        <Route path="/" element={<Layout />}>
          <Route element={<RouteBoundary />}>
            <Route index element={<HomePage />} />
            <Route path="products" element={<CataloguePage />} />
            <Route path="products/:id" element={<ProductDetailPage />} />
            <Route path="design/:id" element={<ProductDesignerPage />} />
            <Route path="cart" element={<CartPage />} />
            <Route path="checkout" element={<CheckoutPage />} />
            <Route path="track" element={<TrackPage />} />
            <Route path="track/view" element={<TrackViewPage />} />
            <Route path="gift-ideas" element={<GiftIdeasPage />} />
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
              <Route path="account" element={<BuyerDashboardPage />} />
              <Route path="quotes" element={<QuoteListPage />} />
              <Route path="orders/:reference" element={<QuoteDetailPage />} />
              <Route path="account/addresses" element={<AddressBookPage />} />
              <Route path="dashboard" element={<ProtectedRoute staffOnly><DashboardPage /></ProtectedRoute>} />
              <Route path="production-queue" element={<ProtectedRoute staffOnly><ProductionQueuePage /></ProtectedRoute>} />
              <Route path="procurement" element={<ProtectedRoute staffOnly><ProcurementPage /></ProtectedRoute>} />
              <Route path="reorders" element={<ProtectedRoute staffOnly><ReorderBuyListPage /></ProtectedRoute>} />
              <Route path="catalogue-admin" element={<ProtectedRoute staffOnly><CatalogueAdminPage /></ProtectedRoute>} />
              <Route path="blank-recommendations" element={<ProtectedRoute staffOnly><BlankRecommendationPage /></ProtectedRoute>} />
              <Route path="product-admin" element={<ProtectedRoute staffOnly><ProductAdminPage /></ProtectedRoute>} />
              <Route path="product-admin/new" element={<ProtectedRoute staffOnly><ProductAdminCreatePage /></ProtectedRoute>} />
              <Route path="product-admin/:id" element={<ProtectedRoute staffOnly><ProductAdminDetailPage /></ProtectedRoute>} />
              {/* Pricing is sensitive but delegable: superadmin, or a staff_admin
                  granted pricing.view. The backend gates the same permission. */}
              <Route path="pricing-admin" element={<ProtectedRoute permission="pricing.view"><PricingAdminPage /></ProtectedRoute>} />
              {/* Staff-level, unlike Pricing: this is an operational setting about
                  what clients hear, not a financial constant. */}
              <Route path="notification-settings" element={<ProtectedRoute staffOnly><NotificationSettingsPage /></ProtectedRoute>} />
              {/* Users is sensitive but delegable: superadmin, or a staff_admin
                  granted users.view. Write actions behind these pages need
                  users.manage, enforced by the backend route middleware. */}
              <Route path="user-admin" element={<ProtectedRoute permission="users.view"><UserAdminPage /></ProtectedRoute>} />
              <Route path="user-admin/new" element={<ProtectedRoute permission="users.manage"><UserAdminCreatePage /></ProtectedRoute>} />
              <Route path="user-admin/:id" element={<ProtectedRoute permission="users.view"><UserAdminDetailPage /></ProtectedRoute>} />
            </Route>
          </Route>
        </Routes>
      </BrowserRouter>
      </ToastProvider>
    </ThemeProvider>
  );
}
