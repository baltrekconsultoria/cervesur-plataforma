# Guía de QA · publicación por IP interna (UX-07E.D)

Para el operador. Se ejecuta **después** de `publicar-ip.sh` (`PUBLICACION-IP-INTERNA.md` §5). Ningún caso es **PASS** sin que una persona lo vea; anote resultado, fecha, hora, **red desde la que probó** y una captura **sin** contraseñas ni enlaces visibles.

**Equipos de prueba (mínimo dos):**
| Equipo | Red | Preparación |
|---|---|---|
| **E1 · Wi-Fi corporativa** | Wi-Fi/LAN de la empresa (la que usan los trabajadores) | CA de Cervesur instalada (`certutil -addstore -f Root ca.crt`, huella verificada), **sin** túnel SSH |
| **E2 · VPN** | VPN del operador | Igual; conectado por VPN |
| **E3 · red NO autorizada** (opcional pero recomendado) | Datos móviles (hotspot del teléfono) u otra red fuera de las redes autorizadas | Sin VPN |

URLs: trabajadores `https://192.168.200.12` · administrador `https://192.168.200.12:8443` · Flutter `http://192.168.200.12`. Cuentas: una de **trabajador** autorizada en la lista blanca y la del **administrador** autorizado (sus propias contraseñas de Flutter; nadie más las necesita).

| ID | Prueba | Equipo | Pasos | Resultado esperado | PASS / FAIL |
|---|---|---|---|---|---|
| **P1** | Conexión al 443 | E1 y E2 | Abrir `https://192.168.200.12` | Carga la pantalla de acceso; **candado sin advertencias** (clic en el candado ▸ el certificado dice «Cervesur CA interna» y la IP). | |
| **P2** | Conexión al 8443 | E1 y E2 | Abrir `https://192.168.200.12:8443` | Pantalla de acceso del administrador, candado sin advertencias. | |
| **P3** | Certificado confiable | E1 | Detalles del certificado | Emisor «Cervesur CA interna (IP privada)»; SAN = `192.168.200.12`; vigente; sin pedir «continuar de todas formas». **Si aparece una advertencia, NO continuar: anotar y avisar.** | |
| **P4** | Login de trabajador | E1 | 443: usuario y contraseña propios | Entra a «Mis informes» con el aviso «PILOTO · Usuarios autorizados · Solo consulta». | |
| **P5** | Informes asignados | E1 | Revisar «Mis informes» y «Mis empresas» | Solo los informes/empresas asignados a esa persona (contrastar con Flutter). Un informe ajeno pegado por URL → «Acceso denegado». | |
| **P6** | Login de administrador | E1 y E2 | 8443: cuenta del administrador autorizado | Entra a «Inicio administrativo» con «PILOTO ADMINISTRATIVO · Solo consulta». | |
| **P7** | Listados administrativos | E1/E2 | Usuarios, Empresas, Reportes, Accesos y asignaciones | Cargan datos reales en solo lectura; sin botones «Nuevo/Editar/Asignar/Eliminar». | |
| **P8** | Selector «Otros informes» | E1/E2 (admin) y E1 (trabajador) | Abrir un informe ▸ botón **Otros informes (N)** | Ventana con buscador, lista agrupada por empresa, marca «Sin enlace autorizado»; Escape/«Cerrar»/clic fuera la cierran; **no** hay fila de etiquetas. El trabajador solo ve informes de sus empresas. | |
| **P9** | Separación de roles | E1 | (a) administrador en el 443; (b) trabajador en el 8443 | En ambos casos «Credenciales inválidas» idéntico al de una clave equivocada (no revela quién es administrador). | |
| **P10** | Flutter sin regresiones | E1 | `http://192.168.200.12` (como siempre) | Flutter funciona exactamente igual (login y uso habitual). | |
| **P11** | Red no autorizada | E3 | Abrir el 443 y el 8443 desde datos móviles | **No carga** (tiempo agotado si el cortafuegos/NAT no lo expone) o **403** de Apache. Nunca la pantalla de acceso. Si carga, **FALLO CRÍTICO**: avisar de inmediato. | |
| **P12** | Cierre de sesión | E1/E2 | «Cerrar sesión»; luego «Atrás» y pegar `/reportes` o `/admin` | Vuelve al acceso; ningún dato visible; revocación en el servidor (`usuarios.sh sesiones`/`auditoria` en el servidor). | |
| **P13** | HTTP plano | E1 | `http://192.168.200.12:8443` (sin la s) | Error del servidor (400) o no carga; nunca una pantalla de acceso. | |
| **P14** | Túneles de QA intactos | Operador | `ssh -L 18093:127.0.0.1:8092 …` y `18094:127.0.0.1:8093` | Siguen funcionando como antes. | |

**Criterios globales:** todo PASS en E1 y E2; P11 sin acceso desde E3; P10 sin cambios en Flutter. Cualquier advertencia de certificado, acceso desde E3, mezcla de roles o regresión de Flutter detiene el QA y se resuelve (o se revierte con `publicar-ip.sh revertir`) antes de continuar. Esta guía no autoriza escrituras productivas, nuevos enlaces de Power BI ni la apertura general; el MVP no se declara cerrado hasta la conformidad funcional.
