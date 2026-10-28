import { createContext, useCallback, useContext, useMemo, useReducer, useState, type ReactNode } from 'react';
import { createApiServices } from '../api/factory';
import { guardUnauthorized } from '../api/guard';
import type { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import type { ApiCapabilities, ApiClient, TokenStore, User } from '../api/types';

export interface AppServices {
  tokens: TokenStore;
  client: ApiClient;
  mock?: MockApi;
  provider?: 'mock' | 'http';
}

export interface AppContextValue {
  /** Cliente protegido: un 401 cierra la sesión local automáticamente. */
  api: ApiClient;
  mock?: MockApi;
  provider: 'mock' | 'http';
  capabilities: ApiCapabilities;
  user: User | null;
  /** Último usuario escrito en el login. Vive solo en memoria; nunca la contraseña. */
  lastUsername: string;
  /** true cuando la sesión terminó por un 401. */
  sessionExpired: boolean;
  /** Usuario cuya sesión venció, para retomar contexto solo si es la misma persona. */
  expiredUserId: string | null;
  login: (login: string, password: string) => Promise<User>;
  logout: () => Promise<void>;
  updateUser: (user: User) => void;
  /** Se incrementa al cambiar datos de demostración para que las pantallas recarguen. */
  version: number;
  bump: () => void;
}

interface SessionState { user: User | null; sessionExpired: boolean; expiredUserId: string | null }
type SessionAction = { type: 'login'; user: User } | { type: 'logout' } | { type: 'unauthorized' } | { type: 'update'; user: User };
const INITIAL_SESSION: SessionState = { user: null, sessionExpired: false, expiredUserId: null };

/** Estado de sesión como reductor puro. `update` nunca resucita una sesión ya cerrada. */
function sessionReducer(state: SessionState, action: SessionAction): SessionState {
  switch (action.type) {
    case 'login': return { ...state, user: action.user, sessionExpired: false };
    case 'logout': return INITIAL_SESSION;
    case 'unauthorized': return state.user ? { user: null, sessionExpired: true, expiredUserId: state.user.id } : state;
    case 'update': return state.user ? { ...state, user: action.user } : state;
  }
}

const AppContext = createContext<AppContextValue | null>(null);

export function useApp(): AppContextValue {
  const value = useContext(AppContext);
  if (!value) throw new Error('useApp debe usarse dentro de <AppProvider>.');
  return value;
}

export function AppProvider({ services, children }: { services?: AppServices; children: ReactNode }) {
  const [base] = useState<AppServices>(() => {
    if (services) return services;
    const tokens = createMemoryTokenStore();
    const created = createApiServices(import.meta.env, tokens);
    return { tokens, client: created.client, mock: created.mock, provider: created.provider };
  });
  const [session, dispatch] = useReducer(sessionReducer, INITIAL_SESSION);
  const [lastUsername, setLastUsername] = useState('');
  const [version, setVersion] = useState(0);
  const { user, sessionExpired, expiredUserId } = session;

  // Un 401 en cualquier petición: se borra el token y la reducción pasa a «sesión vencida» solo si había sesión.
  const onUnauthorized = useCallback(() => {
    base.tokens.set(null);
    dispatch({ type: 'unauthorized' });
  }, [base]);

  const api = useMemo(() => guardUnauthorized(base.client, onUnauthorized), [base, onUnauthorized]);

  const login = useCallback(async (loginName: string, password: string) => {
    const response = await base.client.login({ login: loginName, password });
    base.tokens.set(response.token);
    dispatch({ type: 'login', user: response.user });
    setLastUsername(loginName.trim());
    return response.user;
  }, [base]);

  const logout = useCallback(async () => {
    try { await base.client.logout(); } catch { /* el token local se elimina igualmente; la revocación en servidor puede reintentarse al volver a ingresar */ } finally {
      base.tokens.set(null);
      dispatch({ type: 'logout' });
    }
  }, [base]);

  const updateUser = useCallback((next: User) => dispatch({ type: 'update', user: next }), []);
  const bump = useCallback(() => setVersion(v => v + 1), []);

  const value = useMemo<AppContextValue>(() => ({
    api, mock: base.mock, provider: base.provider ?? (base.mock ? 'mock' : 'http'), capabilities: base.client.capabilities, user, lastUsername, sessionExpired, expiredUserId, login, logout,
    updateUser, version, bump
  }), [api, base, user, lastUsername, sessionExpired, expiredUserId, login, logout, updateUser, version, bump]);

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}
