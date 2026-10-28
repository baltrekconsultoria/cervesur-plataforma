import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { Link, Outlet, useLocation } from 'react-router';
import { initials } from '../lib/text';
import { useApp } from './AppProvider';
import { DemoControls } from './DemoControls';

interface LayoutContextValue { drawerOpen: boolean; setDrawerOpen: (open: boolean) => void }
const LayoutContext = createContext<LayoutContextValue | null>(null);

interface NavEntry { to: string; label: string; icon: string; active?(pathname: string, from: string | undefined): boolean }
type NavItem = NavEntry | { section: string };

const USER_NAV: NavItem[] = [
  { to: '/reportes', label: 'Mis reportes', icon: '▦', active: p => p.startsWith('/reportes') },
  { to: '/empresas', label: 'Mis empresas', icon: '▤' },
  { to: '/perfil', label: 'Mi perfil', icon: '◉' }
];
const ADMIN_NAV: NavItem[] = [
  { section: 'Administración' },
  { to: '/admin', label: 'Inicio', icon: '⌂' },
  { to: '/admin/usuarios', label: 'Usuarios', icon: '♟' },
  { to: '/admin/empresas', label: 'Empresas', icon: '▤' },
  { to: '/admin/reportes', label: 'Reportes', icon: '▦', active: (p, from) => p.startsWith('/admin/reportes') || (p.startsWith('/reportes/') && Boolean(from?.startsWith('/admin/reportes'))) },
  { to: '/admin/asociaciones', label: 'Informes por empresa', icon: '▥' },
  { to: '/admin/accesos', label: 'Accesos y asignaciones', icon: '⇄' },
  { section: 'Cuenta' },
  { to: '/perfil', label: 'Mi perfil', icon: '◉' }
];

export function AppLayout(): ReactNode {
  const { user, logout } = useApp();
  const location = useLocation();
  const [drawerOpen, setDrawerOpen] = useState(false);
  const sidebarRef = useRef<HTMLElement>(null);
  const admin = user?.role === 'admin';
  const from = (location.state as { from?: string } | null)?.from;

  // Al cambiar de ruta el menú móvil se cierra (ajuste de estado durante el render, sin efecto).
  const [drawerPath, setDrawerPath] = useState(location.pathname);
  if (drawerPath !== location.pathname) { setDrawerPath(location.pathname); setDrawerOpen(false); }
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') setDrawerOpen(open => { if (open) document.getElementById('menu-button')?.focus(); return false; }); };
    const onResize = () => { if (window.innerWidth > 760) setDrawerOpen(false); };
    document.addEventListener('keydown', onKey);
    window.addEventListener('resize', onResize);
    return () => { document.removeEventListener('keydown', onKey); window.removeEventListener('resize', onResize); };
  }, []);

  const items = admin ? ADMIN_NAV : USER_NAV;
  return (
    <LayoutContext.Provider value={{ drawerOpen, setDrawerOpen }}>
      <a className="skip-link" href="#view">Saltar al contenido</a>
      <div className={`app-shell${admin ? ' is-admin' : ''}`}>
        {drawerOpen && <div className="drawer-backdrop" onClick={() => setDrawerOpen(false)} />}
        <aside id="sidebar" ref={sidebarRef} className={`sidebar${drawerOpen ? ' open' : ''}`} aria-label="Navegación principal">
          <div className="sidebar-brand"><img src="/branding/logo-cervesur-horizontal.png" alt="Corporación Cervesur" /></div>
          <nav className="navigation" aria-label="Secciones">
            {items.map(item => 'section' in item
              ? <div key={item.section} className="nav-section" role="presentation">{item.section}</div>
              : (
                <Link key={`${item.to}-${item.label}`} to={item.to} className="nav-button" aria-current={(item.active ? item.active(location.pathname, from) : location.pathname === item.to) ? 'page' : undefined}>
                  <span className="nav-icon" aria-hidden="true">{item.icon}</span>{item.label}
                </Link>
              ))}
          </nav>
          <div className="sidebar-footer">
            <span className="role-badge">{admin ? 'Modo administrador' : 'Usuario'}</span>
            <button type="button" className="text-button" onClick={() => { void logout(); }}>Cerrar sesión</button>
          </div>
        </aside>
        <section className="main-column"><Outlet /></section>
      </div>
    </LayoutContext.Provider>
  );
}

export interface Crumb { label: string; to?: string }

/** Cabecera + contenedor de cada pantalla. El foco pasa al título al entrar, salvo al volver a un listado. */
export function Page({ title, crumbs, children }: { title: string; crumbs: Crumb[]; children: ReactNode }) {
  const layout = useContext(LayoutContext);
  const { user } = useApp();
  const location = useLocation();
  const titleRef = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    const returning = Boolean((location.state as { focusId?: string } | null)?.focusId);
    if (!returning) { window.scrollTo(0, 0); titleRef.current?.focus({ preventScroll: true }); }
    // Solo al montar la pantalla.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  useEffect(() => { document.title = `${title} | Cervesur`; }, [title]);

  return (
    <>
      <header className="topbar">
        <button id="menu-button" className="icon-button mobile-only" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded={layout?.drawerOpen ?? false} onClick={() => layout?.setDrawerOpen(!layout.drawerOpen)}>☰</button>
        <div className="topbar-title">
          <nav className="breadcrumb" aria-label="Ruta de navegación">
            {crumbs.map((crumb, index) => (
              <span key={`${crumb.label}-${index}`} style={{ display: 'contents' }}>
                {index > 0 && <span className="sep" aria-hidden="true">›</span>}
                {crumb.to ? <Link to={crumb.to}>{crumb.label}</Link> : <span aria-current={index === crumbs.length - 1 ? 'page' : undefined}>{crumb.label}</span>}
              </span>
            ))}
          </nav>
          <h1 id="page-title" ref={titleRef} tabIndex={-1}>{title}</h1>
        </div>
        <div className="topbar-actions">
          <DemoControls />
          {user && <Link to="/perfil" className="avatar" aria-label={`Mi perfil (${user.displayName})`}>{initials(user.displayName)}</Link>}
        </div>
      </header>
      <main id="view" className="view" tabIndex={-1}>{children}</main>
    </>
  );
}
