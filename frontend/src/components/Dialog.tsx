import { useEffect, useRef, type FormEvent, type ReactNode } from 'react';
import { Button } from './ui';

/**
 * Diálogo modal accesible basado en <dialog>. `onClose` se invoca con Escape o al cerrar con el botón de cancelar.
 * jsdom no implementa `showModal`: en ese caso se recurre al atributo `open` (solo ocurre en pruebas unitarias).
 */
export function Dialog({ title, children, onClose, labelId }: { title: string; children: ReactNode; onClose: () => void; labelId: string }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = ref.current;
    if (!dialog || dialog.open) return;
    if (typeof dialog.showModal === 'function') dialog.showModal();
    else dialog.setAttribute('open', '');
    return () => { if (dialog.open && typeof dialog.close === 'function') dialog.close(); };
  }, []);
  return (
    <dialog ref={ref} className="modal" aria-labelledby={labelId} onCancel={event => { event.preventDefault(); onClose(); }}>
      <div className="modal-head"><h2 id={labelId}>{title}</h2></div>
      {children}
    </dialog>
  );
}

/** Pie estándar de un formulario modal: error visible (role=alert), cancelar y acción principal con estado de guardado. */
export function DialogFooter({ error, saving, submitLabel, savingLabel = 'Guardando…', onCancel, variant = 'primary' }: { error?: string; saving: boolean; submitLabel: string; savingLabel?: string; onCancel: () => void; variant?: 'primary' | 'danger' }) {
  return (
    <>
      <p className="field-error modal-error" role="alert" hidden={!error}>{error}</p>
      <div className="modal-foot">
        <Button variant="secondary" disabled={saving} onClick={onCancel}>Cancelar</Button>
        <Button type="submit" variant={variant} disabled={saving} aria-busy={saving}>{saving ? savingLabel : submitLabel}</Button>
      </div>
    </>
  );
}

export const submitHandler = (action: () => Promise<void>) => (event: FormEvent) => { event.preventDefault(); void action(); };
