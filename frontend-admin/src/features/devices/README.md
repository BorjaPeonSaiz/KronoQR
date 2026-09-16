# devices

Flota de quioscos, su **salud** (RF-PA-07, tarea 3.3) y su vinculacion por
codigo de emparejamiento (RF-PD-06, tarea 5.6). Ruta `/devices`, entrada de
navegacion «Quioscos», ambito `settings:*` (doc 02 §7.3, nota 5): dar de alta
un quiosco es crear un origen de fichajes, la misma potestad que configurar la
instalacion.

## Que hay aqui

| Fichero                 | Que hace                                                                                                                                                                                                                                                 |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `devices.api.ts`        | Cliente tipado de `GET /devices`, `POST /kiosk/pair/confirm` y `POST /devices/{uuid}/unpair`. Las formas salen del contrato; no hay logica aqui, solo la peticion.                                                                                       |
| `useDeviceRows.ts`      | Aritmetica de presentacion, pura y sin Vue: `elapsedSinceHeartbeat`/`elapsedSinceOldestPending` (tiempo en horas y minutos enteros, medido contra el reloj del SERVIDOR).                                                                                |
| `devicePresentation.ts` | Presentacion pura de la salud (patron `errorPresentation.ts`): clase del badge de veredicto, glifo, claves i18n de razon y «que hacer», si el bloque «que hacer» procede (`showsWhatToDo`), lectura de bateria y formato de umbrales (`thresholdLabel`). |
| `PairKioskForm.vue`     | El formulario de vinculacion en si -codigo de seis digitos y nombre del quiosco-, **sin encabezado propio**: lo reutilizan dos pantallas distintas con dos jerarquias de titulo distintas.                                                               |
| `DevicesView.vue`       | La pantalla «Quioscos»: la tabla de la flota CON su salud, el sondeo de respaldo, el dialogo de vinculacion (envuelve `PairKioskForm.vue`) y el de desvinculacion.                                                                                       |

## `PairKioskForm.vue` es una fuente unica con dos quien-lo-contiene

El mismo formulario aparece en dos sitios que no comparten nada mas: la
pantalla «Quioscos» (dentro de un `BaseDialog`) y el paso de quiosco del
asistente de puesta en marcha (`onboarding/steps/KioskStep.vue`, incrustado
directamente en el paso). De ahi tres decisiones que estan en su cabecera y no
aqui por accidente:

- **Sin `<h1>`/`<h2>` propio.** Quien lo contiene ya tiene el suyo -el titulo
  del dialogo, o el del paso del asistente-; duplicarlo rompería el orden de
  encabezados de esa pantalla (`heading-order`, WCAG 2.2 AA).
- **El rechazo del codigo es GENERICO a proposito** (regla dura 17,
  `urn:kronoqr:problem:pairing-code-rejected`): el contrato no distingue si el
  codigo no existe, ha caducado o ya se uso, y el panel no inventa esa
  distincion -la accion siguiente es la misma en los tres casos: que la
  tablet muestre un codigo nuevo-. Es distinto de un `422` de VALIDACION del
  formulario (`name` en uso), que si lleva su mensaje de campo.
- **`existingDevices` es opcional** porque solo la pantalla «Quioscos» tiene
  la flota ya cargada a mano: con ella, el formulario avisa **antes** de
  enviar si el nombre tecleado coincide con un quiosco revocado («se
  reactivara con el mismo identificador»). El paso del asistente no pasa
  lista -no hay ninguna flota previa que revisar en una instalacion nueva-, y
  el aviso de reactivacion, si toca, llega igual en la respuesta ya
  confirmada.

Tras confirmar, el formulario **no se cierra ni avanza solo**: enseña un
resumen de que se ha vinculado -version de la aplicacion y hora en que la
tablet pidio el codigo (`PairingConfirmed.request`)- para que la persona lo
contraste con la tablet que tiene delante antes de darlo por bueno. Es la
misma exigencia que cualquier correccion con consecuencias (CLAUDE.md):
mostrar el resultado antes de que quien lo mira siga adelante. El evento
`paired` se emite en el momento de confirmar, no al cerrar el resumen: quien
lo contiene puede refrescar su lista de inmediato aunque la persona siga
leyendo.

