/**
 * Clasificación de enlaces de Power BI (UX-07C). Debe mantenerse idéntica al clasificador de PHP (carpeta backend/lib):
 * ambas se prueban con los mismos vectores (tests/fixtures/link_vectors.json).
 *
 *   public → «Publicar en web» (app.powerbi.com/view?r=…)  (se inserta en un iframe)
 *   embed  → inserción autenticada (…/reportEmbed?reportId=…)      (requiere Microsoft; no configurado)
 *   portal → enlace normal …/groups/{g}/reports/{r}              (no apto para iframe; se reconoce para extraer ids)
 * La clasificación es léxica: no hace peticiones ni sigue redirecciones. Los UUID solo se devuelven si están en la URL y son válidos.
 */
export type PowerBiLinkKind = 'public' | 'embed' | 'portal';

export type PowerBiLink =
  | { ok: true; kind: PowerBiLinkKind; token: string | null; reportId: string | null; groupId: string | null; params: Record<string, string> }
  | { ok: false; error: string };

export const POWERBI_HOST = 'app.powerbi.com';
export const POWERBI_VIEW_URL = 'https://app.powerbi.com/view';
const MAX_LENGTH = 1024;
const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
const UUID_RE = new RegExp(`^${UUID}$`);
const BOOL_RE = /^(true|false)$/;

const fail = (error: string): PowerBiLink => ({ ok: false, error });

type Rules = Record<string, RegExp>;

export function classifyPowerBiLink(input: unknown): PowerBiLink {
  if (typeof input !== 'string' || input.trim() === '') return fail('Ingrese el enlace del informe de Power BI.');
  const url = input.trim();
  if (url.includes('<') || /iframe/i.test(url)) return fail('Pegue solo la URL del informe, no el código HTML (iframe).');
  if (url.length > MAX_LENGTH) return fail(`El enlace es demasiado largo (máximo ${MAX_LENGTH} caracteres).`);
  if (!/^https:\/\//i.test(url)) return fail('El enlace debe usar HTTPS.');
  // eslint-disable-next-line no-control-regex
  if (/[\x00-\x20\x7f\\]/.test(url)) return fail('El enlace no puede contener espacios, saltos de línea ni barras invertidas.');
  const match = /^https:\/\/([^/?#]+)(\/[^?#]*)?(\?[^#]*)?$/i.exec(url);
  if (!match) return fail('El enlace no es una URL válida (no se admiten fragmentos «#»).');
  if ((match[1] ?? '').toLowerCase() !== POWERBI_HOST) return fail(`El enlace debe pertenecer a ${POWERBI_HOST}.`);
  const path = match[2] ?? '';
  const queryString = match[3] ? match[3].slice(1) : '';

  const query: Record<string, string> = {};
  if (queryString !== '') {
    for (const pair of queryString.split('&')) {
      const kv = /^([A-Za-z][A-Za-z0-9]*)=([^=&]*)$/.exec(pair);
      if (!kv || Object.prototype.hasOwnProperty.call(query, kv[1] as string)) return fail('El enlace tiene parámetros no válidos o repetidos.');
      query[kv[1] as string] = kv[2] as string;
    }
  }
  const validate = (rules: Rules): PowerBiLink | null => {
    for (const [key, value] of Object.entries(query)) {
      const rule = rules[key];
      if (!rule) return fail(`El parámetro «${key}» no está permitido en este tipo de enlace.`);
      if (!rule.test(value)) return fail(`El valor del parámetro «${key}» no es válido.`);
    }
    return null;
  };

  if (path === '/view') {
    const error = validate({ r: /^[A-Za-z0-9_-]{16,1000}(=|%3D|%3d){0,2}$/, pageName: /^[A-Za-z0-9]{1,64}$/, navContentPaneEnabled: BOOL_RE, filterPaneEnabled: BOOL_RE, ctid: UUID_RE });
    if (error) return error;
    if (query.r === undefined) return fail('El enlace de publicación en web debe incluir el parámetro «r».');
    return { ok: true, kind: 'public', token: query.r, reportId: null, groupId: null, params: query };
  }

  if (path === '/reportEmbed') {
    const error = validate({ reportId: UUID_RE, groupId: UUID_RE, autoAuth: BOOL_RE, ctid: UUID_RE, config: /^[A-Za-z0-9_%=-]{1,1000}$/ });
    if (error) return error;
    if (query.reportId === undefined) return fail('El enlace de inserción debe incluir un «reportId» válido.');
    return { ok: true, kind: 'embed', token: null, reportId: query.reportId.toLowerCase(), groupId: query.groupId?.toLowerCase() ?? null, params: query };
  }

  const portal = new RegExp(`^/groups/(${UUID})/reports/(${UUID})(/[A-Za-z0-9_]{1,64})?$`).exec(path) ?? new RegExp(`^/()reports/(${UUID})(/[A-Za-z0-9_]{1,64})?$`).exec(path);
  if (portal) {
    const error = validate({ experience: /^power-bi$/, ctid: UUID_RE });
    if (error) return error;
    return { ok: true, kind: 'portal', token: null, reportId: (portal[2] as string).toLowerCase(), groupId: portal[1] ? portal[1].toLowerCase() : null, params: query };
  }

  return fail('El enlace de Power BI no es de un tipo compatible: use «Publicar en web» (…/view?r=…), un enlace de inserción (…/reportEmbed?reportId=…) o el enlace del informe (…/groups/…/reports/…).');
}

/** Etiqueta para el administrador. */
export function describeLink(link: PowerBiLink): { tone: 'ok' | 'info' | 'error'; text: string } {
  if (!link.ok) return { tone: 'error', text: link.error };
  if (link.kind === 'public') return { tone: 'ok', text: 'Publicación web (informe público en Power BI). No contiene identificadores de Power BI: no se inventan; el sistema usa una referencia interna.' };
  const ids = `ID del informe ${link.reportId ?? '—'}${link.groupId ? ` · ID del grupo ${link.groupId}` : ' · sin ID de grupo en el enlace'}`;
  return link.kind === 'embed'
    ? { tone: 'info', text: `Inserción autenticada. Se detectó: ${ids}. Requiere configuración de acceso a Power BI (aún no disponible): el visor mostrará un aviso.` }
    : { tone: 'info', text: `Enlace normal del informe (no apto para insertar). Se detectó: ${ids}. El visor mostrará un aviso.` };
}

/**
 * URL del iframe para una publicación web, RECONSTRUIDA a partir de los componentes ya validados (token y parámetros permitidos),
 * nunca copiando la cadena recibida. `viewBase` es el endpoint de «Publicar en web» (por defecto el oficial de Microsoft).
 * Devuelve null si el enlace no es una publicación web válida.
 */
export function buildPublicViewerUrl(input: unknown, viewBase: string = POWERBI_VIEW_URL): string | null {
  const link = classifyPowerBiLink(input);
  if (!link.ok || link.kind !== 'public' || link.token === null) return null;
  const order = ['r', 'pageName', 'navContentPaneEnabled', 'filterPaneEnabled', 'ctid'];
  const query = order.filter(key => link.params[key] !== undefined).map(key => `${key}=${link.params[key] as string}`).join('&');
  return `${viewBase}?${query}`;
}
