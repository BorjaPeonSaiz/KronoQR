# Runbook — actualizar una instalación de cliente

> **Quién lo usa:** el IT del hotel, en cada versión nueva, con `update.sh`
> delante. **Cuándo:** en ventana de baja actividad, aunque no es obligatorio:
> el fichaje no se detiene en ningún momento (§3). **Cuánto dura:** entre 5 y
> 20 minutos según el tamaño de la base de datos; casi todo es la copia previa.
>
> Este runbook cubre lo que el script no puede decidir por ti: cómo preparar el
> paquete, qué significa cada salida, y qué hacer **a mano** en el único caso
> que lo exige (salida `5`). Lo que hace el script paso a paso está en su
> cabecera (`./update.sh --help`) y en el informe que deja en el servidor.

---

## 1. Lo primero: no estás en el sitio equivocado

Si has llegado aquí porque `install.sh` ha salido con **código 3** diciendo «se
ha encontrado una instalación previa», es correcto y es deliberado. **El
instalador no se instala encima de un registro horario**, que hay que conservar
cuatro años por ley. Para pasar a una versión nueva se usa `update.sh`.

Y si lo que quieres es saber **desde qué versiones** se puede saltar a la del
paquete que tienes, sin tocar nada:

```bash
./update.sh --supported-sources      # versiones desde las que se actualiza directo
./update.sh --chain 2.1.0            # qué intermedias se aplicarían desde la 2.1.0
```

La regla es la del fabricante (doc 02 §11.6.5): **la versión menor vigente y
las dos anteriores** se actualizan directamente. Desde una más antigua, el
script te dice a cuál ir primero y no intenta el salto. La matriz es un dato
del paquete, `versions.txt`; no se edita.

---

## 2. Preparar la actualización (5 minutos, sin tocar nada)

**Antes: descarga el paquete nuevo y compruébalo.** Se descarga, público, de
<https://github.com/BorjaPeonSaiz/KronoQR/releases>: elige la versión más
reciente de tu serie (hoy, la última `v2.2.x`), **nunca** una cuyo título o
notas digan «no usar» ni una marcada como *Pre-release*. Lee sus notas: dicen si
la versión exige algo antes de actualizar. Hay que bajar dos ficheros de su
apartado *Assets*, `kronoqr-<versión>.tar.gz` y `SHA256SUMS`, en `/opt`:

```bash
cd /opt
curl -fLO https://github.com/BorjaPeonSaiz/KronoQR/releases/download/v2.2.0/kronoqr-2.2.0.tar.gz
curl -fLO https://github.com/BorjaPeonSaiz/KronoQR/releases/download/v2.2.0/SHA256SUMS
sha256sum -c --ignore-missing SHA256SUMS
```

La última orden tiene que decir `kronoqr-2.2.0.tar.gz: OK`; con `FAILED`, no lo
descomprimas y vuelve a descargarlo. Si el servidor no tiene salida a internet,
descárgalos desde otro equipo y cópialos a `/opt` (`scp`, WinSCP o un USB). **La
licencia no cambia al actualizar** y no viaja en el paquete: la que tienes
activada sigue valiendo; si tu proveedor te envía una nueva, se activa aparte con
`license:activate` ([`instalacion.md`](../cliente/instalacion.md) §4).

