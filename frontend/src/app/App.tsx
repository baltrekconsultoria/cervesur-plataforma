import { Navigate, Outlet, Route, Routes, useLocation } from 'react-router';
import { homePath } from '../auth/returnTo';
import { DeniedState } from '../components/DeniedState';
import { AdminHistoryPage } from '../features/admin/HistoryPage';
import { AdminAccessPage, AdminCompaniesPage, AdminDashboardPage, AdminReportsPage, AdminUsersPage } from '../features/admin/AdminPages';
import { AccessWizardPage } from '../features/admin/AccessWizard';
import { CompanyReportsPage } from '../features/admin/CompanyReportsPage';
import { CompaniesPage } from '../features/companies/CompaniesPage';
import { LibraryPage } from '../features/library/LibraryPage';
import { LoginPage } from '../features/login/LoginPage';
import { ProfilePage } from '../features/profile/ProfilePage';
import { ViewerPage } from '../features/viewer/ViewerPage';
import { useApp } from './AppProvider';
import { AppLayout, Page } from './layout';
import type { Role } from '../api/types';

/**
 * Guarda de sesión. Si la sesión terminó por un 401 se recuerda la ruta (se
 * sanea al reingresar); tras un cierre manual no se recuerda nada.
 * Es una comodidad de navegación: la autorización real la aplica el backend.
 */
function RequireAuth() {
  const { user, sessionExpired } = useApp();
  const location = useLocation();
  if (!user) return <Navigate to="/login" replace state={sessionExpired ? { returnTo: `${location.pathname}${location.search}` } : null} />;
  return <AppLayout />;
}

function RequireRole({ role }: { role: Role }) {
  const { user } = useApp();
  if (!user) return null;
  if (user.role === role) return <Outlet />;
  if (user.role === 'admin') return <Navigate to="/admin" replace />;
  return (
    <Page title="Acceso denegado" crumbs={[{ label: 'Portal', to: '/reportes' }, { label: 'Acceso denegado' }]}>
      <DeniedState kind="section" backTo="/reportes" backLabel="Mis reportes" />
    </Page>
  );
}

function Home() {
  const { user } = useApp();
  return <Navigate to={user ? homePath(user.role) : '/login'} replace />;
}

// Piloto visual (UX-07D.4): la compilación `build:pilot` NO registra las pantallas de administración (alta, edición, eliminación ni asignación): el valor se
// sustituye en tiempo de compilación y el bundler elimina esas pantallas. Es una reducción de superficie; la protección real sigue siendo el servidor (403).
const PILOT = import.meta.env.VITE_PILOT === 'true';
// Piloto ADMINISTRATIVO de solo lectura (UX-07E.B): conserva las pantallas de consulta de administración y elimina en compilación el asistente de accesos y las asociaciones empresa–informe
// (únicas pantallas con acciones de escritura sin condición de capacidad). Es reducción de superficie: el servidor rechaza cualquier escritura con 403.
const ADMIN_PILOT = PILOT && import.meta.env.VITE_PILOT_MODE === 'admin';
// UX-08H: administración con escrituras: registra TODAS las pantallas de administración (los botones dependen de las capacidades que declare el servidor).
const ADMIN_WRITE = PILOT && import.meta.env.VITE_PILOT_MODE === 'admin-write';

export function App() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route element={<RequireAuth />}>
        <Route path="/" element={<Home />} />
        <Route element={<RequireRole role="user" />}>
          <Route path="/reportes" element={<LibraryPage />} />
          <Route path="/empresas" element={<CompaniesPage />} />
        </Route>
        <Route path="/reportes/:reportId" element={<ViewerPage />} />
        <Route path="/perfil" element={<ProfilePage />} />
        {(!PILOT || ADMIN_PILOT || ADMIN_WRITE) && (
        <Route element={<RequireRole role="admin" />}>
          <Route path="/admin" element={<AdminDashboardPage />} />
          <Route path="/admin/usuarios" element={<AdminUsersPage />} />
          <Route path="/admin/empresas" element={<AdminCompaniesPage />} />
          <Route path="/admin/reportes" element={<AdminReportsPage />} />
          <Route path="/admin/accesos" element={<AdminAccessPage />} />
          {!ADMIN_PILOT && <Route path="/admin/accesos/nueva" element={<AccessWizardPage />} />}
          {!ADMIN_PILOT && <Route path="/admin/historial" element={<AdminHistoryPage />} />}
          {!ADMIN_PILOT && <Route path="/admin/asociaciones" element={<CompanyReportsPage />} />}
        </Route>
        )}
        <Route path="*" element={<Home />} />
      </Route>
    </Routes>
  );
}
