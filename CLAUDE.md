# Sistema de Fichaje por QR — Reglas del proyecto

**Producto licenciado** de control de presencia y **registro horario con valor legal** para hoteles. Los empleados fichan escaneando una tarjeta QR en una tablet-quiosco compartida.

Se vende a clientes distintos y **se despliega en el servidor de cada uno**. No hay SaaS ni multi-tenencia: cada cliente tiene su instalación completa.

## Documentación obligatoria

Antes de escribir código, lee lo que corresponda a tu tarea:

- [docs/01-especificaciones-proyecto.md](docs/01-especificaciones-proyecto.md) — requisitos (`RF-*`), reglas de negocio (`RN-*`), modelo de dominio, requisitos legales (`RL-*`), seguridad (`RS-*`), calidad (`RQ-*`)
- [docs/02-stack-tecnologico-y-plan-implementacion.md](docs/02-stack-tecnologico-y-plan-implementacion.md) — arquitectura, stack, 20 ADRs, seguridad, observabilidad, pruebas y plan por fases **con el agente asignado a cada tarea**
- [docs/04-decision-credencial.md](docs/04-decision-credencial.md) — por qué la credencial es una tarjeta física
- [docs/adr/](docs/adr/) — decisiones arquitectónicas. **Si vas a contradecir un ADR, para y pregunta.**
- [docs/api/openapi.yaml](docs/api/openapi.yaml) — contrato de la API. **Es la fuente de verdad: se modifica antes que el código.**

## Stack

PHP 8.4 · Laravel 12 · PostgreSQL 17 · Redis 7 · Reverb · Vue 3 + TypeScript estricto · Vite · Tailwind 4 · Pinia · Dexie · `@zxing/browser` · Pest · Playwright · Docker Compose

## Arquitectura

Monolito modular con arquitectura hexagonal. Módulos en `backend/app/Modules/`: `Attendance` (núcleo), `Compliance`, `Workforce`, `Identity`, `Reporting`, `Kiosk`, `Product`, `Shared`.

Cada módulo: `Domain/` → `Application/` → `Infrastructure/` + `Http/`.

**Tres aplicaciones cliente:** `frontend-kiosk/` (PWA de la tablet), `frontend-admin/` (panel de gestión) y `frontend-portal/` (portal web del empleado para consultar su propio registro).

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
19. **El quiosco nunca bloquea al empleado por falta de red.** Encola siempre y confirma localmente.
20. **Cero biometría.** Decisión firme (ADR-009). Si una tarea la sugiere, para y pregunta.
21. **Nunca nombres de empleados en logs técnicos.** Se usa `employee_uuid`.

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
