# KronoQR — Sistema de fichaje por QR · Reglas del proyecto

**Producto licenciado** de control de presencia y **registro horario con valor legal** para hoteles. Los empleados fichan escaneando una tarjeta QR en una tablet-quiosco compartida.

Se vende a clientes distintos y **se despliega en el servidor de cada uno**. No hay SaaS ni multi-tenencia: cada cliente tiene su instalación completa.

## Documentación obligatoria

Antes de escribir código, lee lo que corresponda a tu tarea:

- [docs/01-especificaciones-proyecto.md](docs/01-especificaciones-proyecto.md) — requisitos (`RF-*`), reglas de negocio (`RN-*`), modelo de dominio, requisitos legales (`RL-*`), seguridad (`RS-*`), calidad (`RQ-*`)
- [docs/02-stack-tecnologico-y-plan-implementacion.md](docs/02-stack-tecnologico-y-plan-implementacion.md) — arquitectura, stack, 28 ADRs, seguridad, observabilidad, pruebas y plan por fases **con el agente asignado a cada tarea**
- [docs/03-agentes-y-skills-ia.md](docs/03-agentes-y-skills-ia.md) — qué agente y qué skill usar en cada situación, y los prompts de arranque de cada hito
- [docs/04-decision-credencial.md](docs/04-decision-credencial.md) — por qué la credencial es una tarjeta física
- [docs/05-presentacion-cliente.md](docs/05-presentacion-cliente.md) — **lo que se le ha prometido al cliente.** Si lo que vas a implementar contradice este documento, o el documento promete algo que no existe como requisito, para y dilo
- [docs/adr/](docs/adr/) — decisiones arquitectónicas. **Si vas a contradecir un ADR, para y pregunta.**
- [docs/api/openapi.yaml](docs/api/openapi.yaml) — contrato de la API. **Es la fuente de verdad: se modifica antes que el código.**

**Si dos documentos se contradicen**, este es el orden de autoridad, y arreglar la contradicción forma parte de la tarea:

1. `docs/adr/` — una decisión arquitectónica solo se cambia con otro ADR.
2. `docs/api/openapi.yaml` — manda sobre la forma de cualquier endpoint.
3. `docs/01` — manda sobre **qué** hace el producto (`RF-*`, `RN-*`, `RL-*`, `RS-*`).
4. `docs/02` — manda sobre **cómo** se construye y en qué orden.
5. `docs/05` — no manda, pero **obliga**: si promete algo que no existe como requisito, o el 01 dice algo distinto de lo que se le contó al cliente, hay que resolverlo antes de seguir, no después.

## Stack

PHP 8.4 · Laravel 12 · PostgreSQL 17 · Redis 7 · Reverb · Vue 3 + TypeScript estricto · Vite · Tailwind 4 · Pinia · Dexie · `@zxing/browser` · Pest · Playwright · Docker Compose

## Arquitectura

Monolito modular con arquitectura hexagonal. Módulos en `backend/app/Modules/`: `Attendance` (núcleo), `Compliance`, `Workforce`, `Identity`, `Reporting`, `Kiosk`, `Product`, `Shared`.

Cada módulo: `Domain/` → `Application/` → `Infrastructure/` + `Http/`.

**Tres aplicaciones cliente:** `frontend-kiosk/` (PWA de la tablet), `frontend-admin/` (panel de gestión) y `frontend-portal/` (portal web del empleado para consultar su propio registro).

## Convenciones de código

Las del ecosistema, no un estilo propio. Detalle completo y herramienta que verifica cada una en [docs/02](docs/02-stack-tecnologico-y-plan-implementacion.md) §3.5. Lo innegociable:

- **PHP:** PSR-12/PER con Pint preset `laravel`, `declare(strict_types=1)` en todo fichero, tipado completo, PHPStan 9. Sin lógica de negocio en controladores ni en modelos Eloquent. Sin facades en `Domain/` ni en `Application/`.
- **Vue y TypeScript:** guía de estilo oficial de Vue 3 (prioridades A y B), Composition API con `<script setup lang="ts">`, TS estricto, **sin `any`**, tipos de la API generados del contrato.
- **El código se escribe en inglés**; los textos de usuario van en `i18n`. El glosario del documento 01 §13 traduce el lenguaje ubicuo: *tramo* → `ShiftEntry`, *jornada* → `WorkDay`. Nunca identificadores en español.
- **Una convención que no verifica una herramienta es una sugerencia.** Si propones una, ata su comprobación a Pint, PHPStan, Deptrac, ESLint o `vue-tsc`.

## Pruebas: qué exige cada funcionalidad

El nivel de prueba no lo decide quien implementa. La tabla que lo decide está en [docs/02](docs/02-stack-tecnologico-y-plan-implementacion.md) §9.5, y en resumen: regla de negocio → unitaria; esquema o restricción → integración; endpoint → feature, contrato **y autorización negativa por cada rol**; recorrido de usuario → E2E; escritura del quiosco → los cinco, más idempotencia concurrente.

**Cada prueba se etiqueta con los requisitos que cubre** (`->group('RN-05', 'RF-AT-08')`). `php artisan qa:traceability --check` genera la matriz y **falla en CI si un requisito implementado no tiene prueba** (RQ-13, doc 02 §9.6).

## Reglas duras — no negociables

