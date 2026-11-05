import { useState, type ReactNode } from 'react';
import { isApiError } from '../../api/errors';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';

/** Mensaje explícito por tipo de error: 403 = no habilitada en el servidor; 5xx/503 = el servidor no pudo completarla (las escrituras son atómicas: no queda ningún cambio a medias). */
export function failureMessage(error: unknown, fallback: string): string {
  if (!isApiError(error)) return fallback;
  if (error.kind === 'forbidden') return error.message || 'Esta operación no está habilitada en el servidor.';
  if (error.kind === 'server') return `${error.message || fallback} No se aplicó ningún cambio; intente de nuevo o avise al administrador del sistema.`;
  return error.message || fallback;
}

/**
 * Confirmación de una operación sensible (desactivar, reactivar, retirar…). Solo se da por hecha si el servidor la confirmó.
 * Un conflicto (409) o un registro que ya no existe (404) significa que la pantalla quedó desactualizada: se muestra el mensaje
 * del servidor y, al cerrar, se recargan los datos (`onStale`) para que el usuario vea el estado real.
 */
export function ConfirmActionDialog({ title, labelId, children, confirmLabel, savingLabel, variant = 'primary', fallbackError, run, onClose, onDone, onStale }: {
  title: string;
  labelId: string;
  children: ReactNode;
  confirmLabel: string;
  savingLabel: string;
  variant?: 'primary' | 'danger';
  fallbackError: string;
  run: () => Promise<void>;
  onClose: () => void;
  onDone: () => void;
  onStale: () => void;
}) {
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const [stale, setStale] = useState(false);

  const confirm = async () => {
    setSaving(true);
    setFailure('');
    try {
      await run();
      onDone();
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      const outdated = isApiError(error) && (error.kind === 'conflict' || error.kind === 'not_found');
      setStale(outdated);
      setFailure(failureMessage(error, fallbackError));
      setSaving(false);
    }
  };

  const close = () => {
    if (saving) return;
    if (stale) onStale();
    onClose();
  };

  return (
    <Dialog title={title} labelId={labelId} onClose={close}>
      <form onSubmit={submitHandler(confirm)}>
        <div className="modal-body">{children}</div>
        <DialogFooter error={failure} saving={saving} submitLabel={confirmLabel} savingLabel={savingLabel} variant={variant} onCancel={close} />
      </form>
    </Dialog>
  );
}
