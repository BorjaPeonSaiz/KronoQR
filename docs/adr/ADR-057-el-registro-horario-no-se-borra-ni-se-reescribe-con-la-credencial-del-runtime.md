# ADR-057 — El registro horario no se borra ni se reescribe con la credencial del runtime

| Campo | Valor |
|---|---|
| **Estado** | **Aceptada** por el propietario (08-10-2026). **§4 implementada en la 2.2.0** (conciliación diaria de los últimos 7 días y semanal completa: `compliance:reconcile-work-record`, `infra/observability/prometheus/rules/work-record.yml`, `docs/runbooks/discrepancia-registro-auditoria.md`; bloque 14, revisado por `seguridad-cumplimiento`). **§1–§3 implementadas en la 2.2.1**: §1 y §7 en el bloque 6 (privilegios por columna, migraciones `2026_10_13_100000` y `2026_10_13_100100`), §2 y §3 en el bloque 7 (*trigger* de transiciones en `shift_entries` y en `incidents`, purga por la función `SECURITY DEFINER` `work_record_purge_batch` y retirada del `DELETE` de las seis tablas y de `sites`, migración `2026_10_14_100000`; [implementación del bloque 7](#implementación-de-2-y-3-bloque-7-de-la-221-13-10-2026)). Lo que sigue sin impedirse con la credencial del runtime está en [«Lo que esto no impide, con §1–§3 en vigor»](#lo-que-esto-no-impide-con-13-en-vigor) y lo cubre la detección de §4 |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo R6-AR-01 de la [re-verificación](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md), residuo de AUD-1 y R4-SC-04) |
| **Afecta a** | Enmienda [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (lo que de verdad garantiza hoy) · Extiende a las tablas del registro horario el patrón de [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-027](ADR-027-audit-log-particionado.md) y [ADR-033](ADR-033-tres-roles-de-base-de-datos-no-dos.md) · Respeta [ADR-007](ADR-007-daily-totals-proyeccion-reconstruible.md), [ADR-026](ADR-026-la-correccion-supersede.md) y [ADR-035](ADR-035-la-correccion-estrena-identificador-y-no-cambia-de-jornada.md) · `backend/database/migrations/2026_08_19_099000_provision_database_privileges.php` · `Attendance/Infrastructure/Persistence/EloquentWorkDayRepository.php` · `Compliance/Infrastructure/Persistence/DatabaseWorkRecordArchive.php`, `DatabaseIncidentLedger.php`, `DatabaseIncidentNotices.php` · `Compliance/RetentionServiceProvider.php` · `infra/scripts/update.sh` (`verify_privileges`) |
| **Requisitos** | RL-02, RL-04, RS-07 · reglas duras 5, 6 y 7 |
| **Enmiendas** | [Enmienda del 10-10-2026](#enmienda-del-10-10-2026) (bloque 5 de la 2.2.1): el suelo legal de `retention_years` es por jurisdicción y vive en un catálogo del producto, con su `CHECK` para España; y un asiento de purga no admisible se puede dar por revisado con otro asiento. Añade §5 y §6 y precisa §3 y §4 · [Enmienda del 13-10-2026](#enmienda-del-13-10-2026) (bloque 6 de la 2.2.1): orden de §1 frente a §3 y §7, el perfil de cumplimiento no se reasigna ni cambia de jurisdicción con la credencial del runtime · [Implementación de §2 y §3](#implementación-de-2-y-3-bloque-7-de-la-221-13-10-2026) (bloque 7 de la 2.2.1): transiciones exactas, *trigger* de `incidents` (extiende §2), el suelo legal en SQL, precisa §3 (`p_batch` acota cada sentencia, no cada llamada) y lo que sigue sin impedirse · [Enmienda del 18-10-2026](#enmienda-del-18-10-2026-adr-058-bloque-1-de-la-230) ([ADR-058](ADR-058-como-termino-un-tramo-es-un-hecho-del-registro.md)): `closed_by` es la séptima columna de §1 y §2 y entra en la conciliación de §4 · [Nota del 18-10-2026](#nota-del-18-10-2026-230-bloque-2-el-mismo-patrón-en-sites-y-4-compara-la-zona-del-centro) (bloque 2 de la 2.3.0, [ADR-056](ADR-056-la-zona-horaria-del-centro-queda-fija-desde-el-primer-fichaje.md)): el patrón de §2 y §3 en `sites` (*trigger*, función `SECURITY DEFINER` y traza solo-append de los cambios de zona) y una sexta clase en §4, `site_timezone_differs_from_audit`, con revisión por asiento como §6 · [Enmienda del 27-10-2026](#enmienda-del-27-10-2026-230-bloque-20-2-admite-que-el-sistema-reabra-lo-que-el-sistema-cerró-rn-19) (bloque 20 de la 2.3.0, RN-19): `incidents_guard_transition` admite `resolved` → `open` solo para la `rejected_pin_scan` que cerró el sistema, con `context` al día, y `fichaje_app` recibe `UPDATE (context)` |

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

- **Insertar un tramo falso o cerrar un tramo abierto con una hora falsa** sigue siendo posible con la credencial del runtime, porque son exactamente las escrituras que el fichaje necesita. Lo detecta la conciliación del punto 4, no lo impide. Esto es el residuo con §1 **y** §2 en vigor; mientras falte el *trigger* de §2 es bastante más amplio (enmienda del 13-10-2026, [«Lo que esto no impide hasta el bloque 7»](#lo-que-esto-no-impide-hasta-el-bloque-7)).
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

## Enmienda del 10-10-2026

**Bloque 5 de la 2.2.1, hallazgo R7-SC-01: suelo legal por jurisdicción y revisión de una purga no admisible.** Decide `arquitecto-dominio` sobre dos decisiones del propietario: «se mantiene la promesa de 4 años: suelo legal de `retention_years` por jurisdicción, 4 para España» (09-10-2026) y «“revisado” de `purge_out_of_bounds`: sí» (08-10-2026). El diseño ejecutable, con firmas, nombres y pruebas, está en [`2.2.1-plan-correcciones.md`](../verificacion/2.2.1-plan-correcciones.md), «Decisiones del bloque 5».

**Por qué aquí y no en ADR-027 ni en un ADR nuevo.** Este ADR ya nombra «el suelo legal del perfil» en §3 (la función de purga) y en sus consecuencias (cuándo un asiento de purga es admisible), y ya registra como residuo «bajar `retention_years` y purgar». Las dos decisiones completan §3 y §4 y cierran parte de ese residuo; no abren una dirección nueva. ADR-027 decide cómo se suelta una partición de `audit_log`, no cuánto se conserva: el suelo le llega igual, porque la purga de particiones usa el mismo `retention_years`.

### 5. El suelo de `retention_years` es por jurisdicción y es un dato legal del producto

- **Catálogo** en `config/compliance.php`, clave `retention_floors`: `default` = 1 y `jurisdictions` = `{ ES: 4 }` (art. 34.9 ET). Las claves son países ISO 3166-1 alfa-2; una subdivisión ISO 3166-2 (`ES-CN`) hereda el suelo de su país. **Sin variable de entorno**: un suelo que el `.env` puede rebajar no es un suelo.
- **No vive en `compliance_profiles`.** Una columna de suelo sería editable con la misma credencial que edita el plazo, y el suelo dejaría de serlo. Tampoco es una constante del dominio (regla dura 14): llega ya resuelto por el puerto `Shared\Application\Port\RetentionYearsFloor`, que pasa a resolver por jurisdicción y por centro en lugar de dar un mínimo común a todos los campos.
- **Regla dura 13 y RF-PD-01.** El suelo no es de ningún cliente: es el mismo para todos los de una jurisdicción. Vender en un país que no está en el catálogo no obliga a tocar el repositorio, porque recibe `default`. Añadir un país es mejorar lo que el producto sabe de la ley, y se publica en una versión, con su `CHECK`.
- **Lo hacen cumplir cuatro sitios:** la edición del perfil (`422` con el suelo en el mensaje), la purga (se niega a purgar con un plazo por debajo del suelo), la conciliación de §4 (un asiento de purga por debajo del suelo es `purge_out_of_bounds`) y el esquema.
- **El esquema lleva dos `CHECK`:** `jurisdiction` con forma ISO (`^[A-Z]{2}(-[A-Z0-9]{1,3})?$`) y `split_part(jurisdiction, '-', 1) <> 'ES' OR retention_years >= 4`. Esto enmienda el «no hay tope inferior» de la migración `2026_09_06_100000_bound_compliance_profile_thresholds`. Aquel razonamiento era que el esquema no podía distinguir un 1 tipográfico de un 1 legítimo; con la jurisdicción en la fila, para España sí puede.
- **El dato está en dos sitios, a propósito, y una prueba los ata.** El `CHECK` es la red de quien edita la fila a mano y no lee la configuración. Cada país del catálogo con suelo mayor que `default` tiene su `CHECK` (`compliance_profiles_chk_retention_floor_<país>`), y una prueba de integración recorre el catálogo e intenta escribir `suelo - 1` en una fila de ese país: añadir un país al catálogo sin su migración la pone en rojo.
- **La conciliación juzga cada asiento de purga con el suelo más alto** entre el de la jurisdicción actual del centro y el que el propio asiento declara (`retention_floor_years`, que la purga escribe desde la 2.2.1). Un asiento falsificado solo puede endurecerse a sí mismo.

### 6. Un asiento de purga no admisible se puede dar por revisado, con otro asiento

Un `retention.purge_executed` no admisible queda para siempre en `audit_log`, y cada pasada de §4 lo volvía a contar: la alerta no se apagaba nunca, que es la forma más rápida de que alguien la silencie.

- **La revisión es un asiento nuevo**, `retention.purge_reviewed`, en la familia `RetentionPurge` del asiento que revisa. Lleva el `id` del asiento revisado, los defectos que tenía al revisarlo, el suelo vigente, el motivo y la cuenta de gestión responsable, que es el actor. Sin tabla nueva: la marca vive en `audit_log`, que ya es solo-append (regla dura 6). Nada se borra ni se reescribe (regla dura 5).
- **La escribe solo una persona desde la consola:** `compliance:reconcile-work-record --acknowledge=<id> --reason=… --responsible=<correo o uuid>` (desde la 2.3.0, bloque 3, la cuenta se nombra por su correo o su uuid, nunca por su id, y la orden enseña quién firma antes de escribir; doc 01 §7.2). El asiento tiene que ser una purga del registro horario, tiene que ser no admisible hoy y no puede estar ya revisado. El motivo y una cuenta de gestión activa son obligatorios; **desde el 16-10-2026, por decisión del propietario (D7 de la 2.2.1), esa cuenta tiene que tener rol `admin` o `rrhh`**, como la corrección de la zona del centro, y si no la orden sale con 2 sin asiento, también en la vista previa.
- **Efecto en la conciliación.** Un asiento revisado deja de contarse como `purge_out_of_bounds` pendiente y se cuenta aparte como revisado. Cualquier purga posterior se juzga igual que antes. Si el único defecto del asiento era el plazo (`retention_years` por debajo del suelo, sin otro fallo de forma, de fecha o de centro), además vuelve a explicar sus borrados, pero solo hasta el corte que su propio plazo permitía el día en que se escribió. Uno con un corte imposible o sin centro sigue sin explicar nada.
- **Revisar avisa a seguridad.** El número de purgas revisadas se publica como métrica propia, y una alerta informativa salta cuando sube. Un «revisado» falsificado con la credencial del runtime ([riesgo aceptado](../07-seguridad-madurez-y-amenazas.md) «La cadena de auditoría no autentica a quien escribe») no pasa en silencio.

### Lo que esto no impide, actualizado

- **Bajar `retention_years` y purgar** deja de ser posible por debajo de 4 años en España: lo impiden el `CHECK`, la edición del perfil y la purga. Hasta el bloque 6 seguía siendo posible cambiar `jurisdiction` con la credencial de la aplicación, que tenía `UPDATE` sobre toda la fila, y con ello salir del `CHECK` español. La edición desde el panel no lo permite, pero **la conciliación no lo habría detectado**: juzga con el suelo más alto entre el de la jurisdicción que el centro tiene *hoy* y el que declara el asiento, y si la jurisdicción se cambia antes de purgar, los dos salen de la misma fila manipulada. *(Corregido el 13-10-2026: decía «la conciliación juzga con el suelo más alto que conoce», que daba a entender una defensa que no existía.)* Lo cierra la [enmienda del 13-10-2026](#enmienda-del-13-10-2026), §7.
- **Un par «purga a un año + revisión» falsificado** deja de contar como discrepancia, pero dispara la alerta de revisión. El residuo es el mismo que el de cualquier asiento añadido con la credencial del runtime.

## Enmienda del 13-10-2026

**Bloque 6 de la 2.2.1: privilegios por columna (§1) y el perfil de cumplimiento fuera del alcance del runtime.** Implementa `backend-laravel` con la skill `migracion-segura`; la comprobación en `update.sh` es la tarea 6.4 de `devops-observabilidad`. El hallazgo que añade §7 es un ALTO de `seguridad-cumplimiento` en la revisión del bloque 5.

### Orden de §1 frente a §3

§1 retira `DELETE` de las seis tablas, pero `DatabaseWorkRecordArchive::purge` borra de **las seis** con la conexión de la aplicación, en la transacción de su asiento. Retirarlo antes de que exista la función de §3 rompería la retención legal (RL-02). Por eso la migración del bloque 6 (`2026_10_13_100000_restrict_application_updates_on_work_record`) retira el `UPDATE` —por columnas donde el dominio actualiza, entero donde no— y **conserva el `DELETE`**, que la migración de §3 (bloque 7) retira en el mismo paso en que la purga pasa a la función. `daily_totals` conserva el `UPDATE` completo. `TRUNCATE`, `REFERENCES` y `TRIGGER` se revocan explícitamente en las seis aunque la provisión nunca los concedió.

Antes, la tarea 6.1 quitó `version` del `upsert` de `EloquentWorkDayRepository` (ADR-035): con la columna en el `SET`, el `ON CONFLICT DO UPDATE` habría dado `42501` en cada cierre de tramo.

### 7. El perfil de cumplimiento no cambia de jurisdicción ni se reasigna con la credencial del runtime

El suelo de §5 se resuelve por la jurisdicción del perfil del centro. Si la credencial de la aplicación puede cambiar esa jurisdicción, crear un perfil o reasignar el del centro, el suelo lo decide quien la tenga. La migración `2026_10_13_100100_restrict_application_writes_on_compliance_profiles` deja a `fichaje_app`:

| Tabla | `SELECT` | `INSERT` | `UPDATE` | `DELETE` |
|---|---|---|---|---|
| `compliance_profiles` | sí | **no** | **solo** `name`, `retention_years`, `min_rest_hours`, `max_daily_hours`, `max_weekly_hours`, `break_required_after_hours`, `week_starts_on`, `holiday_calendar`, `updated_at`, `updated_by_user_id` | **no** |
| `sites` | sí | **solo** `name`, `timezone` | **solo** `name`, `timezone` | sí (sin cambio) |

Son exactamente las columnas que escriben `DatabaseComplianceProfileRepository::save` (panel y asistente) y `EloquentSiteRepository` (`POST` y `PATCH /site`). Ningún código de la aplicación inserta ni borra perfiles —el perfil por defecto lo crea su migración con el rol de migración— ni escribe `sites.compliance_profile_id` o `sites.settings`. El `INSERT` de `sites` se limita por columna para que borrar y volver a crear el centro no sea el mismo atajo; el `DELETE` no se toca porque las claves ajenas `RESTRICT` lo impiden en cuanto hay plantilla.

**Lo que esto no impide.** Bajar `retention_years` hasta el suelo de la jurisdicción vigente y purgar dentro de él sigue siendo posible, como ya decía la enmienda del 10-10-2026: es la edición legítima del panel. Asignar otro perfil al centro o cambiar su jurisdicción pasa a ser una operación con el rol de migración, es decir, de una versión del producto o de quien tenga esa credencial, que no llega a ningún contenedor de runtime (ADR-042).

### Lo que esto no impide hasta el bloque 7

*(Histórico: lo cierra el bloque 7, ver [«Lo que esto no impide, con §1–§3 en vigor»](#lo-que-esto-no-impide-con-13-en-vigor). Se conserva como constancia de la ventana en que existió.)*

El privilegio por columna de §1 dice **qué columnas** puede escribir `fichaje_app`, no **en qué filas** ni **desde qué valor**: eso es el *trigger* de §2, que llega en el bloque 7. Hasta entonces, además de insertar un tramo falso o cerrar uno abierto con una hora falsa, quien ejecute código con la credencial del runtime puede:

- **Reescribir `clocked_out_at`, `duration_minutes` y `clock_out_source` de cualquier tramo ya cerrado**, de cualquier antigüedad.
- **Pasar a `voided` o `superseded` un tramo vigente**, que desaparece de la exportación para Inspección, y **poner un `superseded_by_id` arbitrario**.
- **Reescribir la resolución de una incidencia ya cerrada** (`status`, `resolved_at`, `resolved_by_user_id`, `resolution_note`). El `WHERE status = 'open'` de `DatabaseIncidentLedger` es del código, no del esquema.
- **Borrar filas de las seis tablas**, porque el `DELETE` se conserva para la purga hasta §3.

Lo de `shift_entries` lo detecta la conciliación de §4 —la diaria dentro de su ventana, la semanal sobre todo el registro—, que compara marcas, estado y `superseded_by` con el último asiento `shift_entry.*`, salvo que la escritura vaya acompañada de asientos falsificados (Consecuencias, primer punto). **La conciliación no cubre `incidents`**: de una resolución reescrita solo queda, intacto, el asiento `incident.resolved` original en `audit_log`, y nada lo compara con la fila. La migración `2026_10_13_100000` lo dice en su docblock con las mismas palabras.

## Implementación de §2 y §3 (bloque 7 de la 2.2.1, 13-10-2026)

Implementa `backend-laravel` con la skill `migracion-segura`; la comprobación del conjunto de privilegios en `update.sh` (`infra/scripts/lib/work-record-privileges.sh`, `DELETE` a `false` en las siete tablas) es de `devops-observabilidad`. Los *triggers*, la función de purga y la retirada del `DELETE` viven en la migración `2026_10_14_100000_work_record_trigger_and_purge_function`. Tras las revisiones del bloque, dos migraciones la acompañan porque no pueden ir en su transacción: `2026_10_14_100100_restrict_user_keys_on_incidents` (claves de `incidents` hacia `users` a `RESTRICT`, con `VALIDATE` fuera de la transacción) y `2026_10_14_100200_unique_superseded_by_id_on_shift_entries` (índice único parcial `CONCURRENTLY`, que sustituye al no único `shift_entries_superseded_by_id_index`). `product:doctor` (`database.work_record_privileges`) avisa además si falta o está desactivado un *trigger* de `shift_entries` o `incidents`, si la función ya no es `SECURITY DEFINER` con `search_path`, o si la aplicación recupera `DELETE`.

### §2 tal y como se ha implementado

`shift_entries_guard_transition`, `BEFORE UPDATE … FOR EACH ROW`, y `shift_entries_superseded_is_linked`, `CONSTRAINT TRIGGER … DEFERRABLE INITIALLY DEFERRED`. Funciones propiedad del rol de migración, `search_path = pg_catalog, pg_temp`, sin `EXECUTE` para `PUBLIC`. Rechazan con `42501` y un mensaje que nombra este ADR. El rol de migración también pasa por ellos: los puede desactivar porque es su propietario, y hacerlo es un acto explícito.

| Regla | Lo que admite |
|---|---|
| Columnas | Solo cambian `clocked_out_at`, `duration_minutes`, `status`, `clock_out_source`, `superseded_by_id` y `updated_at`. Se compara la fila entera menos esas seis, así que una columna que se añada mañana nace inmutable |
| `status` | Igual que estaba, siempre (el `upsert` de la jornada reescribe con los mismos valores los tramos que no cambian). `open` → `closed`, `anomalous`, `superseded` o `voided`; `closed` o `anomalous` → `superseded` o `voided`. `superseded` y `voided` son finales. `closed` ↔ `anomalous` no: el dominio solo marca la anomalía al cerrar |
| Salida | `clocked_out_at`, `duration_minutes` y `clock_out_source` solo cambian al cerrar: de `open` a `closed` o `anomalous`, con la salida anterior nula y la nueva no nula. Cerrar sin hora de salida tampoco se admite |
| `superseded_by_id` | Una sola vez, de nulo a valor, en una fila cuyo estado es `superseded`, y apuntando a una fila del **mismo empleado, la misma `work_date` y `version + 1`** (ADR-035). No hay columna de linaje: el esquema no distingue la versión siguiente de *ese* tramo de otro tramo del mismo día con esa versión. Que dos filas no apunten a la misma lo garantiza el índice único parcial `shift_entries_superseded_by_id_unique` (migración `2026_10_14_100200`, `CONCURRENTLY`), no una consulta en el *trigger*, que no vería la fila sin confirmar de otra transacción |
| Al insertar | `shift_entries_guard_insert`, `AFTER INSERT … FOR EACH ROW`: una fila nace vigente, nunca `superseded` ni `voided` ni con `superseded_by_id`. `AFTER` porque el `upsert` de la jornada propone `superseded` para la versión retirada, que ya existe, y un `BEFORE INSERT` se dispara también cuando `ON CONFLICT` acaba en `UPDATE`. **Con esto, el estado final solo se alcanza por las transiciones de la tabla**: no se puede insertar ya sustituido o anulado |
| Al confirmar | Un tramo que una sentencia de la transacción dejó en `superseded` tiene que tener ya su `superseded_by_id`. Diferido porque `EloquentWorkDayRepository::save` retira la versión antigua, inserta la nueva y solo entonces las encadena (es una clave ajena) |

Se han contrastado con el dominio y el código: `WorkDay::clockOut` (cerrar y, si procede, marcar anómalo en la misma escritura), `correctEntry` (retirar y encadenar, desde `open`, `closed` o `anomalous`), `voidEntry` (anular sin encadenar, desde los mismos tres), `linkSupersededEntries` y el `upsert` de `persistEntries`, además de `CorrectionSeeder`. Las suites Feature e Integration, que corren con `fichaje_app`, los ejercitan de punta a punta.

### §2 se extiende a `incidents`

La resolución de una incidencia cerrada era el otro residuo de filas del bloque 6, y la conciliación de §4 no la cubre. El dominio solo deja cerrar una incidencia una vez (`Incident::resolvedBy` lanza `IncidentAlreadyClosed`) y `DatabaseIncidentNotices` sella el primer aviso y no lo vuelve a tocar, así que el esquema puede decir lo mismo. `incidents_guard_transition`, `BEFORE UPDATE … FOR EACH ROW`:

- solo cambian `status`, `resolved_at`, `resolved_by_user_id`, `resolution_note`, `notified_at` y `updated_at` (fila entera menos esas seis);
- `status` solo pasa de `open` a `resolved` o `dismissed`, y las tres columnas de la resolución solo cambian en esa transición;
- `notified_at` solo pasa de nulo a un instante. `markNotified` filtra ya `notified_at IS NULL`: si dos pasadas recogieran la misma incidencia, la segunda no actualiza nada en vez de abortar.
- y, al insertar (`incidents_guard_insert`, `AFTER INSERT`), una incidencia nace `open`, sin `resolved_at`, `resolved_by_user_id`, `resolution_note` ni `notified_at`, que es como la inserta `DatabaseIncidentLedger`: no se puede insertar ya resuelta.

**Consecuencia: borrar una cuenta de gestión con incidencias deja de ser posible** (revisión de `arquitecto-dominio`, 13-10-2026). `assigned_to_user_id` y `resolved_by_user_id` son claves ajenas `ON DELETE SET NULL` hacia `users`, y la acción referencial es un `UPDATE` sobre `incidents` que pasa por este *trigger*: cambia una columna que no está entre las seis, o la resolución de una incidencia cerrada, y el `DELETE` de la cuenta aborta con `42501`. Ningún código de la aplicación borra cuentas (se desactivan), así que hoy no rompe nada, y el efecto es el que pide RN-13: que se pierda quién resolvió una incidencia era un borrado de traza por la puerta de atrás. Pero el mensaje engañaba y la regla debía decirla el esquema: **las dos claves pasan a `RESTRICT`** en la migración `2026_10_14_100100_restrict_user_keys_on_incidents`, como el resto del registro, y borrar una cuenta que figura en una incidencia falla con `23503` y el nombre de la restricción.

### §3 tal y como se ha implementado

`public.work_record_purge_batch(p_cutoff date, p_batch integer) RETURNS TABLE (dataset text, deleted bigint)`, `SECURITY DEFINER`, propiedad del rol de migración, `SET search_path = pg_catalog, pg_temp`, `SET lock_timeout = '5s'`, `EXECUTE` solo para `fichaje_app`.

- **Su corte máximo** es la fecha UTC de `now()` del servidor de base de datos menos los años efectivos, con la aritmética de `RetentionPolicy::workRecordCutoff()` (un 29 de febrero menos un año es el 1 de marzo, como en PHP; `public.work_record_purge_max_cutoff`). **Los años efectivos** son, por centro, el mayor entre `retention_years` de su perfil —o del perfil por defecto si no tiene— y el suelo de su jurisdicción; entre centros, el mayor; sin centro, el perfil por defecto. Un corte posterior se rechaza con `42501`; un lote fuera de `1..100000` o un corte nulo, con `22023`; la falta de perfil, con `P0002`.
- **El suelo llega a SQL como `CASE`** en la función inmutable `public.work_record_retention_floor(text)` (el país antes del guion; `default` para los que no están), escrita por la migración desde una copia del catálogo `compliance.retention_floors`. Es el mismo patrón que el `CHECK` de §5: el dato está en dos sitios y `WorkRecordPurgeFunctionTest` los ata (cada país del catálogo, una subdivisión suya y uno que no está). Añadir un país al catálogo sin una migración que reescriba la función pone la prueba en rojo. No se eligió una tabla: tendría que ser igual de inalcanzable para la aplicación, no aporta nada que la función no dé y sería otra relación que vaciar y restaurar en las pruebas.
- Vacía lo vencido de cada tabla en el orden de `DatabaseWorkRecordArchive` (correcciones, incidencias, escaneos, avisos descartados, tramos por generaciones, totales), en sentencias de `p_batch` filas como mucho. **Precisa §3:** `p_batch` acota cada sentencia, no la llamada. Borrar «un lote de cada tabla» por llamada obligaba a repetir en cada una las consultas de las tablas ya vacías, y con un año de 500 personas (750 300 filas) la purga tardaba 90–107 s frente a los 51,5 s de los `DELETE` de antes; así tarda 39–53 s. `DatabaseWorkRecordArchive::purge` la llama una vez dentro de la transacción del asiento, y no la llama si `inspect()` no encuentra nada vencido.

  **Por qué es una precisión y no un cambio de decisión** (revisión de `arquitecto-dominio`, 13-10-2026). El lote nunca fue un punto de confirmación: §3 ya exigía que todo fuera en la transacción del asiento, y así era también con los `DELETE` de la aplicación. Lo que se borra, las filas que quedan bloqueadas hasta el `COMMIT`, el WAL que se escribe y el tamaño de la transacción son los mismos en las dos formas; lo único que cambia es cuántas veces se vuelve a la aplicación y se repiten las consultas. La atomicidad es la misma (o se borra todo y queda el asiento, o nada), el corte máximo se calcula una vez por transacción como antes —`now()` es el instante de inicio de la transacción— y `lock_timeout` sigue valiendo para cada bloqueo que pida la función. `p_batch` acota la memoria de cada `IN`, no la duración. Dos consecuencias que sí cambian y se aceptan:
  - **Para `statement_timeout`, la purga entera es una sola sentencia.** Hoy ninguna conexión de la aplicación lo fija de forma global (solo lo usan las consultas de informes, cada una el suyo). Si un operador lo fijara en el rol o en la base de datos por debajo de lo que tarda la purga, esta fallaría entera y sin asiento, nunca a medias; el remedio es purgar con el valor de sesión adecuado, no partir la transacción.
  - **La transacción es tan grande como lo vencido.** Con la purga anual son unas 750 000 filas por cada 500 personas; si una instalación lleva varios años sin purgar, el atraso entero va en una transacción. Se acepta porque la purga no es automática (RF-PR-03), las filas que borra tienen más de cuatro años y ningún fichaje ni proyección vigente las toca, así que no compite por bloqueos con el quiosco ni necesita ventana de parada. Si algún día una instalación necesitara purgar un atraso grande en varias transacciones, cada una tendría que llevar su propio asiento: eso sí sería un cambio de §3 y pediría enmienda.
- `fichaje_app` pierde `DELETE` sobre `shift_entries`, `shift_corrections`, `scan_events`, `discarded_scan_reports`, `incidents`, `daily_totals` y `sites`.

### Lo que esto no impide, con §1–§3 en vigor

Con la credencial del runtime todavía se puede:

- **Insertar un tramo vigente falso o cerrar uno abierto con una hora falsa.** Son las escrituras del fichaje. Lo detecta §4. Lo que ya no se puede es insertar un tramo directamente `superseded` o `voided` o con `superseded_by_id`, ni una incidencia ya resuelta o avisada.
- **Anular un tramo vigente** (`open`, `closed` o `anomalous` → `voided`) o sustituirlo por una versión siguiente inventada. Son transiciones del dominio y el motor no sabe quién las pide; §4 compara estado y `superseded_by` con el último asiento `shift_entry.*` y lo detecta, salvo que vaya acompañado de asientos falsificados (Consecuencias, primer punto).
- **Cerrar una incidencia abierta con una resolución falsa.** Es la transición legítima. §4 no cubre `incidents`; lo que queda es la ausencia del asiento `incident.resolved` que la acompañaría.
- **Bajar `retention_years` hasta el suelo y purgar dentro de él**, como ya decía la enmienda del 13-10-2026 (§7).
- **Purgar antes de tiempo con el reloj del servidor de base de datos adelantado.** La función confía en `now()` de PostgreSQL, que la aplicación no controla; un reloj mal puesto mueve el corte, y se acepta. Uno atrasado solo hace que la purga se niegue.
- **Llamar a `work_record_purge_batch` fuera de `compliance:apply-retention`.** La aplicación tiene `EXECUTE` y la función no escribe asiento: purga solo lo vencido, pero sin `retention.purge_executed`. En `shift_entries` lo ve §4 como `audit_without_entry` (asientos de tramos que ya no están y que ninguna purga asentada explica); en `scan_events`, `discarded_scan_reports`, `incidents` y `daily_totals` no lo ve nadie.

Quien tenga la credencial del rol de migración es propietario de las tablas y de los *triggers*: los puede desactivar y borrar (saltarlos con `session_replication_role` exige superusuario). Esa credencial no llega a ningún contenedor de runtime (ADR-042). Con la del runtime, `DISABLE TRIGGER`, `DROP TRIGGER`, `session_replication_role` y reescribir las funciones dan `42501` (`WorkRecordTransitionsTest`).

### Verificación del bloque 7

- `tests/Integration/Schema/WorkRecordTransitionsTest.php`: cada transición prohibida de `shift_entries` y de `incidents` da `42501` con el rol de la aplicación (y con el propietario en las columnas que la aplicación no tiene); cada transición del dominio pasa; la sustitución sin versión nueva no llega a confirmarse; insertar una fila terminal da `42501`; dos versiones hacia la misma sustituta dan `23505`; borrar una cuenta con incidencias da `23503`; la aplicación no puede desactivar, soltar ni reescribir los *triggers*.
- `tests/Integration/Schema/WorkRecordPurgeFunctionTest.php`: `EXECUTE` solo para la aplicación (ni `PUBLIC` ni mantenimiento), corte posterior rechazado, también con `retention_years` por debajo del suelo, suelo atado al catálogo, la misma aritmética que `RetentionPolicy`, recuentos por lote y por generaciones, una tabla temporal no la desvía, y `down()`/`up()` reversibles.
- `tests/Feature/Compliance/ApplyRetentionCommandTest.php`: `compliance:apply-retention` de punta a punta con su asiento, y sin borrar ni asentar nada si el reloj de la aplicación pide un corte que la función no admite.
- `tests/Integration/Schema/WorkRecordPrivilegesTest.php`: `DELETE` da `42501` en las siete tablas.

## Enmienda del 18-10-2026 (ADR-058, bloque 1 de la 2.3.0)

[ADR-058](ADR-058-como-termino-un-tramo-es-un-hecho-del-registro.md) añade a `shift_entries` una **séptima columna que el runtime escribe**, `closed_by` (`clock_out` | `break_start`): cómo terminó el tramo. Decide si el tramo del día civil siguiente continúa la jornada (RN-05), así que se protege como la salida.

### §1, con la séptima columna

| Tabla | `UPDATE` de `fichaje_app` |
|---|---|
| `shift_entries` | **solo** `clocked_out_at`, `duration_minutes`, `status`, `clock_out_source`, `closed_by`, `superseded_by_id`, `updated_at` |

El `INSERT` es de tabla y ya cubre la columna. La lista de `infra/scripts/lib/work-record-privileges.sh` lleva `closed_by`, de modo que `update.sh` y `doctor.sh` la exigen y la reparan igual que las otras seis.

### §2, con la séptima columna

`closed_by` entra en el grupo de la salida: **solo cambia al cerrar un tramo abierto**, de `open` a `closed` o `anomalous`, junto con `clocked_out_at`. El *trigger* rechaza con `42501` reescribirla en un tramo cerrado o anómalo, ponerla en uno abierto sin cerrarlo y escribirla al anular un abierto. Un `CHECK` (`shift_entries_chk_closed_by`) añade la invariante: nula si y solo si el tramo sigue abierto, y solo los dos valores. Durante la fase *expand* un *trigger* `shift_entries_closed_by_default` pone `clock_out` a una salida escrita sin cierre (ver ADR-058 §4); corre antes que la guarda y no la rodea.

### §4, con la séptima columna

La conciliación compara `closed_by` con la `action` del asiento `shift_entry.closed` (`clock_out` o `break_start`) o con el `after.closed_by` de la última corrección. Las correcciones anteriores a la 2.3.0 no lo apuntaban: en ellas no se compara. Una discrepancia sale como `entry_differs_from_audit · closed_by`.

### Lo que esto no impide, con la séptima columna

- **Cerrar un tramo abierto en pausa en vez de en salida**, con la credencial del runtime y sin pasar por el dominio. Es la misma escritura que el fichaje necesita, como cerrar con una hora falsa, y la detecta §4 en cuanto no cuadra con el asiento del cierre.
- **Declarar a mano una pausa que no hubo**, por el camino legítimo. Queda firmada, con motivo, versión nueva y asiento (RN-13): no es una manipulación del registro sino una afirmación de quien corrige.

## Nota del 18-10-2026 (2.3.0, bloque 2): el mismo patrón en `sites`, y §4 compara la zona del centro

**No cambia ninguna decisión de este ADR: aplica su patrón a otra tabla.** La enmienda del 18-10-2026 de [ADR-056](ADR-056-la-zona-horaria-del-centro-queda-fija-desde-el-primer-fichaje.md) lleva RN-23 al motor con el mismo diseño que §2 y §3:

- un *trigger* (`zz_sites_guard_timezone`) que rechaza con `42501` cambiar `sites.timezone` con tramos;
- una función `SECURITY DEFINER` (`site_correct_timezone`), propiedad del rol de migración, con `search_path` fijo y `EXECUTE` solo para la aplicación, como única puerta para la corrección de consola;
- y una traza solo-append (`site_timezone_changes`), propiedad del rol de migración, que solo escribe esa función y que la aplicación solo puede leer.

§7 no cambia: la aplicación conserva `UPDATE (name, timezone)` sobre `sites`, porque el cambio de antes del primer fichaje lo necesita.

**§4 gana una sexta clase, `site_timezone_differs_from_audit`.** Agrupa tres comprobaciones, y cada una sale con su campo:

- `timezone`: la zona de `sites` no es la del último asiento `site.created` o `site.updated`;
- `site_timezone_changes`: una fila de la traza no tiene su `site.updated` firmado;
- `correction`: un `site.updated` cambia la zona después del primer tramo sin firma.

Cubren lo que el *trigger* no impide: llamar a la función fuera de la orden de consola, que no escribe asiento (igual que `work_record_purge_batch` fuera de `compliance:apply-retention`), un `UPDATE` sin tramos por fuera del producto y un asiento de cambio que el panel no pudo escribir. Lo que no se puede cotejar —un centro sin asiento `site.*`, o filas de la traza de años cuya auditoría se purgó— se cuenta como no verificable (`work_record_reconciliation_unverifiable`) y no es discrepancia. El límite general de §4 vale también aquí: una fila acompañada de un asiento firmado falsificado cuadra. La alerta `DiscrepanciaEntreRegistroYAuditoria` ya suma todas las clases; solo cambia su texto. El runbook `discrepancia-registro-auditoria.md` §3 dice cómo se resuelve.

Dos precisiones de la revisión del bloque: lo anterior a la línea base que fija la migración (`site_timezone_audit_baseline`, el último asiento al aplicarla) se escribió con las reglas de antes y cuenta como no verificable. Y una discrepancia de la traza o de un asiento sin firma se puede dar por revisada con `--acknowledge-site-change` (asiento `site.timezone_discrepancy_reviewed`), con el mismo patrón que §6: sale de las pendientes, pasa a una métrica de revisadas y dispara `DiscrepanciaDeZonaRevisada`.

La traza y su asiento se emparejan **por identidad**: la función devuelve el `id` de la fila y la orden lo guarda en el asiento (`site_timezone_change_id`). Una revisión de una fila solo cuenta si es posterior al último asiento que había al escribirla. Las revisiones se publican por tipo: la de una fila dispara `CambioDeZonaRevisado`, crítica y de integridad, como `PurgaRevisadaQueExplicaBorrados`; la de un asiento sin firma, `DiscrepanciaDeZonaRevisada`.

## Nota del 21-10-2026 (2.3.0, bloque 11): §2 y §3 se aplican también a `employees` y `users`

**No cambia ninguna decisión de este ADR: aplica su patrón a dos tablas más.** [ADR-060](ADR-060-al-vencer-su-plazo-la-ficha-y-la-cuenta-de-gestion-se-minimizan-en-sitio-con-asiento.md) minimiza en sitio la ficha de la persona y la cuenta de gestión cuando vence su plazo, y lo hace con las mismas dos piezas:

- **Como §3:** dos funciones `SECURITY DEFINER` (`personnel_record_anonymize_batch` y `management_account_anonymize_batch`). Son propiedad del rol de migración, llevan `search_path = pg_catalog, pg_temp` y `lock_timeout`, solo `fichaje_app` tiene `EXECUTE` y **calculan ellas mismas su corte máximo** desde el perfil de cumplimiento, rechazando uno posterior con `42501`. Además comprueban que ya no queda registro horario de la persona ni la partición de auditoría del año de su baja, y que ningún asiento vivo cita a la cuenta.
- **Como §2:** dos *triggers* `BEFORE UPDATE` (`employees_guard_anonymized` y `users_guard_anonymized`) que congelan la fila minimizada y solo dejan escribir `anonymized_at` al propietario.
- **Como §1:** `fichaje_app` pierde `DELETE` sobre `employment_contracts` y `absences`, que solo borra la función de la ficha.

Cada lote de la minimización va en su propia transacción **con sus propios asientos** (`retention.personnel_record_anonymized`, `retention.management_account_anonymized`). Es la condición que §3 («tal y como se ha implementado») pone a cualquier purga partida en varias transacciones.

El residuo es el mismo que el de `work_record_purge_batch`. Con la credencial del runtime se puede llamar a la función fuera de `compliance:apply-retention`: minimiza solo lo elegible, pero sin asiento. La conciliación de §4 todavía no lo detecta. Que cuente como discrepancia una ficha minimizada sin su asiento, o un asiento sin su ficha, queda recomendado para otro bloque (ADR-060, Consecuencias).

## Nota del 25-10-2026 (2.3.0, bloque 19): §2 vigila también la duración con la que nace un tramo

**No cambia ninguna decisión de este ADR: cierra un hueco de §2.** §2 congela `duration_minutes` desde que el tramo se cierra, pero no comprobaba **con qué valor** se escribía la primera vez. Con la credencial del runtime se podía insertar o cerrar un tramo con una duración que no cuadra con sus marcas. `daily_totals` se recalcula desde las marcas (ADR-007) y la exportación legal suma la columna guardada, así que ese tramo hacía que el registro se contradijera a sí mismo (tarea 19.6, «se pasó por alto» de la tanda 3 de la 2.2.1).

- **Un *trigger* más, con la misma función para dos momentos:** `shift_entries_guard_duration_insert` (`AFTER INSERT`) y `shift_entries_guard_duration_close` (`AFTER UPDATE … WHEN (OLD.clocked_out_at IS NULL)`). Los dos rechazan con `23514` una fila cuya duración no es `div(floor(epoch(salida)) − floor(epoch(entrada)), 60)`, o que tiene duración sin salida o salida sin duración. Es la cuenta de `ElapsedMinutes::between()` (RN-09), y `ShiftEntryDurationGuardTest` las ata con las marcas que separan las dos formas de redondear (migración `2026_10_25_100000`).
- **Un *trigger* y no un `CHECK`.** Las filas anteriores no se reescriben (regla dura 5), y una descuadrada es la evidencia que §4 tiene que encontrar. Un `CHECK` validado haría fallar la actualización de una instalación con una sola de esas filas. Uno `NOT VALID` rige en cada `UPDATE`, y el `upsert` de `EloquentWorkDayRepository` reescribe en cada fichaje los tramos cerrados de la jornada, así que un tramo antiguo descuadrado bloquearía los fichajes de ese día.
- **Lo que queda:** las filas escritas antes de esta nota siguen como están. El repositorio conserva su duración guardada (el `CASE` de §2) y §4 la denuncia en el campo `duration_minutes` cuando no cuadra con las marcas auditadas. Ninguna fila nueva puede ya divergir.

## Nota del 27-10-2026 (2.3.0, bloque 20): la tabla `migrations` en §1

**No cambia ninguna decisión: recoge en §1 un privilegio que ya está en vigor** (V6-AR-5 de la verificación final de la 2.2.0). La tabla de §1 enumeraba las tablas del registro horario y dejaba fuera `migrations`. De ella dependen ADR-054 y la propia puesta en marcha de este ADR. Quien escribe en `migrations` decide qué ejecuta el migrador en la actualización siguiente. Si inserta el nombre de una migración futura, sus `REVOKE` no llegan a aplicarse. Si borra una fila, se repite una migración.

| Tabla | `SELECT` | `INSERT` | `UPDATE` | `DELETE` |
|---|---|---|---|---|
| `migrations` | sí | **no** | **no** | **no** |

- **Dueño:** el rol de migración (`fichaje_migrator`, ADR-033 y ADR-042). Es el único que escribe en ella, desde el servicio `migrate`. El runtime solo la lee. Tampoco tiene `TRUNCATE`.
- **Dónde se aplica:** en la migración `2026_10_09_100000_revoke_application_writes_on_migrations_table` y en la de provisión de privilegios (`2026_08_19_099000`), para que volver a ejecutar esta última no devuelva el privilegio.
- **Dónde se comprueba:** `update.sh` (`verify_privileges … migrations`) rechaza que el rol de la aplicación tenga `INSERT` o `UPDATE`, por tabla o por columna, o `DELETE` o `TRUNCATE` sobre `migrations`, y lo repara antes de seguir (`repair_migrations_privileges`, V4-SC-1).
- **Prueba:** `backend/tests/Integration/Schema/MigrationsTablePrivilegesTest.php`.

## Enmienda del 27-10-2026 (2.3.0, bloque 20): §2 admite que el sistema reabra lo que el sistema cerró (RN-19)

**Aprobada por el propietario el 27-10-2026.** Propone `arquitecto-dominio`; la implementa el mismo agente con la skill `migracion-segura` (migración `2026_10_27_100100_allow_system_reopening_of_rejected_pin_scan`).

### El problema

Desde el bloque 20, una `rejected_pin_scan` se cierra sin persona cuando la jornada queda subsanada (RN-19): `resolved`, sin `resolved_by_user_id` ni `resolution_note`, con asiento `incident.resolved` de actor `system`. Pero la subsanación puede llegar antes que otro intento de la misma jornada. Con dos quioscos sin red, el intento de las 08:00 se abre, su subsanación llega y la cierra, y después sincroniza un tercer quiosco con un intento de las 22:00 que nadie subsanó. La revisión lo ve como hallazgo de la misma jornada, `one_incident_per_finding` choca con la incidencia cerrada y el `ON CONFLICT DO NOTHING` lo descarta. **Un fichaje real que puede faltar del registro desaparece sin que nadie lo vea**, que es exactamente lo que RN-19 existe para impedir. Y no hay otra fila donde ponerlo: la restricción no es parcial a propósito (una incidencia trabajada no revive) y la jornada es la granularidad de RN-19.

### Decisión

`incidents_guard_transition` admite **una sola transición más**, y nada más cambia:

| Regla | Lo que admite |
|---|---|
| `status` | `resolved` → `open`, **solo** si `type = 'rejected_pin_scan'`, `OLD.resolved_by_user_id IS NULL` y `OLD.resolution_note IS NULL`, es decir, si la cerró el sistema |
| Resolución | En esa transición, `resolved_at`, `resolved_by_user_id` y `resolution_note` quedan nulos (el `CHECK` `incidents_chk_resolution_is_complete` dice lo mismo para una abierta) |
| `context` | Pasa a ser la séptima columna que cambia, **y solo en esa transición**: el hallazgo nuevo trae los intentos sin subsanar de ahora, y la incidencia los tiene que contar. En cualquier otra escritura `context` sigue inmutable, también para el propietario |
| Lo demás | Igual que antes: `type`, `severity`, `work_date`, `detected_at`, `assigned_to_user_id` y el resto no cambian; `notified_at` solo de nulo a un instante |

- **Privilegio:** `GRANT UPDATE (context) ON incidents TO fichaje_app`. La columna se concede porque la aplicación la escribe; quien decide cuándo puede cambiar es el *trigger*, no el privilegio. `update.sh` y `doctor.sh` (`infra/scripts/lib/work-record-privileges.sh`) la exigen con la migración aplicada, y exigen que la función del *trigger* sea la que admite la reapertura; `product:doctor` también.
- **Lo que resolvió o descartó una persona no se reabre nunca**, tampoco desde el esquema: `resolved_by_user_id` o `resolution_note` no nulos, o `dismissed`, dan `42501`. Ningún otro tipo se reabre, porque ningún otro se cierra sin persona.
- **El dominio dice lo mismo:** `Incident::reopenedBySystem()` solo reabre una `rejected_pin_scan` resuelta sin autor ni nota. La revisión diaria (`DetectAttendanceAnomalies`) publica, después del hallazgo, `RejectedPinScanRelapsed` cuando la jornada sin subsanar tiene, entera, algún intento subsanado; en `Compliance`, `ReopenRecoveredIncident` —el gemelo de `CloseRecoveredIncident`— la reabre con `UPDATE … WHERE status = 'resolved' AND resolved_by_user_id IS NULL AND resolution_note IS NULL` (si una persona la trabajó en medio, no escribe) y deja en la misma transacción el asiento `incident.reopened` de actor `system`, con el contexto anterior y el nuevo, `attempt_scan_ids` y `recovering_scan_ids`. La pasada cuenta los anuncios, no las reaperturas (`relapsed_work_days`), como ya hacía con los cierres.
- **`detected_at` no cambia**: la incidencia se detectó cuando se detectó. El momento de la reapertura está en el asiento y en `updated_at`.

### Alternativa descartada

**Abrir una incidencia nueva para el intento tardío** —una restricción parcial sobre las abiertas, o el `scan_id` del intento en la clave—. Rompe la granularidad «una por persona y jornada» de RN-19 y deja a la vez una cerrada y una abierta sobre el mismo hecho. Y, sobre todo, una restricción parcial reabre la puerta que `one_incident_per_finding` cierra: la incidencia que descartó una persona volvería a la bandeja cada noche mientras su jornada siguiera en la ventana. También se descartó **no hacer nada y avisar en el log**: un fichaje que falta del registro no se puede quedar en un log técnico.

### Lo que esto no impide

- **Reabrir con un contexto falso** una `rejected_pin_scan` cerrada por el sistema, con la credencial del runtime. Es la transición legítima, como cerrar una abierta con una resolución falsa. Lo que queda es la ausencia del asiento `incident.reopened`, y §4 no cubre `incidents`.
- **La reapertura no vuelve a avisar por correo.** El primer aviso no se reescribe (`notified_at` solo de nulo a un instante), así que la incidencia reabierta vuelve a la bandeja y a `incidents_open`, pero no sale en el resumen de la noche. Se acepta: el responsable ya recibió el aviso de esa jornada y la incidencia está donde la busca.

### Verificación

- Integración: `tests/Integration/Schema/IncidentReopeningGuardTest.php` (la reapertura del sistema pasa con el rol de la aplicación; reabrir una resuelta por una persona, una descartada o una de otro tipo, cambiar `context` sin reabrir o dejar la resolución puesta da `42501`; el `down()` no deja a medias una reapertura ya hecha) y `WorkRecordPrivilegesTest`.
- Feature: `tests/Feature/Attendance/RejectedPinScanReopeningTest.php`, con el escenario de los tres quioscos.
- Unitarias: `tests/Unit/Compliance/Domain/IncidentReopeningTest.php`, `tests/Unit/Attendance/Domain/Event/RejectedPinScanRelapsedTest.php` y la recaída en `tests/Unit/Attendance/Application/RejectedPinScanDetectionTest.php`.
- Vigilancia: `WorkRecordPrivilegesUpdateScriptTest`, `WorkRecordPrivilegesDoctorCheckTest` y `WorkRecordGuardListsParityTest` (la función que admite la reapertura y `UPDATE (context)` se exigen con la migración aplicada, y no sin ella).