1. **Descomprime el paquete nuevo AL LADO del actual, nunca encima.**

   ```bash
   cd /opt
   tar xzf kronoqr-2.2.0.tar.gz          # crea /opt/kronoqr-2.2.0
   ls /opt                                # kronoqr-2.1.0  kronoqr-2.2.0
   ```

   El directorio de la versión actual **es tu vuelta atrás manual** (§5): su
   `docker-compose.yml` y su `.env` son lo que relanza la versión anterior si
   todo lo demás fallara. No lo borres hasta la siguiente actualización.
   **Si la actualización termina bien, `update.sh` lo retira** (§3, «Al
   terminar»): desde ese momento se trabaja **solo** desde el directorio nuevo.

   > **Si lo descomprimiste encima y tu versión es anterior a la 2.2.0, el
   > script se niega en el paso 1 y no toca nada** («no se puede actualizar con
   > el paquete descomprimido ENCIMA»). Con el compose nuevo, la 2.1.0 no puede
   > hacer la copia previa (las copias pasan a un rol de solo lectura) ni
   > sostener una vuelta atrás (sus copias nocturnas dejarían de funcionar). Lo
   > arreglas en tres órdenes, que el propio mensaje imprime con tus rutas:
   >
   > ```bash
   > # 1. Devolver al directorio actual el compose de SU versión, sacado de su paquete
   > cd /opt
   > tar xzf kronoqr-2.1.0.tar.gz -O kronoqr-2.1.0/docker-compose.yml | sudo tee /opt/kronoqr-2.1.0/docker-compose.yml >/dev/null
   > # 2. Descomprimir el paquete nuevo AL LADO
   > tar xzf kronoqr-2.2.0.tar.gz
   > # 3. Actualizar desde el directorio nuevo
   > cd /opt/kronoqr-2.2.0 && sudo ./update.sh
   > ```
   >
   > **Desde la 2.2.0**, encima funciona: avisa y sigue. La vuelta atrás usa el
   > compose nuevo con las imágenes antiguas, y la copia previa del paso 3 la
   > hace la imagen del paquete nuevo (el compose nuevo la fija por digest). Si
   > la copia falla, el script vuelve a **arrancar** `horizon` y `scheduler` tal
   > como estaban, sin recrearlos, así que la versión anterior sigue entera. Aun
   > así, la próxima vez, al lado.

2. **Lee qué cambia.** El paquete trae `docs/CHANGELOG.md`; la sección de la
   versión nueva es lo que hay que leer antes de reservar la ventana. Si trae
   claves nuevas en `.env.example`, el script te las enumera en el paso 1 y
   cada una usa su valor de serie hasta que decidas otro
   ([`configuracion.md`](../cliente/configuracion.md)).

3. **Sin salida a internet:** carga antes las imágenes de la versión nueva,
   igual que al instalar ([`instalacion.md`](../cliente/instalacion.md) §7):

   ```bash
   docker load -i imagenes-2.2.0.tar
   ```

4. **Comprueba sin tocar:**

   ```bash
   cd /opt/kronoqr-2.2.0
   sudo ./update.sh --check-only
   ```

   Sale `0` si todo está listo y `2` con la lista de lo que falta, y qué hacer
   con cada cosa. Lo que comprueba, en orden: que el paquete está entero, que
   la versión instalada está en la matriz, que hay espacio para la copia **y**
   para la migración, que los cuatro servicios están sanos y las sondas
   responden, que la clave de cifrado de las copias está en el `.env`, y **que
   la cadena de auditoría está íntegra antes de tocar nada** (§4).

5. **Comprueba que el servidor tiene `setpriv`** (paquete `util-linux`, de
   serie en Debian, Ubuntu y RHEL 7 o posterior):

   ```bash
   command -v setpriv
   ```

   `update.sh` lo usa para dejar su informe, los informes de retención que
   rescata y sus métricas en `BACKUP_PATH` **como el usuario de la
   aplicación (uid 1000) y sin seguir enlaces**, que es lo que impide que un
   contenedor comprometido convierta esa escritura de root en una escalada.
   Sin él la actualización **no se detiene**: avisa, sigue, no rescata los
   informes de retención y deja su propio informe en un directorio temporal,
   diciendo cuál. Instálalo antes (`apt install util-linux` o
   `dnf install util-linux`) y te ahorras copiarlo a mano.

6. **Ejecútalo dentro de `tmux` o `screen`** (o con `nohup`). Si la sesión SSH
   se corta a mitad, el script atrapa la señal y deshace solo, pero el mensaje
   final se iría con la conexión y solo quedaría el informe en el servidor.

---

## 3. Actualizar

```bash
cd /opt/kronoqr-2.2.0
sudo ./update.sh
```

Lo que verás, y lo que significa cada paso:

| Paso | Qué hace | Qué ve la plantilla |
| --- | --- | --- |
| 1 · Precondiciones | Lo mismo que `--check-only`, incluida la ruta de gestión que debe responder `401` sin sesión: es lo que el paso 5 y la vuelta atrás exigirán, y tiene que ser verdad ya. Si algo falla, sale `2` y no ha tocado nada | Nada |
| 2 · Mantenimiento | El panel, el portal y la API de gestión responden «en mantenimiento» (503). `horizon` y `scheduler` se paran para que nada escriba | **Los quioscos siguen fichando**: confirman en local y encolan. Es invisible para quien ficha |
| 3 · Copia previa | Copia lógica cifrada **y verificada** con la versión actual (si descomprimiste encima, cosa que solo se admite desde la 2.2.0, con la imagen del paquete nuevo: §2). **Bloqueante**: si falla, sale `2`, retira el mantenimiento, vuelve a arrancar `horizon` y `scheduler` **tal como estaban** (`start`, sin recrear nada) y no ha tocado nada más. No hay bandera para saltárselo | Igual |
| 4 · Migraciones | Relanza PostgreSQL y Redis con las imágenes nuevas y aplica las migraciones **versión a versión**, con un punto de control entre cada una: `PUNTO DE CONTROL 2.2.0 alcanzado: 3 migraciones aplicadas en 4 s` | Igual |
| 5 · Arranque y verificación | Arranca la aplicación nueva **sin borde** y la comprueba desde dentro: sondas, versión, cadena de auditoría, restricciones de RN-01 y RN-02 y `product:doctor` (**informativo**: se muestra y se resume en el informe; solo deshace si el comando ni siquiera existe en la imagen). Solo si todo pasa arranca Nginx y los procesos de fondo, y vuelve a comprobar por loopback | Sigue encolando hasta que Nginx vuelve |
| 6 · Vuelta atrás | Solo si el 4 o el 5 fallan: restaura la copia del paso 3 y relanza la versión anterior, sin preguntar (§5) | Igual: nada de lo encolado se pierde |
| 7 · Informe | `BACKUP_PATH/reports/update-<fecha>.log`, siempre, también tras una vuelta atrás (uid 1000, `0640`). **El detalle** `update-<fecha>.detalle.log` (salida cruda de migraciones, copia, restauración y logs; **puede llevar datos personales**) **no se publica ahí**: vive solo en `/var/log/kronoqr/`, `root:root 0600` en un directorio `0700`, junto a una copia local del informe. El informe aparece en `reports/` **al terminar** (o al salir por cualquier camino), **no mientras corre**: durante la ejecución se escribe en `/var/log/kronoqr/` y al final se publica como el uid de la aplicación con `setpriv` (paquete `util-linux`; la fase 1 avisa si falta). Si no se puede publicar, el script lo avisa y el informe queda a salvo en `/var/log/kronoqr/`: cópialo con la orden que indica el aviso | — |

**Por qué el mantenimiento va antes de la copia**, y no al revés como lo
enumera el plan: un fichaje aceptado *entre* la copia y el mantenimiento
existiría en la base pero no en la copia, y el quiosco ya lo habría sacado de
su cola porque el servidor lo confirmó. Una vuelta atrás lo perdería. Con el
mantenimiento primero, todo lo que ocurre durante la ventana sigue en las
colas de los quioscos y entra después, gane o pierda la actualización.

**Alertas durante la ventana.** Mientras `update.sh` tiene el mantenimiento puesto, suena (sin notificar a nadie) `VentanaDeMantenimientoActiva`, de severidad `info`: existe solo para que Alertmanager **inhiba** las alertas de quiosco, API, certificado TLS y disco, que son síntomas esperados de una actualización en marcha. Auditoría, copias, integridad, autenticación e incidencias **no** se inhiben. La inhibición tiene un tope de **2 h** desde que se puso el mantenimiento: si `update.sh` muriera sin retirarlo (un `kill -9`, una caída del servidor) y alguien no lo advirtiera, pasadas 2 h las alertas vuelven a sonar solas, que es lo que se quiere. Si ves esta alerta activa **sin** una actualización en curso, comprueba que no hay mantenimiento a medias (§5, caso A) en vez de silenciarla.

**Al terminar** (salida `0`):

- Los quioscos sincronizan lo encolado. Cada fichaje conserva **su hora real**
  (`occurred_at`); la hora de recepción será posterior, y es correcto. Si la
  ventana superó el umbral de retraso, la bandeja mostrará incidencias de
  sincronización: **no son un fallo**, son el sistema diciendo que hubo una
  ventana.
