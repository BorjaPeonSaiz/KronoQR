# pairing

Vinculacion del quiosco con el servidor mediante codigo de emparejamiento (RF-PD-06). Tarea 5.6.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

- `ui/PairingView.vue` — ruta `/pair`: el codigo que la tablet enseña para confirmarlo en el panel
  («Quioscos» o el paso de quiosco del asistente).
- `application/pairingFlow.ts` — maquina de estados `idle → requesting → waiting → paired`. Un
  codigo caducado o rechazado vuelve solo a `requesting`: la tablet nunca se queda sin salida
  (regla dura 19).
- `application/deviceRevocation.ts` — deteccion de una desvinculacion hecha desde el panel: dos
  respuestas `unauthorized` seguidas, sin ningun exito entre ellas, en el latido, el padron o la
  cola. Un fallo de red no cuenta ni reinicia el contador. Este modulo solo cuenta; limpiar el
  token y el padron lo hace `useOfflineQueue`, y navegar, la pantalla.

El token del quiosco no se vuelve a emparejar para renovarse: se releva en el latido, con solape
del anterior (`shared/telemetry/heartbeat.ts` y `tokenRotation.ts`, F1-1, RF-ID-04).
