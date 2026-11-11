import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../../api/errors';
import type { User } from '../../api/types';
import { ResetPasswordDialog, validatePasswordReset } from './ResetPasswordDialog';

const ctx = vi.hoisted(() => ({
  reset: vi.fn(),
  caps: {
    adminWrites: true,
    writes: { empresas: true, reportes: true, accesos: true, usuarios: true },
    usersPasswordReset: true
  },
  actorId: '1',
  actorRole: 'admin'
}));
vi.mock('../../app/AppProvider', () => ({
  useApp: () => ({
    api: { resetUserPassword: ctx.reset },
    capabilities: ctx.caps,
    user: ctx.actorId ? { id: ctx.actorId, role: ctx.actorRole } : null
  })
}));

const target: User = {
  id: '2', username: 'usuario.qa', displayName: 'Usuario Prueba', email: 'qa@example.test',
  role: 'user', active: true, phone: '', companyIds: []
};

beforeEach(() => {
  ctx.reset.mockReset();
  ctx.reset.mockResolvedValue(undefined);
  ctx.caps.adminWrites = true;
  ctx.caps.writes.usuarios = true;
  ctx.caps.usersPasswordReset = true;
  ctx.actorId = '1';
  ctx.actorRole = 'admin';
});

function fill(password: string, confirmation: string) {
  fireEvent.change(screen.getByLabelText(/Nueva contraseña/), { target: { value: password } });
  fireEvent.change(screen.getByLabelText(/Confirmar nueva contraseña/), { target: { value: confirmation } });
}

describe('P5-UI02: validación en el navegador', () => {
  it('rechaza contraseña corta y confirmación ausente', () => {
    const errors = validatePasswordReset('corta', '');
    expect(errors.password).toContain('12 bytes');
    expect(errors.confirmation).toContain('Confirme');
  });

  it('rechaza divergencia y más de 72 bytes UTF-8', () => {
    expect(validatePasswordReset('clave-segura-larga', 'distinta').confirmation).toContain('no coinciden');
    expect(validatePasswordReset('á'.repeat(37), 'á'.repeat(37)).password).toContain('72 bytes');
    expect(validatePasswordReset('clave-segura-larga', 'clave-segura-larga')).toEqual({});
  });
});

describe('P5-UI02: diálogo de cambio de contraseña', () => {
  it('sin capacidad sensible o con autoedición no presenta formulario', () => {
    const { rerender } = render(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={vi.fn()} />);
    expect(screen.getByText('Asignar nueva contraseña')).toBeInTheDocument();
    ctx.caps.usersPasswordReset = false;
    rerender(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={vi.fn()} />);
    expect(screen.queryByText('Asignar nueva contraseña')).not.toBeInTheDocument();
    ctx.caps.usersPasswordReset = true;
    ctx.actorId = '2';
    rerender(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={vi.fn()} />);
    expect(screen.queryByText('Asignar nueva contraseña')).not.toBeInTheDocument();
    expect(ctx.reset).not.toHaveBeenCalled();
  });

  it('sin identidad administrativa no permite abrir el diálogo', () => {
    ctx.actorId = '';
    const { rerender } = render(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={vi.fn()} />);
    expect(screen.queryByText('Asignar nueva contraseña')).not.toBeInTheDocument();
    ctx.actorId = '1';
    ctx.actorRole = 'user';
    rerender(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={vi.fn()} />);
    expect(screen.queryByText('Asignar nueva contraseña')).not.toBeInTheDocument();
    expect(ctx.reset).not.toHaveBeenCalled();
  });

  it('la confirmación no se transmite y solo comunica éxito tras respuesta positiva', async () => {
    const onSaved = vi.fn();
    render(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={onSaved} />);
    fill('clave-nueva-muy-segura', 'clave-nueva-muy-segura');
    fireEvent.click(screen.getByRole('button', { name: 'Asignar contraseña' }));
    await waitFor(() => expect(ctx.reset).toHaveBeenCalledWith('2', 'clave-nueva-muy-segura'));
    await waitFor(() => expect(onSaved).toHaveBeenCalledOnce());
    expect(screen.getByLabelText(/Nueva contraseña/)).toHaveValue('');
    expect(screen.getByLabelText(/Confirmar nueva contraseña/)).toHaveValue('');
  });

  it('ante un 403 no confirma éxito, no refleja mensaje sensible y borra los campos', async () => {
    const onSaved = vi.fn();
    ctx.reset.mockRejectedValue(new ApiError(403, 'No autorizado: clave-nueva-muy-segura'));
    render(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={onSaved} />);
    fill('clave-nueva-muy-segura', 'clave-nueva-muy-segura');
    fireEvent.click(screen.getByRole('button', { name: 'Asignar contraseña' }));
    await waitFor(() => expect(screen.getByText('No tiene permiso para cambiar contraseñas.')).toBeInTheDocument());
    expect(screen.queryByText(/No autorizado: clave-nueva-muy-segura/)).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Nueva contraseña/)).toHaveValue('');
    expect(screen.getByLabelText(/Confirmar nueva contraseña/)).toHaveValue('');
    expect(onSaved).not.toHaveBeenCalled();
  });

  it('no envía nada con contraseñas distintas', async () => {
    render(<ResetPasswordDialog user={target} onClose={vi.fn()} onSaved={vi.fn()} />);
    fill('clave-nueva-muy-segura', 'otra-clave-diferente');
    fireEvent.click(screen.getByRole('button', { name: 'Asignar contraseña' }));
    expect(screen.getByText('Las contraseñas no coinciden.')).toBeInTheDocument();
    expect(ctx.reset).not.toHaveBeenCalled();
  });
});
