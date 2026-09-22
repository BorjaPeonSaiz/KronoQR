# Fixtures de las pruebas E2E con camara simulada

Chromium sabe hacerse pasar por una camara. La suite E2E del quiosco lo aprovecha (doc 02
§9.4):

```bash
chromium --use-fake-device-for-media-stream \
         --use-file-for-fake-video-capture=e2e/fixtures/qr-video.y4m
```

## Los videos

| Fichero                 | Contenido                                                                                                                          | Lo usa              |
| ----------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | ------------------- |
| `qr-video.y4m`          | QR limpio con payload `FH1`, correccion de errores **Q**                                                                           | proyecto `kiosk-qr` |
| `qr-video-degraded.y4m` | El mismo QR con un **28 % del lado (con zona tranquila) tapado** (caso peor: un parche opaco y contiguo)                           | `kiosk-qr-degraded` |
| `qr-video-worn.y4m`     | El mismo QR con un **10 % de sus palabras de codigo** invertidas al azar, repartidas por todo el simbolo (roces, grasa, un doblez) | `kiosk-qr-worn`     |
| `qr-video-blank.y4m`    | Blanco liso, sin codigo (pantalla de espera estable)                                                                               | `kiosk-layout`      |

Formato YUV4MPEG2 `C420mpeg2`, 1280x720 a 30 fps, dos fotogramas (Chromium lo reproduce en
bucle). Es el unico formato que acepta `--use-file-for-fake-video-capture`.

`@RQ-04` (camara simulada con QR real que se decodifica de verdad, tarea 3.7) va SOLO en
las pruebas que corren de verdad contra uno de estos videos con
`--use-file-for-fake-video-capture`: la prueba principal de `scan.spec.ts`, la de
`degraded.spec.ts` y la de `worn.spec.ts`. No va en `layout.spec.ts` (usa
`qr-video-blank.y4m`, sin codigo que decodificar) ni en ninguna prueba de otra aplicacion
que no toque la camara.

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

## El payload y el simbolo real que produce

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

Con este payload, nivel de correccion **Q**, `QRCodeWriter` produce un simbolo de **version
5**: `matrix.getWidth()` (lo que reporta el guion en su log) es 45 modulos, pero eso
**incluye la zona tranquila** que anade `QRCodeWriter` (4 modulos a cada lado,
`QUIET_ZONE_SIZE`). El simbolo real -el que tiene version, patrones y palabras de codigo- son
37x37 modulos, con **134 palabras de codigo en total: 62 de datos y 72 de correccion
Reed-Solomon**, repartidas en 4 bloques de 18 palabras de correccion cada uno. El generador
comprueba estos cuatro numeros con un `assert` cuando se usa el payload por defecto: si
`KIOSK_E2E_QR_PAYLOAD` cambia el simbolo a otra version, el guion **falla y lo dice**, en vez
de regenerar en silencio un video que esta tabla ya no describe.

## Cuanta degradacion aguanta, medido

Hay dos formas muy distintas de estropear una tarjeta, y aguantan fracciones muy distintas
del area del simbolo real. Las dos estan medidas en `scripts/generate-qr-fixture.mjs`, no
supuestas. **Estas cifras son evidencia de que el decodificador no ha sufrido una regresion,
no el sustento de ninguna promesa al cliente**: el producto no documenta ninguna cifra de
tolerancia al desgaste (decision del equipo; doc 05 no la menciona).

### Oclusion contigua (caso peor): `qr-video-degraded.y4m`

Un unico parche opaco tapa una zona **contigua** del simbolo -el equivalente a un dedo, o a
una mancha grande-. La fraccion (`KIOSK_E2E_QR_OCCLUSION`) se aplica al lado de la matriz
**con** zona tranquila (es como se calcula el parche en pixeles, sobre el fotograma
1280x720), pero el area que importa es la del simbolo real, sin ella:

