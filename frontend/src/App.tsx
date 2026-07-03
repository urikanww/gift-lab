import { useEffect } from 'react';
import { BrowserRouter, Navigate, Route, Routes, useParams } from 'react-router-dom';
import Layout from './components/Layout';
import ProtectedRoute from './components/ProtectedRoute';
import RoleLayout from './components/RoleLayout';
import HomePage from './pages/HomePage';
import CataloguePage from './pages/CataloguePage';
import ProductDetailPage from './pages/ProductDetailPage';
import ProductDesignerPage from './pages/ProductDesignerPage';
import CartPage from './pages/CartPage';
import CheckoutPage from './pages/CheckoutPage';
import TrackPage from './pages/TrackPage';
import BrandKitPage from './pages/BrandKitPage';
import KitBuilderPage from './pages/KitBuilderPage';
import QuoteListPage from './pages/QuoteListPage';
import QuoteDetailPage from './pages/QuoteDetailPage';
import ProductionQueuePage from './pages/ProductionQueuePage';
import ProcurementPage from './pages/ProcurementPage';
import CatalogueAdminPage from './pages/CatalogueAdminPage';
import DashboardPage from './pages/DashboardPage';
import LoginPage from './pages/LoginPage';
import { useAuthStore } from './stores/authStore';
import { useQuoteStore } from './stores/quoteStore';
import { ThemeProvider, ToastProvider } from './ui';

function RedirectCatalogueToProduct() {
  const { id } = useParams();
  return <Navigate to={`/products/${id}`} replace />;
}

export default function App() {
  const { user, status, fetchUser } = useAuthStore();
  const { subscribeCompany, unsubscribeCompany } = useQuoteStore();

  useEffect(() => {
    void fetchUser();
  }, [fetchUser]);

  // Subscribe a buyer to their company's realtime channel once known. Depend on
  // the primitive company_id, NOT the whole user object — the user reference
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
          <Route index element={<HomePage />} />
          <Route path="products" element={<CataloguePage />} />
          <Route path="products/:id" element={<ProductDetailPage />} />
          <Route path="design/:id" element={<ProductDesignerPage />} />
          <Route path="cart" element={<CartPage />} />
          <Route path="checkout" element={<CheckoutPage />} />
          <Route path="track" element={<TrackPage />} />
          <Route path="kits" element={<KitBuilderPage />} />
          <Route path="login" element={<LoginPage />} />
          <Route path="catalogue" element={<Navigate to="/products" replace />} />
          <Route path="catalogue/:id" element={<RedirectCatalogueToProduct />} />
            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>

          {/* Authenticated area under ONE RoleLayout parent (staff → console shell,
              buyers → shopfront). Keeping every staff destination under this single
              parent means StaffLayout mounts once per session — navigating between
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
            <Route path="quotes" element={<QuoteListPage />} />
            <Route path="quotes/:id" element={<QuoteDetailPage />} />
            <Route path="brand-kit" element={<BrandKitPage />} />
            <Route path="dashboard" element={<ProtectedRoute staffOnly><DashboardPage /></ProtectedRoute>} />
            <Route path="production-queue" element={<ProtectedRoute staffOnly><ProductionQueuePage /></ProtectedRoute>} />
            <Route path="procurement" element={<ProtectedRoute staffOnly><ProcurementPage /></ProtectedRoute>} />
            <Route path="catalogue-admin" element={<ProtectedRoute staffOnly><CatalogueAdminPage /></ProtectedRoute>} />
          </Route>
        </Routes>
      </BrowserRouter>
      </ToastProvider>
    </ThemeProvider>
  );
}