- La copia previa queda en `BACKUP_PATH/daily/` con la retención normal.
- **El directorio vigente es el nuevo** (`/opt/kronoqr-2.2.0` en el ejemplo).
  El script lo dice en su última pantalla («DIRECTORIO VIGENTE»). Desde ahora,
  `docker compose`, `./doctor.sh`, `./backup.sh`, los cambios del `.env` y del
  logotipo se hacen **desde ahí**. Si tenías un `cron` o un guion propio que
  apuntaba al directorio anterior, cámbialo.
- **El directorio anterior queda retirado.** Su `docker-compose.yml` se
  renombra a `docker-compose.yml.retirado-<versión anterior>` y en su lugar
  queda uno que hace fallar **cualquier** orden de Compose lanzada desde allí,
  con este mensaje:

  ```text
  required variable KRONOQR_DIRECTORIO_RETIRADO is missing a value: ESTE DIRECTORIO
  ESTA RETIRADO (KronoQR 2.1.0). La instalacion vigente esta en /opt/kronoqr-2.2.0
  (version 2.2.0): ejecuta docker compose, doctor.sh y backup.sh desde alli. ...
  ```

  Es a propósito: los dos directorios describen **el mismo** proyecto de
  Compose, y un `docker compose up -d` lanzado por costumbre desde el anterior
  bajaría la instalación a la versión anterior sobre una base ya migrada (y,
  desde la 2.1.0, volvería a meter la contraseña del superusuario en todos los
  contenedores). Nada más se toca: su `.env`, sus certificados y sus scripts
  siguen ahí. Consérvalo hasta la siguiente actualización; su `.env` lleva los
  mismos secretos que el nuevo, salvo `BACKUP_DB_*` si vienes de la 2.1.0
  (§6.1). Para deshacer la retirada, y **solo** como parte de §5.1:
  `sudo mv docker-compose.yml.retirado-<versión> docker-compose.yml` dentro de
  ese directorio.
- **El logotipo viaja solo** si estaba en la carpeta `branding/` junto al
  compose (lo normal, `BRANDING_PATH` vacía): el script la copia al directorio
  nuevo. Si `BRANDING_PATH` es una ruta absoluta, no hay nada que copiar.

**Después del servidor: las tablets** (`docs/cliente/operacion.md` §11, §11.1).
Las tablets se actualizan **después** del servidor y **con la cola a cero**:

- **Una tablet con la 2.2.1 o posterior se pone al día sola**, a cualquier
  hora, en cuanto su cola está vacía y guardada en la tablet (no solo en
  memoria) y nadie ha fichado en `KIOSK_UPDATE_QUIET_MINUTES`: el latido le dice
  que va por detrás del servidor y no espera a la ventana. No hay que hacer
  nada.
- **Una tablet anterior a la 2.2.1** (2.2.0, o 2.1.0, que declara `0.0.0`)
  también se pone al día sola, como siempre: se recarga cuando se cumplen a la
  vez la ventana `KIOSK_UPDATE_WINDOW` (hora local del centro), la cola vacía y
  unos minutos sin fichajes, y solo si ya ha descargado la versión nueva (la
  busca cada hora). Esperar a la franja de serie puede tardar horas; si corre
  prisa, **mueve temporalmente `KIOSK_UPDATE_WINDOW` a una franja que empiece
  ahora** (panel, «Ajustes operativos») y devuélvela a su valor cuando estén al
  día.
- **Compruébalo después de la franja**: en el panel, **Quioscos** (aviso
  «Aplicación desactualizada»), o con `docker compose exec app php artisan product:doctor`
  (línea de la versión de las tablets) o `php artisan kiosk:health` (versión y
  cola de cada una). El fichaje no se ve afectado mientras tanto.
- **Si una tablet anterior a la 2.2.1 sigue igual después de su franja** (en
  esa franja nunca hay calma, o la cola no se vacía) **o no puedes esperar**,
  sigue [`operacion.md`](../cliente/operacion.md) §11.1, «Qué hacer si una
  tablet no cambia sola tras actualizar el servidor». En resumen, como plan B:
  desregistrar el *service worker* (`chrome://serviceworker-internals` →
  **Unregister** en la tablet Android; F12 → **Application** → **Service
  workers** → **Unregister** en un PC) y recargar, **solo** con las tres
  condiciones a la vez: la tablet con red, «Fichajes sin sincronizar» a 0 y sin
  el aviso «Cola solo en memoria» (con la cola en memoria, recargar **borra**
  los fichajes no enviados).
