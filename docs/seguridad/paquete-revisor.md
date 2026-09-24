# Paquete para el revisor de seguridad externo

**Requisito:** RS-11 — «Revisión de seguridad externa antes de la primera versión comercial y con periodicidad anual».
**Nivel objetivo:** OWASP ASVS 2 (estándar), con controles de nivel 3 en el registro de auditoría (doc 02 §7.6).
**Periodicidad:** antes de la primera versión comercial y, después, cada 12 meses y en cada versión mayor (doc 02 §11.6.5).

Este directorio (`docs/seguridad/`) **no viaja en el paquete que se entrega al cliente**: `infra/scripts/package.sh`
copia solo `docs/cliente` y `docs/runbooks`, y `backend/tests/Architecture/SecurityReviewEvidenceTest.php` lo ata.
Contiene detalle de hallazgos y no se publica.

## Calendario

| Hito | Fecha | Estado |
|---|---|---|
| Revisión interna previa (esta preparación) | 2026-09-22 | Hecha: [`revision-interna-asvs-2026-09.md`](revision-interna-asvs-2026-09.md) |
| Revisión externa inicial | Antes de la primera versión comercial | **Pendiente del tercero** |
| Siguiente revisión anual | 12 meses después de la externa inicial; **límite: 2027-09-22** | Programada |

La fecha límite de la siguiente revisión la vigila `SecurityReviewEvidenceTest`: cuando pase sin un informe más reciente,
la prueba falla y `qa:traceability --check` deja RS-11 sin evidencia. No hay que acordarse: el reloj corre en la CI.

## Qué se entrega al revisor

Todo son ficheros del repositorio, sin secretos ni datos personales reales (las semillas de desarrollo usan nombres
ficticios; los `.env` no se entregan).

| Artefacto | Dónde | Qué aporta |
|---|---|---|
| Arquitectura | [`docs/02-stack-tecnologico-y-plan-implementacion.md`](../02-stack-tecnologico-y-plan-implementacion.md) §1 y §2 | Monolito modular hexagonal, fronteras verificadas por Deptrac y Pest Arch |
| Diseño de seguridad | [`docs/02-stack-tecnologico-y-plan-implementacion.md`](../02-stack-tecnologico-y-plan-implementacion.md) §7 | Limitación de tasa por capas, ámbitos de token, auditoría encadenada, ASVS, secretos |
| Modelo de amenazas | [`docs/01-especificaciones-proyecto.md`](../01-especificaciones-proyecto.md) §8.1 | STRIDE, quince vectores con mitigación y técnica ATT&CK (catorce en el commit del informe interno; la del canal SMTP entró en el cierre de la Fase 3) |
| Madurez y riesgos aceptados | [`docs/07-seguridad-madurez-y-amenazas.md`](../07-seguridad-madurez-y-amenazas.md) | SAMM 2.0 con evidencia, mapa SDL, riesgos aceptados con dueño y fecha (§6) |
| Contrato de la API | [`docs/api/openapi.yaml`](../api/openapi.yaml) | Fuente de verdad de las 79 rutas y 93 operaciones, ámbitos y respuestas de error |
| Matriz de trazabilidad de pruebas | [`docs/trazabilidad-pruebas.md`](../trazabilidad-pruebas.md) | Requisito → pruebas, generada por `php artisan qa:traceability` |
| Decisiones arquitectónicas | [`docs/adr/`](../adr/) | ADR-001 a ADR-041; las de seguridad: 005, 009, 010, 014, 015, 016, 019, 020, 037, 038, 039, 041 (enlace de descarga de un solo uso) |
| Revisión interna previa | [`revision-interna-asvs-2026-09.md`](revision-interna-asvs-2026-09.md) | Hallazgos por ASVS y STRIDE, dictamen de los riesgos aceptados, qué mirar con más atención (§7) |
| Evidencia automática | [`evidencia/`](evidencia/) | Salida resumida de `composer audit`, `npm audit`, Semgrep propio y comunitario, Trivy y gitleaks sobre el commit revisado |
| Runbooks de seguridad | [`docs/runbooks/brecha-de-seguridad.md`](../runbooks/brecha-de-seguridad.md), [`ataque-a-credenciales.md`](../runbooks/ataque-a-credenciales.md), [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md), [`triaje-hallazgos-seguridad.md`](../runbooks/triaje-hallazgos-seguridad.md) | Procedimientos de 72 h, respuesta a credenciales, integridad, triaje de hallazgos |

## Cómo reproducir el entorno

`make up` levanta la pila completa de desarrollo (doc 02 §10.2); `make quality && make test` ejecuta calidad y suites;
`make sast sast-community trivy-fs secrets-scan deps-audit-php deps-audit-js` reproduce la evidencia automática.
La instalación de entrega se prueba con `infra/scripts/package.sh` y `install.sh` sobre una máquina limpia
(`docs/cliente/instalacion.md`).

## Qué NO cubre esta preparación

- **DAST**: no hay escáner dinámico; la revisión externa es el primer análisis dinámico del producto (doc 07 §6, fila «Sin DAST»).
- **Prueba de intrusión** sobre una instalación real ni verificación de la cadena TLS de un cliente.
- **Validación jurídica** (art. 34.9 ET, RGPD, EIPD): es de la asesoría laboral y del DPO del cliente.
- Fuera de alcance por decisión: biometría (ADR-009), credencial en móvil y TOTP de empleado (ADR-014), correo como
  dependencia (regla dura 12), acceso permanente del fabricante a datos del cliente (ADR-020).

## Cómo se reciben y cierran los hallazgos externos

Cada hallazgo se registra en el informe vigente con el formato de `revision-interna-asvs-*.md` (severidad
`BLOQUEANTE | REVISAR | OBSERVACIÓN`, ubicación, problema, consecuencia, corrección, requisito, agente responsable, prueba de
no regresión). Un hallazgo se cierra solo con una prueba que falla antes de la corrección (doc 02 §9.5) y la verificación
de `seguridad-cumplimiento`. Los hallazgos abiertos con severidad `BLOQUEANTE` impiden publicar la versión.
