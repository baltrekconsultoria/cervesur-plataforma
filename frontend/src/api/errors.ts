import type { ApiErrorKind } from './types';

const KIND_BY_STATUS: Record<number, ApiErrorKind> = {
  400: 'validation',
  401: 'unauthorized',
  403: 'forbidden',
  404: 'not_found',
  409: 'conflict',
  422: 'validation'
};

export class ApiError extends Error {
  readonly status: number;
  readonly kind: ApiErrorKind;

  constructor(status: number, message: string, kind?: ApiErrorKind) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.kind = kind ?? KIND_BY_STATUS[status] ?? (status === 0 ? 'network' : 'server');
  }
}

export const isApiError = (value: unknown): value is ApiError => value instanceof ApiError;
/** 403 y 404 reciben el mismo tratamiento visual: no se confirma si el recurso existe. */
export const isDenied = (value: unknown): boolean => isApiError(value) && (value.kind === 'forbidden' || value.kind === 'not_found');
