import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach } from 'vitest';

// Las consultas por rol sobre el DOM de la aplicación completa son lentas en runners compartidos (CI): margen amplio, sin cambiar lo que se verifica.
configure({ asyncUtilTimeout: 5000 });

afterEach(() => cleanup());
