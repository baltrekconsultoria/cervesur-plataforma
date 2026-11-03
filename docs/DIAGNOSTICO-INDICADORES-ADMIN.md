# Diagnóstico del panel administrativo: origen de los indicadores y de la leyenda (UX-07E.C, bloque B)

## 1. Resultado

* Los **siete indicadores** del «Inicio administrativo» se calculan en el navegador a partir de los listados que el servidor entrega en esa consulta (usuarios, empresas, informes, relaciones empresa–informe y accesos usuario–informe). **No hay datos sintéticos en la ruta HTTP**: los datos sintéticos solo existen en el proveedor de demostración (`MockApi`), que no se compila en las versiones de servidor.
* La leyenda **«Calculado con los datos sintéticos actuales» era un texto heredado** del prototipo (UX-04B) escrito a mano en la pantalla, que no dependía del origen de los datos. Era **incorrecta** en el piloto con datos reales: no indicaba un problema del origen, sino un texto desactualizado.
* Corrección (UX-07E.C): la leyenda ahora depende del proveedor. Con el servicio real dice «Calculado con los datos reales que el servicio entregó en esta consulta (solo lectura)»; con el proveedor de demostración, «datos de demostración (sintéticos)». También se reemplazó la nota «Estructura inicial de solo lectura… se portarán en la siguiente etapa» por «Versión de solo consulta: las altas, ediciones y asignaciones todavía no están habilitadas…».
* No se modificó ningún registro de producción; la comprobación se hizo sobre el código y con datos sintéticos.

## 2. Origen real de cada indicador

| Indicador | Fórmula (`AdminPages.tsx`) | Ruta del backend | Tabla / condición |
|---|---|---|---|
| Usuarios activos (de N) | `users.filter(active)` de `users.length` | `GET /api/users?status=all` | `user.is_active` (todos los usuarios, activos e inactivos) |
| Empresas activas (de N) | `companies.filter(active)` | `GET /api/empresas` | `empresa.activo`; **todas** las empresas |
| Reportes activos (de N) | `reports.filter(active)` | `GET /api/reportes` | `reporte.activo`; **todos** los informes |
| Accesos vigentes | asignaciones con efecto (`assignmentStatus.ok`) | `GET /api/empresa-reporte-usuario` + `GET /api/asignacion` | `user_empresa_reporte.activo = 1` y su relación `empresa_reporte` activa; con efecto = usuario activo, informe activo y empresa activa |
| Informes activos sin usuarios con acceso | informes activos sin ninguna asignación **con efecto** | las mismas | |
| Asignaciones sin efecto | asignaciones vigentes − con efecto | las mismas | usuario inactivo, informe inactivo o empresa inactiva |
| Usuarios inactivos con asignaciones | usuarios inactivos con alguna asignación vigente | `users` + `empresa-reporte-usuario` | |

## 3. Cosas que conviene saber al interpretarlos (no son errores, son definiciones)

1. El listado de accesos solo trae filas **vigentes** (`activo = 1`); un acceso retirado no aparece, por lo tanto no se cuenta como «sin efecto».
2. «Sin efecto» significa que la asignación existe pero el usuario, el informe o la empresa están inactivos; por eso la suma de «vigentes» y «sin efecto» es el total de filas vigentes del servidor.
3. Las tablas `empresa_usuario` y `user_empresa_reporte` no tienen `id` propio en los permisos de columna; los listados usan un identificador compuesto solo para mostrar. No afecta a los totales.
4. Los totales se leen de la paginación del servidor y se exige que las filas recibidas coincidan con el total declarado: si los datos cambian a mitad de lectura, la pantalla falla en lugar de mostrar una cifra parcial.
5. **Cómo comprobarlos en producción sin escribir nada** (administrador de MySQL, solo lectura): `SELECT COUNT(*) FROM user WHERE is_active=1`, `... empresa WHERE activo=1`, `... reporte WHERE activo=1`, y para accesos `SELECT COUNT(*) FROM user_empresa_reporte uer JOIN empresa_reporte er ON er.id=uer.empresa_reporte_id WHERE uer.activo=1`. Deben coincidir con las tarjetas (salvo el «con efecto», que además exige usuario, informe y empresa activos).

## 4. Pruebas

El backend administrativo prueba que cada total del servidor es igual a SQL (`tests/isolated/http_admin_piloto_test.php`) y las pruebas del frontend verifican las pantallas. La leyenda y la nota se comprobaron en `AdminPages.tsx`.
