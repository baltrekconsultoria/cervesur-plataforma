import { useState } from 'react';
import { isApiError } from '../../api/errors';
import type { Report, ReportInput } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';
import { Button, ErrorState, LoadingState } from '../../components/ui';
import { classifyPowerBiLink, describeLink } from '../../domain/powerbiLink';
import { useAsync } from '../../lib/useAsync';

const EMPTY: ReportInput = { name: '', description: '', link: '' };

type FieldErrors = Partial<Record<keyof ReportInput, string>>;

export function validateReport(value: ReportInput): FieldErrors {
  const errors: FieldErrors = {};
  if (!value.name.trim()) errors.name = 'Ingrese el nombre.';
  else if (value.name.trim().length > 100) errors.name = 'Use hasta 100 caracteres.';
  const link = classifyPowerBiLink(value.link);
  if (!link.ok) errors.link = link.error;
  return errors;
}

/**
 * Alta o edición de un informe con solo tres campos: nombre, descripción y enlace de Power BI.
 * El tipo de enlace se detecta al escribir; los identificadores de Power BI (si el enlace los trae) los extrae y valida el servidor,
 * y nunca se inventan: un informe publicado en web no tiene identificadores de Power BI y usa una referencia interna de Cervesur.
 * El enlace es un dato técnico: solo lo ve un administrador. En esta fase use enlaces SINTÉTICOS.
 */
export function ReportDialog({ report, onClose, onSaved }: { report: Report | null; onClose: () => void; onSaved: (name: string, created: boolean) => void }) {
  const { api } = useApp();
  const [attempt, setAttempt] = useState(0);
  const detail = useAsync(async () => (report ? api.getReportDetail(report.id) : null), [api, report?.id, attempt]);
  return (
    <Dialog title={report ? 'Editar reporte' : 'Nuevo reporte'} labelId="rp-title" onClose={onClose}>
      {detail.status === 'loading' && <div className="modal-body"><LoadingState label="Cargando datos del reporte…" /></div>}
      {detail.status === 'error' && <div className="modal-body"><ErrorState onRetry={() => setAttempt(a => a + 1)} /><div className="modal-foot"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div></div>}
      {detail.status === 'ready' && <ReportForm key={attempt} internalId={detail.data?.internalId ?? null} initial={detail.data ? { name: detail.data.name, description: detail.data.description, link: detail.data.link } : EMPTY} onClose={onClose} onSaved={onSaved} />}
    </Dialog>
  );
}

function ReportForm({ internalId, initial, onClose, onSaved }: { internalId: string | null; initial: ReportInput; onClose: () => void; onSaved: (name: string, created: boolean) => void }) {
  const { api } = useApp();
  const [value, setValue] = useState<ReportInput>(initial);
  const [errors, setErrors] = useState<FieldErrors>({});
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const set = (key: keyof ReportInput) => (event: { target: { value: string } }) => { setValue(v => ({ ...v, [key]: event.target.value })); setErrors(e => ({ ...e, [key]: undefined })); };
  const detected = value.link.trim() ? describeLink(classifyPowerBiLink(value.link)) : null;

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

  return (
    <form noValidate onSubmit={submitHandler(save)}>
      <div className="modal-body">
        <p className="form-note">Los campos con * son obligatorios. En esta fase use <strong>solo enlaces sintéticos</strong>: no registre informes reales de Power BI.</p>
        <div className="form-grid">
          <label htmlFor="rp-name">Nombre *
            <input id="rp-name" value={value.name} autoComplete="off" aria-invalid={Boolean(errors.name)} aria-describedby="rp-name-err" disabled={saving} onChange={set('name')} />
            <span className="field-error" id="rp-name-err" hidden={!errors.name}>{errors.name}</span>
          </label>
          <label htmlFor="rp-description">Descripción
            <input id="rp-description" value={value.description} autoComplete="off" disabled={saving} onChange={set('description')} />
            <span className="form-note">Texto breve que verán los usuarios autorizados.</span>
          </label>
        </div>
        <label htmlFor="rp-link">Enlace de Power BI *
          <input id="rp-link" value={value.link} autoComplete="off" spellCheck={false} aria-invalid={Boolean(errors.link)} aria-describedby="rp-link-help rp-link-err" disabled={saving} onChange={set('link')} />
          <span className="form-note" id="rp-link-help">Pegue la URL (no el código iframe). Se admite «Publicar en web» (<em>app.powerbi.com/view?r=…</em>), un enlace de inserción (<em>reportEmbed?reportId=…</em>) o el enlace del informe. Es un dato técnico: no se muestra a los usuarios.</span>
          <span className="field-error" id="rp-link-err" hidden={!errors.link}>{errors.link}</span>
        </label>
        {detected && (
          <p className={`link-detected link-detected--${detected.tone}`} role="status" data-testid="link-detected">
            {detected.tone === 'error' ? 'Enlace no compatible: ' : 'Tipo de enlace detectado: '}{detected.text}
          </p>
        )}
      </div>
      <DialogFooter error={failure} saving={saving} submitLabel={internalId ? 'Guardar cambios' : 'Crear reporte'} onCancel={onClose} />
    </form>
  );
}
