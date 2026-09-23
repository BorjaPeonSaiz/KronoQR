# diagnostics

Pantalla de diagnostico del quiosco (RF-KI-08, tarea 3.3) y envio de
`error_events` sin datos personales en el latido (RF-PD-15, tarea 5.12, en
`shared/telemetry/errorReporter.ts` y `heartbeat.ts` — esta carpeta no envia
nada, muestra).

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Como se abre

Pulsacion larga de 3 segundos sobre el reloj de `ScanView.vue`/`PairingView.vue`
(`ui/ClockDiagnosticsTrigger.vue`, con `composables/useLongPress.ts`), que
navega a `/diagnostics`. Esa ruta esta EXCEPTUADA del guard de emparejamiento
(`router/index.ts`): una tablet sin vincular tambien se diagnostica, porque no
tiene token ni padron que proteger.

## Codigo de servicio, sin red

`application/serviceCodeGate.ts` compara `sha256Hex("{device_id}:{codigo}")`
contra la huella cacheada en `localStorage` (`shared/telemetry/deviceIdentity.ts`
-> `readServiceCodeHash`), que el planificador del latido
(`shared/telemetry/heartbeat.ts`) guarda de cada `200` de
`POST /kiosk/heartbeat` (`KioskHeartbeat.service_code_hash`). Sin huella
cacheada -instalacion sin codigo, o tablet que nunca ha latido- la pantalla se
abre directamente. Cinco fallos bloquean 60 s; el reloj es inyectable para no
tener que esperarlos de verdad en las pruebas.

## Que muestra

`application/diagnosticsSnapshot.ts` ensambla, de forma PURA, lo que se ve:
camara (con los avisos sin bloquear del `getSettings()` de la pista, ver
`cameraWarnings`), red, cola, padron, token (identificador = ocho hex de
`sha256(token)`, nunca el token en claro), version, bateria, bloqueo de
pantalla, errores pendientes de enviar y responsable del tratamiento. Todo por
`device_id`, nunca por nombre (regla dura 21). Boton «Volver a fichar» y vuelta
automatica a los 120 s sin interaccion: el fichaje nunca depende de esta
pantalla (regla dura 19).

## Actualizacion del quiosco (RF-KI-07, tarea 3.12)

Junto a la version, la seccion `version` enseña si hay una actualizacion pendiente -y en que
ventana se aplicara- o «al dia», y la ventana de actualizacion vigente. `isUpdatePending()`
(`src/sw/registerServiceWorker.ts`, singleton de modulo, mismo patron que
`getLastHeartbeatResult`) y `readUpdateWindow()` (`shared/telemetry/deviceIdentity.ts`,
cacheada del ultimo latido) se leen UNA VEZ al abrir, como `serviceWorkerActive`.