- **Si una 2.2.1 o posterior sigue en «actualización urgente pendiente» varias
  horas después**, o el histórico de errores muestra `kiosk.update.unreachable`,
  el servidor pide una versión que no puede servirle: paquete de diagnóstico y
  caso con soporte (`operacion.md` §11.1). Desregistrar no lo arregla.
- Recargar la página sin más **no** aplica la versión nueva: la que espera solo
  entra por las vías de arriba.
- **Nunca borres los datos del sitio ni de la aplicación, ni desvincules una
  tablet con fichajes pendientes**: ahí están la cola de fichajes sin enviar y
  el emparejamiento, y se pierden (ver
  [`cola-offline-atascada.md`](cola-offline-atascada.md) §5).
- **Desde la 2.2.0, lo que una tablet antigua envíe y el servidor no acepte no se
  pierde**: la tablet lo avisa y queda como incidencia **«Fichaje descartado por
  el quiosco»** (`discarded_scan`, RN-22) en la bandeja de RRHH tras la revisión
  de la madrugada siguiente. No es un fallo de la actualización; la resuelve
  RRHH ([`cola-offline-atascada.md`](cola-offline-atascada.md) §8).
- **Desde la 2.2.0, el histórico de errores se vuelve a filtrar** (ADR-048,
  `operacion.md` §11): los mensajes antiguos se reescriben con el vocabulario
  técnico cerrado, es irreversible y **las huellas cambian**. Si una incidencia
  abierta con soporte citaba la huella de un error, búscalo por su código y su
  origen. Una copia anterior a la actualización conserva el texto antiguo y, al
  restaurarla, el panel lo muestra hasta la siguiente actualización o un
  `migrate` (`restore.sh` no migra); el paquete de diagnóstico lo filtra
  siempre al generarse.
- **Borra los paquetes de diagnóstico generados antes de actualizar**: pueden
  llevar nombres y no deben enviarse. Si queda alguno en el servidor
  (`docker compose exec app rm -f storage/app/diagnostics/<fichero>`), bórralo;
  la purga horaria lo retira a los 7 días. Si sacaste alguno del servidor,
  bórralo también de donde lo guardaras.

---

## 4. Códigos de salida

La tabla es **la misma de los cinco scripts**, publicada en
[`operacion.md`](../cliente/operacion.md) §8. Lo que significan aquí:

| Código | Significa aquí | Qué hacer |
| --- | --- | --- |
| `0` | Actualizado y verificado | Leer el informe. Nada más |
| `1` | Uso incorrecto. Nada tocado | `./update.sh --help` |
| `2` | **Una precondición no se cumple, o la copia previa ha fallado.** La instalación no se ha tocado y sigue en su versión; si ya estaba en mantenimiento, se ha retirado | La línea «Que hacer» de cada `[FALLA]`. Tres merecen párrafo: versión de origen fuera de la matriz (§1), cadena de auditoría rota (§4 bis) y copia fallida ([`restaurar-backup.md`](restaurar-backup.md) §2) |
| `3` | **Ya está en la versión de destino**, o no hay instalación que actualizar. Nada tocado | Nada. Si de verdad no hay instalación, lo que quieres es `install.sh` |
| `4` | **Falló y volvió a la versión anterior.** Copia restaurada, versión anterior en marcha y verificada | Enviar el informe al fabricante **antes** de reintentar: dice en qué paso y por qué |
| `5` | **Falló y la vuelta atrás quedó incompleta.** Nada más se toca | §5. Es el único código que exige a una persona delante |
| `6` | **No lo usa `update.sh`**: toda verificación fallida deshace (RF-PD-10). Si lo ves, es de otro script | — |

### 4 bis. «La cadena de auditoría NO está íntegra» (salida `2`)

El script verifica `compliance:verify-audit-chain` **antes** de tocar nada. Si
falla, no es un problema de la actualización: es un **incidente de seguridad**
que ya existía, y actualizar lo taparía —después nadie podría distinguir si la
rompió la actualización—. Sigue
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md), preserva la
evidencia, y **no actualices** hasta resolverlo.

---

## 5. Vuelta atrás a mano (solo con salida `5`)

Con `4` no hay nada que hacer: el script ya volvió. Con `5`, el script se ha
detenido **sin tocar nada más** y ha impreso las órdenes exactas con las rutas
reales de tu servidor. **Lee primero cuál de los dos casos es**, porque las
órdenes son distintas:

