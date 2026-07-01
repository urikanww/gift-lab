import { useEffect } from 'react';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import ProtectedRoute from './components/ProtectedRoute';
import CataloguePage from './pages/CataloguePage';
import ProductDesignerPage from './pages/ProductDesignerPage';
import CartPage from './pages/CartPage';
import QuoteListPage from './pages/QuoteListPage';
import QuoteDetailPage from './pages/QuoteDetailPage';
import ProductionQueuePage from './pages/ProductionQueuePage';
import ProcurementPage from './pages/ProcurementPage';
import CatalogueAdminPage from './pages/CatalogueAdminPage';
import LoginPage from './pages/LoginPage';
import { useAuthStore } from './stores/authStore';
import { useQuoteStore } from './stores/quoteStore';

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
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<Layout />}>
          <Route index element={<CataloguePage />} />
          <Route path="catalogue/:id" element={<ProductDesignerPage />} />
          <Route path="cart" element={<CartPage />} />
          <Route path="login" element={<LoginPage />} />
          <Route
            path="quotes"
            element={
              <ProtectedRoute>
                <QuoteListPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="quotes/:id"
            element={
              <ProtectedRoute>
                <QuoteDetailPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="production-queue"
            element={
              <ProtectedRoute staffOnly>
                <ProductionQueuePage />
              </ProtectedRoute>
            }
          />
          <Route
            path="procurement"
            element={
              <ProtectedRoute staffOnly>
                <ProcurementPage />
              </ProtectedRoute>
            }
          />
          <Route
            path="catalogue-admin"
            element={
              <ProtectedRoute staffOnly>
                <CatalogueAdminPage />
              </ProtectedRoute>
            }
          />
          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </BrowserRouter>
  );
}
