# Runbook — rotación de secretos

Procedimiento de rotación para los cinco secretos de una instalación (doc 02
§7.7): `APP_KEY`, las **claves HMAC del QR**, las credenciales de base de datos,
los tokens de dispositivo y la clave de copia de seguridad.

Se usa en dos situaciones muy distintas, y conviene decidir cuál es antes de
empezar:

| Situación | Ritmo | Prioridad |
| --- | --- | --- |
| **Rotación programada** | Anual, o según la política del cliente | Que nadie se quede sin fichar |
| **Sospecha de compromiso** | El mismo día | Cerrar la puerta, aunque cueste servicio |

**Impacto en el fichaje:** ninguno si se sigue el orden de cada sección. Los dos
secretos que sí pueden dejar a gente sin fichar son la clave HMAC del QR
—retirada antes de tiempo— y las credenciales de base de datos —cambiadas sin
reiniciar—. Los dos tienen su procedimiento abajo.

**Principio que gobierna todo este runbook** (§7.7, regla dura 13):

> El instalador **genera los secretos en el servidor del cliente y nunca los
> transmite**. Nada de secretos en el repositorio.

Ningún comando de KronoQR imprime, pide por parámetro ni almacena material de
clave. Si un procedimiento te pide pegar un secreto en una terminal compartida,
en un ticket o en un chat, está mal: los argumentos de un comando acaban en
`ps`, en el historial del intérprete y en el registro de cualquier guion que lo
llame.

Todos los comandos se ejecutan dentro del contenedor:

```bash
docker compose -f docker-compose.yml exec -T app <comando>
```

---

## 0. Qué genera el instalador, y cuál de estos secretos es cuál

Desde la tarea 5.4, `install.sh` genera **todos** los secretos de una
instalación nueva con `openssl`, en el servidor del cliente, y los escribe en un
`.env` con permisos `0600`. Ninguno se imprime por pantalla ni queda en el log
del instalador, y eso se comprueba en cada publicación de versión.

| Variable que escribe `install.sh` | Cómo se genera | Se rota en |
| --- | --- | --- |
| `APP_KEY` | `base64:` + 32 bytes aleatorios | §2 |
| `QR_SIGNING_KEY_CURRENT` | 32 bytes aleatorios en base64 | §1 (runbook propio) |
| `QR_SIGNING_KEY_CURRENT_ID` | 2 caracteres hexadecimales | §1 |
| `DB_PASSWORD` | 32 caracteres alfanuméricos | §3 |
| `DB_MIGRATION_PASSWORD` | Íd. | §3 |
| `BACKUP_DB_PASSWORD` | 32 caracteres alfanuméricos, **propia** (no es la del migrador): la usa `fichaje_backup`, un rol de solo lectura con `REPLICATION` | §3 |
| `REVERB_APP_ID` / `_KEY` | 8 y 16 bytes en hexadecimal | §6 bis |
| `REVERB_APP_SECRET` | 32 bytes aleatorios en base64 | §6 bis |
| `BACKUP_ENCRYPTION_KEY` | 32 bytes aleatorios en base64 | §5 |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | 32 bytes aleatorios en base64 (es exactamente lo que hace libsodium al crear una privada X25519; la pública se deriva de ella) | §6 bis |
| `GRAFANA_ADMIN_PASSWORD` | 32 caracteres alfanuméricos | §6 bis |

**Las contraseñas de base de datos son alfanuméricas a propósito.** Viajan por
una cadena de conexión y por un fichero de entorno, y ahí cada capa escapa los
caracteres especiales a su manera; el síntoma sería un `password authentication
failed` que no se parece a su causa. 32 caracteres alfanuméricos son unos 190
bits: de sobra.

**`DB_MAINTENANCE_PASSWORD` NO la genera el instalador**, y no es un descuido:
ADR-027 exige que ese rol —el único que puede soltar una partición vencida de
`audit_log`— no tenga credencial en el `.env` de la aplicación. Nace sin
contraseña, existe y no se puede usar por red, y se le asigna una en el momento
de la purga anual. Procedimiento en
[`../cliente/operacion.md`](../cliente/operacion.md), sección 9.

---

## 1. Claves HMAC del QR — tiene su propio runbook

**→ [`rotacion-clave-qr.md`](rotacion-clave-qr.md)**

Es la única rotación que dura semanas, porque implica reimprimir y volver a
entregar en mano una tarjeta física por persona (ADR-014). El mecanismo que lo
hace posible es el **solape**: dos claves activas identificadas por `key_id`, la
antigua verificando mientras se reimprime, y la retirada solo cuando el panel
confirma que no queda ninguna credencial activa con ese `key_id` (RF-QR-07).

Resumen de una línea, con el detalle en el runbook enlazado:

