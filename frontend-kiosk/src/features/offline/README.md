# offline

Cola de fichajes en IndexedDB con Dexie, sincronizacion ordenada por `occurred_at`, reintentos e indicador de estado (RF-KI-03, RF-KI-04). Tarea 1.9.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

- `useOfflineQueue.ts` — montaje de la cola y su enchufe a la pantalla: un controlador por tablet,
  un solo drenaje.
- `application/scanQueue.ts` — la cola. Nada sale sin desenlace del servidor (RN-22, ADR-047):
  `confirm()` tras un `200` o un `422` estandar, y `discard()`, que no borra sino que mueve el
  fichaje a descartados hasta que se acusa su aviso. Lo que esta en vuelo corta el prefijo: nada
  adelanta a un envio sin desenlace (RN-21).
- `application/syncRunner.ts` — el drenaje: `POST /api/v1/scan` (o `/scan/pin`) cuando el escaneo
  es el unico pendiente, por lotes en otro caso, con retroceso (`domain/backoff.ts`, hasta 5 min,
  sin abandonar nunca).
- `domain/queueOrder.ts` — orden por `occurred_at` y troceado en lotes de 50.
- `application/cachedRoster.ts` + `infrastructure/rosterCipher.ts` — el padron cacheado, cifrado en
  reposo (RL-12) e indexado en memoria para saludar por el nombre sin red.
- `infrastructure/dexieStorage.ts` + `infrastructure/queueStorage.ts` — la cola en IndexedDB y su
  puerto. Si IndexedDB falla se reabre y, solo si sigue fallando, se cae a un respaldo en memoria
  y se avisa: el fichaje entra igual (regla dura 19). Degradada, la cola no sabe cuantos quedaron
  en disco: su tamaño es «desconocido» (`null`), nunca 0, y el latido lo dice con
  `queue_storage`.

## La puerta de actualizacion (RF-KI-07, tarea 3.12)

`domain/updateWindow.ts` decide cuando el service worker puede aplicar una version nueva sin
arriesgar un fichaje: cola vacia, sin escaneo en los ultimos `quiet_minutes` minutos y dentro
de la ventana LOCAL que declaro el centro (`KioskHeartbeat.update_window`, cacheada sin red en
`shared/telemetry/deviceIdentity.ts`). Ya no hay franjas de cambio de turno fijas en codigo:
la ventana es configuracion del centro, nunca una constante del producto (regla dura 13). Ver
`src/sw/README.md` para como se entera y cuando aplica.
