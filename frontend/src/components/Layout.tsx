import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuthStore } from '../stores/authStore';
import { useCartStore } from '../stores/cartStore';
import { isStaffRole } from '../lib/roles';

export default function Layout() {
  const { user, logout } = useAuthStore();
  const cartCount = useCartStore((s) => s.lines.length);
  const navigate = useNavigate();

  const onLogout = async () => {
    await logout();
    navigate('/');
  };

  return (
    <div className="app">
      <header className="topbar">
        <Link to="/" className="brand">
          Gift Lab
        </Link>
        <nav className="nav">
          <NavLink to="/">Catalogue</NavLink>
          <NavLink to="/cart">Cart ({cartCount})</NavLink>
          {user && <NavLink to="/quotes">Quotes</NavLink>}
          {isStaffRole(user?.role) && (
            <>
              <NavLink to="/production-queue">Queue</NavLink>
              <NavLink to="/procurement">Procurement</NavLink>
              <NavLink to="/catalogue-admin">Catalogue</NavLink>
            </>
          )}
        </nav>
        <div className="topbar__user">
          {user ? (
            <>
              <span>{user.name}</span>
              <button type="button" className="btn btn--ghost" onClick={onLogout}>
                Log out
              </button>
            </>
          ) : (
            <Link to="/login" className="btn btn--ghost">
              Log in
            </Link>
          )}
        </div>
      </header>
      <main className="content">
        <Outlet />
      </main>
    </div>
  );
}
