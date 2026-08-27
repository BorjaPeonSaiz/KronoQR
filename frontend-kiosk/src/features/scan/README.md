# scan

Camara, decodificacion del QR y confirmacion con color, texto grande y sonido diferenciado
(RF-KI-01, RF-KI-02, RF-KI-06, RF-AT-05). Tarea 1.8.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).

## Como esta repartido

```text
domain/        reglas sin dependencias: formato FH1, saludo, hora, acumulado, desenlaces
application/   la tuberia del escaneo y sus PUERTOS (cola y padron los enchufa la 1.9)
composables/   camara, bucle de decodificacion, wake lock, sonido, sesion
ui/            la pantalla y el panel de confirmacion
```

## El camino critico, en una linea

`useQrScanner` decodifica → `scanPipeline.handleDecoded()` verifica el formato `FH1`,
resuelve el nombre en el padron cacheado, encola y **devuelve la confirmacion de forma
sincrona** → `useScanSession` la pinta y hace sonar el tono → el envio al servidor sale
despues, en segundo plano.

`handleDecoded` no tiene ni un `await`: eso es lo que garantiza los 300 ms de RNF-P-03 por
construccion y no por suerte. El empleado nunca espera a la red.

## Lo que aporta la tarea 1.9

`application/ports.ts` declara `ScanSubmissionPort` y `RosterLookupPort`. Hoy los cubre
`application/directSubmission.ts`, que envia de uno en uno con un solo intento y devuelve
`deferred` si no hay red. La 1.9 los sustituye por la cola de Dexie —transaccional, con
retroceso exponencial, lotes de 50 ordenados por `occurred_at` y borrado solo tras
confirmacion del servidor— y por el padron cacheado y cifrado. Ni la tuberia ni la interfaz
cambian.

## Lo que NO se hace aqui, a proposito

- **No se verifica la firma HMAC.** Exige la clave, que no sale del servidor (regla dura
  10). Solo se comprueba el formato.
- **No se decide si es entrada o salida.** Lo decide el agregado `WorkDay` en el servidor.
  Mientras no conteste, la pantalla dice «pendiente de validar» y no se inventa nada.
- **No se distingue una causa de rechazo de otra.** Mensaje unico y generico (regla dura 17).
