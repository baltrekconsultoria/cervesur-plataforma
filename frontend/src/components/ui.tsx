import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Link, type LinkProps } from 'react-router';
import { splitMatches } from '../lib/text';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
type Size = 'md' | 'sm';
const buttonClass = (variant: Variant, size: Size) => `btn btn-${variant}${size === 'sm' ? ' btn-sm' : ''}`;

export function Button({ variant = 'primary', size = 'md', type = 'button', className, ...rest }: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: Size }) {
  return <button type={type} className={`${buttonClass(variant, size)}${className ? ` ${className}` : ''}`} {...rest} />;
}

export function ButtonLink({ variant = 'primary', size = 'md', ...rest }: LinkProps & { variant?: Variant; size?: Size }) {
  return <Link className={buttonClass(variant, size)} {...rest} />;
}

export function Tag({ tone, children }: { tone?: 'ok' | 'off' | 'warn' | 'admin'; children: ReactNode }) {
  return <span className={`tag${tone ? ` tag--${tone}` : ''}`}>{children}</span>;
}

export const StatusTag = ({ active, on = 'Activo', off = 'Inactivo' }: { active: boolean; on?: string; off?: string }) => <Tag tone={active ? 'ok' : 'off'}>{active ? on : off}</Tag>;

/** Resalta coincidencias de búsqueda sin usar innerHTML. */
export function Highlight({ text, query }: { text: string; query: string }) {
  return <>{splitMatches(text, query).map((part, i) => (part.match ? <mark key={i}>{part.text}</mark> : <span key={i}>{part.text}</span>))}</>;
}

interface FieldBase { label: string; id: string }

export function SearchField({ label, id, value, onChange, placeholder }: FieldBase & { value: string; onChange: (value: string) => void; placeholder?: string }) {
  return (
    <label className="grow" htmlFor={id}>
      {label}
      <input id={id} type="search" value={value} placeholder={placeholder} autoComplete="off" onChange={e => onChange(e.target.value)} />
    </label>
  );
}

export function SelectField({ label, id, value, onChange, options }: FieldBase & { value: string; onChange: (value: string) => void; options: Array<[string, string]> }) {
  return (
    <label htmlFor={id}>
      {label}
      <select id={id} value={value} onChange={e => onChange(e.target.value)}>
        {options.map(([optionValue, text]) => <option key={optionValue} value={optionValue}>{text}</option>)}
      </select>
    </label>
  );
}

export function ResultBar({ shown, total, noun, canClear, onClear }: { shown: number; total: number; noun: string; canClear: boolean; onClear: () => void }) {
  return (
    <div className="resultbar">
      <span aria-live="polite">Mostrando <strong>{shown}</strong> de {total} {noun}</span>
      <Button variant="ghost" size="sm" disabled={!canClear} onClick={onClear}>Limpiar filtros</Button>
    </div>
  );
}

export function EmptyState({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
  return <div className="empty-state"><div><h2>{title}</h2><p>{children}</p>{action}</div></div>;
}

export function NoMatches({ noun, onClear }: { noun: string; onClear: () => void }) {
  return <EmptyState title="Sin resultados" action={<Button variant="secondary" onClick={onClear}>Limpiar filtros</Button>}>Ningún {noun} coincide con la búsqueda o los filtros aplicados.</EmptyState>;
}

export interface Column<T> { header: string; cls?: string; cell: (row: T) => ReactNode }

export function DataTable<T>({ columns, rows, rowKey, rowClass }: { columns: Array<Column<T>>; rows: T[]; rowKey: (row: T) => string; rowClass?: (row: T) => string }) {
  return (
    <div className="table-wrap">
      <table>
        <thead><tr>{columns.map(c => <th key={c.header} scope="col" className={c.cls}>{c.header}</th>)}</tr></thead>
        <tbody>
          {rows.map(row => (
            <tr key={rowKey(row)} className={rowClass?.(row)}>
              {columns.map(c => <td key={c.header} data-label={c.header} className={c.cls}><div className="cell">{c.cell(row)}</div></td>)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export const ErrorState = ({ onRetry }: { onRetry?: () => void }) => (
  <EmptyState title="No se pudo cargar la información" action={onRetry ? <Button variant="secondary" onClick={onRetry}>Reintentar</Button> : undefined}>Intente nuevamente en unos instantes.</EmptyState>
);

export const LoadingState = ({ label = 'Cargando…' }: { label?: string }) => <p className="muted" role="status">{label}</p>;
