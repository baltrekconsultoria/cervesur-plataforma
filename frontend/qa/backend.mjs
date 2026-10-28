// Orquestador del entorno QA aislado: backend PHP (repositorio, montado de solo lectura) + MySQL sintético desechable.
//   node qa/backend.mjs up       levanta MySQL + API (127.0.0.1:8080, y 127.0.0.1:8081 con JWT de 2 s)
//   node qa/backend.mjs down     elimina contenedores y redes
//   node qa/backend.mjs status   estado y comprobaciones de aislamiento
//   node qa/backend.mjs ci       repite las pruebas del backend como en .github/workflows/backend-ci.yml
// Reutiliza tests/isolated/{Dockerfile,router.php,seed.php} del repositorio sin modificarlos.
// Los secretos (contraseña de MySQL, JWT_SECRET) se generan al azar en cada arranque, viven solo en
// variables de entorno del proceso y de los contenedores, y no se escriben en ningún archivo.
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
// frontend/qa -> raíz del monorepo (el backend se monta de solo lectura en los contenedores).
// BACKEND_REPO permite apuntar a otro checkout del backend (p. ej. el de una rama de PR) sin cambiar de rama.
const REPO = process.env.BACKEND_REPO ?? join(here, '..', '..');
const QA = here;
const IMAGE = 'cervesur-ci-php:local';
const N = { db: 'cervesur-qa-db', app: 'cervesur-qa-app', short: 'cervesur-qa-app-short', proxy: 'cervesur-qa-proxy', dbNet: 'cervesur-qa-db-net', frontNet: 'cervesur-qa-front-net' };

function docker(args, { env = {}, allowFail = false, input } = {}) {
  const r = spawnSync('docker', args, { env: { ...process.env, ...env }, encoding: 'utf8', input });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { ok: r.status === 0, out: (r.stdout || '').trim(), err: (r.stderr || '').trim() };
}
const sleep = ms => new Promise(r => setTimeout(r, ms));
const hex = n => randomBytes(n).toString('hex');

function down() {
  for (const c of [N.proxy, N.app, N.short, N.db]) docker(['rm', '--force', '--volumes', c], { allowFail: true });
  for (const n of [N.frontNet, N.dbNet]) docker(['network', 'rm', n], { allowFail: true });
}

function ensureImages() {
  if (!docker(['image', 'inspect', IMAGE], { allowFail: true }).ok) {
    console.log('Construyendo imagen PHP 8.3 desde tests/isolated/Dockerfile…');
    docker(['build', '--tag', IMAGE, '--file', join(REPO, 'tests', 'isolated', 'Dockerfile'), REPO]);
  }
  if (!docker(['image', 'inspect', 'mysql:8.0'], { allowFail: true }).ok) {
    throw new Error('Falta la imagen mysql:8.0 en caché local. No se descarga sin autorización.');
  }
}

async function waitFor(label, fn, tries = 60) {
  for (let i = 0; i < tries; i++) { if (await fn()) return; await sleep(1000); }
  throw new Error(`Tiempo agotado esperando: ${label}`);
}

