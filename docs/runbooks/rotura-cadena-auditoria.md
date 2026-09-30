# Runbook — rotura de la cadena de hash de auditoría

**Esto es un incidente de seguridad, no una avería.** Antes de tocar nada, lee
la §2: hay evidencia que se pierde sola.

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Rotura de la cadena de hash de
auditoría | cualquiera | Crítica (seguridad)»*), definidas en
[`infra/observability/prometheus/rules/audit.yml`](../../infra/observability/prometheus/rules/audit.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `RoturaDeCadenaDeAuditoria` | cualquiera, `for: 1m` | Crítica (seguridad) | Responsable de seguridad | [§3](#3-rotura-de-la-cadena) |
| `VerificacionDeAuditoriaAusente` | > 26 h sin verificar, `for: 30m` | Crítica (seguridad) | Responsable de seguridad | [§4](#4-nadie-está-verificando-el-silencio) |
| `ParticionDeAuditoriaAusente` | `horizon="current"` a 0, `for: 5m` | Crítica | IT del cliente | [§5](#5-falta-la-partición-del-año-en-curso) |
| `ParticionDeAuditoriaDelProximoAnoSinPreparar` | `horizon="next"` a 0 desde noviembre | Alta | IT del cliente | [§5](#5-falta-la-partición-del-año-en-curso) |

**Impacto en el fichaje, que es lo primero que hay que saber:**

- `RoturaDeCadenaDeAuditoria` y `VerificacionDeAuditoriaAusente` — **ninguno**.
  Nadie se queda sin fichar. Lo que está en juego es el valor probatorio del
  registro y la capacidad de acotar el alcance de un acceso indebido (RL-15).
- `ParticionDeAuditoriaAusente` — **el fichaje está caído**. Un `INSERT` sin
  partición de destino falla y arrastra la transacción de la acción auditada.
  Ve directo a la §5.

---

## 1. Qué hay montado, en 30 segundos

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `audit_log` | Solo-append, encadenada por hash, particionada por año | PostgreSQL, esquema `public` |
| `audit_chain_anchors` | Sella cada partición antes de soltarla (ADR-027) | PostgreSQL |
| `compliance:verify-audit-chain` | Recorre la cadena a diario, 04:05 UTC | Contenedor `scheduler` |
| `compliance:ensure-audit-partitions` | Pide la partición que falte a la función `audit_log_create_partition` de la base, 02:45 UTC. Usa la conexión de la aplicación, no la de migración | Contenedor `scheduler` |
| `audit_chain_verification_failures_total` | Contador. **Debe estar siempre en cero** | `BACKUP_PATH/metrics/kronoqr_audit_chain.prom` |

**La fórmula** (doc 02 §7.4):

```
hash_n = SHA256( prev_hash || occurred_at || actor || action || subject || canonical_json(payload) )
```

La entrada génesis usa `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`, que vale
`5a4bce588b4e0fa301a7a7befe42825a5d44ec5b90d26697b300acca0add5f2e`.

**Los cuatro roles de base de datos, porque son media respuesta:**

| Rol | Qué puede | Dónde vive su credencial |
| --- | --- | --- |
| `fichaje_migrator` | Todo. Propietario y superusuario. Solo migraciones y restauración | `.env`, `DB_MIGRATION_*`. Solo la reciben `postgres` y los servicios de un solo uso `migrate` y `restore`; **ningún contenedor en marcha** |
| `fichaje_app` | Sobre `audit_log`: **solo `INSERT` y `SELECT`**. Crear la partición del año en curso o del siguiente, y nada más, a través de la función | `.env`, `DB_*`. Es el runtime |
| `fichaje_backup` | **Solo lectura** de todo (copias) | `.env`, `BACKUP_DB_*`. Solo el `scheduler` |
| `fichaje_maintenance` | `SELECT` y soltar particiones selladas (retención) | **No está en el `.env`** |

**Las tres cosas que hay que saber sin buscarlas:**

1. **La aplicación no puede haber causado esto.** `fichaje_app` no tiene
   `UPDATE` ni `DELETE` sobre `audit_log` y no es superusuario ni propietario, y
   desde la 2.2.0 ningún contenedor de la aplicación en marcha tiene en su
   entorno otra credencial que pueda escribir en el registro. Si la cadena está
   rota, la escritura vino de fuera de la aplicación: `psql` con otro rol, una
   restauración parcial, o una intervención manual.
2. **Nada de lo que imprime `compliance:verify-audit-chain` contiene datos
   personales** (regla dura 21): identificadores y hashes. Se puede pegar tal
   cual en un parte de incidencia.
3. **Una purga sellada no es una rotura.** Si la línea dice `Purga sellada
   reconocida: partición 2026`, es la retención de RL-02 haciendo su trabajo
   (ADR-027) y el comando sale con código 0.
4. **Una acción desconocida tampoco es una rotura.** Si la salida dice `Acción
   desconocida para esta versión: system.…`, el comando sale con código 0 igual:
   la cadena se verifica **por hash, no por catálogo**. Significa otra cosa —que
   esta base la escribió una versión más nueva que la que está corriendo— y se
   explica en la §3.

---

## 2. Antes de tocar nada: preservar la evidencia

En este orden, y **antes** de reiniciar, migrar o restaurar nada. Lo que se
pierde aquí no se recupera.

```bash
# 0. Marca temporal del incidente, para nombrar todo lo demás.
INCIDENTE="audit-$(date -u +%Y%m%dT%H%M%SZ)"; echo "$INCIDENTE"

# 1. El hallazgo completo, con las filas señaladas.
docker compose exec -T app php artisan compliance:verify-audit-chain \
  | tee "/tmp/${INCIDENTE}-verificacion.txt"

# 2. Copia inmediata de la base. NO esperes a la copia nocturna: una
#    restauración o un mantenimiento posterior se lleva la evidencia. Va por
#    el scheduler, el único con la clave de las copias; si está parado,
#    `docker compose run --rm --no-deps -T scheduler` en lugar de `exec -T`.
docker compose exec -T scheduler php artisan backup:run --mode=dump

# 3. Registro de conexiones y de sentencias del motor.
docker compose logs --no-color --since 168h postgres \
  > "/tmp/${INCIDENTE}-postgres.log"

# 4. Quién ha entrado en la base y desde dónde, ahora mismo.
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "SELECT usename, client_addr, backend_start, state, query
     FROM pg_stat_activity WHERE datname = current_database()"

# 5. Los permisos, tal como están AHORA. Si alguien los abrió, aquí se ve.
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "SELECT grantee, privilege_type
     FROM information_schema.role_table_grants
    WHERE table_name LIKE 'audit_log%' ORDER BY grantee, privilege_type"
```

**Salida esperada del paso 5 en una instalación sana:** `fichaje_app` con
`INSERT` y `SELECT` y nada más; `fichaje_maintenance` con `SELECT`. Si aparece
`UPDATE`, `DELETE` o `TRUNCATE` para cualquiera de los dos, **ese es el
hallazgo**: alguien otorgó permisos, y la fecha de esa concesión acota la
ventana.

Guarda los cuatro ficheros fuera del servidor antes de seguir.

---

## 3. Rotura de la cadena

### Diagnóstico

```bash
docker compose exec -T app php artisan compliance:verify-audit-chain
```

La salida nombra cada hallazgo con su tipo. Los tres significan cosas distintas:

| Tipo | Qué ha pasado | Primera pregunta |
| --- | --- | --- |
| `content_altered` | El contenido de la fila **no produce el hash que lleva escrito**. Alguien cambió un campo después de escribirla | ¿Qué campo? Compara la fila con la copia de seguridad de ayer |
| `broken_link` | El `prev_hash` no es el `hash` de la fila anterior. **Se ha borrado, insertado o reordenado algo** | ¿Falta una fila entre esos dos `id`? |
| `orphan_start` | La cadena empieza por un `prev_hash` que no es la génesis **y no encaja con ninguna ancla** | ¿Se restauró una copia parcial? ¿Se soltó una partición sin sellarla? |

Para ver la fila señalada sin modificarla:

```bash
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "SELECT id, occurred_at, actor_type, actor_id, action, subject_type, subject_id,
          prev_hash, hash
     FROM audit_log WHERE id BETWEEN <id-1> AND <id+1> ORDER BY id"
```

Y la misma fila en la copia de anoche, que es la comparación que dice **qué**
cambió:

```bash
# 1. Localiza la copia de ANOCHE. La del paso 2 de la §2 es de después de la
#    alteración y no sirve para comparar.
docker compose exec scheduler bash /opt/kronoqr/scripts/backup.sh list

# 2. Restáurala en un contenedor LIMPIO y sin red, NUNCA sobre fichaje ni en
#    el PostgreSQL de producción. Desde el directorio de la instalación:
sudo bash ./restore-drill.sh --keep \
  --file "${BACKUP_PATH:-/var/backups/fichaje}/daily/<copia-de-anoche>.dump.enc"

# 3. Consulta la fila. El nombre del contenedor lo imprime el paso anterior
#    («Contenedor conservado: kronoqr-drill-<marca>»).
docker exec -i kronoqr-drill-<marca> psql -U postgres -d drill -c \
  "SELECT * FROM audit_log WHERE id = <id>"

# 4. Destrúyelo al acabar: contiene el registro completo de la plantilla.
docker rm -f kronoqr-drill-<marca>
```

Es el simulacro de restauración con `--keep`: restaura en un contenedor de
PostgreSQL de usar y tirar, sin red, y lo conserva para que lo consultes. No
toca la base de producción, ni sus copias, ni sus contenedores.
El script lee el `.env` de la instalación para obtener la clave de cifrado de las copias, que
necesita para abrir la copia y que no está en el entorno de tu sesión.

### Resolución

**No hay «arreglo».** No se recalculan hashes, no se reescriben filas y no se
borra la cadena para empezar de nuevo: eso destruiría la única prueba de que
algo pasó y convertiría el mecanismo de reparación en la herramienta perfecta
para manipular el registro (ADR-027, alternativa descartada «reencadenar tras el
borrado»).

Lo que sí se hace, en este orden:

1. **Acotar la ventana.** El `id` más bajo con hallazgo y el momento de la última
   verificación en verde delimitan cuándo ocurrió. `audit_chain_last_verification_timestamp_seconds`
   del fichero de métricas da la fecha exacta de la última vez que estuvo bien.
2. **Determinar el alcance (RL-15).** Con esa ventana, revisar `pg_stat_activity`
   histórico, los logs del motor y los accesos al servidor. La pregunta que hay
   que poder responder ante la AEPD en 72 h es *qué datos personales pudieron
   verse*, no *quién lo hizo*.
3. **Cerrar la vía.** Si el paso 5 de la §2 mostró permisos de más, retirarlos y
   volver a ejecutar `infra/docker/postgres/initdb/02-application-roles.sh`, que
   es idempotente y devuelve los tres roles a su sitio.
4. **Registrar el incidente** y valorar con el DPO del cliente si procede
   notificación (RL-15, procedimiento de 72 h en `brecha-de-seguridad.md`).
5. **Dejar constancia en la propia auditoría.** La cadena sigue a partir de la
   última fila: escribir la entrada del incidente es correcto y deseable. Lo que
   no se toca es lo anterior.

**La métrica no se reinicia a mano.** `audit_chain_verification_failures_total`
es acumulativa y seguirá señalando el hallazgo mientras las filas alteradas
estén en la tabla. Eso es lo correcto: la alerta deja de sonar cuando la
partición afectada caduca por retención y se suelta con su ancla, no cuando
alguien decide que ya no importa.

### Falso positivo: cómo descartarlo

Solo hay dos formas de que la cadena se rompa sin que nadie haya escrito en la
tabla, y las dos se descartan en un minuto:

- **Precisión de `occurred_at`.** La columna es `TIMESTAMPTZ(6)`. Si una
  migración la redujera a segundos, el verificador recalcularía un hash distinto
  del escrito y **todas** las filas darían `content_altered`. Compruébalo:
  `\d audit_log` debe decir `timestamp(6) with time zone`.
- **Zona horaria de la sesión.** `SHOW timezone` debe decir `UTC` en la base y en
  la aplicación (regla dura 3). Con otra zona, la representación del instante
  cambia y el hash con ella.

Si el hallazgo es de **una o unas pocas filas**, no es ninguna de las dos: un
fallo de configuración rompe la cadena entera, no una fila del martes.

### El aviso que no es un hallazgo: «acción desconocida»

```text
Accion desconocida para esta version: system.future_action — no es una rotura
(el hash cuadra). Escrita por una version posterior; ver docs/runbooks/…
```

**Código de salida 0 y la cadena íntegra.** No hay nada que investigar en la
integridad: la fórmula del §7.4 mete la acción en el hash **como cadena
literal**, así que una fila cuya acción no está en el catálogo de esta versión se
recalcula y cuadra igual que las demás.

Lo que sí dice el aviso es **qué versión escribió esa fila**: una posterior a la
que está corriendo ahora. Las acciones nuevas las estrena siempre la versión
siguiente, así que solo hay un camino normal para llegar aquí —una actualización
que se deshizo (`update.sh`, paso 6) y dejó corriendo el binario anterior sobre
una base en la que el nuevo ya había escrito—. Comprueba entonces dos cosas:

```bash
# 1. Qué versión está instalada (desde el directorio de la instalación).
cat VERSION

# 2. Qué acciones hay en la tabla y cuál es la última fila de cada una.
docker compose exec -T postgres psql -U fichaje_app -d fichaje -c \
  "SELECT action, count(*), max(occurred_at) FROM audit_log GROUP BY action ORDER BY 1"
```

Si la instalación se quedó en la versión anterior después de una vuelta atrás,
lo que toca es el runbook del actualizador, no este: **no se borra ni se toca
ninguna fila**. Al reinstalar la versión nueva, el aviso desaparece solo porque
la acción vuelve a estar en el catálogo. La métrica
`audit_chain_unknown_actions` publica cuántos nombres distintos hay sin
reconocer; no dispara alerta y no suma en
`audit_chain_verification_failures_total`.

### La discontinuidad que sí es legítima: una vuelta atrás del actualizador

Hay un caso en el que la cadena **verifica en verde y aun así falta un
intervalo**: `update.sh` restauró la copia previa (paso 6) y con ella la cadena
que había en ese momento. Los asientos escritos entre la copia y el fallo ya no
están, y nada en el encadenado lo delata, porque la cadena restaurada es íntegra.
Desde el cierre de la Fase 5 ese hueco deja rastro propio: un asiento
`system.restored_from_backup`, escrito **sobre la cadena restaurada**, con el
nombre y la fecha de la copia, el paso que falló, el motivo y `chain_before`, que
es la huella de la punta de la cadena que se descartó. Si al investigar una
reclamación (una persona dice que fichó y no aparece) el último asiento del
sistema es uno de estos, no busques manipulación: busca en el informe de esa
actualización (`BACKUP_PATH/reports/update-<fecha>.log`) y en la cola de los quioscos, que
reenvían lo que tenían encolado. Una actualización que terminó bien deja
`system.updated`, con `chain_before` y `chain_after`; si falta ese asiento y la
versión cambió, el actualizador salió con `6` y el aviso dice cómo escribirlo a
mano (`compliance:record-system-event`).

---

## 4. Nadie está verificando (el silencio)

RS-07 exige detectar una rotura en menos de 24 h, y eso solo es cierto si el
comando diario se ejecuta. Sin esta alerta, apagar el scheduler sería la forma
más cómoda de que la alerta de la §3 no volviera a sonar nunca.

```bash
# ¿Corre el scheduler?
docker compose ps scheduler

# ¿Está la tarea en la lista y a qué hora?
docker compose exec -T app php artisan schedule:list

# Ejecútala a mano: si falla, el motivo sale aquí.
docker compose exec -T app php artisan compliance:verify-audit-chain

# ¿Llega el fichero de métricas a node-exporter? Se lee en el propio servidor:
# BACKUP_PATH está montado en la misma ruta dentro y fuera de los contenedores.
cat "${BACKUP_PATH:-/var/backups/fichaje}/metrics/kronoqr_audit_chain.prom"
```

Causas, de más a menos frecuente: el contenedor `scheduler` parado; el destino
de métricas (`BACKUP_PATH`) sin montar o sin permisos de escritura;
`node-exporter` sin acceso al volumen. Las tres se ven en la salida de arriba.

---

## 5. Falta la partición del año en curso

**El fichaje está caído mientras dure.** Un `INSERT` en `audit_log` cuyo
`occurred_at` no cae en ninguna partición falla, y ese fallo tumba la
transacción de la acción auditada (ADR-027).

```bash
# Resolución, en una orden. Crea la que falte y avisa de lo que faltaba.
docker compose exec -T app php artisan compliance:ensure-audit-partitions

# Comprobación: qué particiones hay.
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c "\d+ audit_log"
```

**Cómo la crea, desde la 2.2.0** (ADR-042). La aplicación no tiene DDL ni
ninguna credencial que lo tenga: pide la partición a la función
`audit_log_create_partition` de la base, que la crea con los mismos permisos que
las demás y **solo** para el año en curso o el siguiente. Por eso esta orden va
por `app` y no necesita ninguna contraseña del `.env`. `DB_MIGRATION_USERNAME` y
`DB_MIGRATION_PASSWORD` ya no intervienen: no las busques aquí.

Si el comando falla con `la base de datos no ofrece la funcion
audit_log_create_partition al rol de la aplicacion`, la migración que crea la
función (`2026_09_29_100000_audit_log_partition_function`) no está aplicada. Lo
normal es que una actualización no terminara. Compruébalo:

```bash
# ¿Está aplicada? La línea de 2026_09_29_100000 debe decir «Ran».
docker compose run --rm --no-deps -T migrate php artisan migrate:status --database=pgsql_migrator
```

- **Si sale como pendiente**, aplícala con el servicio de un solo uso
  `migrate`, que es la misma orden que lanzan `install.sh` y `update.sh`. Haz
  antes una copia (§2, paso 2) y vuelve a lanzar la orden de arriba:

  ```bash
  docker compose run --rm --no-deps -T migrate php artisan migrate --database=pgsql_migrator --force
  docker compose exec -T app php artisan compliance:ensure-audit-partitions
  ```

  Si hay más de una migración pendiente, no es este caso sino una
  actualización a medias: para y sigue
  [`actualizacion-cliente.md`](actualizacion-cliente.md).
- **Si sale como aplicada y aun así falla**, lo más probable es que se haya
  cambiado `DB_USERNAME` después de instalar: la función guarda el nombre del
  rol de la aplicación al crearse, y hay que volver a crearla. **No lo
  intentes con `migrate:refresh` ni con `migrate:rollback`**: sobre una base con
  registro legal no se deshace ninguna migración. Abre un caso al fabricante con
  el paquete de diagnóstico.
- **Si falla por tiempo de espera de un bloqueo** (`lock timeout`), la tabla
  estaba ocupada en ese instante: la función no espera para no dejar en cola
  los fichajes. Repite la orden pasados unos segundos.

**Para la alerta del año próximo** (`ParticionDeAuditoriaDelProximoAnoSinPreparar`),
que no tiene fichaje afectado: comprueba primero que el `scheduler` corre
(`docker compose ps scheduler`) y que la migración está aplicada con la orden de
arriba. Con las dos cosas bien, la tarea de las 02:45 UTC la crea sola; si no
quieres esperar, lanza la misma `compliance:ensure-audit-partitions`.

**Después de crearla, mira hacia atrás.** Haber llegado a este estado significa
que durante un rato las acciones auditables estaban fallando. Revisa el log de
la aplicación en esa ventana y comprueba con RRHH si alguien reportó no poder
fichar; los fichajes de ese rato están en la cola offline de los quioscos (regla
dura 19) y se reenvían solos, pero conviene confirmarlo.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Cualquier hallazgo de la §3 | Responsable de seguridad del cliente **y** DPO | Inmediato |
| Permisos alterados sobre `audit_log` | Responsable de seguridad + fabricante (sin datos) | Inmediato |
| Falta la partición del año en curso | IT del cliente | Inmediato: hay fichaje afectado |
| Falta la del año próximo (desde noviembre) | IT del cliente | Dentro de la semana: hay dos meses de margen |
| Silencio de la verificación | IT del cliente | Dentro de la jornada |

**El fabricante no accede a los datos del cliente** (ADR-020, regla dura 16). Lo
que se le envía para diagnosticar es el paquete anonimizado; la salida de
`compliance:verify-audit-chain` se puede compartir tal cual porque no lleva datos
personales, pero el contenido de `audit_log` **no sale de la instalación**.
