# Runbook — copias de seguridad: fallo, restauración y simulacro

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Copia de seguridad fallida o no
verificada | cualquiera | Crítica | IT del cliente»*), definidas en
[`infra/observability/prometheus/rules/backup.yml`](../../infra/observability/prometheus/rules/backup.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `CopiaDeSeguridadFallida` | cualquiera, `for: 5m` | Crítica | IT del cliente | [§2](#2-la-copia-ha-fallado) |
| `CopiaDeSeguridadSinVerificar` | verificación en rojo o > 26 h sin verificar | Crítica | IT del cliente | [§2](#2-la-copia-ha-fallado) |
| `CopiaDeSeguridadAusente` | no llega ninguna métrica, `for: 30m` | Crítica | IT del cliente | [§3](#3-no-llega-ninguna-métrica-el-silencio) |
| `ArchivadoDeWalDetenido` | dato sin archivar desde hace más de `archive_timeout` + 10 min (≈ 25 min), o 3 segmentos completos sin archivar, `for: 3m` | Crítica | IT del cliente | [§4](#4-el-archivado-de-wal-está-detenido) |
| `ArchivadoDeWalFallando` | el último intento de archivado falló y no se ha recuperado, `for: 10m` | Crítica | IT del cliente | [§4](#4-el-archivado-de-wal-está-detenido) |
| `MedicionDeWalAusente` | el exportador del RPO lleva > 5 min sin publicar, `for: 5m` | Crítica | IT del cliente | [§4.3](#43-no-llega-la-medida-del-rpo) |
| `ArchiveTimeoutFueraDeRango` | `archive_timeout` = 0 o > 900, `for: 10m` | Crítica | IT del cliente | [§4.4](#44-archive_timeout-fuera-de-rango) |
| `DiscoDeCopiasCasiLleno` | < 20 % libre, `for: 15m` | Alta | IT del cliente | [§5](#5-disco-de-copias-casi-lleno) |
| `SimulacroDeRestauracionCaducado` | simulacro fallido o > 100 días | Alta | IT del cliente | [§7](#7-simulacro-trimestral-rnf-d-05-rq-09) |

**Lo primero, y vale para todas: el fichaje no está afectado.** Ninguna de estas
alertas impide que nadie fiche. Los quioscos siguen registrando y encolando
(regla dura 19). Lo que está en juego es la capacidad de recuperar el registro
si mañana falla el disco, y eso se atiende dentro de la jornada, no a las 03:00
—salvo el archivado de WAL detenido, que si se deja acaba **parando la base de
datos** por disco lleno.

---

## 1. Qué hay montado, en 30 segundos

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `backup.sh run` | Volcado lógico cifrado + verificación | `BACKUP_PATH/daily/` |
| `backup.sh run --mode base` | Copia **física** (`pg_basebackup`), semanal | `BACKUP_PATH/base/` |
| `kronoqr-archive-wal` | Comprime, **cifra y autentica** (KQE1, ADR-049) un segmento de WAL cada 15 min como mucho | `BACKUP_PATH/wal/` (`<segmento>.gz.enc`) |
| `kronoqr-restore-wal` | Es el `restore_command` de la recuperación a un punto en el tiempo (§6.4) | imagen de `postgres` |
| `wal-metrics.sh` | Cada minuto, desde `scheduler`: cuánto lleva sin archivarse el dato más antiguo | `BACKUP_PATH/metrics/kronoqr_wal.prom` |
| `backup.sh verify` | MAC (autenticidad) + `.sha256` + descifrado + `pg_restore --list` | — |
| `restore.sh` | Restauración con intercambio de bases y vuelta atrás | `BACKUP_PATH/reports/` |
| `restore-drill.sh` | Simulacro en contenedor limpio, trimestral | `BACKUP_PATH/reports/` |

**Objetivos que sostiene** (doc 01 §6.2): **RPO ≤ 15 min** — volcado diario +
copia física semanal + WAL archivado con `archive_timeout=900`. **RTO ≤ 4 h** —
el procedimiento de la §6, medido.

**Las tres cosas que hay que saber sin buscarlas:**

1. **Sin `BACKUP_ENCRYPTION_KEY` no hay restauración posible.** Una copia solo se
   abre con la clave con la que se hizo. Si se rotó, hace falta la anterior
   (`BACKUP_ENCRYPTION_KEY_PREVIOUS`, solo para restaurar: se pasa con `-e BACKUP_ENCRYPTION_KEY_PREVIOUS` a `docker compose run --rm restore`; no se deja en el `.env`). La clave del WAL
   (`BACKUP_WAL_KEY`) **se deriva** de esa misma clave: no hay una segunda que
   custodiar (`backup.sh derive-wal-key`).
2. **La copia no sale de aquí.** Vive en la infraestructura del cliente; el
   fabricante no la recibe ni la custodia (regla dura 16, RL-14).
3. **Nada de lo que imprimen estos scripts contiene datos personales**
   (regla dura 21): rutas, nombres de tabla y números. Se pueden pegar en un
   parte de incidencia tal cual.

**Desde qué contenedor se lanza cada cosa** (desde la 2.2.0, ADR-042). Cada
contenedor recibe solo las credenciales que necesita, así que las órdenes de
este runbook **no** van por `app`, que no tiene ni la clave de cifrado ni el rol
de copias:

| Qué | Contenedor | Por qué ahí |
| --- | --- | --- |
| Hacer, verificar, listar y podar copias (`backup:run`, `backup:verify`, `backup.sh`) | `scheduler` | Es el que hace la copia programada: el único de los que están en marcha que recibe `BACKUP_ENCRYPTION_KEY` y el rol de copias `fichaje_backup`, que **solo lee** |
| Restaurar (`restore.sh`) | `restore`, de un solo uso | Restaurar exige crear y renombrar bases, cosa que el rol de copias no puede. Solo `restore` y `migrate` reciben la credencial del rol de migración, y ninguno de los dos se queda en marcha |

Con el `scheduler` en marcha se usa `docker compose exec scheduler …`. **Si está
parado** —por ejemplo, porque lo paraste para restaurar—, la misma orden con
`docker compose run --rm --no-deps scheduler …` levanta un contenedor efímero con
el mismo entorno y lo retira al terminar.

---

## 2. La copia ha fallado

### Diagnóstico

```bash
# 1. Qué dice la última ejecución (código y motivo)
docker compose exec scheduler php artisan backup:verify

# 2. Qué copias hay y desde cuándo
docker compose exec scheduler bash /opt/kronoqr/scripts/backup.sh list

# Si el scheduler está parado, las mismas dos órdenes con un contenedor efímero:
docker compose run --rm --no-deps scheduler php artisan backup:verify
docker compose run --rm --no-deps scheduler bash /opt/kronoqr/scripts/backup.sh list

# 3. Métricas publicadas (lo que ve la alerta)
cat "${BACKUP_PATH:-/var/backups/fichaje}"/metrics/*.prom
```

Salida esperada de una instalación sana: `kronoqr_backup_last_result{type="dump"} 1`,
`kronoqr_backup_last_verify_result 1` y una marca de tiempo de hace menos de un día.

### Códigos de salida y qué significa cada uno

Es la tabla común de los cinco scripts ([`../cliente/operacion.md`](../cliente/operacion.md) §8; hasta la 2.0.0 `backup.sh` tenía una propia, la equivalencia está allí):

| Código | Significa | Qué hacer |
| --- | --- | --- |
| `2` | Falta una herramienta, no se llega a la base, el destino no es escribible o sin espacio, o falta `BACKUP_ENCRYPTION_KEY`. **Nada se ha escrito** | El mensaje dice cuál. `docker compose ps`; [§5](#5-disco-de-copias-casi-lleno); el `.env` |
| `3` | Ya existe el fichero de destino, o no hay copia que verificar. **Nada se ha escrito** | Espera un segundo y repite, o mira `backup.sh list` |
| `4` | La copia falló y lo escrito a medias se retiró; **la anterior sigue siendo la buena** | Sigue el mensaje: dice qué falló y dónde |
| `5` | Quedó algo a medias que hay que retirar a mano | El mensaje dice qué fichero |
| `6` | La copia se escribió pero **no verifica**, o una existente no verifica. **Trátala como inexistente** | Prueba la anterior; si la clave rotó, hace falta la anterior |
| `7` | **El rol con el que se copia es privilegiado** (superusuario, o puede crear roles o bases, o saltarse RLS). No se ha escrito ninguna copia | Es una garantía de seguridad, no una avería: [`rotacion-secretos.md`](rotacion-secretos.md), sección «El rol de las copias es privilegiado». Con el rol equivocado no se copia |

### Resolución

```bash
# Reintento manual, con salida en directo
docker compose exec scheduler php artisan backup:run

# Si el problema era de espacio y ya se ha liberado, la retención sola:
docker compose exec scheduler bash /opt/kronoqr/scripts/backup.sh prune
```

**Mientras no haya una copia nueva verificada, la anterior sigue siendo la
buena**: `backup.sh` no borra ni sobrescribe nada hasta que la nueva está
escrita y comprobada, y nunca conserva menos de `BACKUP_MIN_COPIES`.

**Si la verificación falla pero la copia existe**, trátala como inexistente: o
la clave no es la que corresponde, o el fichero está dañado. Comprueba con la
copia anterior:

```bash
docker compose exec scheduler php artisan backup:verify --file="${BACKUP_PATH}/daily/<copia-anterior>.dump.enc"
```

**Si el mensaje habla de permisos o de no poder conectar como
`fichaje_backup`** y la instalación viene de la 2.1.0: `update.sh` crea ese rol
y escribe sus credenciales en el `.env` de la versión nueva. Comprueba que
`BACKUP_DB_USERNAME` vale `fichaje_backup` y que `BACKUP_DB_PASSWORD` no está
vacía en el `.env` **del directorio desde el que corre la instalación**, y
recrea el planificador con `docker compose up -d scheduler`. Para cambiarle la
contraseña: [`rotacion-secretos.md`](rotacion-secretos.md), «Rotar
`fichaje_backup`».

---

## 3. No llega ninguna métrica (el silencio)

Es el peor de los fallos y por eso tiene alerta propia: si el trabajo programado
no llega a ejecutarse, **ninguna métrica cambia** y sin la regla `absent(...)`
nadie se entera hasta que hace falta restaurar.

```bash
# ¿Está vivo el scheduler?
docker compose ps scheduler
docker compose logs --tail=50 scheduler | grep -i backup

# ¿Existen los ficheros de métricas y los está leyendo node-exporter?
ls -l "${BACKUP_PATH:-/var/backups/fichaje}"/metrics/
docker compose exec prometheus wget -qO- http://node-exporter:9100/metrics | grep kronoqr_backup | head

# ¿Está montado el destino? (típico con almacenamiento en red)
mount | grep "$(dirname "${BACKUP_PATH:-/var/backups/fichaje}")"
```

Las tres causas, por frecuencia: el destino en red no está montado, el
contenedor `scheduler` está parado, o `BACKUP_PATH` del `.env` apunta a un sitio
que no existe.

---

## 4. El archivado de WAL está detenido

**Esta es la urgente.** Si PostgreSQL no puede archivar, **no recicla** los
segmentos: los acumula en su volumen de datos hasta llenarlo, y cuando se llena
la base de datos se para. Además, mientras dure, el RPO deja de ser 15 minutos.

```bash
# 1. Qué dice PostgreSQL (fuente autorizada)
docker compose exec postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE" -c \
  "SELECT last_archived_wal, last_archived_time, failed_count, last_failed_wal, last_failed_time FROM pg_stat_archiver"

# 2. Por qué falla (el script dice qué hacer en cada caso)
docker compose logs --tail=100 postgres | grep -i archive

# 3. Cuánto WAL se está acumulando sin archivar
docker compose exec postgres sh -c 'ls -1 "$PGDATA"/pg_wal | wc -l'
```

| Causa | Síntoma en el log | Resolución |
| --- | --- | --- |
| Destino no montado | `el destino '...' no existe o no esta montado` | Monta el almacenamiento y `docker compose restart postgres` |
| Sin permisos | `no se puede escribir en '...'` | `chown` al uid de `postgres` del contenedor |
| Disco lleno | `no se ha podido comprimir` | [§5](#5-disco-de-copias-casi-lleno), **ya** |
| Segmento distinto ya archivado | `ya esta archivado con un contenido DISTINTO` | Dos servidores archivando en el mismo destino: sepáralos antes de seguir |
| **Falta la clave del WAL** | `falta BACKUP_WAL_KEY` o `BACKUP_WAL_KEY no es valida` | [§4.2](#42-falta-o-no-es-válida-la-clave-del-wal) |
| **Clave de desarrollo en producción** | `BACKUP_WAL_KEY es la clave de desarrollo` | Mismo arreglo, [§4.2](#42-falta-o-no-es-válida-la-clave-del-wal) |

**El archivado nunca escribe un segmento en claro**: sin clave válida
**falla** (RL-12) y PostgreSQL retiene el WAL, así que la señal llega por
`ArchivadoDeWalFallando` y, si dura, por `ArchivadoDeWalDetenido`.

Cuando el destino vuelve a estar disponible, PostgreSQL reintenta solo. No hay
que copiar nada a mano.

**Cómo leer la métrica del RPO** (`kronoqr_wal_*`, la publica `wal-metrics.sh`
cada minuto, en `BACKUP_PATH/metrics/kronoqr_wal.prom`):

| Métrica | Sana | Qué dice |
| --- | --- | --- |
| `kronoqr_wal_unarchived_age_seconds` | de 0 a ≈ 900 | **El RPO real ahora mismo**: cuánto lleva sin archivarse el dato más antiguo, **incluido el segmento en curso**. Es la que dispara `ArchivadoDeWalDetenido` |
| `kronoqr_wal_unarchived_segments` | 0 | Segmentos completos sin archivar (un archivado que va, pero atrasado) |
| `kronoqr_wal_archive_failing` | 0 | 1 si el último intento falló y no se ha recuperado |
| `kronoqr_wal_last_archived_age_seconds` | cualquiera | **Solo informativa.** Sin escrituras (madrugada) PostgreSQL no cierra segmentos y crece sin que haya ningún problema: no la uses para decidir |

### 4.1 «El WAL no se descifra» (clave distinta o fichero alterado)

El mensaje sale de `kronoqr-restore-wal`, de `restore-drill.sh --mode pitr` o
de `backup.sh verify` y nombra el **segmento** y el **motivo**:

| Motivo | Qué significa | Qué hacer |
| --- | --- | --- |
| `clave distinta o cabecera alterada` (`kid` distinto) | El segmento se cifró con otra clave: casi siempre una rotación de `BACKUP_ENCRYPTION_KEY` sin conservar la anterior | Pasa la clave anterior con `-e BACKUP_ENCRYPTION_KEY_PREVIOUS` (`docker compose run --rm -e BACKUP_ENCRYPTION_KEY_PREVIOUS restore ...`; solo para restaurar) y repite: `restore.sh`, el simulacro y `restore-drill --mode pitr` derivan de ella la subclave del WAL anterior solos. Para la recuperación manual de §6.4, ver cómo se obtiene allí. Si no la tienes, ese tramo de WAL no se puede reproducir: la recuperación llegará hasta el anterior |
| `el MAC no cuadra: el fichero esta alterado o danado` (mismo `kid`) | El fichero no es el que se escribió: corrupción del recurso de red **o manipulación** | Trátalo como incidente de seguridad si no hay una avería de almacenamiento que lo explique ([`brecha-de-seguridad.md`](brecha-de-seguridad.md)). No lo uses |
| `hueco` / segmento ausente con segmentos posteriores | Alguien ha borrado un segmento intermedio, o el destino perdió ficheros | Restaura la copia desde un soporte que lo conserve; si no existe, la recuperación **se detiene ahí** (a propósito, ver §6.4) |

### 4.2 Falta o no es válida la clave del WAL

Síntoma: `falta BACKUP_WAL_KEY` en `docker compose logs postgres`, o `doctor.sh`
dice que la clave del WAL no deriva de la maestra. **PostgreSQL está reteniendo
WAL**: arréglalo hoy.

```bash
# 1. Recalcula la clave a partir de la maestra (no se imprime nada si no pides nada más)
sudo bash /opt/kronoqr/scripts/backup.sh derive-wal-key --write-env /opt/kronoqr/.env
# 2. Recrea PostgreSQL para que la lea
docker compose up -d postgres
# 3. Comprueba que archiva (a los pocos segundos)
docker compose exec postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE" -c \
  "SELECT pg_switch_wal()" && sleep 10 && docker compose exec postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tc \
  "SELECT failed_count, last_archived_wal FROM pg_stat_archiver"
```

La clave del WAL **se deriva siempre de `BACKUP_ENCRYPTION_KEY`**: no inventes
una a mano, porque la restauración la recalcula a partir de la maestra y no
encontraría tus segmentos. `doctor.sh` compara la del `.env` con la derivada y
con el `kid` del último segmento archivado.

### 4.3 No llega la medida del RPO

`MedicionDeWalAusente`: el exportador no publica. Es una avería **del
`scheduler`**, que además es quien hace las copias:

```bash
docker compose ps scheduler
docker compose logs --tail=50 scheduler | grep -i wal-metrics
docker compose exec scheduler bash /opt/kronoqr/scripts/wal-metrics.sh   # sale con 2 y dice qué falta
ls -l "${BACKUP_PATH}"/metrics/kronoqr_wal.prom
```

Mientras no haya medida **no se sabe si hay RPO**: comprueba a mano `pg_stat_archiver`
(§4, paso 1) y atiende el `scheduler` hoy.

### 4.4 `archive_timeout` fuera de rango

Sin `archive_timeout=900` el RPO deja de ser 15 minutos: pasa a ser lo que
tarde en llenarse un segmento de 16 MB. Lo fija `infra/compose.prod.yaml`
(y `compose.dev.yaml`): si alguien lo ha editado, restáuralo y
`docker compose up -d postgres`. `docker compose exec postgres psql -U "$DB_USERNAME" -d "$DB_DATABASE" -tc "SHOW archive_timeout"` debe decir `15min`.

### 4.5 Segmentos antiguos sin cifrar (actualización desde la 2.1.0)

La 2.1.0 archivaba el WAL **sin cifrar** (`<segmento>.gz`). `update.sh` los cifra
en sitio en los minutos siguientes (dentro del contenedor de `postgres`, sin
tocar su fecha) y el archivado nuevo ya cifra. Para ver cuántos faltan:

```bash
docker compose exec -T postgres sh -c 'ls "$KRONOQR_WAL_ARCHIVE_DIR"/*.gz 2>/dev/null | wc -l'
# Si no baja a 0 en unos minutos, lánzalo a mano (es idempotente):
docker compose exec -T postgres kronoqr-wal-migrate
```

**Las copias de `BACKUP_PATH/wal` que hicieras en otros soportes antes de
actualizar contienen WAL en claro con datos personales: destrúyelas.**

---

## 5. Disco de copias casi lleno

```bash
df -h "${BACKUP_PATH:-/var/backups/fichaje}"
du -sh "${BACKUP_PATH}"/daily "${BACKUP_PATH}"/base "${BACKUP_PATH}"/wal
```

Qué ajustar, por orden de preferencia:

1. **Ampliar el almacenamiento.** Es una obligación legal de 4 años (RL-05): el
   registro tiene que caber.
2. **Bajar `BACKUP_RETENTION_DAYS`** en el `.env`. Nunca deja menos de
   `BACKUP_MIN_COPIES` copias, aunque el número sea muy bajo.
3. **Bajar `BACKUP_WAL_RETENTION_DAYS`** (por defecto 8). **Debe seguir siendo
   mayor que el intervalo entre copias físicas**: sin la copia física anterior,
   el WAL archivado no reconstruye nada.

```bash
docker compose exec scheduler bash /opt/kronoqr/scripts/backup.sh prune
```

---

## 6. Restaurar (RTO ≤ 4 h)

> **Una restauración en producción se documenta.** `restore.sh` escribe un
> informe en `BACKUP_PATH/reports/` con quién, cuándo, qué copia y con qué
> resultado. **Adjúntalo al parte del incidente** (regla dura 6): es la prueba
> de qué se hizo con el registro horario y de por qué el registro de un día
> concreto cambió.

### 6.1 Reparto del tiempo, medido

| Paso | Tiempo típico | Acumulado |
| --- | --- | --- |
| Diagnóstico y decisión de restaurar | 15–30 min | 0:30 |
| Comprobación previa (`--dry-run`) | 1–3 min | 0:35 |
| Parar servicios que escriben | 1 min | 0:36 |
| Restauración e intercambio de bases | 5–20 min | 1:00 |
| Comprobaciones y arranque | 10 min | 1:10 |
| Margen para lo que salga mal | — | **< 4 h** |

El dato real de tu instalación está en
`kronoqr_backup_restore_drill_duration_seconds`, que publica el simulacro
trimestral. Si crece, el RTO se está estrechando.

### 6.2 Procedimiento

```bash
# 0. SIEMPRE primero: comprueba sin tocar nada.
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --dry-run

# 1. Para lo que escribe. El fichaje NO se detiene: los quioscos encolan en
#    local y sincronizan al volver (regla dura 19).
docker compose stop app horizon scheduler reverb

# 2. Restaura. Se restaura en una base NUEVA y solo al final se intercambian
#    los nombres: hasta ese instante la base viva no se toca.
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --yes

# 3. Arranca y comprueba
docker compose up -d
curl -sk https://localhost/api/v1/health
```

Al terminar, `restore.sh` deja un asiento `system.restored_from_backup` en `audit_log`
(§6.7). Si sale con `6`, la base está restaurada y solo falta ese asiento: sigue
§6.7 y **no repitas la restauración**.

**Lo que la restauración no repone, a propósito** (ADR-045):

- **El volumen `app-storage`**: exportaciones íntegras, informes en diferido,
  paquetes de diagnóstico, exportaciones para la Inspección por consola y el
  estado de la telemetría. Todo eso caduca en días o se regenera desde la base,
  y meterlo en la copia alargaría de 7 a 30 días la vida de una copia completa
  de los datos personales. `restore.sh` no lo toca.
- **Los informes de retención** de `BACKUP_PATH/reports/retention`: describen
  purgas que ocurrieron, aunque la base vuelva a un momento anterior.

**Consecuencia esperada, y el informe de `restore.sh` la anuncia:** las
exportaciones e informes que la base restaurada recuerda como disponibles y cuyo
fichero ya no está pasan a «Caducada» en la primera purga (la horaria para la
exportación íntegra, la de las 04:25 UTC para los informes) y dejan un asiento
`data_export.file_missing` o `report_export.file_missing`; si alguno no había
caducado, puede sonar `FicheroGeneradoDesaparecidoAntesDeCaducar`. **Tras una
restauración no es una brecha**: anótalo en el parte y pide de nuevo la
exportación o el informe que haga falta. Los ficheros generados después de la
copia, que la base ya no conoce, los borra la purga al cumplir su plazo. El
detalle, en [`ficheros-generados.md`](ficheros-generados.md) §2 y en
[`../cliente/operacion.md`](../cliente/operacion.md) §18. Si se restaura en un
servidor nuevo, el volumen está vacío y la telemetría estrena identificador.

Las tres órdenes de `restore.sh` van por el servicio **`restore`**, no por
`app`: es un contenedor de un solo uso que recibe la credencial del rol de
migración (restaurar exige crear y renombrar bases), hace su trabajo y
desaparece con `--rm`. `docker compose up -d` **no** lo arranca, y `--no-deps`
evita que la orden vuelva a levantar lo que acabas de parar. Solo necesita que
`postgres` esté en pie.

Para restaurar una copia concreta, no la última:

```bash
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh --list
docker compose run --rm --no-deps restore bash /opt/kronoqr/scripts/restore.sh \
  --file "${BACKUP_PATH}/daily/kronoqr-<marca>.dump.enc" --yes
```

### 6.3 Vuelta atrás

La base anterior se conserva como `<base>_pre_restore_<marca>` durante
`--keep-previous` días (7 por defecto). Volver atrás son dos renombrados, y el
informe de la restauración los deja escritos literalmente:

```sql
ALTER DATABASE "fichaje" RENAME TO "fichaje_descartada";
ALTER DATABASE "fichaje_pre_restore_<marca>" RENAME TO "fichaje";
```

Con los servicios parados, igual que en la restauración.

### 6.4 Recuperar a un punto en el tiempo (RPO de 15 min)

El volcado diario devuelve el estado **de esa madrugada**. Para perder como
mucho 15 minutos hay que reproducir el WAL archivado sobre la **copia física**.
Todo se hace con la imagen de PostgreSQL **del producto**, que trae las dos
herramientas que entienden el formato cifrado (`kronoqr-extract-base` y
`kronoqr-restore-wal`, ADR-049). La clave va **solo por entorno**
(`-e NOMBRE`, sin valor en la orden):

```bash
# 0. Ensáyalo SIEMPRE antes sobre un contenedor limpio, sin tocar nada:
sudo bash /opt/kronoqr/scripts/restore-drill.sh --mode pitr

# 1. Copia física más reciente
ls -t "${BACKUP_PATH}"/base/

# 2. Verifica su autenticidad y despliégala sobre un PGDATA vacío. `--user 0:0`: las
#    copias son del usuario 1000 (0750) y `postgres` no las lee; el PGDATA queda como
#    `postgres`. `--recovery` deja `recovery.signal`.
IMG="${IMAGE_REGISTRY:-ghcr.io/kronoqr}/postgres:${IMAGE_TAG}"
docker volume create pgdata-restaurado
docker run --rm --user 0:0 -e BACKUP_ENCRYPTION_KEY \
  -v pgdata-restaurado:/restaurado \
  -v "${BACKUP_PATH}/base:/base:ro" "$IMG" \
  kronoqr-extract-base /base/<copia>.tar.gz.enc /restaurado --recovery

# 3. Arráncalo en recuperación con el WAL archivado. La clave del WAL llega por entorno;
#    NUNCA se escribe en postgresql.auto.conf ni en el restore_command.
#    BACKUP_WAL_KEY_PREVIOUS solo hace falta si se rotó BACKUP_ENCRYPTION_KEY: es la
#    subclave derivada de la maestra ANTERIOR (nunca una clave distinta):
#      export BACKUP_WAL_KEY_PREVIOUS="$(BACKUP_ENCRYPTION_KEY="$ANTERIOR" sudo -E bash backup.sh derive-wal-key --print)"
#    (con la anterior en la variable ANTERIOR de tu shell, sin escribirla en la orden).
docker run --rm -e BACKUP_WAL_KEY -e BACKUP_WAL_KEY_PREVIOUS \
  -v pgdata-restaurado:/var/lib/postgresql/data \
  -v "${BACKUP_PATH}/wal:/wal:ro" -e KRONOQR_WAL_ARCHIVE_DIR=/wal "$IMG" \
  postgres -c restore_command='kronoqr-restore-wal %f %p' \
           -c recovery_target_time='2026-01-15 06:00:00+00' -c recovery_target_action=promote
```

(Si `BACKUP_WAL_KEY` no está en tu entorno de `root`, exporta la que deriva
`backup.sh derive-wal-key` antes de la orden; no la escribas en la línea.)
`recovery_target_time` va **en UTC** (regla dura 3). Sin
`recovery_target_time` se reproduce todo el WAL disponible, que es lo que se
quiere tras una pérdida de disco.

**La recuperación se detiene, a propósito, si el WAL no es de fiar.**
`kronoqr-restore-wal` devuelve `exit 1` (PostgreSQL lo entiende como «no hay más
WAL» y promociona) **solo** cuando, tras comprobarlo dos veces, **no existe ni el
segmento ni ninguno posterior** de la misma línea temporal: el final real del
archivo. En cualquier otro caso —el MAC no cuadra, la clave es distinta, falta
un segmento intermedio, el fichero no se puede leer— devuelve **`exit 200`** y
PostgreSQL **aborta con un error fatal** («restore_command failed»): sin esa
parada, la base «recuperaría con éxito» hasta el segmento anterior y se
perderían datos sin que nadie lo viera. El error nombra el segmento y el
motivo (§4.1). Qué hacer:

1. **No promociones ni uses esa base.** Se queda en recuperación: es lo correcto.
2. Resuelve el motivo (§4.1): clave anterior, otro soporte con el segmento, etc.
3. Si no se puede, repite con `recovery_target_time` **anterior** al segmento
   que falla. El asiento de auditoría de esa restauración debe decir
   `wal_integrity=aborted_at:<segmento>` y el LSN al que llegaste.

Tras una recuperación **completa**, el asiento lleva `wal_integrity=authenticated`
y `legacy_wal=N` (§6.8: cuántos segmentos heredados, sin autenticar, se
reprodujeron).

---

### 6.5 La vuelta atrás automática de `update.sh` pasa por aquí

El actualizador (tarea 5.7) no tiene un camino de restauración propio: cuando
una migración o la verificación de la versión nueva fallan, **para lo que
escribe y ejecuta este mismo `restore.sh --yes`** con la copia que hizo en su
paso 3, y después relanza la versión anterior desde su directorio. Lo que eso
deja en el servidor es exactamente lo de §6.2 y §6.3: la base fallida
conservada como `<base>_pre_restore_<marca>` durante 7 días, un informe en
`BACKUP_PATH/reports/restore-<marca>.log` **y otro en
`BACKUP_PATH/reports/update-<marca>.log`** con el paso en que se paró y por
qué. Ese segundo informe es el que se adjunta al caso.

### 6.6 La restauración se niega con salida `7` (la copia toca los roles)

`restore.sh` (y `restore-drill.sh --mode database`) toma, antes del
`pg_restore`, una foto de los atributos y las pertenencias de rol del clúster
(`pg_roles` y `pg_auth_members`, ordenadas y sin contraseñas) y otra después. Si
difieren, **no intercambia las bases**: elimina la base de trabajo, intenta
devolver los roles a su estado anterior, muestra qué ha cambiado (`-` antes, `+`
después) y sale con `7`. `pg_restore` corre como superusuario y ejecuta lo que
traiga el archivo: una copia con `ALTER ROLE fichaje_app SUPERUSER` dentro
dejaría la aplicación con poder para reescribir el registro.

1. **No uses esa copia.** Prueba con la anterior (`backup.sh list`,
   `restore.sh --file <anterior> --yes`).
2. Comprueba que los roles están como antes (el mensaje dice si la reversión se
   ha comprobado; si dice que NO, corrige a mano los que difieran de la lista):
   `SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls,
   rolreplication FROM pg_roles ORDER BY 1`.
3. Una copia manipulada es un incidente de seguridad: quién puede escribir en
   `BACKUP_PATH` y quién tiene `BACKUP_ENCRYPTION_KEY` (el `scheduler`) es el
   perímetro. Sigue [`brecha-de-seguridad.md`](brecha-de-seguridad.md) y rota
   la clave de cifrado y `fichaje_backup`
   ([`rotacion-secretos.md`](rotacion-secretos.md) §3 y §5).

Lo que esta guarda **no** cubre: un archivo manipulado ejecutado por un
superusuario puede hacer más que cambiar un rol. Frente a quien tiene la clave
de cifrado, la garantía completa exige sacar la copia del runtime (ADR-042).

Si el actualizador sale con `5`, la restauración quedó a medias y hay que
terminarla a mano: las órdenes exactas están en su mensaje y en
[`actualizacion-cliente.md`](actualizacion-cliente.md) §5. Son las de §6.2 con
las rutas de los dos paquetes.

### 6.7 El asiento de la restauración (PR1) y la salida `6`

Restaurar descarta un intervalo del registro horario, y eso **tiene que constar
dentro del propio registro**, no solo en un fichero de informe. Por eso, tras
intercambiar las bases, `restore.sh` deja en `audit_log` un asiento
`system.restored_from_backup` con: el nombre de la copia (sin ruta), el instante
en que se hizo, su huella, la versión y **`chain_before`: la punta de la cadena
que se descarta**, leída justo antes del intercambio. `chain_before` no encaja
con el `prev_hash` del asiento, y esa discrepancia es la prueba, dentro de la
cadena, de que hubo un intervalo que ya no está. `compliance:verify-audit-chain`
sigue dando verde.

- Lo escribe el propio servicio `restore` con el rol de migración que ya tiene:
  **no se da ninguna credencial nueva al runtime** (AUD-1, ADR-042).
- Solo se escribe si la base de destino es la de la instalación. Con
  `--database otra_base` (pruebas, simulacros) no se descarta nada y no hay
  asiento.
- Si el entorno no puede escribirlo (sin `php`/`artisan`), `restore.sh` **se
  niega a empezar** con salida `2` y no toca nada.
- `update.sh` llama a `restore.sh` con `--audit-by-caller` porque escribe su
  propio asiento de vuelta atrás, con el paso y el motivo del fallo. No es un
  atajo para restauraciones manuales: si lo usas a mano, el intervalo
  descartado no consta en el registro.

**Si `restore.sh` sale con `6` (asiento pendiente).** La base **está restaurada y
en servicio**; solo falta el asiento. **No repitas la restauración.**

1. Copia la orden del mensaje (o de `BACKUP_PATH/reports/restore-<marca>.log`,
   línea «Para escribirlo») y ejecútala. Lleva el JSON ya hecho:

   ```bash
   docker compose run --rm --no-deps -T -e DB_CONNECTION=pgsql_migrator migrate \
     php artisan compliance:record-system-event system.restored_from_backup \
     --data='{"backup_file":"...","backup_taken_at":"...","failed_step":"manual_restore","reason":"manual_restore",...}'
   ```

2. Comprueba la cadena: `docker compose exec app php artisan compliance:verify-audit-chain`
   debe terminar con `0`.
3. Si la orden falla con «El payload no se admite», copia el mensaje en el parte y
   avisa al fabricante: la causa es que la versión instalada no conoce el motivo
   `manual_restore`. **No edites `audit_log` a mano** (es solo-append y tiene
   cadena por hash). El informe conserva el JSON para escribirlo tras actualizar.
4. Mientras esté pendiente, anótalo en el parte del incidente: es un hueco en el
   registro que hay que cerrar.

### 6.8 La copia se niega por integridad (salida `6`) y las copias de la 2.1.0

Desde la 2.2.0 las copias llevan un **MAC** dentro del fichero (ADR-049).
`restore.sh` y `restore-drill.sh` **se niegan con salida `6`**, sin tocar nada,
si: falta el `.sha256`; el MAC no cuadra (un bit cambiado en la cabecera, el
cuerpo o el final); el nombre de la cabecera no es el del fichero (copia
renombrada o sustituida); falta o no cuadra el `.manifest.mac`; o la copia es
**de la 2.1.0** (sin MAC) y no se ha pedido expresamente.

Antes de restaurar, **comprueba la fecha que enseña la herramienta**: sale de la
cabecera autenticada (`copia creada el …`), no del nombre del fichero ni del
`LATEST`. Si no es la que esperas, alguien ha puesto una copia anterior en su
lugar: no sigas.

| Mensaje | Qué hacer |
| --- | --- |
| `el MAC no cuadra` (mismo `kid`) | Corrupción o manipulación. Prueba con la anterior (`restore.sh --list`); si no hay avería que lo explique, [`brecha-de-seguridad.md`](brecha-de-seguridad.md) |
| `clave distinta o cabecera alterada` | Rotación de clave: pasa la anterior con `-e BACKUP_ENCRYPTION_KEY_PREVIOUS` a `docker compose run restore`, solo para restaurar |
| `sin .sha256` | La copia está incompleta o la han tocado. No la uses |
| `copia heredada de la 2.1.0` | Ver abajo |

**Copias de la 2.1.0.** Están cifradas pero **no autenticadas**: el `.sha256`
junto a ellas no prueba nada frente a quien pueda escribir en el destino. Se
pueden restaurar con la bandera explícita, que **se pasa en cada orden y nunca
se deja en el `.env`** (lo que ponga el `.env` no cuenta, y `doctor.sh` lo avisa):

```bash
docker compose run --rm --no-deps restore \
  bash /opt/kronoqr/scripts/restore.sh --accept-unauthenticated --file <copia>.dump.enc --dry-run
```

Con la bandera, el `.sha256` **sigue siendo obligatorio** y debe coincidir, el
informe lo anota en su primera línea y el asiento `system.restored_from_backup`
lleva `integrity=legacy_accepted` (si no la usas: `integrity=authenticated`, con
`kqe_created` y `kid`). `doctor.sh` avisa si `KRONOQR_ACCEPT_UNAUTHENTICATED` está
en el `.env` o en una tabla de cron, y `restore.sh` y `restore-drill.sh` ignoran esa variable: la bandera es solo la opción `--accept-unauthenticated`. La vuelta atrás de `update.sh` la usa **solo** para la copia previa
que él mismo acaba de crear. La bandera desaparecerá cuando la versión mínima
desde la que se puede actualizar sea la 2.2.0 o posterior.

**Tras volver a la 2.1.0**, lo escrito por la 2.2.0 (`.gz.enc`, copias KQE1) **no
lo lee el `restore.sh` de la 2.1.0**: restaura con el paquete de la 2.2.0, que
`update.sh` deja como «anterior».

---

## 7. Simulacro trimestral (RNF-D-05, RQ-09)

**Una copia no verificada no es una copia, y una copia que nunca se ha
restaurado no está verificada del todo.** El simulacro levanta un contenedor
limpio, restaura la última copia y comprueba dos cosas que ninguna verificación
barata demuestra: que **todas** las claves ajenas se satisfacen con los datos
restaurados, y que los **conteos por tabla** cuadran con el manifiesto.

```bash
# En el servidor del cliente (necesita Docker, que ya está)
sudo bash /opt/kronoqr/scripts/restore-drill.sh

# Sin Docker disponible, contra una instancia de PRUEBAS (nunca la de producción)
sudo bash /opt/kronoqr/scripts/restore-drill.sh --mode database

# Recuperación a un punto en el tiempo: copia física + WAL cifrado, en un contenedor limpio
sudo bash /opt/kronoqr/scripts/restore-drill.sh --mode pitr
```

El modo `database` **no** se lanza con el servicio `restore`: ese servicio
apunta a la base de producción con el rol de migración, y el simulacro crearía
su base de usar y tirar en la misma instancia que sostiene el registro legal.
En el servidor del cliente se usa el modo por defecto, que no toca PostgreSQL.

No toca la instalación: ni la base de producción, ni los contenedores del
producto, ni las copias, que se abren en lectura. El volcado descifrado vive y
muere dentro del contenedor del simulacro; nunca toca el disco del servidor.

**Automatizarlo, que es lo que exige RNF-D-05.** Entrada de cron en el servidor
del cliente, el día 1 de cada trimestre:

```cron
0 4 1 1,4,7,10 * /opt/kronoqr/scripts/restore-drill.sh >> /var/log/kronoqr-drill.log 2>&1
```

El simulacro lee el `.env` de la instalación (`/opt/kronoqr/.env`, junto al directorio `scripts/`) y el `.env` es de `root` con permisos `0600`: por eso se lanza con `sudo` a mano y, en el cron, desde la tabla de `root`. Si tu `.env` está en otro sitio, indícalo con `BACKUP_ENV_FILE=<ruta>` delante de la orden.

En el repositorio del fabricante lo ejecuta
[`.github/workflows/backup-drill.yml`](../../.github/workflows/backup-drill.yml)
con la misma cadencia, sobre datos de prueba: eso demuestra que **el
procedimiento** funciona. La entrada de cron demuestra que funcionan **las
copias de este cliente**, que es lo que preguntará una inspección. Hacen falta
las dos.

**Qué guardar de cada simulacro.** El informe de
`BACKUP_PATH/reports/drill-<marca>.log`. Es la evidencia documental de RNF-D-05
y de RQ-09, y no contiene ni un dato personal.

**Si el simulacro falla**, la última copia no sirve:

1. Repítelo con la copia anterior: `restore-drill.sh --file <copia-anterior>`.
2. Si esa sí pasa, el problema es de la copia nueva: relánzala
   (`docker compose exec scheduler php artisan backup:run`, §2) y vuelve a
   probar.
3. Si fallan varias, **es un incidente**: la instalación lleva tiempo sin copias
   utilizables. Escala al responsable del sistema el mismo día.

---

## 8. Qué no hacer

- **No borrar el WAL a mano** para hacer sitio. Deja la copia física anterior
  inservible y el RPO pasa a ser de 24 h sin que nada lo avise. Usa
  `BACKUP_WAL_RETENTION_DAYS`.
- **No restaurar sin `--dry-run` antes.** Cuesta un minuto y descarta las tres
  causas de fracaso más frecuentes: clave que no corresponde, copia corrupta y
  espacio insuficiente.
- **No rotar `BACKUP_ENCRYPTION_KEY` sin custodiar la anterior.** Las copias
  hechas con la clave vieja solo se abren con la clave vieja. Procedimiento:
  `rotacion-secretos.md`.
- **No apuntar dos instalaciones al mismo `BACKUP_PATH`.** El archivado de WAL
  lo detecta y se niega, pero los volcados se mezclarían.
- **No copiar la copia fuera de la infraestructura del cliente** para
  «analizarla». Contiene el registro horario completo de la plantilla
  (regla dura 16, RL-14).
