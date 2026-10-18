# Runbook — actualizar una instalación de cliente

> **Quién lo usa:** el IT del hotel, en cada versión nueva, con `update.sh`
> delante.
>
> **Cuándo: fuera de los turnos.** Hay dos motivos, y ninguno pierde un
> fichaje:
>
> - **La parada.** Durante la ventana de mantenimiento **toda la API responde
>   503**, también la entrada de fichajes. Las tablets confirman en local,
>   encolan y lo envían al terminar, con la hora real de cada fichaje (§3).
> - **Las tablets se recargan justo después.** Cada tablet con la 2.2.1 o
>   posterior que va por detrás del servidor se recarga sola **a cualquier
>   hora**, en el primer momento en que tiene la cola vacía y nadie la está
>   usando ni ha fichado en `KIOSK_UPDATE_QUIET_MINUTES` (10 de serie). Durante
>   la recarga se queda unos segundos sin cámara (§3, «Después del servidor:
>   las tablets»).
>
> **Cuánto dura:** una parada de **alrededor de un minuto** (503), que cubre la
> cola de la tablet, y **poco más de un minuto** la actualización completa.
> Son las cifras de la etapa ⑧b de la CI del fabricante (actualización desde
> la versión anterior), con la base casi vacía: de 51 a 53 s de parada y de 63
> a 65 s de `update.sh` en las tres últimas ejecuciones verdes (18-10-2026). No
> cuentan la descarga de las imágenes, que `update.sh` hace en el paso 1,
> antes de parar. En tu servidor durará más cuanto mayor sea la base, porque
> la copia previa va dentro de la parada, y también depende del hardware. Tu
> cifra real queda en el informe del paso 7 (`BACKUP_PATH/reports/update-*.log`):
> la línea «Ventana de mantenimiento: N s» y, debajo, su reparto por fases.
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
descomprimas y vuelve a descargarlo. La suma prueba que el paquete llegó entero,
no quién lo publicó: descárgalo siempre de la dirección oficial de arriba, nunca
de una copia reenviada.

**Y, si tienes cosign 3.0.0 o posterior, antes de comprobar la suma, verifica quién
lo publicó.** Baja también `SHA256SUMS.bundle` (tercer fichero del apartado
*Assets*) y ejecuta, **antes de descomprimir** y con la identidad copiada de
[`instalacion.md`](../cliente/instalacion.md) §7.1 —nunca la del paquete nuevo,
porque un paquete sustituido llevaría una a su medida—:

```bash
curl -fLO https://github.com/BorjaPeonSaiz/KronoQR/releases/download/v2.2.0/SHA256SUMS.bundle
cosign verify-blob \
  --bundle SHA256SUMS.bundle \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com \
  --certificate-identity-regexp '^https://github\.com/BorjaPeonSaiz/KronoQR/\.github/workflows/release\.yml@refs/(tags/v[0-9][0-9A-Za-z.+-]*|heads/main)$' \
  SHA256SUMS
sha256sum -c --ignore-missing SHA256SUMS
```

