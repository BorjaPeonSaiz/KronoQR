# ADR-042 — El runtime no tiene ninguna credencial que pueda alterar el registro

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 29 de septiembre de 2026 |
| **Decide** | `arquitecto-dominio` con `seguridad-cumplimiento` (Bloque 3 de la 2.2.0, hallazgo AUD-1) |
| **Afecta a** | Enmienda [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) (condición 1), [ADR-027](ADR-027-audit-log-particionado.md) (creación de la partición anual), [ADR-029](ADR-029-configuracion-en-el-entorno-del-contenedor.md) (inyección del `.env`) y [ADR-033](ADR-033-tres-roles-de-base-de-datos-no-dos.md) (consecuencia 3) · Regla dura 6 de `CLAUDE.md` · `infra/compose.prod.yaml`, `infra/compose.dev.yaml`, `infra/scripts/install.sh`, `infra/scripts/update.sh`, `infra/docker/postgres/initdb/02-application-roles.sh` |
| **Requisitos** | RS-07, RS-08, RL-04 |

## Contexto

La condición 1 de [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) dice que la aplicación **no puede** modificar `audit_log`: la garantía no es que no lo haga, sino que el motor se lo impide. La verificación de la 2.1.0 (hallazgo AUD-1, [tanda 4](../verificacion/2.1.0-tanda-4.md), reclasificado 🔴 CRÍTICO en la [tanda 7](../verificacion/2.1.0-tanda-7.md)) demostró que esa frase era falsa por dos vías:

1. **Por el entorno.** Los contenedores `app`, `horizon`, `reverb` y `scheduler` —y `nginx`— recibían `env_file: .env` completo. Con él llegaban `DB_MIGRATION_PASSWORD` y `BACKUP_DB_PASSWORD`, las dos del rol `fichaje_migrator`, que es `SUPERUSER` porque es el rol de arranque del clúster y PostgreSQL no permite degradarlo ([ADR-033](ADR-033-tres-roles-de-base-de-datos-no-dos.md)).
2. **Por la conexión.** El propio runtime usaba ese rol: `compliance:ensure-audit-partitions`, programado a diario, resolvía `pgsql_migrator` para crear la partición anual de `audit_log` (`ComplianceServiceProvider.php:189`). Crear una partición es DDL y exige ser propietario de la tabla madre.

Quien consiguiera ejecutar código en PHP podía reescribir `shift_entries` y `audit_log` y recalcular la cadena, que tiene génesis fija y no está anclada fuera de la base: `compliance:verify-audit-chain` saldría en verde. [ADR-033](ADR-033-tres-roles-de-base-de-datos-no-dos.md) dejaba la separación de credenciales como «decisión de despliegue del cliente». Para un registro con valor legal que se instala en el servidor del cliente, esa garantía tiene que darla el producto, no la configuración de cada instalación.

## Decisión

**Ningún proceso que corre con la aplicación en marcha posee una credencial con la que se pueda alterar el registro: ni en su entorno ni en su conexión.**

### 1. La partición anual la pide la aplicación y la crea el motor

Una función `public.audit_log_create_partition(integer)`, `SECURITY DEFINER` y propiedad del rol de migración, hace exactamente una cosa: crear `public.audit_log_<año>` como partición de `public.audit_log`. Sus límites son parte de la decisión:

- solo admite el año UTC en curso o el siguiente, nunca menos que el primer año del producto, y **nunca un año sellado** en `audit_chain_anchors`, porque un año purgado no puede volver a aparecer vacío;
- deja la partición con **los mismos permisos que las demás**, en la misma operación: `INSERT` y `SELECT` para la aplicación, `SELECT` para mantenimiento y nada para `PUBLIC`. Los `ALTER DEFAULT PRIVILEGES` del migrador se aplicarían también dentro de la función, así que sin esos `REVOKE` la partición nacería con `UPDATE` y `DELETE` para la aplicación;
- es idempotente, y falla, en vez de no hacer nada, si existe con ese nombre una tabla que no es partición;
- lleva `SET search_path = pg_catalog, pg_temp`, califica todos los nombres con `public.` y construye los identificadores con `format('%I')`, porque su propietario es superusuario;
- lleva `lock_timeout`, porque `CREATE TABLE … PARTITION OF` bloquea la tabla madre en exclusiva y una espera larga dejaría en cola los fichajes.

Solo el rol de aplicación tiene `EXECUTE`. Es el mismo mecanismo que ya delega la purga al rol de mantenimiento (tarea 2.10, `audit_log_drop_sealed_partition`): el estándar de PostgreSQL para delegar un privilegio estrecho sin repartir el ancho.

