import { useState } from 'react';
import type { User } from '../../api/types';
import { isApiError } from '../../api/errors';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';

export function passwordResetError(password: string, confirmation: string): string | null {
  if (password.length < 12) return 'La nueva contraseña requiere al menos 12 caracteres.';
  if (new TextEncoder().encode(password).length > 72) return 'La contraseña supera el límite de 72 bytes.';
  if (password !== confirmation) return 'Las contraseñas no coinciden.';
  return null;
}

/** P5-R: no muestra contraseñas actuales ni permite modificar roles, emails o cuentas admin. */
export function UserResetPasswordDialog({ user, onClose, onSaved }: {
  user: User; onClose: () => void; onSaved: () => void
}) {
  const { api } = useApp();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [ack, setAck] = useState(false);
  const [failure, setFailure] = useState('');
  const [saving, setSaving] = useState(false);

  const save = async () => {
    const error = passwordResetError(password, confirm);
    if (error) { setFailure(error); return; }
    if (!ack) { setFailure('Confirme que informará al usuario por un canal seguro.'); return; }
    setFailure('');
    setSaving(true);
    try {
      if (!api.resetUserPassword) throw new Error('Proveedor no compatible con P5-R');
      await api.resetUserPassword(user.id, password);
      setPassword('');
      setConfirm('');
      onSaved();
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo restablecer la contraseña.');
      setSaving(false);
    }
  };

  return (
    <Dialog title="Restablecer contraseña" labelId="p5r-title" onClose={() => { if (!saving) onClose(); }}>
      <form noValidate onSubmit={submitHandler(save)}>
        <div className="modal-body">
          <p>Usuario estándar: <strong>{user.displayName}</strong>. No se modifica su rol ni su correo.</p>
          <p className="form-note">El cambio invalida las sesiones compatibles con session_version. Algunas sesiones antiguas de Flutter podrían continuar hasta caducar (máximo previsto: 24 h); no afirme revocación inmediata en todos los sistemas.</p>
          <div className="form-grid">
            <label htmlFor="p5r-password">Nueva contraseña
              <input id="p5r-password" type="password" autoComplete="new-password" value={password}
                minLength={12} maxLength={72} disabled={saving} onChange={event => setPassword(event.target.value)} />
            </label>
            <label htmlFor="p5r-confirm">Confirmar contraseña
              <input id="p5r-confirm" type="password" autoComplete="new-password" value={confirm}
                disabled={saving} onChange={event => setConfirm(event.target.value)} />
            </label>
          </div>
          <label className="form-note">
            <input type="checkbox" checked={ack} disabled={saving} onChange={event => setAck(event.target.checked)} />
            Entregaré la contraseña nueva por un canal seguro y solicitaré un nuevo inicio de sesión.
          </label>
        </div>
        <DialogFooter error={failure} saving={saving} submitLabel="Confirmar restablecimiento" onCancel={onClose} />
      </form>
    </Dialog>
  );
}