La primera tiene que decir `Verified OK`. Con cualquier otra salida, no
descomprimas nada y avisa al fabricante. Sin cosign esta comprobación se omite y
sigue valiendo la suma de arriba; instalarlo es opcional (§7.1). Si el servidor no tiene salida a internet, descarga los
dos ficheros desde otro equipo y cópialos a `/opt` (`scp`, WinSCP o un USB);
eso basta para el paquete, pero las imágenes de la versión nueva llegan aparte,
como explica [`instalacion.md` §7](../cliente/instalacion.md#7-instalar-sin-salida-a-internet). **La
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

   El `.env` de un servidor así ya lleva `IMAGE_DIGEST_PHP=`,
   `IMAGE_DIGEST_NGINX=`, `IMAGE_DIGEST_POSTGRES=` y `REDIS_IMAGE_DIGEST=`
   vacías (las tres del producto y Redis, que también va fijado por digest): se
   conservan al actualizar y el aviso de `update.sh` te lo recordará en cada
   actualización. Sin digest no hay firma que comprobar, así que `cosign` no
   interviene en las imágenes; lo que sí puedes hacer es verificar `SHA256SUMS`
   con su `.bundle` en el equipo con internet donde descargas el paquete (paso
   previo de arriba). Si tus imágenes vienen de un registro propio que no las
   firma, `update.sh` acepta `--skip-signature-check` (queda en su informe), pero
   **nunca** con las imágenes del fabricante.

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
| 5 · Arranque y verificación | Arranca la aplicación nueva **sin borde** y la comprueba desde dentro: sondas, versión, cadena de auditoría, restricciones de RN-01 y RN-02, privilegios del rol de la aplicación sobre el registro horario (sin `DELETE` de tabla; solo `EXECUTE` sobre la función de purga) y **triggers del registro presentes y activos** (si falta uno o está desactivado, o la función de purga ha perdido `SECURITY DEFINER`, **deshace la actualización sin reactivarlos él**: una actualización limpia nunca los deja así; sigue la §3.2 de [`discrepancia-registro-auditoria.md`](discrepancia-registro-auditoria.md)), **conciliación completa del registro con su auditoría** (`compliance:reconcile-work-record --full`, con tope de 300 s; **solo informa**: una discrepancia, un corte por tiempo o un fallo del comando se leen en el informe, línea `work-record-reconciliation`, y se siguen con [`discrepancia-registro-auditoria.md`](discrepancia-registro-auditoria.md) sin deshacer la actualización) y `product:doctor` (**informativo**: se muestra y se resume en el informe; solo deshace si el comando ni siquiera existe en la imagen). Solo si todo pasa arranca Nginx y los procesos de fondo, y vuelve a comprobar por loopback | Sigue encolando hasta que Nginx vuelve |
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
  memoria), nadie la está usando (ni un PIN a medio teclear ni una
  confirmación en pantalla) y nadie ha fichado en `KIOSK_UPDATE_QUIET_MINUTES`
  (10 de serie; al menos 2 aunque valga `0`): el latido le dice que va por
  detrás del servidor y no espera a la ventana. No hay que hacer nada. **Por
  esto `update.sh` se ejecuta fuera de los turnos:** justo después, cada tablet
  atrasada se recarga en el primer hueco de calma, sea la hora que sea, y pasa
  unos segundos sin cámara. Una tablet que estaba sin red se recarga cuando la
  recupera, si está en calma, aunque sea a las seis de la mañana.
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
- **Desde la 2.3.0, el alta y la corrección manuales siguen la cota de
  continuación de RN-05.** Los tramos escritos antes no se tocan; comprueba si
  hay alguno que hoy se rechazaría con las consultas del [§3 bis](#3-bis-tras-actualizar-a-la-230-tramos-escritos-antes-de-la-cota-de-continuación).

### 3 bis. Tras actualizar a la 2.3.0: tramos escritos antes de la cota de continuación

Desde la 2.3.0 (ADR-058),
un tramo dado de alta o corregido a mano que empieza en un día civil distinto de
su jornada solo se admite si **continúa una pausa**: el tramo anterior terminó en
pausa (`closed_by`, que la actualización rellena desde los escaneos «Pausa»), su
entrada queda a menos de `min_rest_hours` de la salida de aquel, cae como mucho
el día siguiente y entre medias no hay ningún tramo de otra jornada de la
persona. A una persona de baja, además, ninguna marca le puede quedar a
`min_rest_hours` o más del final de su día de cese (RN-05, RN-14, RF-PA-04).
Antes no se comprobaba, así que una instalación puede tener tramos escritos que
hoy se rechazarían. **La actualización no los toca** (regla dura 5): estas tres
consultas, de solo lectura, dicen si existen. Muestran `employee_uuid`, no
nombres.

**Cuánto tarda.** El relleno de `closed_by` va por lotes de 2000 tramos, cada uno
en su propia transacción. Medido en desarrollo con 22 560 tramos cerrados y
193 574 escaneos: **1 s en total, unos 90 ms por lote**. Una instalación de 100
personas con cuatro años de registro (unos 200 000 tramos) tarda del orden de
**10 s**. Corre dentro de la ventana de mantenimiento de `update.sh`, sin
fichajes concurrentes, que es el único despliegue soportado: con la versión
anterior atendiendo, las pausas que cerrara quedarían como salidas. Si hubiera
que deshacer la actualización, se restaura la copia (ADR-054): repetir el
relleno no reproduce las pausas declaradas a mano.

```bash
docker compose exec -T postgres psql -U fichaje_app -d fichaje <<'SQL'
-- 1. Tramos de otro día civil que no continúan su jornada: el anterior no terminó
--    en pausa, el hueco llega a la cota, cae después del día siguiente u otra
--    jornada de la persona se interpone (RN-05).
WITH perfil AS (
    SELECT s.id AS site_id, s.timezone, cp.min_rest_hours
    FROM sites s
    JOIN compliance_profiles cp
      ON cp.id = COALESCE(s.compliance_profile_id,
                          (SELECT id FROM compliance_profiles WHERE is_default))
), vigentes AS (
    SELECT se.uuid, se.employee_id, se.site_id, se.work_date, se.clocked_in_at,
           LAG(se.clocked_out_at) OVER (
               PARTITION BY se.employee_id, se.work_date ORDER BY se.clocked_in_at
           ) AS salida_anterior,
           LAG(se.closed_by) OVER (
               PARTITION BY se.employee_id, se.work_date ORDER BY se.clocked_in_at
           ) AS cierre_anterior
    FROM shift_entries se
    WHERE se.status NOT IN ('voided', 'superseded')
)
SELECT e.uuid AS employee_uuid, v.uuid AS shift_entry_uuid, v.work_date,
       v.salida_anterior, v.cierre_anterior, v.clocked_in_at,
       round(extract(epoch FROM v.clocked_in_at - v.salida_anterior) / 3600, 2) AS horas_de_hueco,
       p.min_rest_hours
FROM vigentes v
JOIN perfil p ON p.site_id = v.site_id
JOIN employees e ON e.id = v.employee_id
WHERE (v.clocked_in_at AT TIME ZONE p.timezone)::date <> v.work_date
  AND v.salida_anterior IS NOT NULL
  AND (v.cierre_anterior IS DISTINCT FROM 'break_start'
       OR v.clocked_in_at - v.salida_anterior >= make_interval(hours => p.min_rest_hours)
       OR (v.clocked_in_at AT TIME ZONE p.timezone)::date > v.work_date + 1
       -- Otra jornada de la persona entre la salida anterior y la entrada.
       OR EXISTS (SELECT 1 FROM shift_entries o
                   WHERE o.employee_id = v.employee_id
                     AND o.work_date <> v.work_date
                     AND o.status NOT IN ('voided', 'superseded')
                     AND tstzrange(o.clocked_in_at, o.clocked_out_at)
                         && tstzrange(v.salida_anterior, v.clocked_in_at)))
ORDER BY v.work_date, e.uuid;

-- 2. Marcas de personas de baja más allá de la cota de su día de cese (RN-05, RN-14).
WITH perfil AS (
    SELECT s.id AS site_id, s.timezone, cp.min_rest_hours
    FROM sites s
    JOIN compliance_profiles cp
      ON cp.id = COALESCE(s.compliance_profile_id,
                          (SELECT id FROM compliance_profiles WHERE is_default))
)
SELECT e.uuid AS employee_uuid, se.uuid AS shift_entry_uuid, se.work_date,
       e.terminated_at, se.clocked_in_at, se.clocked_out_at,
       ((e.terminated_at + 1)::timestamp AT TIME ZONE p.timezone)
           + make_interval(hours => p.min_rest_hours) AS limite
FROM shift_entries se
JOIN employees e ON e.id = se.employee_id
JOIN perfil p ON p.site_id = se.site_id
WHERE se.status NOT IN ('voided', 'superseded')
  AND e.status = 'terminated'
  AND e.terminated_at IS NOT NULL
  AND GREATEST(se.clocked_in_at, COALESCE(se.clocked_out_at, se.clocked_in_at))
      >= ((e.terminated_at + 1)::timestamp AT TIME ZONE p.timezone)
         + make_interval(hours => p.min_rest_hours)
ORDER BY se.work_date, e.uuid;

-- 3. Jornadas que hoy abre un tramo de otro día civil (RN-05): por ejemplo, porque
--    en una versión anterior se anuló el tramo que las abría.
WITH primeros AS (
    SELECT DISTINCT ON (se.employee_id, se.work_date)
           se.employee_id, se.site_id, se.work_date, se.uuid, se.clocked_in_at
    FROM shift_entries se
    WHERE se.status NOT IN ('voided', 'superseded')
    ORDER BY se.employee_id, se.work_date, se.clocked_in_at
)
SELECT e.uuid AS employee_uuid, p.uuid AS shift_entry_uuid, p.work_date,
       p.clocked_in_at,
       (p.clocked_in_at AT TIME ZONE s.timezone)::date AS fecha_civil_de_la_entrada
FROM primeros p
JOIN sites s ON s.id = p.site_id
JOIN employees e ON e.id = p.employee_id
WHERE (p.clocked_in_at AT TIME ZONE s.timezone)::date <> p.work_date
ORDER BY p.work_date, e.uuid;
SQL
```

**Si sale alguna fila, no es una avería ni se arregla con SQL**: pásale la lista
a RRHH. Puede salir aunque nadie haya hecho nada mal: antes de la 2.3.0 un tramo
escrito a mano no podía decir que terminaba en pausa, así que la vuelta de
madrugada de un turno de noche completado a mano sale en la consulta 1. Cada fila
se revisa con la persona y tiene **dos salidas**, cada una con su motivo
(RF-PA-04, RN-13):

- **Declarar que el tramo anterior terminó en pausa** (casilla «Este tramo
  terminó en pausa» al corregirlo), si de verdad fue una pausa y la vuelta queda
  dentro de la cota y del día siguiente. Las horas se quedan en su jornada.
- **Anular y dar de alta**, si las horas son de otra jornada: anular el tramo en
  la jornada en que está y darlo de alta en la suya.

En la consulta 3, la jornada empieza en otro día: se anulan sus tramos de atrás
hacia delante y se dan de alta en la jornada correcta. Si la marca es posterior
al cese (consulta 2), RRHH decide además si la baja se registró con la fecha
correcta.

---

## 4. Códigos de salida

La tabla es **la misma de los cinco scripts**, publicada en
[`operacion.md`](../cliente/operacion.md) §8. Lo que significan aquí:

| Código | Significa aquí | Qué hacer |
| --- | --- | --- |
| `0` | Actualizado y verificado | Leer el informe. Nada más |
| `1` | Uso incorrecto. Nada tocado | `./update.sh --help` |
| `2` | **Una precondición no se cumple, o la copia previa ha fallado.** La instalación no se ha tocado y sigue en su versión; si ya estaba en mantenimiento, se ha retirado | La línea «Que hacer» de cada `[FALLA]`. Cuatro merecen párrafo: versión de origen fuera de la matriz (§1), cadena de auditoría rota (§4 bis), perfil de cumplimiento bajo el suelo legal (§4 ter) y copia fallida ([`restaurar-backup.md`](restaurar-backup.md) §2) |
| `3` | **Ya está en la versión de destino**, o no hay instalación que actualizar. Nada tocado | Nada. Si de verdad no hay instalación, lo que quieres es `install.sh` |
| `4` | **Falló y volvió a la versión anterior.** Copia restaurada, versión anterior en marcha y verificada | Enviar el informe al fabricante **antes** de reintentar: dice en qué paso y por qué |
| `5` | **Falló y la vuelta atrás quedó incompleta.** Nada más se toca | §5. Es el único código que exige a una persona delante |
| `6` | **Casi nunca**: toda verificación fallida deshace (RF-PD-10). La única excepción es una actualización que SÍ terminó, con la versión nueva en marcha, pero cuyo asiento `system.updated` de la auditoría no se pudo escribir: el trabajo no se deshace por eso | Leer el informe: dice qué orden escribe el asiento que falta |
| `7` | **La firma de cosign de una imagen del producto no es válida.** Sale en el paso 1: la instalación no se ha tocado y sigue en su versión. Solo ocurre si tienes cosign 3.0.0 o posterior instalado y la imagen va fijada por digest ([`instalacion.md`](../cliente/instalacion.md) §7.1). Si cosign no pudo consultar Sigstore o el registro, **no** es esto: avisa y sigue | **No sigas con ese paquete.** Envía al fabricante el detalle que imprime el script y el informe (línea `image-signature`). No uses `--skip-signature-check` con las imágenes del fabricante |

### 4 bis. «La cadena de auditoría NO está íntegra» (salida `2`)

El script verifica `compliance:verify-audit-chain` **antes** de tocar nada. Si
falla, no es un problema de la actualización: es un **incidente de seguridad**
que ya existía, y actualizar lo taparía —después nadie podría distinguir si la
rompió la actualización—. Sigue
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md), preserva la
evidencia, y **no actualices** hasta resolverlo.

