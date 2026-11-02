import { useState } from 'react';
import { PAGE_SIZE } from '../components/ui';

/**
 * Paginación local de una lista ya filtrada (UX-08A.1). La página vuelve a 1 cuando cambia `filterKey`
 * (búsqueda o filtros) y se ajusta si la lista se acorta (p. ej. tras recargar), de modo que nunca queda una página vacía.
 */
export function usePaging(filterKey: string) {
  const [paging, setPaging] = useState({ key: '', page: 1 });
  const requested = paging.key === filterKey ? paging.page : 1;
  return {
    setPage: (page: number) => setPaging({ key: filterKey, page }),
    view<T>(rows: T[]): { page: number; rows: T[] } {
      const pages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
      const page = Math.min(requested, pages);
      return { page, rows: rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE) };
    }
  };
}
