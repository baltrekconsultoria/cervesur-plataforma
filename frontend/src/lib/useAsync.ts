import { useEffect, useState } from 'react';

export type AsyncState<T> = { status: 'loading' } | { status: 'ready'; data: T } | { status: 'error'; error: unknown };

const sameDeps = (a: ReadonlyArray<unknown>, b: ReadonlyArray<unknown>) => a.length === b.length && a.every((value, index) => Object.is(value, b[index]));

/**
 * Carga asíncrona mínima con descarte de respuestas obsoletas.
 * Al cambiar las dependencias se vuelve a «loading» durante el mismo render (patrón de React para ajustar
 * estado derivado), de modo que nunca se pinta un dato viejo mientras llega el nuevo.
 */
export function useAsync<T>(load: () => Promise<T>, deps: ReadonlyArray<unknown>): AsyncState<T> {
  const [state, setState] = useState<AsyncState<T>>({ status: 'loading' });
  const [previousDeps, setPreviousDeps] = useState(deps);
  if (!sameDeps(previousDeps, deps)) {
    setPreviousDeps(deps);
    setState({ status: 'loading' });
  }
  useEffect(() => {
    let current = true;
    load().then(
      data => { if (current) setState({ status: 'ready', data }); },
      (error: unknown) => { if (current) setState({ status: 'error', error }); }
    );
    return () => { current = false; };
    // `load` se recrea en cada render; las dependencias reales las declara el llamador.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  return state;
}
