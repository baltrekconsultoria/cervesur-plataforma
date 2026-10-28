const fold = (value: string): string => value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Normaliza para comparar: sin tildes, sin mayúsculas, sin espacios laterales. */
export const norm = (value: string | null | undefined): string => fold(value ?? '').trim();

export const plural = (n: number, one: string, many: string): string => `${n} ${n === 1 ? one : many}`;

export const initials = (name: string): string => name.split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase();

/** Divide `text` en fragmentos marcando las coincidencias (insensible a mayúsculas y tildes). */
export function splitMatches(text: string, query: string): Array<{ text: string; match: boolean }> {
  const q = norm(query);
  if (!q) return [{ text, match: false }];
  const chars = [...text];
  let folded = '';
  const map: number[] = [];
  chars.forEach((ch, index) => {
    for (const f of fold(ch)) { folded += f; map.push(index); }
  });
  const marked = new Set<number>();
  let from = 0;
  let at = folded.indexOf(q, from);
  while (at !== -1) {
    for (let k = at; k < at + q.length; k++) { const original = map[k]; if (original !== undefined) marked.add(original); }
    from = at + q.length;
    at = folded.indexOf(q, from);
  }
  const parts: Array<{ text: string; match: boolean }> = [];
  chars.forEach((ch, index) => {
    const match = marked.has(index);
    const last = parts[parts.length - 1];
    if (last && last.match === match) last.text += ch; else parts.push({ text: ch, match });
  });
  return parts;
}
