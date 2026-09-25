# Registro de cambios

Todas las novedades relevantes de KronoQR. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el producto se
versiona con [SemVer](https://semver.org/lang/es/) (doc 02 §10.5).

**Este fichero se genera**, no se edita a mano:

    make changelog          # regenera la seccion [Unreleased]

La fuente son los mensajes de commit con formato convencional. Un commit que no
lo siga no aparece aqui, y el generador lo avisa por la salida de error.
Ninguna version se publica sin su entrada: `make changelog-check VERSION=1.2.3`
falla si no la encuentra, y la CI ejecuta esa comprobacion al etiquetar.

## [2.1.0] - 2026-09-24

### Cambios incompatibles

- 2FA obligatorio y ambito por departamento en la autenticacion de gestion (tarea 2.1) (identity)

### Anadido

- cuadro de impacto y adopcion con doce indicadores, comparacion entre periodos, pantalla y exportacion sellada (RF-IN-08, RNF-D-01) (3.13)
- resumen semanal por correo a los responsables y ventana configurable de actualizacion del quiosco (RF-PR-05, RF-KI-07) (3.12)
- deteccion de patrones anomalos de uso de credencial con incidencia en la bandeja (RF-PR-06, RN-16) (3.11)
- informes en diferido con enlace de descarga de un solo uso y exportacion configurable para nomina (RF-IN-06/07) (3.9)
- registro de ausencias (RF-GP-04) con efecto en el informe por periodo y consumo del calendario de festivos (3.10)
- tarea 3.8 revision interna ASVS 2, paquete del revisor y correccion de los hallazgos con prueba de no regresion (seguridad)
- tarea 3.7 E2E con camara simulada y suite de accesibilidad: RQ-04 donde esta la camara, bloqueo del PIN sin oraculo, desgaste repartido medido y canal sonoro afirmado (pruebas)
- RN-18 fichaje irreconciliable: el elemento imposible se registra, responde 422 y abre incidencia en vez de reintentarse para siempre (fichaje)
- perfil del runner de 3 instancias en load-test.yml (18/s: RQ-03 y RS-03 medibles) (carga)
- linea base del runner y veredicto de RNF-P-02/06 por regresion en load-test.yml (decision 19) (carga)
- entrada fpm_max_children en load-test.yml para medir el runner con el perfil recomendado (carga)
- prueba de carga k6 con veredictos por requisito, workflow propio y reconciliacion sin abrazo mortal (carga)
- fichaje de pausa y aviso de desfase de reloj en el quiosco (fichaje)
- vista de cumplimiento con descansos, jornada maxima y exceso semanal (cumplimiento)
- panel de salud y pantalla de diagnostico con codigo de servicio (quioscos)
- menú lateral fijo en vez de cabecera superior (panel)
- cinco cuadros de Grafana, catálogo de alertas con runbooks y Alertmanager por destinatario (tarea 3.2) (observabilidad)
- OpenTelemetry extremo a extremo, /metrics, Tempo, Loki y sondas (tarea 3.1) (observabilidad)
- guia de RRHH, guia del portal, hoja del empleado generada por el producto y correcciones desde el panel (5.11b) (docs)
- historico de errores agrupado por huella, captacion de los siete origenes, panel y alerta (5.12) (product)
- documentacion de cliente completa, guia de endurecimiento, ingles, capturas y kiosk:health (5.11) (docs)
- exportacion integra de datos y telemetria opcional desactivada por defecto (5.10) (product)
- paquete de diagnostico anonimizado, product:doctor y accesos de soporte auditados (5.9) (product)
- doctor.sh, pantalla Soporte del panel y guias de cliente (5.9) (product)
- contrato de diagnostico y accesos de soporte (5.9) (product)
- marca blanca en las tres aplicaciones y en los PDF (5.8) (product)
- actualizador con copia previa, cadena de versiones y vuelta atras (tarea 5.7) (product)
- emparejamiento de quiosco por código (tarea 5.6, RF-PD-06) (kiosk)
- tarea 5.5 — asistente de puesta en marcha e importacion masiva de plantilla (product)
- tarea 5.4 - instalador del servidor del cliente, compose de produccion y etapa de instalacion limpia (infra)
- tarea 5.3 - licencia firmada, verificacion local y degradacion honesta (product)
- tarea 5.2 - perfil de cumplimiento gestionable y umbrales parametrizados (compliance)
- tarea 5.1 - configuracion de instalacion con catalogo, cascada y auditoria (product)
- negocia el idioma de la respuesta con Accept-Language (api)
- rotacion de la clave de firma con solape y reimpresion progresiva (tarea 2.12) (identity)
- exportaciones CSV, XLSX y PDF del informe por periodo en streaming (tarea 2.9) (reporting)
- retencion con confirmacion, purga documentada y DROP PARTITION sellado (tarea 2.10) (compliance)
- RN-12 queda suspendida hasta la pausa declarada de la 3.5 (attendance)
- la reconciliacion de proyeccion deja asiento projection.reconciled registrado por el proveedor (tarea 2.7) (compliance)
- informes por periodo con contratos historizados y comparativa trabajadas frente a contratadas (tarea 2.8) (reporting)
- reconciliacion nocturna de daily_totals con alerta de divergencia (tarea 2.7) (attendance)
- bandeja de incidencias con resolucion trazada y marca en el detalle de jornada (tarea 2.5) (admin)
- bandeja de incidencias y resolucion con nota por la API (tarea 2.5, backend) (compliance)
- deteccion automatica de incidencias con asignacion, aviso y metrica (tarea 2.6) (compliance)
- presencia en vivo, difusion por Reverb y metricas de turnos abiertos (tarea 2.4) (reporting)
- presencia en vivo con Reverb y respaldo por sondeo (tarea 2.4) (admin)
- pantalla del segundo factor TOTP en el acceso, con alta por QR y E2E (tarea 2.1) (admin)

### Corregido

- sello diario de la capa de paquetes Alpine (era semanal) y HANDOFF hacia la PR #82 (ci)
- apk_index_stamp entra por env del paso y se expande en el shell, no interpolado en run (Semgrep run-shell-injection) (ci)
- CI verde tras el cierre: entrada apk_index_stamp para refrescar la capa Alpine (libexpat CVE-2026-93990) y enlace del runbook de vigilancia que no viajaba en el paquete (cierre-fase-3)
- la E2E «no reintenta en bucle» del portal y del panel espera la peticion del reintento con expect.poll (pruebas)
- NodeWorkspaceComposeTest sin `->not` encadenado (PHPStan nivel 9) (pruebas)
- en modo linea base RNF-P-06 no exige 50/s ofrecidos (carga)
- el aprovisionamiento completa la puesta en marcha mínima en una instalación limpia, y guarda de Domain/Event (carga)
- la pila del runner se declara staging tras instalar para que el aprovisionamiento de k6 corra (carga)
- el renderizador de Alertmanager es POSIX puro y sh-lint distingue los scripts #!/bin/sh (observabilidad)
- la lectura de audit_log tolera acciones desconocidas y la vuelta atras solo escribe su asiento si la version restaurada lo entiende (auditoria)
- el asiento system.restored_from_backup lo escribe la imagen nueva contra la base restaurada (la anterior puede no tener el comando) (update)
- la presencia de product:doctor se comprueba sin tuberia bajo pipefail (scripts)
- indentacion del comentario dentro del bloque run de la etapa 8b (ci)
- la etapa 8b congela la frontera de versiones con la ultima migracion del arbol ANTERIOR (ci)
- el runbook incidencia-sin-acceso no enlaza al ADR, que no viaja en el paquete (docs)
- IssuedSupportGrant validable y read_only actua como admin con ambitos de lectura (contract)
- update.sh localiza la instalacion por el contenedor app, no por todos (product)
- 401 sin ruta de login para quien no pide JSON; el actualizador exige la ruta de gestion antes de tocar nada (identity)
- update.sh localiza la instalacion con .Label, no con index .Labels (product)
- libuuid entra en el apk upgrade acotado de la imagen de PostgreSQL (infra)
- fast-uri 3.1.7 salda los cuatro avisos altos publicados contra 3.1.5 (deps)
- los catalogos de producto vuelven a su linea base en cada vaciado (tests)
- el chmod del entrypoint de nginx cubre tambien los .envsh (docker)
- Trivy de imagenes por una sola via de make y DS-0031 corregido moviendo las rutas TLS al entrypoint (infra)
- las SPA declaran su index, el borde exige sus variables y gana un humo de 30 segundos (infra)
- la entrega fija APP_VERSION desde VERSION y el instalador reconoce sus propios puertos (infra)
- la fase 1 comprueba que el nginx sin privilegios podra leer el certificado, y la fase 4 lo espera (infra)
- la vuelta atras del instalador solo actua en el proceso principal y los generadores no cortan tuberias (infra)
- el --build-arg de la rama buildx quedo tras el «|| exit» y anulaba el bucle sin cache (make)
- sello semanal APK_INDEX_STAMP para que la cache de Actions no congele CVE ya parcheados (docker)
- allowlist de gitleaks para los tres ejemplos de licencia de la 5.3 (ci)
- acepta include_open_shifts=true tal y como lo serializa el contrato (reporting)
- el escalado de integridad dispara y el runbook de divergencia usa un rol que existe (cierre F2) (observability)
- los stubs viven en tools/PHPStan, la grafia que ya existia (phpstan)
- AuditLogRow, la clase que DatabaseAuditChainReader ya referenciaba (compliance)
- la deteccion no reabre incidencias resueltas ni sella avisos sin entregar (revision 2.6) (compliance)
- el arranque no depende de las credenciales de Reverb (regla dura 19) (reporting)
- tsc acepta la extension .ts en la importacion de vitest.config (deps)
- el simulacro de copia arranca el cluster como fichaje_migrator, no como fichaje_app (ci)
- el simulacro de copia espera a PostgreSQL por TCP y vuelca los logs si no arranca (ci)
- PostgreSQL arranca como postgres sin gosu y Trivy pasa a bloqueante (security)

### Seguridad

- la tarjeta viva con clave fuera del llavero deja de ser invisible (revision 2.12) (identity)
- correcciones de la revision de seguridad de la tarea 2.1 (identity)

### Interno

- CI manual 36018614725 en verde; la PR #82 de las decisiones post cierre queda lista para integrar (handoff)
- soporte read_only sin presencia en vivo ni cumplimiento, mutacion acotada por push y regla de idioma con herramienta (decisiones)
- condiciones previas a la primera venta tras el cierre de la Fase 3 (decisiones)
- CI manual 35990497391 en verde; la PR #81 del cierre de la Fase 3 queda lista para integrar (handoff)
- cierre de la Fase 3 con las cuatro revisiones, current_phase 3 y las correcciones del cierre (cierre-fase-3)
- CI manual 35940352359 en verde; la PR #80 de la 3.13 queda lista para integrar (handoff)
- CI manual 35887267293 en verde; la PR #79 de la 3.12 queda lista para integrar (handoff)
- CI manual 35853369572 en verde; la PR #78 de la 3.11 queda lista para integrar (handoff)
- CI manual 35831869626 en verde; la PR #77 de la 3.9 queda lista para integrar (handoff)
- CI manual 35776674369 en verde; la PR #76 queda lista para integrar (handoff)
- contenedores node-* desde el workspace de npm, linea base del runner regenerada y fila 12 del modelo de amenazas (restos-3.8)
- siguiente accion: rama chore/restos-3.8 (contenedores node-*, linea base del runner, fila del reloj del quiosco) y despues la tarea grande (handoff)
- decisiones del usuario tras la 3.8: pantalla de cuentas, linea base del runner y fila del reloj del quiosco (handoff)
- Bump docker/setup-buildx-action (deps)
- la PR #69 ya esta integrada; fusion con main por la #71 y trampa del lock de Dependabot (handoff)
- Bump the npm-menores-y-parches group with 5 updates (deps)
- Bump larastan/larastan (deps-dev)
- la E2E de RN-18 encola el imposible antes de reconectar (quiosco)
- pasada de validacion del modo linea base (handoff)
- restos de la 3.6 con la linea base del runner y la siguiente accion (handoff)
- linea base del runner con el perfil de 3 instancias (carga)
- CI manual de la 3.6 en verde (handoff)
- tarea 3.6 confirmada, PR abierta y primera ejecución de load-test tras integrar (handoff)
- alinear el doc 05 con el fichaje de pausa y la vista de cumplimiento (presentacion)
- tarea 3.5 confirmada, CI manual en verde y PR #66 abierta (handoff)
- tarea 3.4 confirmada, CI manual en verde y PR #65 abierta (handoff)
- recargar antes de esperar los dos 401 en el E2E de revocacion (quiosco)
- aplazar Vitest 5 en Dependabot y agrupar sus mayores (ci)
- Bump the npm-menores-y-parches group across 1 directory with 5 updates (deps)
- Bump the composer-menores-y-parches group across 1 directory with 4 updates (deps)
- hotfix de los relojes integrado en main y rebase pedido a Dependabot (handoff)
- congelar los dos relojes en las suites con framework (backend)
- cámara del quiosco documentada y menú lateral del panel integrados en main (handoff)
- efectos de cámara del sistema y webcam de portátil (quiosco)
- tarea 3.2 integrada en main; siguiente, la 3.3 (handoff)
- tarea 3.1 integrada en main; siguiente, la 3.2 (handoff)
- la lista de servicios del perfil observability incluye tempo y blackbox-exporter (instalacion)
- CI de main tras el merge del cierre de la Fase 5 en verde (handoff)
- CI del cierre en verde al octavo intento; lo que destapo cada uno (handoff)
- el E2E del asistente busca el departamento dentro de su lista (la pista 'Cocina, recepción…' coincidia en modo estricto) (panel)
- QualityGatesTest exige los asientos system.* en U1/U3 en vez de conteos identicos de audit_log (arch)
- puppeteer y Chrome tambien en el job de cobertura (la suite completa incluye los PDF con motor real) (cobertura)
- primera ejecucion real de las etapas 4 y 7 y de la 8b con los asientos system.* (cierre-fase-5)
- espera explicita al secreto TOTP en la unitaria del alta del segundo factor (fragil en el runner) (panel)
- cierre de la Fase 5 con los cuatro revisores; current_phase a 5 (fase-5)
- estado de la 5.11b tras las dos vueltas de revision (handoff)
- memoria persistente Engram junto a HANDOFF.md; reparto de roles en CLAUDE.md (engram)
- CI manual en verde y MSI de la 5.12 (handoff)
- mutacion en paralelo y tope del job a 60 min; retirados los use sin efecto que rompian los procesos hijos (mutation)
- tipos regenerados tras el texto de resolved_by en el contrato; trampa de concurrencia de la CI en HANDOFF (api)
- commit, CI manual y PR de la 5.12 (handoff)
- CI manual en verde y PR de la 5.11 (handoff)
- js-yaml 4.3.2 via override (GHSA-2883-xcg3-v3hh) y lock regenerado desde Linux (deps)
- tipos del quiosco y del portal regenerados tras el contrato de la 5.10 (api)
- commit, CI manual y PR de la 5.10 (handoff)
- ventana de auditoria bajo concurrencia real, UtcInstant en SetupStatus y ayuda del motivo (product)
- carrera de pipefail en la comprobacion de product:doctor (handoff)
- dos fallos de la primera CI manual de la 5.9 y su correccion (handoff)
- PR #47 y ejecucion manual de la CI anotadas (handoff)
- tarea 5.9 cerrada, changelog regenerado y ficha marcada (handoff)
- sintaxis --verify=RUTA en incidencia-sin-acceso (runbook)
- decisiones de la segunda vuelta de revision de la 5.9 (product)
- decisiones de la 5.9, runbook incidencia-sin-acceso y reparto legal del soporte (product)
- bloques reservados en register() para la 5.9 (product)
- tarea 5.8 cerrada en PR #46 con la CI manual lanzada (handoff)
- Bump the npm-menores-y-parches group with 4 updates (deps)
- Bump node in /infra/docker/nginx (deps)
- PR #43 con la etapa 8b en verde; cuatro arreglos de la primera ejecucion real anotados (handoff)
- los comodines del directorio de informes los expande root (5.7)
- PR #42 con la CI en verde; tres arreglos de CI anotados (handoff)
- por que libuuid esta en el apk upgrade acotado de PostgreSQL (infra)
- cliente del portal regenerado y pairing_secret de ejemplo en la allowlist de gitleaks (5.6)
- tarea 5.6 en origin, PR #42 abierta (handoff)
- resumen — de 287 KB a lo vigente; el diario completo queda en git (handoff)
- rama de la 5.6 creada con el hook de Pint; la tarea no esta empezada (handoff)
- Pint automatico sobre cada fichero de backend editado (harness)
- merge de origin/main resuelto y hallazgo del lock roto en main (handoff)
- cierre de la 5.4 — etapa 8 en verde en el run 33573780721 (handoff)
- Bump @types/node from 24.13.3 to 25.9.5 (deps-dev)
- Bump @tanstack/vue-query in the npm-menores-y-parches group (deps)
- Bump hotmeteor/spectator (deps-dev)
- Bump node from 24-alpine to 25-alpine in /infra/docker/node (deps)
- PR #35 abierta con la CI en verde (handoff)
- cierre de fase — current_phase a 2, matriz y changelog regenerados (fase-2)
- runbook de brecha 72h y hallazgos de la revision de cierre de fase 2 (seguridad)
- exposicion textfile compartida y hallazgos de la revision de cierre (shared)
- cierra los huecos de cobertura del cierre de fase 2 (trazabilidad)
- retirados los .gitkeep de directorios que ya tienen ficheros (cierre F2)
- fase 2 integrada con CI en verde en 94cafe5; siguiente accion, el cierre de fase (handoff)
- stubs de Sanctum y Carbon para que el analisis local iguale a la CI (phpstan)
- clientes TypeScript regenerados tras la descripcion nueva de IncidentOutcome (api)
- tarea 2.4 completa en la rama; la CI de a1549d0 cayo en setup-php y se relanza (handoff)
- gitleaks perdona el secreto señuelo del rechazo TOTP por valor exacto (security)
- tarea 2.1 completa en la PR #30, revision de seguridad aplicada (handoff)
- [Unreleased] y trazabilidad recogen la tarea 2.1 (changelog)
- PR #28 (toolchain) y #29 (E2E del panel) abiertas (handoff)
- [Unreleased] recoge los E2E del panel (changelog)
- matriz regenerada con los E2E del panel (trazabilidad)
- E2E con Playwright y axe del panel de gestion (admin)
- [Unreleased] recoge la toolchain nueva y el cierre de la Fase 1 (changelog)
- spatie/laravel-permission 6 a 8 (deps)
- toolchain del frontend a Vite 8, Vitest 4, ESLint 10, Pinia 4, vue-router 5 y vue-i18n 11 (deps)
- cierre de la Fase 1 con reservas y p95 como medicion informativa (fase-1)
- triaje de las 16 PR de Dependabot y PR #26 mergeada (handoff)
- Bump actions/upload-artifact from 4.6.2 to 7.0.1 (deps)
- PR #26 abierta, CI y simulacro de copia en verde (handoff)
- Bump the npm-menores-y-parches group with 2 updates (deps)
- Bump the composer-menores-y-parches group (deps)
- Bump nginxinc/nginx-unprivileged (deps)

## [2.0.0] - 2026-08-29

### Cambios incompatibles

- un centro de trabajo por instalacion y por licencia (ADR-040) (workforce)

### Anadido

- la autenticacion deja rastro consultable (OWASP A09) (compliance)

### Corregido

- Pest y Semgrep comunitario en verde en el runner, y Semgrep pasa a bloqueante (ci)

### Interno

- CI run 25 con dos jobs en rojo pendientes de leer (handoff)
- [Unreleased] recoge el centro unico por instalacion (changelog)
- [Unreleased] recoge RS-13 (changelog)
- RS-13, la autenticacion deja rastro consultable, entra en doc 01 (requisitos)
- [Unreleased] arranca en v1.2.0 (changelog)
- sesion SSDLC — pipeline, rastro de autenticacion, ATT&CK y SAMM (handoff)
- columna ATT&CK en el modelo de amenazas y autoevaluacion SAMM (seguridad)
- SAST comunitario, Trivy, gitleaks, Dependabot y SBOM en el pipeline (security)

## [1.2.0] - 2026-08-28

### Anadido

- la ficha del empleado muestra el estado de su tarjeta y sus acciones (admin)
- el tablero de credenciales se acota a una persona con employee_uuid (identity)
- los selectores filtran toda la plantilla y se pagina de 30 en 30 (admin)
- registro mas legible y acceso sin textos de ayuda (portal)
- filtro pin_status en GET /employees resuelto en servidor (workforce)
- el QR ocupa media tarjeta y el codigo va en negrita bajo el nombre (identity)
- visor centrado para encuadrar el QR, sin velo sobre la camara (kiosk)
- el registro ocupa el 88 % del ancho desde 1024 px, con tema (portal)
- buscadores y paginacion en plantilla y credenciales, y tema (admin)
- acceso por PIN bajo las instrucciones, aviso RGPD compacto y tema (kiosk)
- busqueda q por nombre, apellidos y codigo en GET /employees (workforce)
- sistema visual compartido con contraste medido por prueba (web-kit)
- agente ui-ux para el sistema visual de las tres SPA (agents)

### Corregido

- la ficha elige la fila por employee_uuid y no permite un doble envio (admin)
- la lectura acotada a una persona no deja asiento y baja al SQL (identity)
- un PIN erroneo ya no muestra un exito antes del rechazo (kiosk)
- el filtro pending de /credentials/status acepta true/false (identity)

### Interno

- ficha con estado de tarjeta y decision sobre la zona horaria (handoff)
- una sola entrada 1.2.0 (changelog)
- sesion 1.2.0 — quiosco, tarjeta, portal y panel (handoff)
- la entrada 1.2.0 no repite lo publicado en 1.1.0 (changelog)
- version 1.2.0 (release)
- el envio de la tarjeta por correo queda descartado (handoff)
- sesion de UI/UX, buscadores y sistema visual compartido (handoff)
- version 1.1.0 empujada; la PR y la etiqueta quedan para main (handoff)

## [1.1.0] - 2026-08-27

### Anadido

- el panel y el portal capturan por fin sus errores (regla dura 21) (web-kit)

### Corregido

- el quiosco envia por fin su llave de dispositivo (kiosk)
- las tres SPA se prueban desde el host, con proxy /api al Nginx del entorno (dev)
- la etapa de unitarias levanta el PostgreSQL del producto y el workspace se audita entero (ci)

### Seguridad

- neutraliza la inyeccion de formulas CSV en los dos escritores (export)

### Interno

- retira suma.py, un fichero suelto ajeno al producto
- prueba de carga k6 del fichaje, con aprovisionamiento y agregado multi-origen (perf)
- la duracion de la suite unitaria gana gate y umbral honesto (quality)
- el umbral de RN-07 vuelve a tener una sola fuente (attendance)

## [1.0.0] - 2026-08-27

### Anadido

- cierre de la Fase 1 - MVP de fichaje instalable y legalmente defendible
- el anti-rebote es un desenlace aceptado, no un rechazo (ADR-031) (contrato)
- cumplir RS-10 con analisis de dependencias y SAST en la CI (0.7)
- docs:consistency y etapa 3b de la CI (RQ-12, RNF-M-04) (0.7)
- catalogo de requisitos y comando qa:traceability (RQ-13) (0.7)
- contrato OpenAPI 3.1 con /health, /ready y /scan, y Spectator (0.6)
- adoptar Laravel 13 antes de escribir el dominio (ADR-030) (deps)

### Corregido

- los objetivos de trazabilidad no funcionaban en la CI (0.7)
- resolver la raiz del repositorio por marca, no contando niveles (0.7)
- repartir a su fase los 21 requisitos que el Anexo A no asignaba (docs)

### Interno

- escribir ADR-001 a ADR-020 y ADR-029, y revisar los ocho existentes (0.6)
- esqueleto de los tres frontends con TS estricto, Tailwind y Vitest (0.5)
- pipeline de CI con las etapas 1-3 y puerta de version (0.4)
- conservar las suites Contract e Integration vacias (0.2)
- corregir la ubicacion del puerto Clock y documentar el bind mount
- cadena de calidad y pruebas de arquitectura de ADR-021 y ADR-025 (0.3)
- esqueleto Laravel 12 con los 8 modulos y el puerto Clock (0.2)
- entorno de desarrollo con los 14 servicios y make de arranque (0.1)

