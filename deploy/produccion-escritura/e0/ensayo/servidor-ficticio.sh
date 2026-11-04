#!/usr/bin/env bash
# Ensayo (corre DENTRO del contenedor «servidor»): crea un «servidor» ficticio con el admin 8443, un .env con secretos SINTÉTICOS, el backend de Flutter con escrituras,
# un paquete candidato y servicios en 8093 (loopback) y 8443 (todas las interfaces). Todo inventado.
set -eu
FIX="${1:-fixture_e0}"
mkdir -p /var/www/cervesur-react-admin/public /opt/cervesur-react-admin/backend/lib /var/www/cervesur/backend/models /var/www/cervesur/backend/router /var/www/cervesur/backend/controllers /var/backups /tmp/candidato/lib
echo "admin-react 2026.11.0-fase2a-previa" >/var/www/cervesur-react-admin/VERSION
echo "<html></html>" >/var/www/cervesur-react-admin/public/index.html
echo "<?php echo 1;" >/opt/cervesur-react-admin/backend/lib/a.php
(cd /var/www/cervesur-react-admin && sha256sum VERSION public/index.html >SHA256SUMS)
cat >/opt/cervesur-react-admin/backend/.env <<EOF
CERVESUR_MODE=piloto_admin
CERVESUR_REQUIRED_MODE=piloto_admin
AUDIT_LOG_MODE=required
REACT_STRICT_VIEWS=1
ADMIN_WRITES_EMPRESAS=0
DB_HOST=127.0.0.1
DB_NAME=$FIX
DB_USER=cervesur_lectura_admin
DB_PASS=SecretoSinteticoDeEnsayo-AbC123xyz-NoImprimir
JWT_SECRET=jwt-secreto-sintetico-de-ensayo-0123456789abcdef0123456789
POWERBI_LINK=https://app.powerbi.com/view?r=eyJrIjoiU0lOVEVUSUNPIn0
EOF
chmod 640 /opt/cervesur-react-admin/backend/.env
chown root:www-data /opt/cervesur-react-admin/backend/.env
cat >/var/www/cervesur/backend/models/EmpresaModel.php <<'EOF'
<?php
$pdo->beginTransaction();
$pdo->prepare("INSERT INTO empresa (nombre) VALUES (?)");
$pdo->prepare("UPDATE empresa SET nombre = ? WHERE id = ?");
$pdo->commit();
EOF
cat >/var/www/cervesur/backend/models/ReporteModel.php <<'EOF'
<?php
$pdo->prepare("INSERT INTO `reporte` (nombre) VALUES (?)");
$pdo->prepare("UPDATE reporte SET activo = 0 WHERE id = ?");
$pdo->prepare("DELETE FROM user_empresa_reporte WHERE id = ?");
EOF
cat >/var/www/cervesur/backend/router/index.php <<'EOF'
<?php
if ($method === "POST" && $uri === "/api/empresas") { empresa_crear(); }
if ($method === "PUT" && preg_match("#^/api/reportes/(\d+)$#", $uri)) { reporte_editar(); }
if ($method === "DELETE" && preg_match("#^/api/asignacion/(\d+)$#", $uri)) { asignacion_borrar(); }
EOF
echo "admin-react 2026.11.0-fase2a-previa" >/tmp/candidato/VERSION
echo "<?php function ok() { return 1; }" >/tmp/candidato/lib/ok.php
(php -S 127.0.0.1:8093 -t /tmp >/dev/null 2>&1 &)
(php -S 0.0.0.0:8443 -t /tmp >/dev/null 2>&1 &)
sleep 1
echo "fixture-servidor-listo"
