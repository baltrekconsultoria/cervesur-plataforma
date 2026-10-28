// Mini arnés CDP (sin dependencias): Chromium/Edge/Chrome headless + WebSocket nativo de Node.
// Navegador: variable BROWSER_PATH o el primero encontrado entre las rutas habituales.
import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

function browserPath() {
  if (process.env.BROWSER_PATH) return process.env.BROWSER_PATH;
  const candidates = [
    'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe', 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/microsoft-edge',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
  ];
  const found = candidates.find(p => existsSync(p));
  if (!found) throw new Error('No se encontró un navegador Chromium/Edge/Chrome. Defina BROWSER_PATH.');
  return found;
}

export async function launch({ width = 1366, height = 850, mobile = false, port = 9333 } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'cdp-'));
  const proc = spawn(browserPath(), [...(process.env.CI ? ['--no-sandbox'] : []), '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${dir}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let targets;
  for (let i = 0; i < 60; i++) {
    try { targets = await (await fetch(`http://127.0.0.1:${port}/json`)).json(); if (targets.find(t => t.type === 'page')) break; } catch { /* el navegador aún arranca */ }
    await new Promise(r => setTimeout(r, 250));
  }
  const page = targets.find(t => t.type === 'page');
  const ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise(r => ws.addEventListener('open', r));
  let id = 0; const pending = new Map(); const listeners = [];
  ws.addEventListener('message', e => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { const { res, rej } = pending.get(m.id); pending.delete(m.id); m.error ? rej(new Error(m.error.message)) : res(m.result); } else listeners.forEach(l => l(m)); });
  const send = (method, params = {}) => new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); ws.send(JSON.stringify({ id: i, method, params })); });
  const requests = []; const requestLog = []; const consoleErrors = [];
  listeners.push(m => {
    if (m.method === 'Network.requestWillBeSent') { requests.push(m.params.request.url); requestLog.push({ method: m.params.request.method, url: m.params.request.url, hasAuth: Boolean(m.params.request.headers?.Authorization || m.params.request.headers?.authorization) }); }
    if (m.method === 'Runtime.exceptionThrown') consoleErrors.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') consoleErrors.push(m.params.args.map(a => a.value ?? a.description).join(' '));
  });
  await send('Network.enable'); await send('Runtime.enable'); await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile });
  const api = {
    requests, requestLog, consoleErrors, send,
    async goto(url) { await send('Page.navigate', { url }); await new Promise(r => setTimeout(r, 700)); },
    async eval(expr) { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text); return r.result.value; },
    async wait(ms = 250) { await new Promise(r => setTimeout(r, ms)); },
    async click(sel) { const ok = await api.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)}); if(!e) return false; e.click(); return true;})()`); if (!ok) throw new Error('No existe: ' + sel); await api.wait(); },
    async type(sel, text) { await api.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)}); e.focus(); const set=Object.getOwnPropertyDescriptor(Object.getPrototypeOf(e),'value').set; set.call(e,${JSON.stringify(text)}); e.dispatchEvent(new Event('input',{bubbles:true}));})()`); await api.wait(); },
    async select(sel, value) { await api.eval(`(()=>{const e=document.querySelector(${JSON.stringify(sel)}); const set=Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype,'value').set; set.call(e,${JSON.stringify(value)}); e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true}));})()`); await api.wait(); },
    async key(key, code = key, extra = {}) { const base = { key, code, windowsVirtualKeyCode: extra.vk || 0, ...extra }; await send('Input.dispatchKeyEvent', { type: 'keyDown', ...base }); await send('Input.dispatchKeyEvent', { type: 'keyUp', ...base }); await api.wait(120); },
    async shot(file) { const r = await send('Page.captureScreenshot', { format: 'png' }); writeFileSync(file, Buffer.from(r.data, 'base64')); },
    async close() { try { await send('Browser.close'); } catch { /* el navegador ya se cerró */ } ws.close(); proc.kill(); }
  };
  return api;
}
