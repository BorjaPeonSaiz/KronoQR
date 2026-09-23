# offline

Cola de fichajes en IndexedDB con Dexie, sincronizacion ordenada por occurred_at, reintentos e indicador de estado (RF-KI-03, RF-KI-04). Tarea 1.9.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## La puerta de actualizacion (RF-KI-07, tarea 3.12)

`domain/updateWindow.ts` decide cuando el service worker puede aplicar una version nueva sin
arriesgar un fichaje: cola vacia, sin escaneo en los ultimos `quiet_minutes` minutos y dentro
de la ventana LOCAL que declaro el centro (`KioskHeartbeat.update_window`, cacheada sin red en
`shared/telemetry/deviceIdentity.ts`). Ya no hay franjas de cambio de turno fijas en codigo:
la ventana es configuracion del centro, nunca una constante del producto (regla dura 13). Ver
`src/sw/README.md` para como se entera y cuando aplica.
