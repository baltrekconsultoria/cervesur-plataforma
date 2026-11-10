import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/errors';
import { createMemoryTokenStore } from '../../api/tokenStore';
import type { ApiCapabilities, ApiClient, ViewerConfig } from '../../api/types';
import { AppProvider } from '../../app/AppProvider';
import { ViewerPanel } from './ViewerPanel';

/* UX-07C: el visor no declara éxito por montar el iframe, solo muestra URLs reconstruidas y permitidas y respeta cada modo. */
const reject = () => Promise.reject(new ApiError(501, 'x', 'not_integrated'));
function client(getViewerConfig: () => Promise<ViewerConfig>, liveViewer = true): ApiClient {
  const capabilities: ApiCapabilities = { adminDirectory: false, reportCompany: true, reportGroups: false, profileEdit: false, adminWrites: false, liveViewer };
  return {
    capabilities, getViewerConfig,
    login: reject, logout: () => Promise.resolve(), me: reject, listReports: reject, getReport: reject, listCompanies: reject, listUsers: reject, listAssignments: reject,
    updateProfile: reject, createUser: reject, grantAccess: reject, revokeAssignment: reject, getCompanyDetail: reject, createCompany: reject, updateCompany: reject,
    getReportDetail: reject, createReport: reject, updateReport: reject, listReportLinks: reject, linkReports: reject, unlinkReport: reject, deactivateCompany: reject, reactivateCompany: reject, deactivateReport: reject, reactivateReport: reject, reactivateAssignment: reject, listAudit: reject, getUserAdminEnabled: reject, getUserDetail: reject, updateUser: reject, deactivateUser: reject, reactivateUser: reject, changeUserRole: reject, resetUserPassword: reject, getUserAccess: reject
  };
}

function show(api: ApiClient) {
  return render(
    <MemoryRouter>
      <AppProvider services={{ tokens: createMemoryTokenStore(), client: api }}>
        <ViewerPanel reportId="00000000-0000-4000-8000-000000000001" title="Informe sintético" />
      </AppProvider>
    </MemoryRouter>
  );
}

const PUBLIC = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&pageName=ReportSection1';

afterEach(() => { vi.unstubAllEnvs(); vi.useRealTimers(); });

