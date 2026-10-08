# ADR-033 — Tres roles de base de datos, no dos

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 19 de agosto de 2026 |
| **Decide** | `backend-laravel` (tarea 1.14), ratificada al cierre de la Fase 1 |
| **Afecta a** | [ADR-027](ADR-027-audit-log-particionado.md) · [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) · Regla dura 6 de `CLAUDE.md` · `infra/compose.dev.yaml`, `infra/compose.prod.yaml` |
| **Requisitos** | RS-07, RS-08 |
| **Enmiendas** | Enmendada por [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (29-09-2026): consecuencia 3 (dónde vive la credencial del migrador) y rol nuevo `fichaje_backup` |

## Contexto

[ADR-027](ADR-027-audit-log-particionado.md) fija, en sus consecuencias: *«`docker-compose` y el instalador provisionan **dos** roles de base de datos, no uno. El de mantenimiento no aparece en el `.env` de la aplicación»* — el rol de aplicación (`fichaje_app`) y el rol de mantenimiento (purga de particiones).

Al implementar la tarea 1.14 apareció un problema que ADR-027 no había anticipado: en el Compose existente, `fichaje_app` **era también** `POSTGRES_USER`, es decir, el superusuario de arranque del clúster de PostgreSQL. Un superusuario no está sujeto a comprobación de privilegios — el `REVOKE UPDATE, DELETE ON audit_log` de la regla dura 6 se ejecutaba sin error y no impedía nada, y además era propietario de las tablas, con lo que podría volver a otorgarse cualquier permiso revocado.

La solución obvia — quitarle el atributo `SUPERUSER` al rol de arranque — no es viable: PostgreSQL 16+ exige que el superusuario de arranque del clúster conserve ese atributo (`the bootstrap superuser must have the SUPERUSER attribute`). No se puede degradar in situ.

## Decisión

**Se provisionan tres roles, no dos:**

| Rol | Función | Privilegios | Dónde vive su credencial |
|---|---|---|---|
| `fichaje_migrator` | `POSTGRES_USER` de arranque. Propietario de las tablas. Ejecuta las migraciones | Único con DDL | `.env`, `DB_MIGRATION_*` — separado de la conexión de runtime |
| `fichaje_app` | Conexión de runtime de la aplicación | Sin DDL, sin `CREATEDB`/`CREATEROLE`/`REPLICATION`. Sobre `audit_log`: solo `INSERT` y `SELECT` | `.env`, `DB_*` |
| `fichaje_maintenance` | Purga de particiones (tarea 2.10) | `SELECT` sobre `audit_log`, `INSERT` en `audit_chain_anchors`, `DROP` de partición | No aparece en el `.env` de la aplicación, tal como ya fijaba ADR-027 para el rol de mantenimiento |

El tercer rol no es una alternativa a los dos de ADR-027: es la pieza que faltaba para que el rol de aplicación deje de ser, también, el propietario/superusuario. Sin `fichaje_migrator`, no hay ningún rol que pueda ejecutar `CREATE TABLE`/`ALTER TABLE` sin que ese mismo rol tenga que ser el runtime de la aplicación — que es exactamente la situación que ADR-010 y la regla dura 6 existen para evitar.

Laravel usa una conexión de base de datos distinta para migraciones (`pgsql_migrator`, `fichaje_migrator`) que para runtime (`pgsql`, `fichaje_app`). El comando `php artisan migrate` se ejecuta explícitamente contra la conexión de migración; una migración se niega a correr si detecta que el rol activo es el de aplicación o que ese rol es superusuario.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Dejar `fichaje_app` como `POSTGRES_USER` y aplicar `REVOKE` de todos modos** | Decorativo: un superusuario ignora `REVOKE`. La regla dura 6 dejaría de ser una garantía real y pasaría a ser una declaración de intenciones |
| **Degradar el rol de arranque quitándole `SUPERUSER`** | No lo permite PostgreSQL 16+ para el rol de arranque del clúster |
| **Un solo rol de aplicación con DDL limitado por `search_path` o esquema separado** | No resuelve el problema de fondo: seguiría siendo el propietario de `audit_log`, con capacidad de recrear la tabla sin las restricciones de permisos |

## Consecuencias

- `infra/compose.dev.yaml` y `infra/compose.prod.yaml` arrancan el clúster con `POSTGRES_USER=${DB_MIGRATION_USERNAME:-fichaje_migrator}`, no con `fichaje_app`.
- `infra/docker/postgres/initdb/02-application-roles.sh` provisiona los tres roles de forma idempotente, incluida la ruta de actualización de un clúster ya existente (donde `fichaje_app` era el rol de arranque): aparta ese rol con otro nombre, crea `fichaje_app` de cero sin `SUPERUSER`, y traslada la propiedad de los objetos de `public`.
- Las credenciales de migración y las de runtime viven en el mismo `.env` por defecto. Esto protege el camino de la **aplicación** (una inyección, un endpoint comprometido) pero no protege contra quien ya tiene acceso al fichero de entorno del servidor. En producción, `install.sh` puede mantener `DB_MIGRATION_*` fuera del entorno de los contenedores de runtime y pasarlas solo al desplegar — decisión de despliegue del cliente, no de esta ADR.

  > **Enmienda de [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (29-09-2026): esta consecuencia queda sustituida.** Dejarla en manos del cliente no protegía nada: `DB_MIGRATION_PASSWORD` llegaba por `env_file` a los cuatro contenedores de runtime, y el propio runtime usaba `pgsql_migrator` para crear la partición anual (hallazgo AUD-1). Ahora la separación la garantiza el producto:
  >
  > - `DB_MIGRATION_*` solo llega a los servicios de un solo uso `migrate` y `restore` (`profiles: [tools]`);
  > - la partición la crea una función `SECURITY DEFINER` que ejecuta el rol de aplicación;
  > - las copias las hace un cuarto rol de solo lectura, `fichaje_backup` (`LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE REPLICATION`, `GRANT pg_read_all_data`), cuya credencial conserva el `scheduler` junto a `BACKUP_ENCRYPTION_KEY`.
  >
  > El resto de esta ADR sigue vigente, y `fichaje_migrator` sigue siendo el rol de arranque, propietario y `SUPERUSER`.
- El texto de ADR-027 («dos roles, no uno») queda desactualizado en su literal de conteo; su decisión de fondo —rol de aplicación sin `UPDATE`/`DELETE` sobre `audit_log`, purga por `DROP PARTITION` con rol de mantenimiento separado— sigue vigente sin cambios.

## Verificación

- `psql -U fichaje_app -c "UPDATE audit_log SET action='x' WHERE id=1;"` falla con `permission denied for table audit_log`, y lo mismo para `DELETE` y `TRUNCATE`, y contra cualquier partición directamente.
- `php artisan migrate` ejecutado por el rol de aplicación falla explícitamente antes de tocar el esquema.
- Instalación limpia con `POSTGRES_USER=fichaje_migrator`: exactamente tres roles al terminar, un solo superusuario, `fichaje_migrator` como propietario de las cuatro bases.
- Actualización de un clúster donde `fichaje_app` era el rol de arranque: tras ejecutar el script de provisión, el mismo resultado que en instalación limpia.

## Enmienda 08-10-2026 (bloque 14 de la 2.2.0, hallazgo A6-4): los privilegios reales del rol de mantenimiento, y un cuarto rol

**Motivo.** La verificación de la 2.1.0 ([tanda 6](../verificacion/2.1.0-tanda-6.md), A6-4), confirmada en la re-verificación de la 2.2.0, encontró que la tabla de la decisión atribuye a `fichaje_maintenance` el «`DROP` de partición» como privilegio directo, y que su credencial «no aparece en el `.env`». Ninguna de las dos cosas describe lo implementado. Esta enmienda corrige la tabla. La decisión de fondo, separar la conexión de la aplicación del propietario de las tablas, no cambia.

- **`fichaje_maintenance` no tiene `DROP` sobre ninguna tabla**, porque solo un propietario puede soltar una partición y un propietario puede volver a darse lo que se le revoca. Lo que tiene es: `SELECT` sobre `audit_log`, `INSERT` en `audit_chain_anchors` y `EXECUTE` sobre `audit_log_drop_sealed_partition(integer)`, una función `SECURITY DEFINER` del rol de migración que solo suelta un año ya sellado ([ADR-027](ADR-027-audit-log-particionado.md), enmienda del 08-10-2026).
- **Su credencial no vive en ningún contenedor.** El `.env` declara `DB_MAINTENANCE_USERNAME`, pero `DB_MAINTENANCE_PASSWORD` va vacía a propósito y se aporta al ejecutar la purga manual. El instalador no la genera (`docs/runbooks/rotacion-secretos.md`).
- **La consecuencia 3** («las credenciales de migración y las de runtime viven en el mismo `.env` [...] decisión de despliegue del cliente») **ya estaba sustituida por [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md)**: `DB_MIGRATION_*` solo llega a los servicios de un solo uso `migrate` y `restore`.
- **Ya no son tres roles, sino cuatro.** ADR-042 añadió `fichaje_backup` (`pg_read_all_data` y `REPLICATION`, sin escritura) para que las copias no usen el superusuario. El título de este ADR se conserva porque su decisión era separar el rol de la aplicación del de arranque, y eso sigue igual.
- **El rol de la aplicación conserva `UPDATE` y `DELETE` sobre las tablas del registro horario** por los privilegios por defecto de la migración `2026_08_19_099000_provision_database_privileges`. Eso es lo que corrige [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md).
