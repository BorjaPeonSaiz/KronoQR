# devices

Flota de quioscos y su vinculacion por codigo de emparejamiento (RF-PA-07,
RF-PD-06, tareas 3.3 y 5.6). Ruta `/devices`, entrada de navegacion
«Quioscos», ambito `settings:*` (doc 02 §7.3, nota 5): dar de alta un quiosco
es crear un origen de fichajes, la misma potestad que configurar la
instalacion.

## Que hay aqui

| Fichero             | Que hace                                                                                                                                                                                   |
| ------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `devices.api.ts`    | Cliente tipado de `GET /devices`, `POST /kiosk/pair/confirm` y `POST /devices/{uuid}/unpair`. Las formas salen del contrato; no hay logica aqui, solo la peticion.                         |
| `useDeviceRows.ts`  | Aritmetica de presentacion, pura y sin Vue: `elapsedSinceHeartbeat` (tiempo desde el ultimo latido, en horas y minutos enteros).                                                           |
| `PairKioskForm.vue` | El formulario de vinculacion en si -codigo de seis digitos y nombre del quiosco-, **sin encabezado propio**: lo reutilizan dos pantallas distintas con dos jerarquias de titulo distintas. |
| `DevicesView.vue`   | La pantalla «Quioscos»: la tabla de la flota, el sondeo de respaldo, el dialogo de vinculacion (envuelve `PairKioskForm.vue`) y el de desvinculacion.                                      |

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

## `elapsedSinceHeartbeat` mide contra el reloj del NAVEGADOR, no del servidor

A diferencia de `IncidentTable`/`PresenceTable` (regla dura 3, «el reloj es el
del servidor»), esta cifra es deliberadamente distinta: el contrato dice que
`last_seen_at` **lo declara el propio dispositivo y nadie lo comprueba**, y no
publica un «ahora» de referencia para esta lista sin paginar (a diferencia de
`meta.generated_at` en el historico de errores o en la bandeja de
incidencias). Es una cifra meramente operativa -«hace cuanto que esta tablet
no habla»-, no un dato con valor legal.

## Vinculacion y flota, no salud detallada

Esta pantalla vincula, enseña la flota y desvincula. La comprobacion de salud
mas fina -umbrales de latido, veredicto por quiosco- vive en la consola
(`kiosk:health`, tarea 5.11) y no aqui: el panel de gestion es el sitio para
actuar sobre un quiosco (vincular, nombrar, revocar), no para diagnosticar su
red o su camara.

`sortDevices` se retiro de `useDeviceRows.ts` en una revision posterior al
lanzamiento: el orden -activos primero, luego por nombre- lo pone ahora el
servidor en `GET /devices`, para que esa regla no viva en dos sitios que
podrían divergir sin que ninguna prueba lo detectara.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
