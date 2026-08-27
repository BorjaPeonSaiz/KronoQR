# Runbook — copias de seguridad: fallo, restauración y simulacro

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Copia de seguridad fallida o no
verificada | cualquiera | Crítica | IT del cliente»*), definidas en
[`infra/observability/prometheus/rules/backup.yml`](../../infra/observability/prometheus/rules/backup.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `CopiaDeSeguridadFallida` | cualquiera, `for: 5m` | Crítica | IT del cliente | [§2](#2-la-copia-ha-fallado) |
| `CopiaDeSeguridadSinVerificar` | verificación en rojo o > 26 h sin verificar | Crítica | IT del cliente | [§2](#2-la-copia-ha-fallado) |
| `CopiaDeSeguridadAusente` | no llega ninguna métrica, `for: 30m` | Crítica | IT del cliente | [§3](#3-no-llega-ninguna-métrica-el-silencio) |
| `ArchivadoDeWalDetenido` | > 30 min sin archivar, o fallos en 1 h | Crítica | IT del cliente | [§4](#4-el-archivado-de-wal-está-detenido) |
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
| `kronoqr-archive-wal` | Archiva un segmento de WAL cada 15 min como mucho | `BACKUP_PATH/wal/` |
| `backup.sh verify` | Huella + descifrado + `pg_restore --list` | — |
| `restore.sh` | Restauración con intercambio de bases y vuelta atrás | `BACKUP_PATH/reports/` |
| `restore-drill.sh` | Simulacro en contenedor limpio, trimestral | `BACKUP_PATH/reports/` |

**Objetivos que sostiene** (doc 01 §6.2): **RPO ≤ 15 min** — volcado diario +
copia física semanal + WAL archivado con `archive_timeout=900`. **RTO ≤ 4 h** —
el procedimiento de la §6, medido.

**Las tres cosas que hay que saber sin buscarlas:**

1. **Sin `BACKUP_ENCRYPTION_KEY` no hay restauración posible.** Una copia solo se
   abre con la clave con la que se hizo. Si se rotó, hace falta la anterior.
2. **La copia no sale de aquí.** Vive en la infraestructura del cliente; el
   fabricante no la recibe ni la custodia (regla dura 16, RL-14).
3. **Nada de lo que imprimen estos scripts contiene datos personales**
   (regla dura 21): rutas, nombres de tabla y números. Se pueden pegar en un
   parte de incidencia tal cual.

---

## 2. La copia ha fallado

### Diagnóstico

```bash
# 1. Qué dice la última ejecución (código y motivo)
docker compose exec app php artisan backup:verify

# 2. Qué copias hay y desde cuándo
docker compose exec app bash /opt/kronoqr/scripts/backup.sh list

# 3. Métricas publicadas (lo que ve la alerta)
cat "${BACKUP_PATH:-/var/backups/fichaje}"/metrics/*.prom
```

Salida esperada de una instalación sana: `kronoqr_backup_last_result{type="dump"} 1`,
`kronoqr_backup_last_verify_result 1` y una marca de tiempo de hace menos de un día.

### Códigos de salida y qué significa cada uno

| Código | Significa | Qué hacer |
| --- | --- | --- |
| `1` | La copia o su verificación fallaron | Sigue el mensaje: dice qué falló y dónde |
| `3` | Falta una herramienta o no se llega a la base de datos. **Nada se ha tocado** | `docker compose ps`; levanta `postgres` |
| `4` | Destino no escribible o sin espacio. **Nada se ha tocado** | [§5](#5-disco-de-copias-casi-lleno) |
| `5` | Clave de cifrado ausente o incorrecta | Comprueba `BACKUP_ENCRYPTION_KEY` en el `.env` |

### Resolución

```bash
# Reintento manual, con salida en directo
docker compose exec app php artisan backup:run

# Si el problema era de espacio y ya se ha liberado, la retención sola:
docker compose exec app bash /opt/kronoqr/scripts/backup.sh prune
```

**Mientras no haya una copia nueva verificada, la anterior sigue siendo la
buena**: `backup.sh` no borra ni sobrescribe nada hasta que la nueva está
escrita y comprobada, y nunca conserva menos de `BACKUP_MIN_COPIES`.

**Si la verificación falla pero la copia existe**, trátala como inexistente: o
la clave no es la que corresponde, o el fichero está dañado. Comprueba con la
copia anterior:

```bash
docker compose exec app php artisan backup:verify --file="${BACKUP_PATH}/daily/<copia-anterior>.dump.enc"
```

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

Cuando el destino vuelve a estar disponible, PostgreSQL reintenta solo. No hay
que copiar nada a mano.

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
docker compose exec app bash /opt/kronoqr/scripts/backup.sh prune
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
docker compose exec app bash /opt/kronoqr/scripts/restore.sh --dry-run

# 1. Para lo que escribe. El fichaje NO se detiene: los quioscos encolan en
#    local y sincronizan al volver (regla dura 19).
docker compose stop app horizon scheduler reverb

# 2. Restaura. Se restaura en una base NUEVA y solo al final se intercambian
#    los nombres: hasta ese instante la base viva no se toca.
docker compose run --rm app bash /opt/kronoqr/scripts/restore.sh --yes

# 3. Arranca y comprueba
docker compose up -d
curl -sk https://localhost/api/v1/health
```

Para restaurar una copia concreta, no la última:

```bash
docker compose exec app bash /opt/kronoqr/scripts/backup.sh list
docker compose run --rm app bash /opt/kronoqr/scripts/restore.sh \
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
mucho 15 minutos hay que reproducir el WAL archivado sobre la **copia física**:

```bash
# 1. Copia física más reciente
ls -t "${BACKUP_PATH}"/base/

# 2. Descífrala y despliégala sobre un PGDATA vacío
openssl enc -d -aes-256-cbc -md sha512 -pbkdf2 -iter 600000 \
  -in "${BACKUP_PATH}/base/<copia>.tar.gz.enc" | tar -xzf - -C /var/lib/postgresql/data-restaurado

# 3. Deja escrito el punto de recuperación y de dónde sacar el WAL
cat >> /var/lib/postgresql/data-restaurado/postgresql.auto.conf <<'CONF'
restore_command = 'gunzip -c /var/backups/fichaje/wal/%f.gz > %p'
recovery_target_time = '2026-01-15 06:00:00+00'
CONF
touch /var/lib/postgresql/data-restaurado/recovery.signal

# 4. Arranca ese PGDATA y espera a que termine la recuperación
```

`recovery_target_time` va **en UTC** (regla dura 3). Sin `recovery_target_time`
se reproduce todo el WAL disponible, que es lo que se quiere tras una pérdida de
disco.

---

## 7. Simulacro trimestral (RNF-D-05, RQ-09)

**Una copia no verificada no es una copia, y una copia que nunca se ha
restaurado no está verificada del todo.** El simulacro levanta un contenedor
limpio, restaura la última copia y comprueba dos cosas que ninguna verificación
barata demuestra: que **todas** las claves ajenas se satisfacen con los datos
restaurados, y que los **conteos por tabla** cuadran con el manifiesto.

```bash
# En el servidor del cliente (necesita Docker, que ya está)
bash /opt/kronoqr/scripts/restore-drill.sh

# Sin Docker disponible, contra una instancia de PRUEBAS (nunca la de producción)
bash /opt/kronoqr/scripts/restore-drill.sh --mode database
```

No toca la instalación: ni la base de producción, ni los contenedores del
producto, ni las copias, que se abren en lectura. El volcado descifrado vive y
muere dentro del contenedor del simulacro; nunca toca el disco del servidor.

**Automatizarlo, que es lo que exige RNF-D-05.** Entrada de cron en el servidor
del cliente, el día 1 de cada trimestre:

```cron
0 4 1 1,4,7,10 * /opt/kronoqr/scripts/restore-drill.sh >> /var/log/kronoqr-drill.log 2>&1
```

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
   (`php artisan backup:run`) y vuelve a probar.
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
