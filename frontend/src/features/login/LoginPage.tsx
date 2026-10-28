import { useState, type FormEvent } from 'react';
import { Navigate, useLocation } from 'react-router';
import { isApiError } from '../../api/errors';
import { useApp } from '../../app/AppProvider';
import { homePath, sanitizeReturnTo } from '../../auth/returnTo';
import { Button } from '../../components/ui';

// Cuentas de demostración: solo existen con el proveedor simulado; la compilación de servidor las elimina del bundle.
const SERVER_BUILD = import.meta.env.VITE_DEPLOY_TARGET === 'server';
const DEMO_ACCOUNTS: Array<[string, string]> = SERVER_BUILD ? [] : [['maria.rios', 'Usuario con reportes asignados'], ['elena.torres', 'Administrador']];

export function LoginPage() {
  const { login, user, mock, provider, lastUsername, sessionExpired, expiredUserId } = useApp();
  const location = useLocation();
  const [username, setUsername] = useState(lastUsername || (mock ? DEMO_ACCOUNTS[0]?.[0] ?? '' : ''));
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  if (user) {
    const resume = expiredUserId === user.id ? sanitizeReturnTo((location.state as { returnTo?: string } | null)?.returnTo, user.role) : null;
    return <Navigate to={resume ?? homePath(user.role)} replace />;
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    setBusy(true);
    setError('');
    try {
      await login(username, password);
    } catch (e) {
      setPassword('');
      setError(isApiError(e) && e.kind !== 'network' ? e.message : 'No se pudo iniciar sesión. Intente nuevamente.');
      setBusy(false);
    }
  };

  return (
    <main className="login-layout">
      <section className="login-brand" aria-label="Corporación Cervesur">
        <img src="/branding/logo-cervesur-vertical.png" alt="Corporación Cervesur" />
        <p>Portal corporativo de información y reportes.</p>
      </section>
      <section className="login-card" aria-labelledby="login-title">
        <span className="eyebrow">Acceso seguro</span>
        <h1 id="login-title">Bienvenido</h1>
        <p>Use una cuenta autorizada para consultar los informes asignados.</p>
        <div role="alert">
          {sessionExpired && <div className="alert warning"><strong>Su sesión venció (401).</strong> Ingrese nuevamente para continuar donde estaba.</div>}
          {error && <div className="alert danger">{error}</div>}
        </div>
        <form onSubmit={e => { void submit(e); }}>
          <label htmlFor="login-name">Correo o usuario
            <input id="login-name" required value={username} autoComplete="username" onChange={e => setUsername(e.target.value)} />
          </label>
          <label htmlFor="login-password">Contraseña
            <input id="login-password" required type="password" value={password} autoComplete="current-password" onChange={e => setPassword(e.target.value)} />
          </label>
          {mock && (
            <label htmlFor="login-role">Perfil de demostración
              <select id="login-role" value={DEMO_ACCOUNTS.some(([name]) => name === username) ? username : ''} onChange={e => { if (e.target.value) setUsername(e.target.value); }}>
                <option value="">Otra cuenta</option>
                {DEMO_ACCOUNTS.map(([name, label]) => <option key={name} value={name}>{label}</option>)}
              </select>
            </label>
          )}
          <Button type="submit" disabled={busy}>Ingresar al portal</Button>
        </form>
        {!SERVER_BUILD && (
          <p className="form-note">{provider === 'http'
            ? 'Entorno QA local: las credenciales se envían únicamente al backend aislado de este equipo (datos sintéticos).'
            : 'Prototipo local con datos ficticios. No se envían credenciales; con el proveedor simulado solo se valida que la cuenta exista.'}</p>
        )}
      </section>
    </main>
  );
}
