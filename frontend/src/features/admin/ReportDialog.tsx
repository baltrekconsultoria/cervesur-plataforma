import { useState } from 'react';
import { isApiError } from '../../api/errors';
import type { Report, ReportInput } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';
import { Button, ErrorState, LoadingState } from '../../components/ui';
import { useAsync } from '../../lib/useAsync';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const EMPTY: ReportInput = { name: '', description: '', link: '', groupId: '', reportId: '' };

type FieldErrors = Partial<Record<keyof ReportInput, string>>;

export function validateReport(value: ReportInput): FieldErrors {
  const errors: FieldErrors = {};
  if (!value.name.trim()) errors.name = 'Ingrese el nombre.';
  else if (value.name.trim().length > 100) errors.name = 'Use hasta 100 caracteres.';
  const urlOk = (() => { try { const url = new URL(value.link.trim()); return url.protocol === 'https:' || url.protocol === 'http:'; } catch { return false; } })();
  if (!urlOk || value.link.trim().length > 255) errors.link = 'Ingrese una URL válida (http o https) de hasta 255 caracteres.';
  if (!UUID.test(value.groupId.trim())) errors.groupId = 'Ingrese un UUID válido, p. ej. 00000000-0000-0000-0000-000000000000.';
  if (!UUID.test(value.reportId.trim())) errors.reportId = 'Ingrese un UUID válido, p. ej. 00000000-0000-0000-0000-000000000000.';
  return errors;
}

/**
 * Alta o edición de un informe. El vínculo y los identificadores de Power BI son datos técnicos: solo los ve un administrador,
 * y en esta fase del MVP deben ser SINTÉTICOS (no se conectan informes reales).
 */
export function ReportDialog({ report, onClose, onSaved }: { report: Report | null; onClose: () => void; onSaved: (name: string, created: boolean) => void }) {
  const { api } = useApp();
  const [attempt, setAttempt] = useState(0);
  const detail = useAsync(async () => (report ? api.getReportDetail(report.id) : null), [api, report?.id, attempt]);
  return (
    <Dialog title={report ? 'Editar reporte' : 'Nuevo reporte'} labelId="rp-title" onClose={onClose}>
      {detail.status === 'loading' && <div className="modal-body"><LoadingState label="Cargando datos del reporte…" /></div>}
      {detail.status === 'error' && <div className="modal-body"><ErrorState onRetry={() => setAttempt(a => a + 1)} /><div className="modal-foot"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div></div>}
      {detail.status === 'ready' && <ReportForm key={attempt} internalId={detail.data?.internalId ?? null} initial={detail.data ? { name: detail.data.name, description: detail.data.description, link: detail.data.link, groupId: detail.data.groupId, reportId: detail.data.reportId } : EMPTY} onClose={onClose} onSaved={onSaved} />}
    </Dialog>
  );
}

function ReportForm({ internalId, initial, onClose, onSaved }: { internalId: string | null; initial: ReportInput; onClose: () => void; onSaved: (name: string, created: boolean) => void }) {
  const { api } = useApp();
  const [value, setValue] = useState<ReportInput>(initial);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const set = (key: keyof ReportInput) => (event: { target: { value: string } }) => setValue(v => ({ ...v, [key]: event.target.value }));

  const save = async () => {
    const next = validateReport(value);
    setErrors(next);
    setFailure('');
    if (Object.keys(next).length) return;
    setSaving(true);
    try {
      if (internalId) await api.updateReport(internalId, value); else await api.createReport(value);
      onSaved(value.name.trim(), internalId === null);
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo guardar el reporte. Intente nuevamente.');
      setSaving(false);
    }
  };

  const field = (key: keyof ReportInput, id: string, label: string, hint?: string) => (
    <label htmlFor={id}>{label}
      <input id={id} value={value[key]} autoComplete="off" aria-invalid={Boolean(errors[key])} aria-describedby={`${id}-err`} disabled={saving} onChange={set(key)} />
      {hint && <span className="form-note">{hint}</span>}
      <span className="field-error" id={`${id}-err`} hidden={!errors[key]}>{errors[key]}</span>
    </label>
  );

  return (
    <form noValidate onSubmit={submitHandler(save)}>
      <div className="modal-body">
        <p className="form-note">Los campos con * son obligatorios. En esta fase use <strong>solo datos sintéticos</strong>: no conecte informes reales de Power BI.</p>
        <div className="form-grid">
          {field('name', 'rp-name', 'Nombre *')}
          {field('description', 'rp-description', 'Descripción', 'Texto breve que verán los usuarios autorizados.')}
          {field('link', 'rp-link', 'Enlace del informe *', 'URL del informe en Power BI. Es un dato técnico: nunca se muestra a los usuarios.')}
          {field('groupId', 'rp-group', 'ID del grupo (workspace) de Power BI *', 'UUID del área de trabajo.')}
          {field('reportId', 'rp-uuid', 'ID del informe en Power BI *', 'UUID del informe. Identifica al informe en toda la aplicación y no puede repetirse.')}
        </div>
      </div>
      <DialogFooter error={failure} saving={saving} submitLabel={internalId ? 'Guardar cambios' : 'Crear reporte'} onCancel={onClose} />
    </form>
  );
}