## Sin canal en tiempo real: sondeo de respaldo, nunca congelado sin avisar

El contrato no publica un evento de presencia para la flota de quioscos -a
diferencia de RF-PA-01/02, que si tienen su canal de Reverb-, asi que
`DevicesView.vue` no inventa uno en el cliente. Lo que hace, para no quedarse
congelada sin decirlo, es volver a pedir `GET /devices` cada 15 s
(`POLL_INTERVAL_MS`, el mismo respaldo que usa la presencia en vivo cuando su
canal cae) y enseñar siempre la marca de la ultima actualizacion.

## La salud la calcula el SERVIDOR, y se mide contra SU reloj (tarea 3.3)

Antes de la tarea 3.3, `elapsedSinceHeartbeat` medía contra `Date.now()` del
navegador porque el contrato no publicaba ningun «ahora» de referencia para
esta lista sin paginar. Desde `DeviceList.meta`, si lo publica -junto con la
zona del centro y los umbrales de salud-, y esta pantalla pasa a seguir la
misma regla que `IncidentTable`/`PresenceTable`/`ErrorTable` (regla dura 3):
**el reloj es el del servidor**, extrapolado desde `meta.generated_at` con el
tiempo transcurrido desde que `useQuery` recibio la respuesta
(`dataUpdatedAt`, el mismo papel que `receivedAt` en `incidents.store.ts` y
`errors.store.ts`), nunca `Date.now()` a secas.

`Device.health` (`verdict`/`reason`/`seconds_since_last_seen`) lo calcula el
servidor con **la misma clase** que `php artisan kiosk:health`
(`KioskHealthRow`) y con los mismos umbrales que la alerta «Quiosco sin
latido > 10 min» de Prometheus (doc 01 §9.3): panel, consola y alerta cuentan
siempre lo mismo. El panel **no reimplementa** esa regla -no compara
`last_seen_at` contra ningun umbral propio-, solo la presenta:
`devicePresentation.ts` traduce el veredicto a color+glifo+texto (WCAG 1.4.1,
nunca un estado solo por color) y la razon a su frase en
`locales/{es,en}.json`. `warning`/`failure` usan el control SOLIDO
(`bg-kq-{estado} text-kq-on-{estado}`, doc 06 regla 5) y no el badge suave:
esas dos filas llevan ADEMAS el tinte `-soft` de `rowToneClass` sobre toda la
fila, y un badge tambien `-soft` sobre una fila del mismo `-soft` pierde el
contorno -se confunde con el fondo justo en las dos filas que mas necesitan
destacar- (correccion de `ui-ux`, segunda vuelta de la tarea 3.3). `ok` y
`revoked` si usan el badge suave: su fila no lleva tinte, asi que no hay
fondo con el que camuflarse.

`useDeviceRows.elapsedSinceHeartbeat`/`elapsedSinceOldestPending` clavan a
cero un instante «del futuro»: el reloj de la TABLET (no el del panel) puede
llegar adelantado, y una antiguedad negativa no dice nada a quien mira la
pantalla.

## «Que hacer» visible mientras hay algo que hacer, no un desplegable

A diferencia de `ErrorTable` (que esconde su detalle tras un boton porque una
pagina puede traer decenas de grupos), la fila de cada quiosco lleva debajo su
bloque «Que hacer» sin necesidad de un clic, con el texto que corresponde a
`health.reason` (ocho razones, cada una con su propio texto: revisar el
cargador no es lo mismo que esperar el primer latido). Es una decision
deliberada de la ficha 3.3 (decision 11): con unos pocos quioscos por
instalacion (ADR-040), esconder la guia operativa detras de un clic no ahorra
espacio de verdad y cuesta un paso a quien ya esta mirando un quiosco en
fallo. **El runbook no viaja al navegador** (regla dura 16): el texto solo
NOMBRA `docs/runbooks/quiosco-no-responde.md` §2 y, para los veredictos mas
graves, el comando `php artisan kiosk:health` que usa el IT del cliente desde
la consola.