### 2. Las copias las hace un rol de solo lectura propio

Se provisiona un cuarto rol, **`fichaje_backup`**: `LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE REPLICATION`, con `GRANT pg_read_all_data`. Sustituye al migrador en `BACKUP_DB_USERNAME`/`BACKUP_DB_PASSWORD`. El contenedor `scheduler`, que ejecuta la copia programada, conserva esa credencial junto a `BACKUP_ENCRYPTION_KEY`. Con ella se puede leer todo, pero no escribir nada.

### 3. Migrar y restaurar son servicios de un solo uso

Las migraciones y la restauración corren en dos servicios de Compose, **`migrate`** y **`restore`**, con `profiles: [tools]`. Solo se levantan cuando se invocan (`install.sh`, `update.sh`, la restauración) y terminan al acabar. **Son los únicos que reciben `DB_MIGRATION_*`.**

### 4. El runtime declara su entorno variable a variable

`app`, `horizon`, `reverb`, `scheduler` y `nginx` dejan de recibir `env_file: .env` y declaran `environment:` con los nombres que necesitan, sin valor (la forma de Compose que toma el valor del entorno o del `.env` del proyecto). Es el patrón que ya usa `alertmanager`. Añadir un secreto al `.env` ya no lo reparte a todos los contenedores.

### 5. Ningún código de `backend/app` resuelve `pgsql_migrator`

La conexión `pgsql_migrator` solo la usan las migraciones y las pruebas. Una prueba de arquitectura falla si algún fichero de `backend/app` la nombra o si algún servicio de runtime de `compose.prod.yaml` recibe `DB_MIGRATION_*`.

### Reparto resultante

| Rol | `audit_log` y particiones | Crear partición | Soltar partición | Dónde vive su credencial |
|---|---|---|---|---|
| `fichaje_app` | `INSERT`, `SELECT` | solo mediante la función, año en curso o siguiente | no | runtime (`app`, `horizon`, `reverb`, `scheduler`) |
| `fichaje_maintenance` | `SELECT` | no | solo mediante su función y con ancla | en ningún contenedor: se aporta al ejecutar la purga |
| `fichaje_backup` | `SELECT` (vía `pg_read_all_data`) | no | no | `scheduler`, junto a `BACKUP_ENCRYPTION_KEY` |
| `fichaje_migrator` | propietario | sí | sí | solo `migrate` y `restore`, y el propio `postgres` para el arranque |

### Excepción: el entorno de desarrollo

`infra/compose.dev.yaml` **conserva `env_file`**. La suite de pruebas usa `pgsql_migrator` para `migrate:fresh` y para simular al atacante que altera la tabla por fuera (`AuditLogTest`, `RetentionTest`), así que el contenedor de desarrollo necesita la credencial. La excepción es solo de desarrollo, donde no hay registro legal ni datos reales, y la prueba de arquitectura mira únicamente `compose.prod.yaml`.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Dar `CREATE` en el esquema, o la propiedad de `audit_log`, al rol de aplicación** | `PARTITION OF` exige ser propietario de la tabla madre, y un propietario puede volver a darse `UPDATE` y `DELETE` o soltar la partición. Anula la condición 1 de ADR-010 |
| **Crear N años de particiones en cada migración** | El fichaje dependería de que el cliente actualice, y un producto con licencia on-premise no controla cuándo lo hace. Se conserva solo como margen adicional al aplicar una migración |
| **Un contenedor aparte con la credencial del migrador para la tarea anual** | Traslada un secreto de superusuario a otro proceso en marcha: el «no puede» sigue siendo falso |
| **`pg_partman` o `pg_cron`** | Extensiones en la imagen de PostgreSQL del cliente, y `pg_cron` ejecuta como superusuario dentro de la base. Más superficie para una sentencia al año |
| **Partición `DEFAULT`** | El `INSERT` nunca fallaría, pero crear después la partición del año obligaría a mover filas probatorias, y la purga por año dejaría de ser un `DROP` limpio |
| **Seguir haciendo las copias con el migrador** | La copia programada corre en el `scheduler`, un contenedor de runtime: la contraseña del superusuario volvería a estar donde este ADR la quita. `pg_read_all_data` da exactamente la lectura que necesita `pg_dump` |
| **Un servicio de copias propio, fuera del `scheduler`** | Es mejor aislamiento, porque la clave de cifrado de las copias saldría también del runtime. Pero cambia la programación, la supervisión y las alertas de las copias en la misma versión que corrige AUD-1. Queda como evolución |
| **Quitar también `env_file` en desarrollo** | Rompería la suite, que necesita el migrador para preparar la base y para simular la manipulación por fuera de la aplicación |

