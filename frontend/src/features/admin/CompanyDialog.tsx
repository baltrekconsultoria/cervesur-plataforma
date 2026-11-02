import { useState } from 'react';
import { isApiError } from '../../api/errors';
import type { Company, CompanyInput } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';
import { Button, ErrorState, LoadingState } from '../../components/ui';
import { useAsync } from '../../lib/useAsync';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE = /^[\d\s+()-]{6,30}$/;
const EMPTY: CompanyInput = { name: '', legalName: '', taxId: '', city: '', country: '', email: '', phone: '', address: '' };

type FieldErrors = Partial<Record<keyof CompanyInput, string>>;

export function validateCompany(value: CompanyInput): FieldErrors {
  const errors: FieldErrors = {};
  if (!value.name.trim()) errors.name = 'Ingrese el nombre.';
  else if (value.name.trim().length > 100) errors.name = 'Use hasta 100 caracteres.';
  if (!value.legalName.trim()) errors.legalName = 'Ingrese la razón social.';
  else if (value.legalName.trim().length > 100) errors.legalName = 'Use hasta 100 caracteres.';
  if (!/^\d{11}$/.test(value.taxId.trim())) errors.taxId = 'El RUC debe tener exactamente 11 dígitos.';
  if (value.city.trim().length > 100) errors.city = 'Use hasta 100 caracteres.';
  if (value.country.trim().length > 50) errors.country = 'Use hasta 50 caracteres.';
  if (value.email.trim() && !EMAIL.test(value.email.trim())) errors.email = 'Ingrese un correo válido.';
  if (value.phone.trim() && !PHONE.test(value.phone.trim())) errors.phone = 'Use solo números, espacios y + ( ) -.';
  if (value.address.trim().length > 100) errors.address = 'Use hasta 100 caracteres.';
  return errors;
}

/**
 * Alta o edición de una empresa. Solo datos no sensibles: no hay campos de Power BI.
 * En edición se vuelve a pedir el detalle al servidor (el listado solo trae datos mínimos).
 */
export function CompanyDialog({ company, onClose, onSaved }: { company: Company | null; onClose: () => void; onSaved: (name: string, created: boolean) => void }) {
  const { api } = useApp();
  const [attempt, setAttempt] = useState(0);
  const detail = useAsync(async () => (company ? api.getCompanyDetail(company.id) : null), [api, company?.id, attempt]);
  const title = company ? 'Editar empresa' : 'Nueva empresa';
  return (
    <Dialog title={title} labelId="co-title" onClose={onClose}>
      {detail.status === 'loading' && <div className="modal-body"><LoadingState label="Cargando datos de la empresa…" /></div>}
      {detail.status === 'error' && <div className="modal-body"><ErrorState onRetry={() => setAttempt(a => a + 1)} /><div className="modal-foot"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div></div>}
      {detail.status === 'ready' && <CompanyForm key={attempt} id={company?.id ?? null} initial={detail.data ? { name: detail.data.name, legalName: detail.data.legalName, taxId: detail.data.taxId, city: detail.data.city, country: detail.data.country, email: detail.data.email, phone: detail.data.phone, address: detail.data.address, ...(detail.data.version ? { version: detail.data.version } : {}) } : EMPTY} onClose={onClose} onSaved={onSaved} />}
    </Dialog>
  );
}

function CompanyForm({ id, initial, onClose, onSaved }: { id: string | null; initial: CompanyInput; onClose: () => void; onSaved: (name: string, created: boolean) => void }) {
  const { api } = useApp();
  const [value, setValue] = useState<CompanyInput>(initial);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const set = (key: keyof CompanyInput) => (event: { target: { value: string } }) => setValue(v => ({ ...v, [key]: event.target.value }));

  const save = async () => {
    const next = validateCompany(value);
    setErrors(next);
    setFailure('');
    if (Object.keys(next).length) return;
    setSaving(true);
    try {
      if (id) await api.updateCompany(id, value); else await api.createCompany(value);
      onSaved(value.name.trim(), id === null);
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo guardar la empresa. Intente nuevamente.');
      setSaving(false);
    }
  };

  const field = (key: keyof CompanyInput, fieldId: string, label: string, props: { hint?: string; type?: string; autoComplete?: string } = {}) => (
    <label htmlFor={fieldId}>{label}
      <input id={fieldId} type={props.type ?? 'text'} value={value[key]} autoComplete={props.autoComplete ?? 'off'} aria-invalid={Boolean(errors[key])} aria-describedby={`${fieldId}-err`} disabled={saving} onChange={set(key)} />
      {props.hint && <span className="form-note">{props.hint}</span>}
      <span className="field-error" id={`${fieldId}-err`} hidden={!errors[key]}>{errors[key]}</span>
    </label>
  );

  return (
    <form noValidate onSubmit={submitHandler(save)}>
      <div className="modal-body">
        <p className="form-note">Los campos con * son obligatorios. Las credenciales de Power BI no se gestionan en este formulario.</p>
        <div className="form-grid">
          {field('name', 'co-name', 'Nombre *')}
          {field('legalName', 'co-legal', 'Razón social *')}
          {field('taxId', 'co-ruc', 'RUC *', { hint: '11 dígitos.' })}
          {field('city', 'co-city', 'Ciudad')}
          {field('country', 'co-country', 'País')}
          {field('email', 'co-email', 'Correo', { type: 'email' })}
          {field('phone', 'co-phone', 'Teléfono', { autoComplete: 'tel' })}
          {field('address', 'co-address', 'Dirección')}
        </div>
      </div>
      <DialogFooter error={failure} saving={saving} submitLabel={id ? 'Guardar cambios' : 'Crear empresa'} onCancel={onClose} />
    </form>
  );
}