**Caso A — «solo ha quedado a medias el modo mantenimiento».** Falló algo
antes de la copia previa (pasos 2 o 3) y no se pudo retirar el mantenimiento.
La instalación sigue en su versión **con todos sus datos** y **no hay ninguna
copia que restaurar**: restaurar la de anoche borraría los fichajes del día.
Lo único que hay que hacer:

```bash
ACTUAL=/opt/kronoqr-2.1.0
sudo docker compose --env-file $ACTUAL/.env -f $ACTUAL/docker-compose.yml exec -T app php artisan up
sudo docker compose --env-file $ACTUAL/.env -f $ACTUAL/docker-compose.yml start horizon scheduler
curl -k https://127.0.0.1/api/v1/auth/me     # 401 = atiende; 503 = sigue en mantenimiento
```

**`start` y no `up -d`**, a propósito. El paso 2 solo **paró** `horizon` y
`scheduler`: sus contenedores siguen ahí, con la imagen y la configuración de
la versión que estaba en marcha, y `start` los vuelve a arrancar tal cual.
`up -d` compara la definición del compose con la de cada contenedor y recrea
lo que difiera: si descomprimiste el paquete **encima**, ese compose ya es el
nuevo y levantaría `horizon` y `scheduler` con la **imagen nueva** sobre la
base **sin migrar** (y, sin `--no-deps`, recrearía también PostgreSQL y Redis
si su definición cambió).

**Caso B — la vuelta atrás con la copia quedó incompleta.** Son estas órdenes,
en este orden, y cada una se puede repetir:

```bash
# Rutas de ejemplo: el mensaje del script trae las tuyas.
NUEVO=/opt/kronoqr-2.2.0
ANTERIOR=/opt/kronoqr-2.1.0
COPIA=/var/backups/fichaje/daily/kronoqr-20260907T031500Z.dump.enc   # la del informe

# 1. Parar lo que escribe (postgres se queda)
sudo docker compose --env-file $NUEVO/.env -f $NUEVO/docker-compose.yml stop app horizon scheduler reverb nginx

# 2. Restaurar la copia previa. Restaura en una base NUEVA y solo al final
#    intercambia los nombres; la base fallida se conserva 7 días como
#    <base>_pre_restore_<marca> para el diagnóstico.
#    Va por el servicio de un solo uso `restore` del paquete NUEVO: es el único
#    que recibe la credencial del rol de migración, que restaurar necesita.
sudo docker compose --env-file $NUEVO/.env -f $NUEVO/docker-compose.yml run --rm --no-deps restore \
  bash /opt/kronoqr/scripts/restore.sh --file $COPIA --yes

# 3. Relanzar la versión anterior desde SU directorio (su compose, su .env,
#    su IMAGE_TAG). --remove-orphans retira lo que la versión nueva creó.
sudo docker compose --env-file $ANTERIOR/.env -f $ANTERIOR/docker-compose.yml up -d --remove-orphans

# 4. Comprobar: la versión que publica la sonda es la anterior
curl -k https://127.0.0.1/api/v1/health
curl -k https://127.0.0.1/api/v1/ready
sudo docker compose --env-file $ANTERIOR/.env -f $ANTERIOR/docker-compose.yml exec -T app php artisan compliance:verify-audit-chain
```

Si `restore.sh` se niega por **conexiones abiertas** (salida `3`), algún
contenedor de aplicación sigue en pie. Antes de rendirse espera diez segundos a que se cierren las que acaban de
pararse y, si no, lista el rol y la aplicación de cada una: `docker ps` y párralo. Si sale `2`, la
copia no se descifra o no se lee: prueba con la anterior (`restore.sh --list`, por el mismo servicio `restore`) y
lee [`restaurar-backup.md`](restaurar-backup.md) §6, que tiene los tiempos que
caben en el RTO de 4 h.

**Mientras tanto los quioscos siguen fichando**: encolan en local (regla dura
19). Nada de lo que ocurra en la ventana se pierde si la base que acaba viva es
la restaurada, porque el servidor no confirmó ningún fichaje durante ella.

