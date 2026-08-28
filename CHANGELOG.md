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

- un PIN erroneo ya no muestra un exito antes del rechazo (kiosk)
- el filtro pending de /credentials/status acepta true/false (identity)

### Interno

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