```bash
# 1. En el .env: PREVIOUS = la que había, CURRENT = 32 bytes nuevos con otro key_id
php artisan credentials:rotate-key                  # reemite, sin invalidar nada
php artisan credentials:print-batch --pending       # reimprimir en tandas
php artisan credentials:deliver <uuid> --by=...     # la entrega revoca la vieja
php artisan credentials:status --key-id=<saliente>  # quién falta
php artisan credentials:retire-key <saliente>       # se niega si queda alguien
# 6. En el .env: vaciar PREVIOUS
```

**No rotes la clave del QR y las credenciales de base de datos la misma
semana.** Si algo sale mal quieres saber cuál de las dos cosas fue.

---

## 2. `APP_KEY`

Cifra lo que Laravel cifra: cookies de sesión del panel, valores marcados como
`encrypted` y poco más. **No cifra el registro horario** ni los hashes de
credencial, así que rotarla no pone en riesgo ningún dato legal.

```bash
php artisan key:generate --show     # imprime la clave, NO reescribe ningún fichero
```

`--show` es deliberado: la aplicación lee su configuración del entorno del
contenedor y no de un `backend/.env`, así que `artisan` no tiene fichero que
reescribir (ver `.env.example`).

1. Copia el valor al `.env` o al gestor de secretos del servidor.
2. Reinicia la aplicación.
3. **Todas las sesiones del panel se caen**: quien esté dentro tendrá que volver
   a entrar. No afecta al quiosco —que se autentica con su token de
   dispositivo— ni al portal del empleado.

Avisa a RRHH antes: no es una avería, pero lo parece.

---

## 3. Credenciales de base de datos

Son **cuatro roles distintos** (ADR-033 y AUD-1) y se rotan por separado,
empezando por el que menos duele:

| Rol | Dónde vive | Qué pasa si se hace mal |
| --- | --- | --- |
| `fichaje_maintenance` | **Fuera del `.env`**, solo en la caja fuerte del operador | La purga por retención falla. Nadie deja de fichar |
| `fichaje_migrator` | `DB_MIGRATION_*` en el `.env`, que solo leen PostgreSQL y los servicios puntuales `migrate` y `restore`. **Ningún contenedor de runtime lo recibe** | El siguiente despliegue o restauración falla. Nadie deja de fichar |
| `fichaje_backup` | `BACKUP_DB_*`. **Solo lectura** (`pg_read_all_data` + `REPLICATION`). Lo lleva el `scheduler`, que lanza las copias | La copia diaria falla y salta la alerta de copia fallida. Nadie deja de fichar |
| `fichaje_app` | `DB_*`. **Es el runtime** | **El fichaje se cae entero** |

Para `fichaje_app`, el orden importa:

```sql
-- 1. Cambiar la contraseña en PostgreSQL
ALTER ROLE fichaje_app WITH PASSWORD '<nueva>';
```

```bash
# 2. Actualizar DB_PASSWORD en el .env
# 3. Reiniciar la aplicación y las colas
docker compose -f docker-compose.yml up -d app horizon scheduler
# 4. Comprobar de verdad, no solo que el contenedor arranca
php artisan credentials:status --no-metrics --quiet-table
```

Entre 1 y 3 **la aplicación no puede consultar la base de datos**. Hazlo fuera
de horario de entrada y salida de turnos, y recuerda que durante ese hueco el
quiosco **encola y no bloquea a nadie** (regla dura 19): los fichajes de esos
minutos llegan después, con su `occurred_at` real.

### Rotar `fichaje_backup` (las copias)

No toca el fichaje. **No uses la contraseña del migrador para esto**: es el
superusuario, y la razón de existir de este rol es que no esté en el entorno del
planificador.

```bash
# 1. Genera la nueva y aplícala; la contraseña entra por la ENTRADA ESTÁNDAR del
#    script, no por la línea de órdenes (no queda en `ps` ni en `docker inspect`).
nueva="$(openssl rand -base64 48 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-32)"
printf '%s\n' "${nueva}" | docker compose exec -T \
  -e DB_BACKUP_USERNAME=fichaje_backup postgres \
  /docker-entrypoint-initdb.d/03-backup-role.sh --password-stdin
# 2. Escribe la misma en BACKUP_DB_PASSWORD del .env y recrea el planificador
docker compose up -d scheduler
# 3. Comprueba con una copia de verdad
docker compose exec scheduler php artisan backup:run
```

El script es idempotente y **se niega a tocar** el rol si `BACKUP_DB_USERNAME`
coincide con el de migración, el de aplicación o el de mantenimiento (una 2.1.0
lo trae apuntando al migrador hasta que `update.sh` lo pasa al rol de solo
lectura): degradar al migrador dejaría la instalación sin quien pueda migrar.