Cuando la versión anterior responda, genera el paquete de diagnóstico y abre un
caso al fabricante adjuntando el **informe** de `BACKUP_PATH/reports/`
(`update-<fecha>.log`). **El paquete va anonimizado por defecto** (sin identificador de empleado y con
el texto de los errores reducido a palabras técnicas) y el informe no lleva
secretos ni datos personales. El **detalle técnico**
(`update-<fecha>.detalle.log`) es otra cosa: es de root con modo `0600` y está en
`/var/log/kronoqr/` (no en `BACKUP_PATH`, que escribe la aplicación), lleva la salida
cruda de migraciones, copia, restauración y logs, y **puede contener datos
personales** (un `DETAIL: Failing row contains (...)` de PostgreSQL, por
ejemplo). Revísalo antes de enviarlo, y envíalo solo si el fabricante lo pide.

**Las tablets y la vuelta atrás.** Una tablet que ya recargó la PWA 2.2.0 usa la
**versión 2** de su base local (tabla `discarded`). Si se vuelve a la 2.1.0,
Dexie da `VersionError` y la cola cae a memoria: lo que está en disco no se
pierde, pero la tablet no lo ve ni lo drena. Por eso:

- **Antes de volver atrás**, vacía la cola de cada tablet (`kiosk:health`, cola
  a 0 y sin descartes sin avisar).
- **Tras la vuelta atrás**, en cada tablet que había actualizado: borra los datos
  del sitio y vuelve a emparejarla ([`alta-nuevo-quiosco.md`](alta-nuevo-quiosco.md)),
  solo con la cola ya a cero.

### 5.1 Volver a la versión anterior después de una actualización que terminó bien

No es una vuelta atrás del script y no se hace por costumbre: la versión nueva
está verificada y migrada, y la anterior **no sabe leer su esquema**. Volver
supone **restaurar la copia previa** del informe y perder todo lo registrado
desde entonces que no siga en las colas de las tablets. Decídelo con el
fabricante. Si se decide, son las órdenes del caso B, con un paso previo que
deshace la retirada del directorio anterior (§3, «Al terminar»):

```bash
cd $ANTERIOR
sudo mv docker-compose.yml.retirado-2.1.0 docker-compose.yml   # el nombre exacto está en el informe
```

---

## 6. Lo que no cambia nunca al actualizar

- **El `.env` con tus secretos.** El actualizador lo copia al paquete nuevo tal
  cual (0600) y solo cambia `IMAGE_TAG`. No regenera ningún secreto, con una
  excepción única al pasar de la 2.1.0 (§6.1).
- **Los datos.** Las migraciones amplían el esquema; ninguna borra registro
  horario. `audit_log` es solo-append y su cadena se verifica antes y después.
- **La licencia.** Una licencia caducada o inválida **no impide actualizar**:
  dejaría al cliente sin correcciones de seguridad sobre su registro legal
  (ADR-019). El estado de la licencia se anota en el informe, nada más.
- **Qué imágenes corren.** El `docker-compose.yml` del paquete fija `php`, `nginx` y `postgres` por **digest** (`:…`, ADR-053): una versión publicada es inmutable, y lo que arranca son los bytes que se publicaron aunque alguien reescribiera la etiqueta en el registro. `docker compose config --images` muestra las referencias exactas. Redis y las imágenes del perfil de observabilidad siguen por etiqueta de versión. Sin salida a internet, vaciar `IMAGE_DIGEST_*` en el `.env` vuelve a la etiqueta (ver [`instalacion.md`](../cliente/instalacion.md) §7) y esa instalación pierde la garantía: no lo hagas si puedes evitarlo.
- **El fichaje.** Ni durante la actualización ni si falla.

**Lo que sí cambia al pasar de la 2.1.0: dónde viven los ficheros generados**
(ADR-045). El primer arranque de la 2.2.0 crea el volumen `app-storage`;
`update.sh` rescata a `BACKUP_PATH/reports/retention/` los informes de
retención que encuentre en los contenedores de la 2.1.0 (solo
`retencion-propuesta-*.txt` y `retencion-purga-*.txt`, sin sobrescribir, en
`0640`; si falla, avisa y sigue), y **no** rescata las exportaciones, los
informes en diferido, los paquetes de diagnóstico ni el estado de la
telemetría. Tras la actualización puede sonar
`FicheroGeneradoDesaparecidoAntesDeCaducar` por exportaciones que no habían
caducado: es lo esperado. Lo que el cliente tiene que saber, con cómo citar el
asiento de una purga cuyo informe se perdió, está en
[`../cliente/operacion.md`](../cliente/operacion.md) §11, «Al actualizar desde
la 2.1.0: los ficheros generados».

