import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Reenvío de /api al backend QA local (solo se usa con el modo `qa`; el proveedor simulado no hace peticiones).
// El navegador habla siempre con el origen de Vite (mismo origen), así que el backend no necesita CORS.
const target = process.env.QA_API_TARGET ?? 'http://127.0.0.1:8080';
if (!['127.0.0.1', 'localhost'].includes(new URL(target).hostname)) {
  throw new Error(`QA_API_TARGET debe apuntar a localhost (recibido: ${target}).`);
}
const proxy = { '/api': { target, changeOrigin: false } };

export default defineConfig({
  plugins: [react()],
  server: { host: '127.0.0.1', port: 5173, strictPort: true, proxy },
  preview: { host: '127.0.0.1', port: 4174, strictPort: true, proxy },
  build: { sourcemap: false },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}']
  }
});
