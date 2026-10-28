import react from '@vitejs/plugin-react';
import { defineConfig, type Plugin } from 'vitest/config';

// Reenvío de /api al backend QA local (solo se usa con el modo `qa`; el proveedor simulado no hace peticiones).
// El navegador habla siempre con el origen de Vite (mismo origen), así que el backend no necesita CORS.
const target = process.env.QA_API_TARGET ?? 'http://127.0.0.1:8080';
if (!['127.0.0.1', 'localhost'].includes(new URL(target).hostname)) {
  throw new Error(`QA_API_TARGET debe apuntar a localhost (recibido: ${target}).`);
}
const proxy = { '/api': { target, changeOrigin: false } };

/**
 * Solo modo `qa`: página LOCAL que hace de «Power BI» en las pruebas del visor (sin acceso externo). No forma parte de las
 * compilaciones normales. Muestra el token y los parámetros recibidos para poder comprobar qué URL construyó el visor.
 */
const QA_STUB_PATH = '/qa/powerbi-view.html';
const QA_STUB_HTML = `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Power BI simulado (QA local)</title>
<style>body{margin:0;font:16px system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;background:#eef3fb;color:#1b2a41}main{max-width:560px;padding:1.5rem;text-align:center}code{word-break:break-all}</style></head>
<body><main id="qa-powerbi-stub"><h1>Informe de Power BI simulado</h1><p>Página local de pruebas: no es Power BI y no contacta con ningún servicio externo.</p><p>Token recibido: <code id="qa-token"></code></p><p>Página: <code id="qa-page"></code></p></main>
<script>var p=new URLSearchParams(location.search);var tk=p.get('r')||'(ninguno)',pg=p.get('pageName')||'(inicial)';document.getElementById('qa-token').textContent=tk;document.getElementById('qa-page').textContent=pg;try{parent.postMessage({source:'qa-powerbi-stub',token:tk,page:pg},'*')}catch(e){}</script></body></html>`;
function qaPowerBiStub(): Plugin {
  return {
    name: 'cervesur-qa-powerbi-stub',
    apply: (_config, env) => env.mode === 'qa',
    generateBundle() { this.emitFile({ type: 'asset', fileName: QA_STUB_PATH.slice(1), source: QA_STUB_HTML }); },
    configureServer(server) {
      server.middlewares.use((req, res, next) => {
        if (req.url?.split('?')[0] !== QA_STUB_PATH) { next(); return; }
        res.setHeader('Content-Type', 'text/html; charset=utf-8');
        res.end(QA_STUB_HTML);
      });
    }
  };
}

export default defineConfig({
  plugins: [react(), qaPowerBiStub()],
  server: { host: '127.0.0.1', port: 5173, strictPort: true, proxy },
  preview: { host: '127.0.0.1', port: 4174, strictPort: true, proxy },
  build: { sourcemap: false },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    testTimeout: 20000
  }
});
