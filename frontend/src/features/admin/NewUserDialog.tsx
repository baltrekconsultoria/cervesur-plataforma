import { useState } from 'react';
import { isApiError } from '../../api/errors';
import type { NewUser } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const USERNAME = /^[A-Za-z0-9._-]{3,30}$/;
const PHONE = /^[\d\s+()-]{6,20}$/;
const MIN_PASSWORD = 12;
/** bcrypt solo considera los primeros 72 BYTES: el servidor rechaza más. */
const MAX_PASSWORD_BYTES = 72;

type FieldErrors = Partial<Record<keyof NewUser, string>>;
const EMPTY: NewUser = { firstName: '', lastName: '', username: '', email: '', phone: '', password: '' };

export function validateNewUser(value: NewUser): FieldErrors {
  const errors: FieldErrors = {};
  if (!value.firstName.trim()) errors.firstName = 'Ingrese los nombres.';
  if (!value.lastName.trim()) errors.lastName = 'Ingrese los apellidos.';
  if (!USERNAME.test(value.username.trim())) errors.username = 'Use de 3 a 30 caracteres: letras, números, punto, guion o guion bajo.';
  if (!EMAIL.test(value.email.trim())) errors.email = 'Ingrese un correo válido.';
  if (value.phone.trim() && !PHONE.test(value.phone.trim())) errors.phone = 'Use solo números, espacios y + ( ) -.';
  if (value.password.length < MIN_PASSWORD) errors.password = `La contraseña debe tener al menos ${MIN_PASSWORD} caracteres.`;
  else if (new TextEncoder().encode(value.password).length > MAX_PASSWORD_BYTES) errors.password = `La contraseña no puede superar ${MAX_PASSWORD_BYTES} bytes.`;
  return errors;
}

/**
 * Alta de usuario (POST /api/register). El rol, el estado y la versión de sesión NO se envían: los fija el backend
 * (rol `user`, activo). Solo se cierra y se informa «creado» cuando el servidor confirmó el alta.
 */
export function NewUserDialog({ onClose, onCreated }: { onClose: () => void; onCreated: (username: string) => void }) {
  const { api } = useApp();
  const [value, setValue] = useState<NewUser>(EMPTY);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const set = (key: keyof NewUser) => (event: { target: { value: string } }) => setValue(v => ({ ...v, [key]: event.target.value }));

  const save = async () => {
    const next = validateNewUser(value);
    setErrors(next);
    setFailure('');
    if (Object.keys(next).length) return;
    setSaving(true);
    try {
      await api.createUser(value);
      onCreated(value.username.trim());
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return; // la sesión se cierra sola
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo crear el usuario. Intente nuevamente.');
      setSaving(false);
    }
  };

  const field = (key: keyof NewUser, id: string, label: string, props: { type?: string; autoComplete?: string; hint?: string } = {}) => (
    <label htmlFor={id}>{label}
      <input id={id} type={props.type ?? 'text'} value={value[key]} autoComplete={props.autoComplete ?? 'off'} aria-invalid={Boolean(errors[key])} aria-describedby={`${id}-err`} disabled={saving} onChange={set(key)} />
      {props.hint && <span className="form-note">{props.hint}</span>}
      <span className="field-error" id={`${id}-err`} hidden={!errors[key]}>{errors[key]}</span>
    </label>
  );

  return (
    <Dialog title="Nuevo usuario" labelId="nu-title" onClose={() => { if (!saving) onClose(); }}>
      <form noValidate onSubmit={submitHandler(save)}>
        <div className="modal-body">
          <p>El usuario se crea activo y con el rol <strong>Usuario</strong>. Después podrá asignarle empresas e informes.</p>
          <div className="form-grid">
            {field('firstName', 'nu-first', 'Nombres', { autoComplete: 'given-name' })}
            {field('lastName', 'nu-last', 'Apellidos', { autoComplete: 'family-name' })}
            {field('username', 'nu-username', 'Usuario', { autoComplete: 'off' })}
            {field('email', 'nu-email', 'Correo', { type: 'email', autoComplete: 'off' })}
            {field('phone', 'nu-phone', 'Teléfono (opcional)', { autoComplete: 'off' })}
            {field('password', 'nu-password', 'Contraseña inicial', { type: 'password', autoComplete: 'new-password', hint: `Mínimo ${MIN_PASSWORD} caracteres. Comuníquela por un canal seguro.` })}
          </div>
        </div>
        <DialogFooter error={failure} saving={saving} submitLabel="Crear usuario" onCancel={onClose} />
      </form>
    </Dialog>
  );
}