Lo que este rol **no** protege: lee todo (la confidencialidad de la copia la da
`BACKUP_ENCRYPTION_KEY`, §5) y una copia física incluye los verificadores SCRAM
de los demás roles. Con contraseñas aleatorias de 32 caracteres no es explotable
en la práctica, pero no es cero. Tampoco puede leer un *objeto grande* de
PostgreSQL (`lo_import`): KronoQR no los usa y, si alguien crea uno, la copia
falla con un mensaje que lo dice.

---

## 4. Tokens de dispositivo del quiosco

Cada token vive `IDENTITY_DEVICE_TOKEN_DAYS` (90 de serie) y **rota solo**: en
el primer latido después de haber consumido
`IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD` (80 %, hacia el día 72) de su vida,
el servidor entrega a la tablet un token nuevo y deja el anterior en solape
`IDENTITY_DEVICE_TOKEN_OVERLAP_HOURS` (24 h) o hasta el primer uso del nuevo
(ADR-044).
No hay nada que programar. Cada relevo escribe `device.paired` en `audit_log`
con `rotation: true`, sin el token ni su hash; si una rotación falla, el latido
responde igual, se reintenta en el siguiente y el fallo queda en el histórico de
errores y en `kiosk_token_rotations_total{result="failed"}`.

El límite: una tablet que pasa **más de unos 18 días seguidos sin latido**
(apagada o sin red) puede no recoger el relevo y caducar; entonces vuelve a la
pantalla de emparejamiento y hay que desvincularla y volver a vincularla
([`../cliente/operacion.md`](../cliente/operacion.md) §18).