| Fraccion del lado (con zona tranquila) | Area del simbolo real | Resultado           |
| -------------------------------------- | --------------------- | ------------------- |
| 0,28 (por defecto)                     | 11,5 %                | decodifica          |
| 0,32                                   | 15,1 %                | `ChecksumException` |

Se puede explorar el limite con `KIOSK_E2E_QR_OCCLUSION=0.32 npm run e2e:fixtures`.

### Desgaste repartido (el caso realista): `qr-video-worn.y4m`

Una tarjeta con roces, grasa o un doblez no pierde un parche entero: pierde **bits sueltos,
repartidos por todo el simbolo**. `qr-video-worn.y4m` invierte, con una semilla fija
(determinista), una fraccion de las **palabras de codigo** del simbolo real -nunca de la
zona tranquila ni de los patrones estructurales (deteccion de posicion, sincronismo,
formato, version), que si se tocaran impedirian LOCALIZAR el simbolo y no dirian nada de la
tolerancia a errores de Reed-Solomon-. Un solo bit invertido por palabra elegida basta para
que Reed-Solomon la cuente como erronea; invertir mas bits de la misma palabra no cambia
nada. El orden real de colocacion de cada palabra se obtiene reproduciendo el zigzag del
propio codificador (`codewordCellOrder` en el guion), sobre el simbolo real (37x37, sin la
zona tranquila).

**Medido con 20 semillas por punto** (no solo la semilla fija que usa el fixture, para saber
si el resultado depende de la suerte de una sola tirada), sobre el simbolo de 134 palabras
de codigo que produce este payload:

| Fraccion de palabras corrompidas | Palabras | Semillas que decodifican (de 20) |
| -------------------------------- | -------- | -------------------------------- |
| 10,0 % (por defecto)             | 13/134   | 20/20                            |
| 14,9 %                           | 20/134   | 19/20                            |
| 17,9 %                           | 24/134   | 16/20                            |
| 20,9 %                           | 28/134   | 11/20                            |
| 23,9 %                           | 32/134   | 3/20                             |
| 26,9 %                           | 36/134   | 0/20                             |

`KIOSK_E2E_QR_WEAR` por defecto es **0,10**: el unico punto de la tabla con 20/20 semillas
-incluida la semilla fija de este fichero- y con margen comprobado hasta que empiezan a
aparecer los primeros fallos (14,9 %). Subir a 0,15 ya deja una semilla de cada veinte sin
decodificar en esta muestra: para un fixture determinista, que depende de UNA semilla fija,
0,10 es el punto que no arriesga nada por una mala tirada.

Coincide con la teoria: el nivel Q de este simbolo dedica 4 bloques de 18 palabras de
correccion cada uno (72 en total, 36/134 = 26,9 % son las que puede corregir sin saber DONDE
esta el error -la mitad de la redundancia, no toda ella-), y 26,9 % es justo el punto donde
ninguna de las 20 semillas decodifica ya.

Se puede explorar el limite con `KIOSK_E2E_QR_WEAR=0.267 npm run e2e:fixtures` (una sola
semilla: puede decodificar o no, segun la tirada, cerca del limite).

**Por que el limite del desgaste repartido es mas bajo que el de la oclusion contigua**,
aunque parezca lo contrario: Reed-Solomon corrige palabras de codigo enteras, no bits. Un
parche contiguo arruina POCAS palabras por completo y deja intactas casi todas las demas -es
ineficiente para quien quisiera romper el simbolo, y por eso aguanta mas area-. Repartir el
mismo dano al azar por todo el simbolo, en cambio, toca una palabra DISTINTA por cada bit
(salvo coincidencia), asi que agota antes el presupuesto de correccion: por eso el desgaste
repartido, con menos area danada (hasta el 14,9 % de las palabras en la tabla de arriba),
puede fallar donde la oclusion contigua (11,5-15,1 % del area) todavia decodifica.
