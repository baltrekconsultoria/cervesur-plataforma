import { Component, type ReactNode } from 'react';
import { ConfigurationError } from '../api/serverServices';

/**
 * Si la configuración de la compilación es incorrecta (proveedor, dominio no autorizado, HTTP sin TLS…) se muestra una pantalla clara
 * en lugar de una página en blanco o, peor, de datos simulados. Cualquier otro error se vuelve a lanzar.
 */
export class ConfigurationBoundary extends Component<{ children: ReactNode }, { message: string | null }> {
  state: { message: string | null } = { message: null };

  static getDerivedStateFromError(error: unknown): { message: string | null } {
    if (error instanceof ConfigurationError) return { message: error.message };
    throw error;
  }

  render(): ReactNode {
    if (this.state.message === null) return this.props.children;
    return (
      <main className="config-error" role="alert">
        <h1>Portal no disponible: configuración incorrecta</h1>
        <p>{this.state.message}</p>
        <p>Avise al administrador del sistema. No se muestran datos de demostración.</p>
      </main>
    );
  }
}