Rotación forzada de una tablet concreta —robo, extravío, baja del equipo—: se
**desvincula** desde el panel, con lo que su token queda revocado, ese quiosco
deja de poder enviar fichajes inmediatamente y hay que volver a emparejarlo. El
procedimiento está en [`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md) §5.2 y
queda en `audit_log`.

Antes de revocar, si la tablet todavía enciende: **déjala conectada hasta que su
cola local llegue a cero** (`kiosk_offline_queue_size{device}`). Los fichajes que
no se hayan sincronizado se pierden con el token, y son registro horario de
alguien.

### El rol de las copias es privilegiado (`backup.sh` sale con `7`)

`backup.sh run` comprueba, nada más conectar, que el rol con el que copia
(`SELECT rolsuper OR rolcreaterole OR rolcreatedb OR rolbypassrls FROM pg_roles
WHERE rolname = current_user`) **no** puede alterar nada. Si puede, se niega a
copiar, sale con `7` (*garantía de seguridad rota*, `docs/cliente/operacion.md`
§8), deja la métrica de copia como fallida —salta `CopiaDeSeguridadFallida`— y
`doctor.sh` marca el mismo fallo. No depende de que el `.env` esté bien: lo dice
el propio servidor.

**Causa habitual:** una instalación que viene de la 2.1.0 y todavía tiene
`BACKUP_DB_USERNAME=fichaje_migrator` (el superusuario). Con ese rol, quien
ejecute código en el `scheduler` podría reescribir el registro legal (AUD-1).

```bash
# 1. ¿Qué rol es y qué puede? (solo nombres y atributos, ninguna contraseña)
grep '^BACKUP_DB_USERNAME=' .env
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc \
  "SELECT rolname, rolsuper, rolcreaterole, rolcreatedb, rolbypassrls FROM pg_roles ORDER BY 1"'
# 2. Provisiona el rol de solo lectura con una contraseña nueva (entra por la
#    entrada estándar; el script quita además cualquier pertenencia extra)
nueva="$(openssl rand -base64 48 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-32)"
printf '%s\n' "${nueva}" | docker compose exec -T \
  -e DB_BACKUP_USERNAME=fichaje_backup postgres \
  /docker-entrypoint-initdb.d/03-backup-role.sh --password-stdin
# 3. Pon BACKUP_DB_USERNAME=fichaje_backup y BACKUP_DB_PASSWORD=<nueva> en el
#    .env, recrea el planificador y comprueba con una copia de verdad
docker compose up -d scheduler
docker compose exec scheduler php artisan backup:run
```

**Si el `scheduler` llegó a arrancar con la credencial del migrador, trátalo como
una exposición de esa credencial**: rota `fichaje_migrator` (§3), ejecuta
`php artisan compliance:verify-audit-chain` y, si algo no cuadra, sigue
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md).

---

## 5. Clave de copia de seguridad

`BACKUP_ENCRYPTION_KEY` cifra las copias. **Custódiala fuera del servidor**: sin
ella no hay restauración posible, y una copia que no se puede restaurar no es
una copia (RL-12).

Rotarla **no vuelve a cifrar las copias antiguas**, y ese es el punto delicado:

1. Guarda la clave saliente donde puedas recuperarla mientras existan copias
   cifradas con ella (`BACKUP_RETENTION_DAYS`, 30 de serie, y
   `BACKUP_MIN_COPIES`, que no se borran nunca).
2. Genera la nueva en el servidor: `openssl rand -base64 32`.
3. Actualiza `BACKUP_ENCRYPTION_KEY` y lanza una copia completa **inmediatamente**.
4. Verifica esa copia antes de dar la rotación por buena, con el procedimiento de
   [`restaurar-backup.md`](restaurar-backup.md).
5. **No destruyas la clave anterior** hasta que caduque la última copia cifrada
   con ella. Anota la fecha.

**La clave del WAL (`BACKUP_WAL_KEY`, ADR-049) se deriva de esta** y es la única
parte de ella que recibe `postgres`. Al rotar la maestra hay que recalcularla,
o el archivado seguiría cifrando con la derivada de la clave anterior:

6. Antes del paso 3, **conserva la anterior como `BACKUP_ENCRYPTION_KEY_PREVIOUS`**
   (solo para restaurar; se pasa con `-e BACKUP_ENCRYPTION_KEY_PREVIOUS` a `docker compose run --rm restore`, no se deja en el `.env`): los segmentos de WAL de los últimos
   `BACKUP_WAL_RETENTION_DAYS` y las copias que siguen vivas solo se abren con ella.
   `restore.sh`, el simulacro y `kronoqr-restore-wal` la prueban por el `kid` de la
   cabecera de cada fichero y, si ninguna sirve, dicen **«clave distinta o cabecera
   alterada»** (no confundir con «el MAC no cuadra», que es un fichero alterado).
7. Tras actualizar `BACKUP_ENCRYPTION_KEY` en el `.env`, recalcula la del WAL y recrea
   PostgreSQL:
   desde el directorio vigente de la instalación (`backup.sh` está en su raíz),
   `sudo bash ./backup.sh derive-wal-key --write-env .env` y
   `sudo docker compose up -d postgres`. **`doctor.sh` falla** si `BACKUP_WAL_KEY` no es
   la derivada de la maestra o si el `kid` del último segmento archivado no es el de
   la derivada: así una rotación a medias se ve hoy y no el día de la recuperación.
   `update.sh` hace la misma comprobación **antes** de parar nada y se niega a
   actualizar si no cuadran.
8. El archivo de WAL es de **8 días** (`BACKUP_WAL_RETENTION_DAYS`) y las copias de 30:
   la clave anterior se retira cuando caduque lo más viejo cifrado con ella.

---

## 5 bis. Los tres secretos restantes del instalador

Ninguno de los tres tiene un procedimiento delicado: se genera un valor nuevo,
se sustituye en el `.env` y se reinician los servicios. Se documentan porque el
instalador los genera y alguien tiene que saber qué pasa al cambiarlos.

| Secreto | Efecto de rotarlo | Procedimiento |
| --- | --- | --- |
| `REVERB_APP_ID` / `_KEY` / `_SECRET` | Los paneles abiertos pierden la conexión de presencia en vivo y **caen a sondeo**; nadie deja de fichar ni pierde datos | Genera los tres, sustitúyelos y reinicia `reverb`, `app` y `horizon` |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | **Los PIN que un quiosco tenga sellados en su cola sin red dejan de poder abrirse.** No se pierde el fichaje: entra como incidencia para revisión humana (regla dura 19) | Vacía las colas de los quioscos antes: comprueba en el panel que ninguno tiene pendientes, y solo entonces rota |
| `GRAFANA_ADMIN_PASSWORD` | Solo afecta al acceso al cuadro de mandos | Sustitúyelo y recrea el contenedor `grafana` |

**El único de los tres con un momento malo es el del sobre del PIN**, y por eso
la comprobación previa no es opcional.

---

## 6. Después de cualquier rotación

- Comprueba un fichaje real y un acceso al panel. Que el contenedor arranque no
  demuestra nada.
- Anota en el registro de operación **qué se rotó y cuándo**. Los secretos no
  dejan asiento en `audit_log` —no son acciones sobre datos de nadie—, salvo la
  clave del QR, que sí deja `signing_key.rotated` y `signing_key.retired`.
- Destruye las copias del secreto saliente que hayas hecho por el camino,
  incluidas las del portapapeles y las del historial del intérprete.

---

## 7. A quién se escala

| Situación | Destinatario | Plazo |
| --- | --- | --- |
| Sospecha de compromiso de cualquier secreto | Responsable de seguridad | Inmediato |
| El fichaje no se recupera tras rotar credenciales de base de datos | IT del cliente | Inmediato |
| Copia de seguridad que no verifica tras rotar su clave | IT del cliente | Mismo día |

**Relacionados:** [`rotacion-clave-qr.md`](rotacion-clave-qr.md) ·
[`restaurar-backup.md`](restaurar-backup.md) ·
[`ataque-a-credenciales.md`](ataque-a-credenciales.md) ·
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md)
