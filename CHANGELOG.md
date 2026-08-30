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

## [Unreleased]

### Cambios incompatibles

- 2FA obligatorio y ambito por departamento en la autenticacion de gestion (tarea 2.1) (identity)

### Anadido

- presencia en tiempo real: GET /attendance/live, difusion por Reverb en canales privados por departamento y metricas open_shifts_current y websocket_connections_active (tarea 2.4) (reporting)
- pantalla del segundo factor TOTP en el acceso, con alta por QR y E2E (tarea 2.1) (admin)

### Corregido

- tsc acepta la extension .ts en la importacion de vitest.config (deps)
- el simulacro de copia arranca el cluster como fichaje_migrator, no como fichaje_app (ci)
- el simulacro de copia espera a PostgreSQL por TCP y vuelca los logs si no arranca (ci)
- PostgreSQL arranca como postgres sin gosu y Trivy pasa a bloqueante (security)

### Seguridad

- correcciones de la revision de seguridad de la tarea 2.1 (identity)

### Interno

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

