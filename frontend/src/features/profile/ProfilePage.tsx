import { useEffect, useState, type FormEvent } from 'react';
import { isApiError } from '../../api/errors';
import type { Company, User } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { Button, Tag } from '../../components/ui';
import { useAsync } from '../../lib/useAsync';

const PHONE = /^[\d\s+()-]{6,20}$/;

export function ProfilePage() {
  const { api, user, updateUser, logout, version } = useApp();
  const companies = useAsync(() => api.listCompanies(), [api, version]);
  // El perfil se vuelve a pedir al servidor: refleja cambios de rol y revela sesiones revocadas (401).
  const profile = useAsync(() => api.me(), [api, version]);
  useEffect(() => {
    if (profile.status === 'ready') updateUser(profile.data);
  }, [profile, updateUser]);
  if (!user) return null;
  const admin = user.role === 'admin';
  // El formulario se vuelve a montar al llegar el perfil del servidor para partir de sus valores.
  const source = profile.status === 'ready' ? profile.data : user;

  return (
    <Page title="Mi perfil" crumbs={[{ label: admin ? 'Administración' : 'Portal', to: admin ? '/admin' : '/reportes' }, { label: 'Mi perfil' }]}>
      <div className="profile-grid">
        <ProfileForm key={profile.status} source={source} companies={companies.status === 'ready' ? companies.data : []} />
        <div className="panel">
          <h2>Sesión</h2>
          <p className="muted">Al cerrar sesión se elimina el token de la memoria del navegador y se invalida la sesión en el servidor.</p>
          <Button variant="secondary" onClick={() => { void logout(); }}>Cerrar sesión</Button>
        </div>
      </div>
    </Page>
  );
}

function ProfileForm({ source, companies }: { source: User; companies: Company[] }) {
  const { api, updateUser, capabilities } = useApp();
  const [name, setName] = useState(source.displayName);
  const [phone, setPhone] = useState(source.phone);
  const [errors, setErrors] = useState<{ name?: string; phone?: string }>({});
  const [status, setStatus] = useState('');
  const admin = source.role === 'admin';

  const submit = async (event: FormEvent) => {
    event.preventDefault();
    const next: { name?: string; phone?: string } = {};
    if (!name.trim()) next.name = 'Ingrese su nombre.';
    if (phone.trim() && !PHONE.test(phone.trim())) next.phone = 'Use solo números, espacios y + ( ) -.';
    setErrors(next);
    setStatus('');
    if (next.name || next.phone) return;
    try {
      updateUser(await api.updateProfile({ displayName: name, phone }));
      setStatus('Perfil actualizado. Cambio solo en datos de demostración.');
    } catch (e) {
      if (isApiError(e) && e.kind === 'unauthorized') return;
      setStatus('No se pudo actualizar el perfil.');
    }
  };

  return (
    <div className="panel">
      <h2>{source.displayName} {admin && <Tag tone="admin">Administrador</Tag>}</h2>
      <p className="muted">Actualice solo sus datos de contacto. El correo, el rol y las empresas los gestiona un administrador.</p>
      <form noValidate onSubmit={e => { void submit(e); }}>
        <div className="form-grid">
          <label htmlFor="pf-name">Nombres
            <input id="pf-name" value={name} readOnly={!capabilities.profileEdit} aria-readonly={!capabilities.profileEdit} autoComplete="name" aria-invalid={Boolean(errors.name)} aria-describedby="pf-name-err" onChange={e => setName(e.target.value)} />
            <span className="field-error" id="pf-name-err" hidden={!errors.name}>{errors.name}</span>
          </label>
          <label htmlFor="pf-phone">Teléfono
            <input id="pf-phone" value={phone} readOnly={!capabilities.profileEdit} aria-readonly={!capabilities.profileEdit} autoComplete="tel" aria-invalid={Boolean(errors.phone)} aria-describedby="pf-phone-err" onChange={e => setPhone(e.target.value)} />
            <span className="field-error" id="pf-phone-err" hidden={!errors.phone}>{errors.phone}</span>
          </label>
          <label htmlFor="pf-email">Correo
            <input id="pf-email" value={source.email} readOnly aria-readonly="true" />
          </label>
        </div>
        {!admin && companies.length > 0 && (
          <div><span className="muted">Empresas relacionadas</span><div className="chips">{companies.filter(c => c.active).map(c => <Tag key={c.id}>{c.name}</Tag>)}</div></div>
        )}
        {capabilities.profileEdit
          ? <div><Button type="submit">Guardar cambios</Button></div>
          : <p className="form-note">La edición del perfil se conectará con el servicio real en una etapa posterior; por ahora es de solo lectura.</p>}
        <p className="form-note" role="status">{status}</p>
      </form>
    </div>
  );
}
