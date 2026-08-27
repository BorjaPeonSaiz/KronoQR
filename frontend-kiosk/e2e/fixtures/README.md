# Fixtures de las pruebas E2E con camara simulada

Chromium sabe hacerse pasar por una camara. La suite E2E del quiosco lo aprovecha (doc 02
§9.4):

```bash
chromium --use-fake-device-for-media-stream \
         --use-file-for-fake-video-capture=e2e/fixtures/qr-video.y4m
```

## Los dos videos

| Fichero                 | Contenido                                                     | Lo usa              |
| ----------------------- | ------------------------------------------------------------- | ------------------- |
| `qr-video.y4m`          | QR limpio con payload `FH1`, correccion de errores **Q**      | proyecto `kiosk-qr` |
| `qr-video-degraded.y4m` | El mismo QR con un **28 % del lado tapado** (tarjeta gastada) | `kiosk-qr-degraded` |

Formato YUV4MPEG2 `C420mpeg2`, 1280x720 a 30 fps, dos fotogramas (Chromium lo reproduce en
bucle). Es el unico formato que acepta `--use-file-for-fake-video-capture`.

## Se GENERAN, no se versionan

```bash
npm run e2e:fixtures        # y lo hace tambien playwright.config.ts antes de arrancar
```

`scripts/generate-qr-fixture.mjs` codifica el QR con el **mismo ZXing** que usa el quiosco
para leerlo y escribe los fotogramas a mano. No hace falta `ffmpeg`.

El motivo de no versionarlos es el tamano: el `.y4m` es video **sin comprimir**, y un solo
fotograma de 1280x720 en `yuv420p` son 1,38 MB. Cada fichero pesa 2,6 MB y volveria a pesar
lo mismo cada vez que rotara la clave de firma o cambiara el payload. El resultado es
determinista: mismo payload, mismos bytes.

## El payload

Por defecto, el ejemplo literal del doc 02 §5.1, que es tambien el del contrato:

```text
FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa
```

Su **firma no es valida** contra ninguna clave real, y no importa para esta tarea:

- el quiosco **no verifica firmas** (regla dura 10), solo el formato `FH1`;
- el E2E de la tarea 1.8 no habla con el backend, lo intercepta con `page.route`.

Cuando exista `php artisan credential:issue` (tarea 1.5), la CI puede inyectar un payload
**realmente firmado** sin tocar ni el guion ni las pruebas:

```bash
KIOSK_E2E_QR_PAYLOAD="$(php artisan credential:issue --print-payload)" npm run test:e2e
```

Ese es el momento de conectar el E2E contra el servidor de verdad, que es lo que pide el
ciclo offline completo de la **tarea 1.9**.

El video **no lleva datos personales**: el payload de la tarjeta nunca los contiene
(regla dura 10).

## Cuanta degradacion aguanta, medido

Con oclusion **opaca y contigua** sobre este simbolo:

| Fraccion del lado tapada | Area   | Resultado           |
| ------------------------ | ------ | ------------------- |
| 0,28                     | 7,8 %  | decodifica          |
| 0,32                     | 10,2 % | `ChecksumException` |

No contradice el «tolerante a un 25 % de degradacion» del doc 02 §5.1: ese 25 % son
**palabras de codigo repartidas** por el simbolo —que es como se estropea una tarjeta de
verdad: roces, grasa, un doblez—, mientras que un agujero de una pieza concentra el dano en
pocos bloques Reed-Solomon y es el caso peor posible. Conviene tenerlo escrito: si alguien
tapa media tarjeta con el dedo, el quiosco no la lee, y eso es correcto.

Se puede explorar el limite con `KIOSK_E2E_QR_OCCLUSION=0.32 npm run e2e:fixtures`.