### 4 ter. «Perfil de cumplimiento dentro del suelo legal» (salida `2`)

Desde la 2.2.1 la base de datos rechaza un perfil de jurisdicción `ES` que
conserve menos de **4 años** (art. 34.9 del Estatuto de los Trabajadores, RL-02)
y una jurisdicción que no tenga forma ISO 3166 (`ES`, `ES-CN`; no `es`, `ESP`
ni `ES `). `update.sh` lo comprueba **antes de tocar nada**, con una consulta de
solo lectura contra la base instalada; si no pasa, sale con `2` y la instalación
sigue como estaba.

**Plazo bajo (`El perfil N (ES) conserva 3 años…`).** Es lo habitual y se
arregla desde el producto: Panel → Perfil de cumplimiento, sube «Años de
conservación» a 4 o más y guarda. El cambio queda en `audit_log` como cualquier
otro cambio de perfil. Vuelve a lanzar `sudo ./update.sh`.

**Jurisdicción mal escrita (`La jurisdicción del perfil N no tiene forma ISO
3166…`).** El panel no permite editarla, así que se corrige a mano con el rol
de migración. Mira primero qué hay (muestra configuración del centro, no datos
personales):

```bash
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "SELECT id, jurisdiction, retention_years FROM compliance_profiles
    WHERE jurisdiction !~ '^[A-Z]{2}(-[A-Z0-9]{1,3})?\$' ORDER BY id"
```

