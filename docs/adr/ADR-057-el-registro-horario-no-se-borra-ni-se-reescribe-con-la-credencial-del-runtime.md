# ADR-057 — El registro horario no se borra ni se reescribe con la credencial del runtime

| Campo | Valor |
|---|---|
| **Estado** | **Aceptada** por el propietario (08-10-2026). **§4 implementada en la 2.2.0** (conciliación diaria de los últimos 7 días y semanal completa: `compliance:reconcile-work-record`, `infra/observability/prometheus/rules/work-record.yml`, `docs/runbooks/discrepancia-registro-auditoria.md`; bloque 14, revisado por `seguridad-cumplimiento`). **§1-§3 aceptadas para la 2.2.x, implementación pendiente** (`backend-laravel`, con `devops-observabilidad` para `update.sh`). Hasta entonces el registro horario se protege por detección, no por privilegios, y la enmienda de ADR-042 sigue vigente para sus tablas |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo R6-AR-01 de la [re-verificación](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md), residuo de AUD-1 y R4-SC-04) |
| **Afecta a** | Enmienda [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (lo que de verdad garantiza hoy) · Extiende a las tablas del registro horario el patrón de [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-027](ADR-027-audit-log-particionado.md) y [ADR-033](ADR-033-tres-roles-de-base-de-datos-no-dos.md) · Respeta [ADR-007](ADR-007-daily-totals-proyeccion-reconstruible.md), [ADR-026](ADR-026-la-correccion-supersede.md) y [ADR-035](ADR-035-la-correccion-estrena-identificador-y-no-cambia-de-jornada.md) · `backend/database/migrations/2026_08_19_099000_provision_database_privileges.php` · `Attendance/Infrastructure/Persistence/EloquentWorkDayRepository.php` · `Compliance/Infrastructure/Persistence/DatabaseWorkRecordArchive.php`, `DatabaseIncidentLedger.php`, `DatabaseIncidentNotices.php` · `Compliance/RetentionServiceProvider.php` · `infra/scripts/update.sh` (`verify_privileges`) |
| **Requisitos** | RL-02, RL-04, RS-07 · reglas duras 5, 6 y 7 |

## Contexto

ADR-042 dice que «ningún proceso que corre con la aplicación en marcha posee una credencial con la que se pueda alterar el registro». Para `audit_log` es cierto. **Para el registro horario no lo es.** La migración `2026_08_19_099000_provision_database_privileges` concede a `fichaje_app`, por defecto y sobre todas las tablas, `SELECT, INSERT, UPDATE, DELETE`, y solo `audit_log` y `audit_chain_anchors` lo revocan después. Así, con una ejecución de código en PHP se puede cambiar el `clocked_in_at` de un tramo, borrarlo o insertar uno inventado. La cadena de auditoría sigue en verde porque no se toca, y la exportación para Inspección, que lee `shift_entries`, sale manipulada. Ninguna tabla del registro tiene un *trigger* que limite sus transiciones, y nada concilia `shift_entries` con los asientos `shift_entry.*` de `audit_log`. La re-verificación de la 2.2.0 lo registró como R6-AR-01 (🟠).

Esto es lo que la aplicación necesita de verdad, leído en el código a 08-10-2026:

| Tabla | `UPDATE` que hace la aplicación | `DELETE` que hace la aplicación |
|---|---|---|
| `shift_entries` | `EloquentWorkDayRepository`: `upsert` por `uuid` que fija `clocked_out_at`, `duration_minutes`, `status`, `clock_out_source`, `version` y `updated_at`; y `linkSupersededEntries`, que fija `superseded_by_id` y `updated_at` | solo la purga de retención (`DatabaseWorkRecordArchive::purge`) |
| `shift_corrections` | ninguno (`DatabaseShiftCorrectionLedger` solo inserta) | solo la purga |
| `scan_events` | ninguno (`EloquentScanLog` inserta con `ON CONFLICT DO NOTHING`) | solo la purga |
| `discarded_scan_reports` | ninguno (inserta con `ON CONFLICT DO NOTHING`) | solo la purga |
| `incidents` | `DatabaseIncidentLedger` (resolución: `status`, `resolved_at`, `resolved_by_user_id`, `resolution_note`, `updated_at`) y `DatabaseIncidentNotices::markNotified` (`notified_at`, `updated_at`) | solo la purga |
| `daily_totals` | `DailyTotalsProjector` (`ON CONFLICT … DO UPDATE`) y `FOR UPDATE OF d` en la proyección | solo la purga |

La purga de retención corre hoy con la conexión de la aplicación (`RetentionServiceProvider`, `DB::connection()`) a propósito: el borrado y su asiento en `audit_log` tienen que ir en la misma transacción, y el rol de mantenimiento no puede escribir en `audit_log`.

## Decisión

**Se elige retirar privilegios, no rebajar la promesa.** Ningún `DELETE` sobre el registro horario con la credencial del runtime. Ningún `UPDATE` fuera de las columnas y transiciones que el dominio produce. Y una conciliación diaria que detecte lo que los privilegios no pueden impedir.

### 1. Privilegios de `fichaje_app` sobre el registro horario

| Tabla | `SELECT` | `INSERT` | `UPDATE` | `DELETE` |
|---|---|---|---|---|
| `shift_entries` | sí | sí | **solo** `clocked_out_at`, `duration_minutes`, `status`, `clock_out_source`, `superseded_by_id`, `updated_at` | **no** |
| `shift_corrections` | sí | sí | **no** | **no** |
| `scan_events` | sí | sí | **no** | **no** |
| `discarded_scan_reports` | sí | sí | **no** | **no** |
| `incidents` | sí | sí | **solo** `status`, `resolved_at`, `resolved_by_user_id`, `resolution_note`, `notified_at`, `updated_at` | **no** |
| `daily_totals` | sí | sí | sí (es una proyección reconstruible, ADR-007) | **no** |

Se hace con `REVOKE UPDATE, DELETE` sobre cada tabla y `GRANT UPDATE (columnas)` donde corresponde. PostgreSQL admite privilegios por columna, y un `INSERT … ON CONFLICT DO UPDATE SET …` solo exige `UPDATE` sobre las columnas del `SET`. `version` sale de la lista: el `upsert` de `EloquentWorkDayRepository` la incluye entre las columnas que actualiza, pero un tramo existente nunca cambia de versión (la corrección estrena fila, ADR-035), así que hay que quitarla de ese `upsert` antes de retirar el privilegio. Las tablas nuevas siguen naciendo con los cuatro privilegios por defecto (`ALTER DEFAULT PRIVILEGES`). Una tabla nueva del registro horario declara sus `REVOKE` en su propia migración, que es lo que ya hacen `audit_log` y `audit_chain_anchors`.

### 2. Un *trigger* que solo admite las transiciones del dominio en `shift_entries`

`BEFORE UPDATE ON shift_entries FOR EACH ROW`, propiedad del rol de migración. La aplicación no lo puede desactivar: `ALTER TABLE … DISABLE TRIGGER` exige ser propietario y `session_replication_role` exige superusuario. Rechaza con `42501`, o con un `SQLSTATE` propio, cualquier fila en la que:

- cambie `id`, `uuid`, `employee_id`, `site_id`, `work_date`, `clocked_in_at`, `clock_in_source`, `version` o `created_at`. Esta segunda barrera cubre lo mismo que el punto 1 por si alguien vuelve a conceder la columna;
- `clocked_out_at`, `duration_minutes` o `clock_out_source` cambien cuando su valor anterior **no** era `NULL`;
- `superseded_by_id` cambie cuando no era `NULL`, o se fije sin que `status` pase a `superseded`;
- `status` haga una transición que el dominio no produce. Las admitidas son `open` → `closed`, `anomalous`, `superseded` o `voided`; `closed` o `anomalous` → `superseded` o `voided`. `superseded` y `voided` son finales.

### 3. La purga de retención pasa por una función `SECURITY DEFINER`

`public.work_record_purge_batch(p_cutoff date, p_batch integer)`, propiedad del rol de migración y con `EXECUTE` solo para `fichaje_app`, siguiendo el patrón de `audit_log_create_partition` (ADR-042). Borra un lote de cada tabla en el orden que hoy sigue `DatabaseWorkRecordArchive::purge` y devuelve los recuentos. Sus límites son parte de la decisión:

- **calcula ella misma la fecha de corte más reciente admisible**, a partir de la fecha UTC del servidor de base de datos y de `retention_years` del perfil de cumplimiento del centro, y **rechaza** un `p_cutoff` posterior. Quien la llama no puede pedir que se borre un tramo de ayer;
- lleva `SET search_path = pg_catalog, pg_temp`, califica todo con `public.` y fija `lock_timeout`;
- la aplicación la llama **en la misma transacción** en la que escribe el asiento de la purga. Se conserva la propiedad que hoy justifica usar la conexión de la aplicación.

### 4. Conciliación diaria entre el registro y su auditoría

Un comando programado a diario cruza cada tramo vigente de los últimos días con el último asiento `shift_entry.*` de su `shift_entry_uuid`. Esos asientos ya llevan `clocked_in_at`, `clocked_out_at`, `work_date` y el antes y el después de las correcciones (`RecordShiftEntryAudit`). Falla, deja una métrica a 0 y hace sonar una alerta, igual que la verificación de la cadena, si un tramo no tiene asiento (un `INSERT` inventado), si sus marcas no coinciden con las del asiento (un `UPDATE` permitido con un valor falso) o si un asiento de un tramo dentro del plazo de retención no tiene fila (un borrado). **Es la única de las cuatro defensas que detecta un tramo insertado**, y la que convierte los residuos del punto siguiente en detectables.

Además de la pasada diaria, una **pasada completa semanal** recorre todo el registro: es la única que ve una edición o un borrado de un tramo cuyos asientos ya quedaron fuera de la ventana.

## Lo que esto no impide, dicho con exactitud

- **Insertar un tramo falso o cerrar un tramo abierto con una hora falsa** sigue siendo posible con la credencial del runtime, porque son exactamente las escrituras que el fichaje necesita. Lo detecta la conciliación del punto 4, no lo impide.
- **Bajar `retention_years` y purgar.** El perfil de cumplimiento se edita desde el panel (`settings:*`) con la conexión de la aplicación, así que quien ejecute código puede rebajar el plazo y después purgar dentro de los límites de la función. Deja rastro en la propia fila del perfil y, si se hace por el caso de uso, en `audit_log`. Las copias conservan lo purgado. Es un riesgo residual para doc 07 §6.
- **`daily_totals`** conserva `UPDATE` completo porque es una proyección. Si se manipula, `attendance:reconcile` la recalcula desde `shift_entries` y la alerta de divergencia de ADR-007 lo señala.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Enmendar ADR-042 y no tocar los privilegios** | Deja el registro horario, que es lo que el producto existe para proteger (RL-04), menos protegido que su auditoría. Con un borrado de tramo, la exportación para Inspección saldría manipulada y nada lo detectaría |
| **Purga con el rol de mantenimiento** | Separa el borrado de su asiento en dos sesiones: el asiento lo escribe la aplicación y el borrado otro rol. Un fallo entre las dos deja un borrado sin constancia o una constancia sin borrado (regla dura 6) |
| **Solo el *trigger*, sin retirar privilegios** | Un *trigger* no impide un `DELETE` salvo que se escriba también para eso. Los privilegios son la barrera estándar y la que comprueba `has_table_privilege` en `update.sh` y en las pruebas |
| **Hacer `shift_entries` solo-append** (cerrar un tramo insertando otra fila) | Cambia el modelo de ADR-026 y las restricciones de RN-01 y RN-02 que viven en el esquema, por una protección que los privilegios por columna y el *trigger* ya dan |
| **Solo la conciliación** | Detecta, pero no impide el borrado, y un borrado se descubriría al día siguiente sobre un registro ya exportado |

## Consecuencias

- **La conciliación no detecta una escritura acompañada de asientos falsificados.** La cadena de `audit_log` no lleva secreto (ADR-010): prueba que lo escrito no se cambia ni se borra sin romperla, no quién lo escribió. `fichaje_app` tiene `INSERT` sobre `audit_log` porque cada fichaje lo necesita, así que quien ejecute código con esa credencial puede añadir asientos `shift_entry.*` bien encadenados que «expliquen» un tramo inventado o una hora cambiada, y la conciliación, que compara con el **último** asiento, los da por buenos. Se conserva lo esencial: los asientos anteriores no se pueden tocar —la versión original sigue anotada— y los añadidos quedan para siempre con su `id`, su actor y su momento. La garantía es «ninguna escritura por fuera de la aplicación pasa inadvertida y lo anotado no se reescribe», no «ninguna manipulación pasa inadvertida», y así lo dicen doc 05 §6.1 y `obligaciones-legales.md` §5. Es el riesgo «La cadena de auditoría no autentica a quien escribe» de doc 07 §6.
- **Un asiento de purga solo excusa un borrado si es admisible:** corte con formato de fecha, no posterior a la fecha del asiento menos su `retention_years`, y `retention_years` no inferior al suelo legal del perfil. Un `retention.purge_executed` que no cumpla sale como discrepancia; nunca tapa borrados.


- **ADR-042 queda enmendado** (enmienda al final de ese ADR): hasta que este ADR esté implementado, su garantía alcanza a `audit_log` y a las credenciales, no a las tablas del registro horario.
- **Las pruebas que hoy preparan escenarios modificando o borrando filas del registro con la conexión por defecto tienen que pasar a `pgsql_migrator`**, que es como ya simulan al atacante `AuditLogTest` y `RetentionTest`. Al menos `RejectedPinScanTest`, `DailyTotalsReconciliationTest`, `ScanLogTest`, `DataExportVolumeTest` y `AdoptionReportVolumeTest`.
- **`update.sh` (`verify_privileges`)** tiene que comprobar también que `fichaje_app` no tiene `DELETE` sobre las seis tablas ni `UPDATE` sobre `shift_corrections` y `scan_events`. Si no, una restauración o una actualización que devolviera los privilegios pasaría la verificación. Es de `devops-observabilidad`.
- **Doc 07** (AUD-1 «CERRADO», la fila `T1565.001` y la evidencia de Arquitectura segura) tiene que recoger este residuo hasta que se implemente. Es de `seguridad-cumplimiento`.

## Verificación

- Integración (RS-07): `fichaje_app` recibe `42501` en `DELETE` sobre las seis tablas, en `UPDATE` sobre `shift_corrections`, `scan_events` y `discarded_scan_reports`, y en `UPDATE` de cualquier columna no concedida de `shift_entries` y de `incidents`. Lo mismo con `TRUNCATE`.
- Integración: el *trigger* rechaza cambiar `clocked_in_at`, reabrir un tramo cerrado, cambiar un `clocked_out_at` ya puesto y sacar un tramo de `superseded` o `voided`. Las transiciones del dominio (cerrar, marcar anómalo, superseder con su `superseded_by_id`, anular) pasan.
- Integración: `work_record_purge_batch` rechaza un corte posterior al que permite el perfil, borra en el orden correcto y deja el asiento en la misma transacción. Un `CREATE TEMP TABLE shift_entries` del rol de aplicación no la desvía.
- Feature y E2E existentes del fichaje, de las correcciones, de la resolución de incidencias y de la purga, en verde con los privilegios nuevos.
- Integración: la conciliación detecta un tramo insertado sin asiento, un `clocked_out_at` que no coincide con su asiento y un tramo borrado dentro del plazo, y no da falsos positivos sobre una purga legítima.
- Migración: `down()` devuelve los privilegios anteriores y retira el *trigger* y la función, sin tocar datos.