## Consecuencias

- **La consecuencia 3 de ADR-033** («las credenciales de migración y las de runtime viven en el mismo `.env`… decisión de despliegue del cliente») **queda sustituida**. Las credenciales pueden seguir en el mismo fichero del servidor, pero no llegan a ningún contenedor de runtime.
- **La condición 1 de ADR-010** se lee ahora así: la aplicación no puede modificar el registro **porque no posee ninguna credencial que pueda, ni en su entorno ni en su conexión**. La única forma de DDL que puede provocar es pedir, mediante la función, la partición del año en curso o del siguiente, ya restringida.
- **La tarea programada de ADR-027** crea la partición con la función y la conexión de la aplicación. Si la función falta, porque la migración no se aplicó, la tarea falla con un mensaje propio, deja la métrica `audit_log_partition_ready` a 0 y hace sonar las alertas existentes. El fichaje no se bloquea mientras exista la partición del año en curso, y el año siguiente tiene dos meses de margen.
- **El migrador sigue siendo `SUPERUSER`** y es el propietario de la función. Por eso la función es mínima, recibe un `integer` y califica todo. La evolución prevista es un rol propietario `NOLOGIN` y sin superusuario que posea las tablas y las funciones; exige traspasar la propiedad de todo el esquema y queda fuera de este ADR.
- **`install.sh` y `update.sh` migran con el servicio `migrate`**, y la restauración usa `restore`. La vuelta atrás de una actualización sigue siendo restaurar la copia. Volver a la 2.1.0 reabre AUD-1 hasta volver a la 2.2.0, y el informe de la actualización tiene que decirlo.
- **Los nombres de rol quedan grabados en la función** al crearla, igual que en la función de purga. Si cambian `DB_USERNAME` o `DB_MAINTENANCE_USERNAME`, hay que volver a lanzar la migración.
- **Las instalaciones existentes** reciben la función, el rol `fichaje_backup` y la nueva forma de Compose al actualizar. `02-application-roles.sh` provisiona el rol de forma idempotente, y la actualización traslada `BACKUP_DB_USERNAME`/`BACKUP_DB_PASSWORD` al rol nuevo.
- **Cada variable nueva del runtime** hay que añadirla también al `environment:` de los servicios que la usan, además de a `.env.example` ([ADR-029](ADR-029-configuracion-en-el-entorno-del-contenedor.md)). Una prueba de arquitectura lo comprueba, para que olvidarla no se convierta en un fallo de configuración silencioso.

## Verificación

- Integración: el rol de aplicación crea con la función la partición del año siguiente. La partición nace propiedad del migrador, con `relacl` idéntico a `audit_log_2026` y con los índices y restricciones de la tabla madre. La función rechaza años fuera de rango o sellados, falla ante una tabla homónima que no es partición y es idempotente.
- Integración: un `CREATE TEMP TABLE audit_log` del rol de aplicación no desvía la función, que crea la partición en `public.audit_log`.
- Integración (RS-07): el rol de aplicación sigue recibiendo `42501` en `UPDATE`, `DELETE`, `TRUNCATE`, `DETACH`, `DROP` y `CREATE TABLE … PARTITION OF`, sobre la tabla madre y sobre cada partición.
- Integración: `compliance:ensure-audit-partitions` termina en verde con la credencial del migrador inutilizada, y falla con mensaje propio y métrica a 0 si falta la función.
- Arquitectura: ningún fichero de `backend/app` nombra `pgsql_migrator` ni `database.migrations.connection`. Ningún servicio de runtime ni `nginx` de `compose.prod.yaml` usa `env_file`, y ninguno recibe `DB_MIGRATION_*`. Solo `migrate` y `restore` reciben `DB_MIGRATION_*`, y `BACKUP_DB_*` solo llega al `scheduler`.
- Integración: `fichaje_backup` puede ejecutar `pg_dump` completo y recibe `42501` en cualquier escritura.
- Migración: `down()` quita la función sin tocar las particiones que creó, y volver a migrar la recrea con los mismos permisos.
- Actualización 2.1.0 → 2.2.0 en dind (job ⑧b): tras actualizar, `docker inspect` de los contenedores de runtime no muestra `DB_MIGRATION_PASSWORD`, y `doctor.sh` queda en verde.