Si el valor es una errata evidente de un país o región (`es` por `ES`, `ES `
por `ES`), corrígela en una sola sentencia, con el `id` de arriba:

```bash
docker compose exec -T postgres psql -U fichaje_migrator -d fichaje -c \
  "UPDATE compliance_profiles SET jurisdiction = 'ES' WHERE id = <id>"
```

Esa sentencia **no escribe en `audit_log`** (es una intervención directa, no un
cambio hecho por la aplicación): anótala en el registro de cambios del hotel con
la fecha, quién la hizo, el valor anterior y el nuevo. Si tras corregirla el
plazo queda por debajo de 4 años en `ES`, aplica el caso anterior. **Si dudas de
qué jurisdicción corresponde, o el valor no parece una errata, no lo toques y
consúltalo con el fabricante** (sin datos: basta el `id` y el valor), porque la
jurisdicción decide el suelo legal de conservación del registro.

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
  bash /opt/kronoqr/scripts/restore.sh --file $COPIA --yes --operator <tu correo>

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
- **Qué imágenes corren.** El `docker-compose.yml` del paquete fija `php`, `nginx` y `postgres` por **digest** (`:…`, ADR-053): una versión publicada es inmutable, y lo que arranca son los bytes que se publicaron aunque alguien reescribiera la etiqueta en el registro. `docker compose config --images` muestra las referencias exactas. Redis también va por digest (`REDIS_IMAGE_DIGEST`); las siete imágenes del perfil de observabilidad siguen por etiqueta de versión. Las tres del producto van además **firmadas con cosign**: con cosign 3.0.0 o posterior instalado, `update.sh` verifica la firma en el paso 1 y sale con el código 7 si no es válida (§4); sin cosign avisa y vale el digest. Sin salida a internet, vaciar `IMAGE_DIGEST_*` y `REDIS_IMAGE_DIGEST` en el `.env` vuelve a la etiqueta (ver [`instalacion.md`](../cliente/instalacion.md) §7) y esa instalación pierde la garantía: no lo hagas si puedes evitarlo.
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