Estas reglas existen porque su incumplimiento produce un registro horario legalmente inválido, datos de nómina erróneos o un producto imposible de vender. No son preferencias de estilo.

1. **`Domain/` es puro.** Cero imports de `Illuminate\*`, de Eloquent, de otro módulo o de cualquier librería de infraestructura. Deptrac lo verifica y la CI falla.
2. **Nunca `now()`, `time()`, `Carbon::now()` ni `new DateTime()` en el dominio.** Se inyecta el puerto `Clock`. Sin esto no se puede probar DST ni medianoche.
3. **Todo instante se almacena en UTC** (`TIMESTAMPTZ`). La conversión a la zona del centro ocurre solo en la capa de presentación. `APP_TIMEZONE=UTC` siempre.
4. **Los turnos no se parten a medianoche.** Un turno 22:00→06:00 es un único tramo, atribuido a la jornada de su hora de inicio (RN-05, ADR-006).
5. **Nada se borra ni se sobrescribe.** Las correcciones crean una versión nueva y conservan la anterior con autor, momento y motivo (RN-13, RL-04).
6. **Toda acción con relevancia legal escribe en `audit_log`**, que es solo-append y encadenado por hash. El usuario de base de datos de la aplicación no tiene `UPDATE` ni `DELETE` sobre esa tabla.
7. **`daily_totals` es una proyección reconstruible.** Se **recalcula** en la misma transacción, nunca se incrementa acumulativamente (RN-06, ADR-007).
8. **Todo fichaje es idempotente por `scan_id`** (UUID v7 generado en el cliente). Un reenvío devuelve la respuesta original, nunca un error ni un duplicado.
9. **Dos marcas de tiempo siempre:** `occurred_at` (momento real, puede venir de la cola offline) y `recorded_at` (recepción en servidor). El registro legal usa `occurred_at`.
10. **El payload QR va firmado con HMAC** (`FH1.<key_id>.<token>.<sig>`). Nunca PII ni identificadores secuenciales en el QR.
11. **La credencial es una tarjeta física impresa** (ADR-014). No hay credencial en móvil, ni invitaciones por correo, ni TOTP. Si una tarea lo sugiere, para y pregunta.
12. **El producto no depende del correo electrónico del empleado.** Es un campo opcional. El acceso al portal personal es con código de empleado y PIN (ADR-015).
13. **Nada específico de un cliente vive en el código** (ADR-017). Marca, umbrales legales, idiomas y funcionalidades son configuración. Si algo obliga a tocar el repositorio para vender a un cliente nuevo, está mal diseñado. **Nunca una rama por cliente.**
14. **Los umbrales legales se leen del perfil de cumplimiento**, no son constantes: descanso mínimo, jornada máxima, pausas y retención (RN-10/11/12). El dominio recibe el umbral ya resuelto por un puerto; nunca consulta la configuración.
15. **La caducidad de la licencia jamás bloquea el fichaje ni el acceso al registro legal** (ADR-019). Se degradan funcionalidades accesorias, nunca el registro.
16. **El fabricante no accede a los datos del cliente** salvo concesión expresa, temporal y auditada. El paquete de diagnóstico va anonimizado por defecto (ADR-020).
17. **Los rechazos de escaneo son genéricos y de tiempo constante.** Nunca se revela si un código no existe, está revocado o tiene mala firma (RS-03).
18. **Cada endpoint tiene su policy y su prueba de autorización negativa** (que un rol no autorizado recibe 403). Obligatorio, sin excepciones.
19. **El quiosco nunca bloquea al empleado.** Ni por falta de red, ni por desfase de reloj, ni porque el padrón cacheado no reconozca la tarjeta: encola siempre, confirma localmente y, si algo no cuadra, genera una incidencia para revisión humana (RF-AT-10, RN-15).
20. **Cero biometría.** Decisión firme (ADR-009). Si una tarea la sugiere, para y pregunta.
21. **Nunca nombres de empleados en logs técnicos ni en `error_events`.** Se usa `employee_uuid`. El histórico de errores viaja al fabricante dentro del paquete de diagnóstico: si lleva PII, se ha filtrado.

## Comandos

```bash
make up                      # Levanta el entorno completo
make test                    # Toda la suite
make test-unit               # Dominio, sin base de datos, < 2 s
make quality                 # Pint + PHPStan 9 + Deptrac + Rector dry-run
make mutate                  # Mutación sobre el dominio (MSI ≥ 80 %)
make e2e                     # Playwright con cámara simulada
```

## Definición de Terminado

Consulta [docs/02-stack-tecnologico-y-plan-implementacion.md](docs/02-stack-tecnologico-y-plan-implementacion.md) §10.3. Resumen: arquitectura en verde, pruebas en todos los niveles aplicables, PHPStan 9 limpio, contrato OpenAPI actualizado, autorización probada en negativo, instrumentación añadida, auditoría escrita, migración reversible, textos en español e inglés, y nada específico de un cliente en el código.

## Agentes y skills

Hay 10 agentes en `.claude/agents/` y 6 skills en `.claude/skills/`.

**El plan de implementación del documento 02, §11, indica el agente y la skill de cada tarea.** Si estás ejecutando una tarea del plan, usa el que ahí se indica. Para trabajo ad-hoc, consulta [docs/03-agentes-y-skills-ia.md](docs/03-agentes-y-skills-ia.md).
