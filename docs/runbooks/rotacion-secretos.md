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
docker compose --env-file .env -f infra/compose.dev.yaml exec -T app <comando>
```

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

Son **tres roles distintos** (ADR-033) y se rotan por separado, empezando por el
que menos duele:

| Rol | Dónde vive | Qué pasa si se hace mal |
| --- | --- | --- |
| `fichaje_maintenance` | **Fuera del `.env`**, solo en la caja fuerte del operador | La purga por retención falla. Nadie deja de fichar |
| `fichaje_migrator` | `DB_MIGRATION_*`, idealmente solo al desplegar | El siguiente despliegue falla. Nadie deja de fichar |
| `fichaje_app` | `DB_*`. **Es el runtime** | **El fichaje se cae entero** |

Para el tercero, el orden importa:

```sql
-- 1. Cambiar la contraseña en PostgreSQL
ALTER ROLE fichaje_app WITH PASSWORD '<nueva>';
```

```bash
# 2. Actualizar DB_PASSWORD en el .env
# 3. Reiniciar la aplicación y las colas
docker compose --env-file .env -f infra/compose.dev.yaml up -d app horizon scheduler
# 4. Comprobar de verdad, no solo que el contenedor arranca
php artisan credentials:status --no-metrics --quiet-table
```

Entre 1 y 3 **la aplicación no puede consultar la base de datos**. Hazlo fuera
de horario de entrada y salida de turnos, y recuerda que durante ese hueco el
quiosco **encola y no bloquea a nadie** (regla dura 19): los fichajes de esos
minutos llegan después, con su `occurred_at` real.

---

## 4. Tokens de dispositivo del quiosco

No se «rotan» a mano de forma masiva: **se renuevan solos**. Cada token vive
`IDENTITY_DEVICE_TOKEN_DAYS` (90 de serie) y se rota cuando ha consumido
`IDENTITY_DEVICE_TOKEN_ROTATION_THRESHOLD` (80 %) de su vida, en una petición
normal del propio quiosco.

Rotación forzada de una tablet concreta —robo, extravío, baja del equipo—: se
**revoca** su token, con lo que ese quiosco deja de poder enviar fichajes
inmediatamente y hay que volver a emparejarlo. La revocación deja asiento
`device.revoked` en `audit_log`.

> **Pendiente de su procedimiento.** El caso de uso existe
> (`Identity\Application\UseCase\RevokeDeviceToken`), pero la vía de operación
> —emparejamiento por código, alta y baja de una tablet— la entrega la tarea 5.6
> junto con `alta-nuevo-quiosco.md`. Hasta entonces, la revocación la ejecuta
> quien despliega, y este runbook enlazará ahí en cuanto exista.

Antes de revocar, si la tablet todavía enciende: **déjala conectada hasta que su
cola local llegue a cero** (`kiosk_offline_queue_size{device}`). Los fichajes que
no se hayan sincronizado se pierden con el token, y son registro horario de
alguien.

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