async function up() {
  // La integración QA (contrato, E2E contra PHP real) exige la paginación y las vistas mínimas del PR #4 del backend.
  if (!existsSync(join(REPO, 'backend', 'lib', 'pagination.php'))) {
    throw new Error('Este checkout del backend no incluye paginación ni vistas mínimas (PR #4). La integración QA no puede validarse aquí; use una rama que las contenga.');
  }
  ensureImages();
  down();
  const secrets = { TEST_DB_PASS: hex(24), JWT_SECRET: hex(32) };
  const env = { ...secrets, DB_PASS: secrets.TEST_DB_PASS, MYSQL_ROOT_PASSWORD: secrets.TEST_DB_PASS };

  // MySQL: solo en una red interna (sin salida a Internet) y sin puertos publicados.
  docker(['network', 'create', '--internal', N.dbNet]);
  // Red puente normal: la usa SOLO el reenviador que publica 127.0.0.1. La API y MySQL nunca se conectan a ella.
  docker(['network', 'create', N.frontNet]);
  docker(['run', '--detach', '--name', N.db, '--network', N.dbNet, '--network-alias', 'db', '--tmpfs', '/var/lib/mysql',
    '--env', 'MYSQL_ROOT_PASSWORD', '--env', 'MYSQL_DATABASE=fixture', 'mysql:8.0'], { env });
  await waitFor('MySQL sintético', () => docker(['exec', N.db, 'mysqladmin', 'ping', '-h', '127.0.0.1', '-uroot', `--password=${secrets.TEST_DB_PASS}`, '--silent'], { allowFail: true }).ok, 90);
  await sleep(2000); // mysqladmin responde antes de aceptar conexiones TCP externas en la primera inicialización

  const php = (script, volumes) => docker(['run', '--rm', '--network', N.dbNet, ...volumes, '--env', 'TEST_DB_PASS', IMAGE, 'php', script], { env });
  const repoVol = ['--volume', `${REPO}:/work:ro`, '--workdir', '/work'];
  for (let i = 0; ; i++) {
    try { console.log(php('tests/isolated/seed.php', repoVol).out); break; } catch (e) { if (i > 8) throw e; await sleep(2000); }
  }
  console.log(php('/qa/seed-extra.php', [...repoVol, '--volume', `${QA}:/qa:ro`]).out);

  const harden = ['--read-only', '--tmpfs', '/tmp', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--memory', '256m', '--pids-limit', '100'];
  // API y API de JWT corto: SOLO red interna (sin ruta a Internet). No publican puertos.
  for (const [name, alias, ttl] of [[N.app, 'app', 3600], [N.short, 'app-short', 2]]) {
    docker(['run', '--detach', '--name', name, '--network', N.dbNet, '--network-alias', alias, ...harden,
      '--volume', `${REPO}:/work:ro`, '--workdir', '/work',
      '--env', 'DB_HOST=db', '--env', 'DB_NAME=fixture', '--env', 'DB_USER=root', '--env', 'DB_PASS', '--env', 'DB_CHARSET=utf8mb4',
      '--env', 'JWT_SECRET', '--env', `JWT_EXPIRATION=${ttl}`, '--env', 'PHP_CLI_SERVER_WORKERS=4',
      IMAGE, 'php', '-S', '0.0.0.0:8080', 'tests/isolated/router.php'], { env });
  }
  // Reenviador: publica solo 127.0.0.1:8080/8081 y reenvía bytes a la red interna.
  docker(['create', '--name', N.proxy, '--network', N.frontNet, '--publish', '127.0.0.1:8080:8080', '--publish', '127.0.0.1:8081:8081', ...harden,
    '--volume', `${QA}:/qa:ro`, IMAGE, 'php', '/qa/proxy.php', '8080=app:8080,8081=app-short:8080']);
  docker(['network', 'connect', N.dbNet, N.proxy]);
  docker(['start', N.proxy]);
  for (const port of [8080, 8081]) {
    await waitFor(`API en 127.0.0.1:${port}`, async () => { try { return (await fetch(`http://127.0.0.1:${port}/api/ping`)).ok; } catch { return false; } }, 40);
  }
  console.log('Entorno QA listo:\n  API principal   http://127.0.0.1:8080  (JWT 3600 s)\n  API JWT corto   http://127.0.0.1:8081  (JWT 2 s, solo pruebas de expiración)\n  Cuentas sintéticas (contraseña pública del fixture: synthetic-password): usera, userb, admin, inactive, legacy, weird, nobody');
}

function status() {
  const lines = [];
  for (const c of [N.db, N.app, N.short, N.proxy]) lines.push(`${c.padEnd(22)} ${docker(['inspect', '-f', '{{.State.Status}}', c], { allowFail: true }).out || 'no existe'}`);
  lines.push(`Puertos publicados por el reenviador: ${docker(['port', N.proxy], { allowFail: true }).out.split('\n').join(' | ') || '—'}`);
  lines.push(`Puertos publicados app/short: ${docker(['port', N.app], { allowFail: true }).out || 'ninguno'} / ${docker(['port', N.short], { allowFail: true }).out || 'ninguno'}`);
  lines.push(`Puertos publicados db:    ${docker(['port', N.db], { allowFail: true }).out || 'ninguno'}`);
  const egress = (c, host) => docker(['exec', c, 'php', '-r', `$s=@stream_socket_client("tcp://${host}:80",$e,$m,3); echo $s?"ABIERTA":"BLOQUEADA";`], { allowFail: true }).out;
  lines.push(`Salida a Internet desde la API (1.1.1.1:80):  ${egress(N.app, '1.1.1.1')}`);
  lines.push(`Salida a Internet desde MySQL (vía red interna): ${docker(['exec', N.db, 'bash', '-c', 'timeout 3 bash -c "</dev/tcp/1.1.1.1/80" && echo ABIERTA || echo BLOQUEADA'], { allowFail: true }).out}`);
  lines.push(`Red db interna: ${docker(['network', 'inspect', '-f', 'internal={{.Internal}}', N.dbNet], { allowFail: true }).out}; redes de la API: ${docker(['inspect', '-f', '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}', N.app], { allowFail: true }).out}; redes de MySQL: ${docker(['inspect', '-f', '{{range $k,$v := .NetworkSettings.Networks}}{{$k}} {{end}}', N.db], { allowFail: true }).out}`);
  lines.push(`Resolución DNS externa desde la API: ${docker(['exec', N.app, 'php', '-r', 'echo gethostbyname("example.com")==="example.com"?"BLOQUEADA":"ABIERTA";'], { allowFail: true }).out}`);
  console.log(lines.join('\n'));
}

/** Repite el CI del backend (workflow backend-ci.yml) con redes aisladas y nombres propios. */
async function ci() {
  ensureImages();
  const vol = ['--volume', `${REPO}:/work:ro`, '--workdir', '/work'];
  const run = (title, args, opt) => { console.log(`\n== ${title}`); const r = spawnSync('docker', args, { encoding: 'utf8', ...opt }); process.stdout.write(r.stdout || ''); process.stderr.write(r.stderr || ''); if (r.status !== 0) throw new Error(`${title}: falló`); };
  run('Sintaxis PHP', ['run', '--rm', '--network', 'none', ...vol, IMAGE, 'sh', '-lc', 'find backend tests -type f -name "*.php" -print0 | xargs -0 -r -n 1 php -l >/dev/null && echo "PHP syntax: $(find backend tests -type f -name "*.php" | wc -l) files passed"']);
  run('Pruebas unitarias de seguridad PHP', ['run', '--rm', '--network', 'none', ...vol, IMAGE, 'php', 'tests/security_unit.php']);
  console.log('\n== Pruebas estáticas de seguridad (Python)');
  const py = spawnSync('python', ['-m', 'unittest', 'discover', '-s', 'tests', '-v'], { cwd: REPO, encoding: 'utf8', env: { ...process.env, PYTHONDONTWRITEBYTECODE: '1' } });
  process.stdout.write(py.stdout || ''); process.stderr.write(py.stderr || '');
  if (py.status !== 0) throw new Error('Pruebas estáticas: fallaron');

  const net = 'cervesur-ci-local-net'; const db = 'cervesur-ci-local-db'; const app = 'cervesur-ci-local-app';
  const secrets = { TEST_DB_PASS: hex(24), JWT_SECRET: hex(32) };
  const env = { ...secrets, DB_PASS: secrets.TEST_DB_PASS, MYSQL_ROOT_PASSWORD: secrets.TEST_DB_PASS };
  const clean = () => { docker(['rm', '--force', app, db], { allowFail: true }); docker(['network', 'rm', net], { allowFail: true }); };
  clean();
  try {
    docker(['network', 'create', '--internal', net]);
    docker(['run', '--detach', '--name', db, '--network', net, '--network-alias', 'db', '--tmpfs', '/var/lib/mysql', '--env', 'MYSQL_ROOT_PASSWORD', '--env', 'MYSQL_DATABASE=fixture', 'mysql:8.0'], { env });
    await waitFor('MySQL', () => docker(['exec', db, 'mysqladmin', 'ping', '-h', '127.0.0.1', '-uroot', `--password=${secrets.TEST_DB_PASS}`, '--silent'], { allowFail: true }).ok, 90);
    await sleep(2000);
    for (let i = 0; ; i++) { try { docker(['run', '--rm', '--network', net, ...vol, '--env', 'TEST_DB_PASS', IMAGE, 'php', 'tests/isolated/seed.php'], { env }); break; } catch (e) { if (i > 8) throw e; await sleep(2000); } }
    docker(['run', '--detach', '--name', app, '--network', net, '--network-alias', 'app', '--read-only', '--tmpfs', '/tmp', ...vol,
      '--env', 'DB_HOST=db', '--env', 'DB_NAME=fixture', '--env', 'DB_USER=root', '--env', 'DB_PASS', '--env', 'DB_CHARSET=utf8mb4', '--env', 'JWT_SECRET', '--env', 'JWT_EXPIRATION=3600', '--env', 'PHP_CLI_SERVER_WORKERS=4',
      IMAGE, 'php', '-S', '0.0.0.0:8080', 'tests/isolated/router.php'], { env });
    await sleep(2000);
    run('Pruebas HTTP aisladas del repositorio (tests/isolated/http_test.php)', ['run', '--rm', '--network', net, ...vol, IMAGE, 'php', 'tests/isolated/http_test.php']);
    // UX-06B.0: tras las pruebas base se carga el fixture de volumen (0, 1, 20, 21, 45 y 105 relaciones) y se prueba la paginación.
    if (existsSync(join(REPO, 'tests', 'isolated', 'http_pagination_test.php'))) {
      console.log(docker(['run', '--rm', '--network', net, ...vol, '--env', 'TEST_DB_PASS', IMAGE, 'php', 'tests/isolated/seed_pagination.php'], { env }).out);
      run('Pruebas HTTP de paginación, vistas y compatibilidad (tests/isolated/http_pagination_test.php)', ['run', '--rm', '--network', net, ...vol, IMAGE, 'php', 'tests/isolated/http_pagination_test.php']);
    } else {
      console.log('\n(Esta rama no incluye la suite de paginación del PR #4; se omite.)');
    }
    // SEC-05A (registro, roles, errores) y UX-07A (administración de accesos): mismos contenedores; requieren las credenciales de la base.
    for (const [file, title] of [['http_auth_hardening_test.php', 'SEC-05A: registro, roles y errores internos'], ['http_admin_access_test.php', 'UX-07A: administración de accesos']]) {
      if (existsSync(join(REPO, 'tests', 'isolated', file))) run(`Pruebas HTTP ${title} (tests/isolated/${file})`, ['run', '--rm', '--network', net, ...vol, '--env', 'TEST_DB_PASS', IMAGE, 'php', `tests/isolated/${file}`], { env: { ...process.env, ...env } });
    }
  } finally { clean(); }
  console.log('\nBackend CI local: OK');
}

const cmd = process.argv[2];
try {
  if (cmd === 'up') await up();
  else if (cmd === 'down') { down(); console.log('Entorno QA eliminado.'); }
  else if (cmd === 'status') status();
  else if (cmd === 'ci') await ci();
  else { console.log('Uso: node qa/backend.mjs up | down | status | ci'); process.exit(2); }
} catch (e) { console.error(e.message); process.exit(1); }
