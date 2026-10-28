import { useState } from 'react';
import { isApiError } from '../../api/errors';
import type { Assignment } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';

/** Confirmación antes de retirar un acceso. Solo se cierra con éxito si el servidor confirmó la baja. */
export function RevokeDialog({ assignment, userName, reportName, companyName, onClose, onRevoked }: { assignment: Assignment; userName: string; reportName: string; companyName: string; onClose: () => void; onRevoked: () => void }) {
  const { api } = useApp();
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');

  const confirm = async () => {
    if (!assignment.id) return;
    setSaving(true);
    setFailure('');
    try {
      await api.revokeAssignment(assignment.id);
      onRevoked();
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo retirar el acceso. Intente nuevamente.');
      setSaving(false);
    }
  };

  return (
    <Dialog title="Retirar acceso" labelId="rv-title" onClose={() => { if (!saving) onClose(); }}>
      <form onSubmit={submitHandler(confirm)}>
        <div className="modal-body">
          <p>¿Retirar el acceso de <strong>{userName}</strong> al informe <strong>{reportName}</strong> ({companyName})?</p>
          <p className="form-note">El usuario dejará de ver este informe. La relación con la empresa se conserva y el acceso puede volver a concederse.</p>
        </div>
        <DialogFooter error={failure} saving={saving} submitLabel="Retirar acceso" savingLabel="Retirando…" variant="danger" onCancel={onClose} />
      </form>
    </Dialog>
  );
}