### 6.1 Desde la 2.1.0: cada contenedor recibe solo lo suyo (ADR-042)

Hasta la 2.1.0, los contenedores de la aplicación (`app`, `horizon`, `reverb`,
`scheduler`) y `nginx` recibían el `.env` **entero**, incluida la contraseña del
rol de migración, que es superusuario de PostgreSQL. Desde la 2.2.0 cada
servicio recibe **solo las variables que nombra** el `docker-compose.yml`, y la
credencial de migración solo llega a los servicios de un solo uso `migrate` y
`restore`. Tabla completa: [`configuracion.md`](../cliente/configuracion.md)
§6.2. Lo que eso cambia al actualizar:

- **Variables propias en el `.env`.** Si añadiste alguna que el producto no
  declara, ya no llega a ningún contenedor. El paso 1 (y `--check-only`) lo
  avisa: «Tu .env tiene N claves que ningun servicio de esta version recibe y
  se IGNORARAN». Es un aviso, no un fallo, y no detiene la actualización. Si
  alguna te hace falta de verdad, dilo al fabricante: una variable soportada
  tiene que figurar en el compose del paquete, y editarlo a mano se pierde en
  la siguiente actualización.
- **Rol de las copias.** En el paso 4, `update.sh` crea en PostgreSQL el rol
  `fichaje_backup` (solo lectura) y escribe `BACKUP_DB_USERNAME=fichaje_backup`
  y una `BACKUP_DB_PASSWORD` nueva en el `.env` **del paquete nuevo**. Lo dice
  en pantalla: «Las copias pasan al rol fichaje_backup, de solo lectura». Si ya
  tenías un rol propio distinto del de migración y del de la aplicación, lo
  respeta.
- **La vuelta atrás vuelve a la 2.1.0 con sus credenciales.** El `.env` de la
  versión anterior no se toca: sigue diciendo `BACKUP_DB_USERNAME=fichaje_migrator`,
  y la 2.1.0 vuelve a repartir el `.env` entero a sus contenedores. Es
  deliberado —es lo que la 2.1.0 sabe usar—, pero significa que el problema que
  corrige la 2.2.0 vuelve a estar abierto **hasta que actualices otra vez**. El
  rol `fichaje_backup` queda en la base sin uso, y es inocuo. El script lo dice
  en pantalla y en el informe (`update-<fecha>.log`, línea `aud-1-reopened`) cada
  vez que una vuelta atrás deja la instalación en una versión anterior a la
  2.2.0.
- **Órdenes que cambian de contenedor.** Las copias a mano van por
  `docker compose exec scheduler …`, la restauración por
  `docker compose run --rm --no-deps restore …` y las migraciones a mano por
  `docker compose run --rm --no-deps -T migrate …`. Ninguna va ya por `app`:
  [`restaurar-backup.md`](restaurar-backup.md) tiene las órdenes completas.

---

## 7. Salto de versión mayor

De una serie `2.x` a una `3.x` el script **no salta**: te remite a la última de
tu serie y a la ventana de migración que el fabricante anuncia con antelación
(doc 02 §11.6.5). Cuando exista, las instrucciones del salto llegarán en el
paquete de la primera versión de la serie nueva, en esta misma sección.

Tampoco cubre hoy un cambio de versión **mayor de PostgreSQL** (17 → 18): las
imágenes del producto fijan la 17 y una versión que la cambie traerá su propio
procedimiento de `pg_upgrade` en este runbook antes de publicarse.

---

## 8. Para el fabricante: cómo se prueba esto

La etapa ⑧b de la CI (`update`) instala la versión anterior, siembra datos,
actualiza, comprueba idempotencia, **inyecta un fallo al arrancar la versión
nueva y exige la vuelta atrás automática** con los conteos intactos, reintenta,
y restaura la copia previa en una base limpia. Con una licencia inválida a
propósito. Corre en `main`, en cada etiqueta y a mano. Los detalles de qué
versión hace de «anterior» mientras no exista una etiqueta con instalador
están en el propio job.