**Se OMITE para `ok`** (`devicePresentation.showsWhatToDo`, correccion de
`ui-ux` en la segunda vuelta): un quiosco que late con normalidad no tiene
ninguna accion que ofrecer, y repetir «no hace falta ninguna accion» en cada
fila sana es ruido que entierra el aviso de las filas que si lo necesitan.
`warning`, `failure` y `revoked` si lo llevan.

La fila entera se tiñe ademas para `failure`/`warning`
(`devicePresentation.rowToneClass`), pero **nunca solo con color**: el tinte
es refuerzo, la informacion real ya va en texto+icono en la celda «Salud»
(WCAG 1.4.1).

## La leyenda enseña los umbrales REALES, nunca unos supuestos

`DeviceListMeta.thresholds` trae los umbrales tal y como los fija la
instalacion (`KIOSK_HEALTH_FRESH_WITHIN_SECONDS`,
`KIOSK_HEALTH_SILENT_AFTER_SECONDS`, `KIOSK_HEALTH_BATTERY_LOW_PERCENT`, tarea
3.3): un aviso cuyo criterio no se ve es un aviso que nadie puede defender
ante quien pregunta por que un quiosco esta en fallo.

**`thresholdLabel` nunca redondea a un minuto que no es** (correccion de
`revisor-codigo`, segunda vuelta: la primera version hacia
`Math.round(seconds / 60)`, y un umbral afinado de 90 s se leia «2 min» -el
doble de lo real- y uno de 20 s se leia «0 min» -parecia que cualquier
retraso ya era fallo-, los dos mintiendo sobre el umbral real de la
instalacion). Por debajo del minuto enseña los segundos («20 s»); a partir de
un minuto, minutos y, si sobran, los segundos que no llegan a completar otro
minuto («1 min 30 s»); un multiplo exacto no lleva segundos de mas («10 min»,
no «10 min 0 s»).

## Vinculacion y flota, con salud detallada (revision de la tarea 3.3)

Antes de la tarea 3.3, esta pantalla vinculaba, enseñaba la flota y
desvinculaba, y la comprobacion de salud fina vivia solo en la consola
(`kiosk:health`, tarea 5.11). Desde la 3.3, el mismo veredicto que calcula
`kiosk:health` llega tambien aqui por `GET /devices`, asi que el panel de
gestion sigue siendo el sitio para ACTUAR sobre un quiosco (vincular, nombrar,
revocar) y ahora tambien el primer sitio donde SE VE que un quiosco necesita
atencion -el runbook `quiosco-no-responde.md` §2 empieza precisamente por esta
pantalla-.

`sortDevices` se retiro de `useDeviceRows.ts` en una revision posterior al
lanzamiento: el orden -activos primero, luego por nombre- lo pone ahora el
servidor en `GET /devices`, para que esa regla no viva en dos sitios que
podrían divergir sin que ninguna prueba lo detectara.

## Sin virtualizar, a proposito (decision 11 de la ficha 3.3)

El contrato no pagina `GET /devices` (ADR-040: un centro por instalacion, unos
pocos quioscos), asi que la tabla sigue siendo un `<table>` semantico normal y
corriente. Pasar a `role="table"` con `useVirtualizer` solo para una lista que
cabe entera en pantalla cambiaria selectores de pruebas y E2E sin ningun
beneficio real. Si algun dia `GET /devices` paginara, la virtualizacion
llegaria junto con la paginacion, no antes.

## `KIOSK_SERVICE_CODE` vive en «Ajustes operativos», no aqui

El codigo de servicio con el que se abre la pantalla de diagnostico de la
tablet (RF-KI-08) es una clave mas del catalogo de configuracion de la
instalacion, y se edita en `features/settings/OperationalSettingsView.vue`
junto a los demas umbrales operativos -no en «Quioscos», que es donde se
gestiona la flota, no donde se configura la instalacion-. El servidor nunca
envia el codigo en claro a la tablet, solo su huella SHA-256 por el latido
(`KioskHeartbeat.service_code_hash`): este panel tampoco lo ve fuera de ese
formulario.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