describe('visor de Power BI', () => {
  it('informe público: muestra el estado de carga y un iframe con la URL reconstruida, aislado y con título', async () => {
    show(client(() => Promise.resolve({ mode: 'public', url: PUBLIC })));
    const frame = await screen.findByTitle('Informe: Informe sintético');
    expect(frame.tagName).toBe('IFRAME');
    expect(frame.getAttribute('src')).toBe('https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&pageName=ReportSection1');
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts allow-same-origin allow-popups');
    expect(screen.queryByTestId('viewer-mode')).toBeNull(); // el aviso de modo solo existe en la compilación qa
    expect(frame.getAttribute('referrerpolicy')).toBe('no-referrer');
    expect(screen.getByText(/Cargando el informe de Power BI/)).toBeInTheDocument();
  });

  it('el evento load solo oculta la carga: no se declara éxito y la ayuda con la alternativa sigue visible', async () => {
    const { container } = show(client(() => Promise.resolve({ mode: 'public', url: PUBLIC })));
    const frame = await screen.findByTitle('Informe: Informe sintético');
    fireEvent.load(frame);
    await waitFor(() => expect(container.querySelector('.powerbi-frame')?.getAttribute('data-state')).toBe('frame-loaded'));
    expect(screen.queryByText(/cargado correctamente|se cargó|listo/i)).toBeNull();
    expect(screen.getByText(/¿No ve el informe\?/)).toBeInTheDocument();
    const alternative = screen.getByRole('link', { name: 'Abrir en una pestaña nueva' });
    expect(alternative.getAttribute('href')).toBe(frame.getAttribute('src'));
    expect(alternative.getAttribute('rel')).toBe('noopener noreferrer');
    expect(alternative.getAttribute('target')).toBe('_blank');
  });

  it('si el marco tarda, se avisa sin afirmar que falló ni que cargó', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    show(client(() => Promise.resolve({ mode: 'public', url: PUBLIC })));
    await screen.findByTitle('Informe: Informe sintético');
    await act(async () => { await vi.advanceTimersByTimeAsync(13000); });
    expect(screen.getByText(/Está tardando más de lo normal/)).toBeInTheDocument();
  });

  it('una URL peligrosa o ajena que llegara del servidor no genera ningún iframe', async () => {
    for (const url of ['https://evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE', 'javascript:alert(1)', '<iframe src="https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE"></iframe>', 'https://app.powerbi.com/reportEmbed?reportId=11111111-2222-3333-4444-555555555555']) {
      const { container, unmount } = show(client(() => Promise.resolve({ mode: 'public', url })));
      await screen.findByText('El enlace de este informe no es compatible con el visor');
      expect(container.querySelector('iframe'), url).toBeNull();
      unmount();
    }
  });

  it('inserción autenticada sin configurar: mensaje claro, sin iframe ni token', async () => {
    const { container } = show(client(() => Promise.resolve({ mode: 'private' })));
    expect(await screen.findByText('Este informe requiere configuración de acceso a Power BI')).toBeInTheDocument();
    expect(container.querySelector('iframe')).toBeNull();
    expect(screen.queryByText(/token|acceso general/i)?.textContent ?? '').not.toMatch(/eyJ/);
  });

  it('enlace no compatible: mensaje con alternativa de reintento', async () => {
    const calls = vi.fn(() => Promise.resolve<ViewerConfig>({ mode: 'unsupported' }));
    show(client(calls));
    await screen.findByText('El enlace de este informe no es compatible con el visor');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Reintentar' }));
    await waitFor(() => expect(calls).toHaveBeenCalledTimes(2));
  });

  it('sin autorización (404/403) se muestra el estado neutral de acceso denegado', async () => {
    show(client(() => Promise.reject(new ApiError(404, 'Reporte no encontrado'))));
    expect(await screen.findByRole('alert')).toHaveTextContent(/No tiene permiso para abrir este contenido|no está disponible/);
  });

  it('un fallo de red ofrece Reintentar', async () => {
    show(client(() => Promise.reject(new ApiError(0, 'sin conexión'))));
    expect(await screen.findByRole('button', { name: 'Reintentar' })).toBeInTheDocument();
  });

  it('el proveedor simulado conserva el panel simulado y no pide nada al servidor', async () => {
    const fn = vi.fn(() => Promise.resolve<ViewerConfig>({ mode: 'public', url: PUBLIC }));
    show(client(fn, false));
    expect(await screen.findByText('Visor Power BI simulado')).toBeInTheDocument();
    expect(fn).not.toHaveBeenCalled();
  });

  it('QA simulado (predeterminado en qa): iframe hacia la página local, sandbox SIN allow-same-origin y aviso visible', async () => {
    vi.stubEnv('MODE', 'qa');
    vi.stubEnv('VITE_POWERBI_VIEW_BASE', '/qa/powerbi-view.html');
    show(client(() => Promise.resolve({ mode: 'public', url: PUBLIC })));
    const frame = await screen.findByTitle('Informe: Informe sintético');
    expect(frame.getAttribute('src')).toBe(`${window.location.origin}/qa/powerbi-view.html?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&pageName=ReportSection1`);
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts');
    expect(screen.getByTestId('viewer-mode')).toHaveAttribute('data-mode', 'qa-simulated');
    expect(screen.getByTestId('viewer-mode')).toHaveTextContent('visor simulado');
  });

  it('QA con Power BI público real (opt-in): el iframe apunta a app.powerbi.com, no a la página local, y el aviso lo dice', async () => {
    vi.stubEnv('MODE', 'qa');
    vi.stubEnv('VITE_POWERBI_VIEW_BASE', '/qa/powerbi-view.html');
    vi.stubEnv('VITE_POWERBI_VIEWER_MODE', 'real-public');
    show(client(() => Promise.resolve({ mode: 'public', url: PUBLIC })));
    const frame = await screen.findByTitle('Informe: Informe sintético');
    expect(frame.getAttribute('src')).toBe('https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&pageName=ReportSection1');
    expect(frame.getAttribute('sandbox')).toBe('allow-scripts allow-same-origin allow-popups');
    expect(screen.getByTestId('viewer-mode')).toHaveAttribute('data-mode', 'qa-real');
    expect(screen.getByTestId('viewer-mode')).toHaveTextContent('Power BI público REAL');
  });

  it('el modo real no admite enlaces que no sean publicaciones web (inserción, ajenos): no hay iframe', async () => {
    vi.stubEnv('MODE', 'qa');
    vi.stubEnv('VITE_POWERBI_VIEWER_MODE', 'real-public');
    for (const url of ['https://app.powerbi.com/reportEmbed?reportId=11111111-2222-3333-4444-555555555555', 'https://evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE', 'javascript:alert(1)']) {
      const { container, unmount } = show(client(() => Promise.resolve({ mode: 'public', url })));
      await screen.findByText('El enlace de este informe no es compatible con el visor');
      expect(container.querySelector('iframe'), url).toBeNull();
      unmount();
    }
  });

  it('una variable mal configurada nunca apunta el iframe a un dominio externo', async () => {
    vi.stubEnv('MODE', 'qa');
    vi.stubEnv('VITE_POWERBI_VIEW_BASE', 'https://evil.example/view');
    show(client(() => Promise.resolve({ mode: 'public', url: PUBLIC })));
    const frame = await screen.findByTitle('Informe: Informe sintético');
    expect(frame.getAttribute('src')?.startsWith('https://app.powerbi.com/view?r=')).toBe(true);
  });
});
