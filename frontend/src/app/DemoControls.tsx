import { useState } from 'react';
import { useApp } from './AppProvider';

type Scenario = 'normal' | 'empty' | 'forbidden' | 'expired';

/**
 * Controles de demostración. Solo se muestran con el proveedor simulado y desaparecen
 * por completo cuando se usa otro proveedor.
 */
export function DemoControls() {
  const { mock, api, bump, provider } = useApp();
  const [scenario, setScenario] = useState<Scenario>(() => mock?.getScenario() ?? 'normal');
  if (!mock) return provider === 'http' ? <div className="demo-control"><span className="tag tag--warn" title="Datos sintéticos; el backend escucha solo en este equipo">QA · backend local aislado</span></div> : null;

  const change = async (next: Scenario) => {
    setScenario(next);
    if (next === 'expired') {
      mock.setScenario('normal');
      mock.expireSessions();
      // Una petición real recibe el 401 y el cliente protegido cierra la sesión.
      await api.me().catch(() => undefined);
      return;
    }
    mock.setScenario(next);
    bump();
  };

  return (
    <div className="demo-control">
      <label>Escenario de prueba
        <select id="demo-state" value={scenario} onChange={e => { void change(e.target.value as Scenario); }}>
          <option value="normal">Sesión válida</option>
          <option value="empty">Sin informes asignados</option>
          <option value="forbidden">403 al abrir informes</option>
          <option value="expired">401 sesión vencida</option>
        </select>
      </label>
      <button id="reset-demo" className="btn btn-ghost btn-sm" type="button" title="Restablece los datos sintéticos al estado inicial" onClick={() => { mock.reset(); setScenario('normal'); bump(); }}>Restablecer datos</button>
    </div>
  );
}
