import { useState } from 'react';
import { isApiError } from '../../api/errors';
import type { User, UserAccess, UserEdit } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';
import { Button, ErrorState, LoadingState, Tag } from '../../components/ui';
import { useAsync } from '../../lib/useAsync';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const PHONE = /^[\d\s+()-]{6,20}$/;

type Draft = Omit<UserEdit, 'version'>;
type FieldErrors = Partial<Record<keyof Draft, string>>;

export function validateUserEdit(value: Draft, emailReadOnly = false): FieldErrors {
  const errors: FieldErrors = {};
  if (!value.firstName.trim()) errors.firstName = 'Ingrese los nombres.';
  else if (value.firstName.trim().length > 50) errors.firstName = 'Máximo 50 caracteres.';
  if (!value.lastName.trim()) errors.lastName = 'Ingrese los apellidos.';
  else if (value.lastName.trim().length > 50) errors.lastName = 'Máximo 50 caracteres.';
  // Con el correo de solo lectura no se valida: el valor guardado no lo escribió esta pantalla y no debe impedir corregir el nombre o el teléfono.
  if (!emailReadOnly) {
    if (!EMAIL.test(value.email.trim())) errors.email = 'Ingrese un correo válido.';
    else if (value.email.trim().length > 100) errors.email = 'Máximo 100 caracteres.';
  }
  if (value.phone.trim() && !PHONE.test(value.phone.trim())) errors.phone = 'Use de 6 a 20 caracteres: dígitos, espacios, +, paréntesis o guion.';
  return errors;
}

/**
 * Edición de datos personales de un usuario (UX-08B.3). Solo nombres, apellidos, correo y teléfono: el rol, el estado, la contraseña y las
 * sesiones tienen operaciones propias. La versión leída viaja al guardar: si otro administrador cambió el registro, el servidor responde 409.
 */
export function UserEditDialog({ user, onClose, onSaved, onStale }: { user: User; onClose: () => void; onSaved: (name: string) => void; onStale: () => void }) {
  const { api } = useApp();
  const [attempt, setAttempt] = useState(0);
  const detail = useAsync(() => api.getUserDetail(user.id), [api, user.id, attempt]);
  return (
    <Dialog title="Editar usuario" labelId="us-edit-title" onClose={onClose}>
      {detail.status === 'loading' && <div className="modal-body"><LoadingState label="Cargando datos del usuario…" /></div>}
      {detail.status === 'error' && <div className="modal-body"><ErrorState onRetry={() => setAttempt(a => a + 1)} /><div className="modal-foot"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div></div>}
      {detail.status === 'ready' && <UserEditForm key={attempt} id={user.id} username={detail.data.username} version={detail.data.version} initial={{ firstName: detail.data.firstName, lastName: detail.data.lastName, phone: detail.data.phone, email: detail.data.email }} onClose={onClose} onSaved={onSaved} onStale={onStale} />}
    </Dialog>
  );
}

function UserEditForm({ id, username, version, initial, onClose, onSaved, onStale }: { id: string; username: string; version: string; initial: Draft; onClose: () => void; onSaved: (name: string) => void; onStale: () => void }) {
  const { api, capabilities } = useApp();
  const emailReadOnly = capabilities.emailReadOnly === true;
  const [value, setValue] = useState<Draft>(initial);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const [stale, setStale] = useState(false);
  const set = (key: keyof Draft) => (event: { target: { value: string } }) => setValue(v => ({ ...v, [key]: event.target.value }));

  const save = async () => {
    const next = validateUserEdit(value, emailReadOnly);
    setErrors(next);
    setFailure('');
    if (Object.keys(next).length) return;
    setSaving(true);
    try {
      await api.updateUser(id, { ...value, version });
      onSaved(`${value.firstName.trim()} ${value.lastName.trim()}`);
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setStale(isApiError(error) && (error.kind === 'conflict' || error.kind === 'not_found'));
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo guardar el usuario. Intente nuevamente.');
      setSaving(false);
    }
  };

  const close = () => { if (stale) onStale(); onClose(); };
  const field = (key: keyof Draft, fieldId: string, label: string, props: { type?: string; autoComplete?: string } = {}) => (
    <label htmlFor={fieldId}>{label}
      <input id={fieldId} type={props.type ?? 'text'} value={value[key]} autoComplete={props.autoComplete ?? 'off'} aria-invalid={Boolean(errors[key])} aria-describedby={`${fieldId}-err`} disabled={saving} onChange={set(key)} />
      <span className="field-error" id={`${fieldId}-err`} hidden={!errors[key]}>{errors[key]}</span>
    </label>
  );

  return (
    <form noValidate onSubmit={submitHandler(save)}>
      <div className="modal-body">
        <p className="form-note">Usuario <strong>{username}</strong>. El nombre de usuario, el rol, el estado y la contraseña no se cambian aquí.</p>
        <div className="form-grid">
          {field('firstName', 'ue-first', 'Nombres *')}
          {field('lastName', 'ue-last', 'Apellidos *')}
          {emailReadOnly
            ? (
              <label htmlFor="ue-email">Correo
                <input id="ue-email" type="email" value={value.email} readOnly aria-readonly="true" aria-describedby="ue-email-note" />
                <span className="muted" id="ue-email-note">El correo no se puede cambiar desde este panel (se comparte con otro sistema).</span>
              </label>
            )
            : field('email', 'ue-email', 'Correo *', { type: 'email' })}
          {field('phone', 'ue-phone', 'Teléfono', { autoComplete: 'tel' })}
        </div>
      </div>
      <DialogFooter error={failure} saving={saving} submitLabel="Guardar cambios" onCancel={close} />
    </form>
  );
}

/** Empresas e informes de un usuario y si surten efecto hoy (solo lectura; sin enlaces ni datos de Power BI). */
export function UserAccessDialog({ user, onClose }: { user: User; onClose: () => void }) {
  const { api } = useApp();
  const [attempt, setAttempt] = useState(0);
  const access = useAsync(() => api.getUserAccess(user.id), [api, user.id, attempt]);
  const section = (kind: UserAccess['kind'], title: string) => {
    const rows = access.status === 'ready' ? access.data.filter(a => a.kind === kind) : [];
    return (
      <section>
        <h3>{title} ({rows.length})</h3>
        {rows.length === 0 ? <p className="muted">Ninguno.</p> : (
          <ul className="attention-list">
            {rows.map((row, index) => (
              <li key={`${row.name}-${row.via ?? ''}-${index}`}>
                <span>{row.name}{row.via ? <span className="muted"> · {row.via}</span> : null}</span>
                {row.effective ? <Tag tone="ok">Vigente</Tag> : <Tag tone="warn">Sin efecto · {row.reason}</Tag>}
              </li>
            ))}
          </ul>
        )}
      </section>
    );
  };
  return (
    <Dialog title={`Accesos de ${user.displayName}`} labelId="us-access-title" onClose={onClose}>
      <div className="modal-body">
        {access.status === 'loading' && <LoadingState label="Cargando accesos…" />}
        {access.status === 'error' && <ErrorState onRetry={() => setAttempt(a => a + 1)} />}
        {access.status === 'ready' && <>{section('company', 'Empresas')}{section('report', 'Informes')}</>}
      </div>
      <div className="modal-foot"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div>
    </Dialog>
  );
}
