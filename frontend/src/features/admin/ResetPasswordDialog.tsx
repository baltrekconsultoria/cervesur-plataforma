import { useRef, useState } from 'react';
import { isApiError } from '../../api/errors';
import { canManageSensitiveUsers, type User } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';

type PasswordErrors = { password?: string; confirmation?: string };

/** Igual que el backend PHP: 12-72 bytes UTF-8, no caracteres. La confirmación nunca se envía. */
export function validatePasswordReset(password: string, confirmation: string): PasswordErrors {
  const errors: PasswordErrors = {};
  const bytes = new TextEncoder().encode(password).length;
  if (bytes < 12) errors.password = 'La contraseña debe tener al menos 12 bytes.';
  else if (bytes > 72) errors.password = 'La contraseña no puede superar 72 bytes.';
  if (!confirmation) errors.confirmation = 'Confirme la nueva contraseña.';
  else if (confirmation !== password) errors.confirmation = 'Las contraseñas no coinciden.';
  return errors;
}

/** P5: reasignación administrativa, nunca recuperación de contraseña ni cambio de la propia. */
export function ResetPasswordDialog({ user, onClose, onSaved }: {
  user: User;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { api, user: actor, capabilities } = useApp();
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [errors, setErrors] = useState<PasswordErrors>({});
  const [failure, setFailure] = useState('');
  const [saving, setSaving] = useState(false);
  const busy = useRef(false);

  // No se confía en el botón: incluso el diálogo exige capacidad sensible explícita.
  const allowed = canManageSensitiveUsers(capabilities)
    && actor?.role === 'admin'
    && actor.id !== user.id
    && user.active
    && !user.loginBlocked;

  const close = () => {
    if (busy.current) return;
    setPassword('');
    setConfirmation('');
    onClose();
  };

  const save = async () => {
    if (busy.current || !allowed) return;
    const problems = validatePasswordReset(password, confirmation);
    setErrors(problems);
    setFailure('');
    if (Object.keys(problems).length) return;
    busy.current = true;
    setSaving(true);
    try {
      await api.resetUserPassword(user.id, password);
      setPassword('');
      setConfirmation('');
      onSaved(); // El mensaje de éxito nunca contiene la contraseña.
    } catch (error) {
      // Nunca mostrar el cuerpo de errores del servidor: podría contener valores sensibles.
      if (isApiError(error) && error.kind === 'unauthorized') {
        setFailure('Su sesión ya no está vigente. Inicie sesión nuevamente.');
      } else if (isApiError(error) && error.kind === 'forbidden') {
        setFailure('No tiene permiso para cambiar contraseñas.');
      } else if (isApiError(error) && error.kind === 'conflict') {
        setFailure('El usuario cambió o la operación ya no es válida. Actualice la pantalla.');
      } else {
        setFailure('No se pudo actualizar la contraseña. Revise el estado del servicio e intente nuevamente.');
      }
      // No retener el secreto si hubo un fallo: el operador deberá introducirlo de nuevo.
      setPassword('');
      setConfirmation('');
    } finally {
      busy.current = false;
      setSaving(false);
    }
  };

  if (!allowed) return null;

  return (
    <Dialog title="Asignar nueva contraseña" labelId="us-reset-title" onClose={close}>
      <form noValidate onSubmit={submitHandler(save)}>
        <div className="modal-body">
          <p>Cuenta: <strong>{user.username}</strong>. La contraseña anterior no se puede consultar ni recuperar.</p>
          <p className="form-note">La nueva contraseña se guardará únicamente si el servidor autoriza la operación. Las sesiones de este usuario deberán invalidarse; su comportamiento en Flutter requiere validación antes de liberar P5.</p>
          <div className="form-grid">
            <label htmlFor="us-reset-password">Nueva contraseña
              <input
                id="us-reset-password" type="password" autoComplete="new-password"
                value={password} disabled={saving} required
                aria-invalid={Boolean(errors.password)} aria-describedby="us-reset-password-error"
                onChange={event => setPassword(event.target.value)}
              />
              <span className="form-note">Entre 12 y 72 bytes UTF-8. Acentos y símbolos pueden ocupar varios bytes.</span>
              <span className="field-error" id="us-reset-password-error" hidden={!errors.password}>{errors.password}</span>
            </label>
            <label htmlFor="us-reset-confirmation">Confirmar nueva contraseña
              <input
                id="us-reset-confirmation" type="password" autoComplete="new-password"
                value={confirmation} disabled={saving} required
                aria-invalid={Boolean(errors.confirmation)} aria-describedby="us-reset-confirmation-error"
                onChange={event => setConfirmation(event.target.value)}
              />
              <span className="field-error" id="us-reset-confirmation-error" hidden={!errors.confirmation}>{errors.confirmation}</span>
            </label>
          </div>
          <p className="form-note">Comunique la nueva contraseña directamente al titular mediante un canal seguro. No se enviará por correo desde esta pantalla.</p>
        </div>
        <DialogFooter error={failure} saving={saving} savingLabel="Actualizando…" submitLabel="Asignar contraseña" onCancel={close} />
      </form>
    </Dialog>
  );
}
