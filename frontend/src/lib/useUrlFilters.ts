import { useSearchParams } from 'react-router';

/**
 * Filtros guardados en la URL (?q=…&company=…). Así sobreviven a abrir un informe y volver,
 * al botón Atrás del navegador y a recargar la página.
 */
export function useUrlFilters<K extends string>(defaults: Record<K, string>) {
  const [params, setParams] = useSearchParams();
  const keys = Object.keys(defaults) as K[];
  const values = Object.fromEntries(keys.map(key => [key, params.get(key) ?? defaults[key]])) as Record<K, string>;

  const setMany = (patch: Partial<Record<K, string>>) => {
    setParams(previous => {
      const next = new URLSearchParams(previous);
      for (const key of Object.keys(patch) as K[]) {
        const value = patch[key] ?? defaults[key];
        if (value === defaults[key]) next.delete(key); else next.set(key, value);
      }
      return next;
    }, { replace: true });
  };

  return {
    values,
    set: (key: K, value: string) => setMany({ [key]: value } as Partial<Record<K, string>>),
    setMany,
    clear: () => setParams({}, { replace: true }),
    active: keys.some(key => values[key] !== defaults[key])
  };
}
