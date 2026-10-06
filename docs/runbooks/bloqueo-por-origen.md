# Runbook — bloqueos por origen en el portal (`KronoqrPortalOriginLockouts`)

**Qué dice la alerta.** En la última hora, más de 5 direcciones IP han llegado al
límite de accesos fallidos al portal del empleado y el servidor las ha bloqueado
un rato (RS-12, ADR-050). El bloqueo cuenta los fallos de **una dirección**, sea
cual sea el código tecleado.

**Impacto en el fichaje: ninguno.** El quiosco no usa este mecanismo (regla dura
19) y no se bloquea. Lo que se pierde es consultar el registro desde el portal;
mientras tanto, RRHH puede exportar el registro de quien lo pida (RL-05).

**Destinatario: la alerta llega a Seguridad; la ejecuta el IT del hotel.** Todo se hace en el servidor, desde el directorio
de la instalación (donde están `docker-compose.yml` y `.env`). La alerta no lleva
la IP como etiqueta (privacidad y cardinalidad): la dirección sale del registro.

## 1. ¿Ataque o plantilla bloqueada por error?

El umbral está pensado para distinguirlos. Mira cuántos orígenes hay:

```bash
docker compose logs --no-log-prefix --since 1h app | grep 'auth.origin_locked'
```

El log **no lleva la IP** (regla dura 21): solo `origin_hash`, un seudónimo que no se
entiende a simple vista. Para saber **quiénes** son, hay dos caminos:

- **`audit_log.ip` (lo normal).** Cada apertura deja un asiento `auth.origin_locked`
  con la IP en claro en la columna `ip`:

  ```bash
  docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \n    "SELECT occurred_at, ip, payload->>'channel' AS channel,
            payload->>'failures' AS failures, payload->>'ip_hash' AS ip_hash
       FROM audit_log
      WHERE action = 'auth.origin_locked'
        AND occurred_at > now() - interval '1 hour'
      ORDER BY occurred_at DESC"
  ```

- **Recalcular el hash (si no hay fila).** Con una IP candidata se calcula su
  `ip_hash` y se compara con el `origin_hash` del log: receta de
  [`ataque-a-credenciales.md`](ataque-a-credenciales.md) §4.3.

Dos salvedades:

- **Techo de asientos por hora.** Por encima de él el bloqueo se aplica igual, pero
  **no se escribe fila en `audit_log`**: solo quedan el log y la métrica. Si la
  consulta devuelve menos filas que aperturas cuenta la alerta, estás en un barrido
  grande (casi seguro §4) y las IP que faltan solo se pueden contrastar con el
  recálculo del hash.
- **IPv6.** El bloqueo y el hash son del **`/64` normalizado**, no de la dirección
  completa: al recalcular, usa el `/64` (los 4 primeros grupos, forma canónica) y no
  la IP del equipo. La columna `ip` guarda la dirección que hizo la petición.

| Lo que ves | Qué es | Siguiente paso |
| --- | --- | --- |
| Una sola IP que se repite, y es la de **la red del hotel** o la de tu propio router | **Origen compartido.** Toda la plantilla sale por esa IP y un solo despistado (o un tercero) la ha bloqueado para todos | §2 y §3 |
| Muchas IP distintas, de fuera de la red del hotel, cada una con 20 fallos | Ataque repartido contra el portal (T1110.003) | §4 |
| Una IP concreta de dentro del hotel | Un equipo con un PIN equivocado en un script o un navegador con el PIN guardado | Desbloquea (§3) y avisa a su dueño |

## 2. Si toda la plantilla comparte una IP: arregla la causa

Desbloquear sin arreglar esto solo lo retrasa: volverá a pasar.

1. **`TRUSTED_PROXY_CIDR`.** Si hay un proxy o balanceador delante del servidor,
   la aplicación ve la IP del proxy, no la del empleado. Debe contener la red del
   proxy para que el borde lea la `X-Forwarded-For` de verdad:

   ```bash
   grep '^TRUSTED_PROXY_CIDR=' .env
   docker compose exec nginx printenv TRUSTED_PROXY_CIDR
   ```

   Vacía con proxy delante: defínela (CIDR IPv4 de la red del proxy), y recrea el
   contenedor del borde (`docker compose up -d --force-recreate nginx`).
2. **Hairpin NAT.** Los empleados que entran por la dirección pública **desde
   dentro** del hotel pueden salir con la IP pública del router. Soluciones: un
   DNS interno que resuelva el nombre del portal a la IP interna del servidor, o
   activar *NAT loopback* con conservación de origen en el router.
3. Con CGNAT o VPN de salida única no hay IP individual que recuperar: el
   desbloqueo de §3 es el control, y conviene que el hotel acceda por su red.

## 3. Cómo desbloquear una IP

La IP en claro sale de la consulta a `audit_log` del §1.

```bash
docker compose exec app php artisan identity:origin-unlock 203.0.113.9
```

Levanta el bloqueo de esa dirección (su `/32`, o su `/64` si es IPv6) y deja el
asiento `auth.origin_unlocked` en el registro de auditoría, con quien lo ejecutó.
No hay endpoint ni pantalla: es una operación del servidor. El bloqueo expira por
sí solo al cabo del tiempo configurado; desbloquear solo es necesario si no se
puede esperar. Comprueba que entra: el portal debe dejar teclear código y PIN.

## 4. Si es un ataque repartido

- El bloqueo por origen no frena a quien rota direcciones; lo que lo frena es el
  PIN de 8 cifras. En los ajustes del producto, `IDENTITY_PIN_LENGTH` a 8 (la
  plantilla conserva su PIN de 6 hasta restablecerlo). `product:doctor` dice
  cuántos faltan.
- Si no hace falta el portal fuera del hotel, cierra el rango:
  `PORTAL_INTERNAL_CIDR` a la red del hotel y recrea el borde
  ([`portal-403.md`](portal-403.md) §3 y §5).
- Sigue con [`ataque-a-credenciales.md`](ataque-a-credenciales.md): alcance, si
  alguna cuenta entró, y bloqueo del origen en Nginx.

## 5. Verificación

```bash
# El contador ya no sube: ninguna apertura nueva en 15 minutos
docker compose logs --no-log-prefix --since 15m app | grep -c 'auth.origin_locked'
```

La alerta se resuelve sola cuando pasa una hora sin más de 5 aperturas.

## 6. Escalado

Si hay indicios de que alguna cuenta entró durante el ataque, es un incidente de
seguridad: [`brecha-de-seguridad.md`](brecha-de-seguridad.md).
