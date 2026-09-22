#!/usr/bin/env node
//
// KronoQR — generacion de los videos de camara simulada para el E2E (doc 02 §9.4).
//
// Chromium sabe hacerse pasar por una camara:
//
//   chromium --use-fake-device-for-media-stream \
//            --use-file-for-fake-video-capture=e2e/fixtures/qr-video.y4m
//
// El unico formato que acepta es YUV4MPEG2 sin comprimir. Este guion lo produce
// sin depender de ffmpeg ni de ninguna herramienta externa: codifica el QR con
// el MISMO ZXing que usa el quiosco para leerlo y escribe los fotogramas a mano.
//
// POR QUE SE GENERA Y NO SE VERSIONA
// ----------------------------------
// Un fotograma de 1280x720 en yuv420p son 1,38 MB. Un video de cuatro segundos a
// 30 fps son 166 MB, y ni siquiera reduciendo a dos fotogramas baja de los tres
// megas. Meter eso en el repositorio —y volver a meterlo cada vez que rote la
// clave de firma o cambie el payload de prueba— es peor que reconstruirlo en
// medio segundo antes de cada ejecucion. El resultado es determinista: el mismo
// payload produce byte a byte el mismo fichero.
//
// EL PAYLOAD
// ----------
// Por defecto se usa el ejemplo literal del documento 02 §5.1, que es tambien el
// del contrato. Su FIRMA no es valida contra ninguna clave real, y no importa:
//
//   - el quiosco NO verifica firmas (regla dura 10), solo el formato `FH1`;
//   - el E2E de esta tarea no habla con el backend, lo intercepta.
//
// Cuando exista `php artisan credential:issue` (tarea 1.5), la CI puede pasar un
// payload REALMENTE firmado por la variable `KIOSK_E2E_QR_PAYLOAD` sin tocar ni
// este guion ni las pruebas. Ese es el momento de conectar el E2E contra el
// servidor de verdad.
//
// El video NO lleva datos personales: el payload de una tarjeta nunca los
// contiene (regla dura 10).
//
// Codigos de salida:
//   0  ficheros escritos
//   1  el codificador fallo

import assert from 'node:assert/strict'
import { mkdirSync, writeFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  BarcodeFormat,
  EncodeHintType,
  QRCodeDecoderErrorCorrectionLevel,
  QRCodeWriter,
} from '@zxing/library'

// Piezas INTERNAS del codificador (no son API publica de `@zxing/library`,
// por eso se importan por ruta y no desde el paquete): hacen falta para
// reproducir el ORDEN real en que se colocan las palabras de codigo en la
// matriz (ver `codewordCellOrder`, mas abajo), que es lo unico que permite
// desgastar por PALABRA y no por modulo suelto. Si una subida de version de
// `@zxing/library` reorganiza estos ficheros, este `require` fallara alto y
// claro -el guion ya envuelve todo en un `try/catch` con mensaje- en vez de
// producir un video silenciosamente incorrecto.
const require = createRequire(import.meta.url)
const ByteMatrix = require('@zxing/library/cjs/core/qrcode/encoder/ByteMatrix.js').default
const MatrixUtil = require('@zxing/library/cjs/core/qrcode/encoder/MatrixUtil.js').default
const Version = require('@zxing/library/cjs/core/qrcode/decoder/Version.js').default
const BitArray = require('@zxing/library/cjs/core/common/BitArray.js').default

const WIDTH = 1280
const HEIGHT = 720
const FPS = 30
// Chromium reproduce el fichero en bucle. Dos fotogramas bastan y son 2,7 MB en
// lugar de 166 MB.
const FRAMES = 2

// Rango limitado (C420mpeg2): negro = 16, blanco = 235, croma neutro = 128.
const LUMA_BLACK = 16
const LUMA_WHITE = 235
const CHROMA_NEUTRAL = 128

const appDir = path.resolve(fileURLToPath(new URL('..', import.meta.url)))
const outputDir = path.join(appDir, 'e2e', 'fixtures')

const payload =
  process.env['KIOSK_E2E_QR_PAYLOAD'] ?? 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'

/** Lee una fraccion `0 <= x <= 1` de una variable de entorno, o falla alto y claro. */
function readFractionEnv(name, fallback) {
  const raw = process.env[name]
  if (raw === undefined) return fallback
  const value = Number(raw)
  if (!Number.isFinite(value) || value < 0 || value > 1) {
    throw new Error(
      `${name}="${raw}" no es una fraccion valida (debe ser un numero finito entre 0 y 1).`,
    )
  }
  return value
}

// Fraccion del LADO PADDED del QR (con zona tranquila) que se tapa en la
// variante degradada. Se aplica sobre `matrix.getWidth()`, no sobre el
// simbolo real: ver el comentario de `QUIET_ZONE`, mas abajo.
//
// MEDIDO, no supuesto (pixel a pixel, sobre el mismo fotograma 1280x720 que
// escribe el video): un cuadrado OPACO Y CONTIGUO de esta fraccion del lado
// tapa un 11,5 % del AREA DEL SIMBOLO REAL (sin la zona tranquila) y
// decodifica; a partir de 0,32 (15,1 % del area) falla con
// `ChecksumException`.
//
// Un agujero opaco de una pieza concentra el dano en pocos bloques
// Reed-Solomon y es el caso peor posible: para la misma area danada, un
// desgaste REPARTIDO (`resolveFractions().wearFraction`, mas abajo) tolera mucho menos, porque
// toca muchas mas palabras de codigo distintas. Conviene tenerlo escrito: si
// alguien tapa media tarjeta con el dedo, el quiosco no la lee, y eso es
// correcto, no un fallo.
//
// Se resuelve DENTRO del `try` de mas abajo (con `resolveFractions()`), no
// aqui arriba: un valor invalido tiene que salir por el mismo canal de error
// -con su «Que hacer»- que cualquier otro fallo del guion, no como una
// excepcion sin capturar en la carga del modulo.
function resolveFractions() {
  return {
    occlusionFraction: readFractionEnv('KIOSK_E2E_QR_OCCLUSION', 0.28),
    wearFraction: readFractionEnv('KIOSK_E2E_QR_WEAR', 0.1),
  }
}

// Fraccion de PALABRAS DE CODIGO (no de modulos sueltos) que se corrompen al
// azar, con semilla fija, para la variante repartida `qr-video-worn.y4m`.
//
// POR QUE PALABRAS Y NO MODULOS SUELTOS. Reed-Solomon protege palabras de
// codigo (bytes), no bits: un solo bit mal en una palabra ya la cuenta como
// erronea entera, y estropear mas bits de esa misma palabra no cuesta nada
// extra pero tampoco perdona nada. Invertir MODULOS al azar sin mirar a que
// palabra pertenece cada uno desperdicia dano por partida doble -por
// coincidencia (efecto cumpleanos), pocos bits bastan para tocar casi todas
// las palabras del simbolo- y el decodificador se rinde mucho antes de lo
// que un simple «X % de bits» sugeriria. Elegir PALABRAS completas -un bit
// por palabra elegida, nunca dos en la misma- es lo que de verdad representa
// «X % de las palabras de codigo estan mal». El orden real de colocacion se
// obtiene de `codewordCellOrder`, mas abajo, sobre el SIMBOLO REAL (sin la
// zona tranquila que anade `QRCodeWriter`).
//
// MEDIDO, no supuesto: 20 semillas por punto, sobre el simbolo real que
// produce este payload -version 5, nivel Q, 134 palabras de codigo-:
//
//   fraccion  palabras   decodifican
//   10,0 %    13/134     20/20
//   14,9 %    20/134     19/20
//   17,9 %    24/134     16/20
//   20,9 %    28/134     11/20
//   23,9 %    32/134      3/20
//   26,9 %    36/134      0/20
//
// (la curva completa, con el metodo, vive en `e2e/fixtures/README.md`).
// Coincide con la teoria: el nivel Q de este simbolo dedica 4 bloques de 18
// palabras de correccion cada uno (36/134 = 26,9 %) a Reed-Solomon, y ese es
// justo el punto donde ninguna semilla decodifica ya: para errores de
// POSICION DESCONOCIDA el margen se agota MUCHO antes del 100 % de la
// redundancia nominal.
//
// POR DEFECTO SE QUEDA EN 0,10: es el unico punto de la tabla con 20/20 de
// 20 semillas -incluida la semilla fija que usa este fichero-, con margen
// comprobado hasta que empiezan a aparecer fallos (14,9 %). El producto NO
// promete ninguna cifra de tolerancia al desgaste (decision del usuario): lo
// que hay aqui es evidencia de regresion del decodificador, no el sustento
// de una promesa. Se resuelve dentro del `try` de mas abajo, junto con
// `OCCLUSION_FRACTION` (ver `resolveFractions()`).

/** Semilla fija: mismo payload, mismas palabras corrompidas, siempre. */
const WEAR_SEED = 0xa5e7c0de

/**
 * Modulos de zona tranquila que anade `QRCodeWriter` a cada lado del simbolo
 * real (`QRCodeWriter.QUIET_ZONE_SIZE`, y el mismo numero que se le pasa como
 * `EncodeHintType.MARGIN` en `encode()`, mas abajo: tienen que ser el mismo
 * valor o esta cuenta se desincroniza).
 *
 * `matrix.getWidth()` (lo que devuelve `encode()`) incluye esa franja: para
 * este payload son 45 modulos, pero el simbolo CON version, patrones y
 * palabras de codigo mide 45 - 2*4 = 37 modulos (version 5), no 45.
 *
 * Un fallo real de esta implementacion trato el ancho CON zona tranquila
 * como si fuera el simbolo entero: dedujo la version 7 (`(45-17)/4`) en vez
 * de la 5 real, y de las 20 celdas que invertia a fraccion 0,10 solo 11
 * caian de verdad sobre palabras de codigo -el resto caian en la zona
 * tranquila (siempre blanca; invertirla ahi no hace nada salvo por
 * casualidad) o sobre los patrones estructurales de un simbolo version 7 que
 * no era el que se estaba dibujando-. `codewordCellOrder` corrige esto:
 * resta la zona tranquila ANTES de deducir la version, y los `assert` de mas
 * abajo comprueban que nunca vuelva a pasar en silencio.
 */
const QUIET_ZONE = 4

/**
 * La geometria real que produce el payload por defecto, con nivel Q: version
 * 5, simbolo de 37x37 modulos, 134 palabras de codigo en total -62 de datos
 * y 72 de correccion Reed-Solomon (`Version.getECBlocksForLevel`)-. Todas
 * las cifras del README de fixtures -la tabla del desgaste, el porcentaje de
 * la oclusion- se midieron sobre ESTE simbolo exacto.
 *
 * Si `KIOSK_E2E_QR_PAYLOAD` cambia (o una version futura de `@zxing/library`
 * codifica distinto), el simbolo puede pasar a otra version sin que nadie lo
 * note -ZXing no avisa, solo produce un QR mas grande o mas pequeno- y la
 * tabla del README quedaria describiendo un simbolo que ya no existe,
 * mientras el E2E sigue en verde porque el desgaste se sigue expresando como
 * FRACCION de palabras, no como un numero fijo. Por eso se comprueba aqui,
 * con numeros explicitos: si el payload por defecto no produce esto, el
 * guion falla y dice por que, en vez de regenerar en silencio un video cuya
 * documentacion ya no es cierta.
 */
const EXPECTED_DEFAULT_SYMBOL = {
  version: 5,
  symbolWidth: 37,
  totalCodewords: 134,
  dataCodewords: 62,
}

const log = (message) => process.stdout.write('[qr-fixture] ' + message + '\n')

/** Generador determinista (mulberry32): mismo `seed`, misma secuencia siempre. */
function mulberry32(seed) {
  let a = seed
  return function random() {
    a |= 0
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/**
 * El orden REAL en que `MatrixUtil.embedDataBits` (el propio codificador)
 * coloca las 8 celdas de cada palabra de codigo en la matriz -el zigzag de
 * JISX0510-. Se obtiene reproduciendo el mismo embebido de patrones
 * estructurales que hace el codificador, sobre el SIMBOLO REAL (`paddedModules`
 * menos la zona tranquila de cada lado: ver el comentario de `QUIET_ZONE`),
 * y espiando que coordenadas visita `embedDataBits`: los VALORES que escribe
 * no importan aqui, solo el ORDEN, que es identico sea cual sea la mascara
 * (por eso se le pasa `0`).
 *
 * Tres comprobaciones en tiempo de ejecucion, no solo en un comentario: cada
 * celda de dato cae DENTRO del simbolo real, ninguna coincide con un patron
 * estructural (deteccion, sincronismo, formato, version -capturados ANTES de
 * embeber ningun dato-), y el numero de palabras coincide con lo que declara
 * la version detectada. Un futuro error de geometria como el que esto
 * corrige (zona tranquila contada como simbolo) hace fallar el guion en vez
 * de producir, en silencio, un video que desgasta la zona equivocada.
 *
 * @param paddedModules `matrix.getWidth()`: el simbolo real MAS la zona tranquila.
 * @returns `{ codewords, version, symbolWidth }`, con las coordenadas de cada
 *          palabra en el sistema de la matriz COMPLETA (con zona tranquila),
 *          que es el que usan `pickWornCells`/`renderLumaWorn`.
 */
function codewordCellOrder(paddedModules) {
  const symbolWidth = paddedModules - QUIET_ZONE * 2
  const version = Version.getVersionForNumber((symbolWidth - 17) / 4)
  const matrix = new ByteMatrix(symbolWidth, symbolWidth)
  matrix.clear(255)
  MatrixUtil.embedBasicPatterns(version, matrix)
  MatrixUtil.embedTypeInfo(QRCodeDecoderErrorCorrectionLevel.Q, 0, matrix)
  MatrixUtil.maybeEmbedVersionInfo(version, matrix)

  // Instantanea de las celdas FUNCIONALES (deteccion, sincronismo, formato,
  // version): todo lo que ya no vale 255 antes de embeber ningun dato. Sirve
  // para el aserto de mas abajo, no solo para que `embedDataBits` las salte.
  const functional = new Set()
  for (let y = 0; y < symbolWidth; y += 1) {
    for (let x = 0; x < symbolWidth; x += 1) {
      if (matrix.get(x, y) !== 255) functional.add(`${x}:${y}`)
    }
  }

  const order = []
  const originalSetBoolean = matrix.setBoolean.bind(matrix)
  matrix.setBoolean = (x, y, value) => {
    // Coordenadas ya trasladadas al sistema de la matriz CON zona tranquila.
    order.push([x + QUIET_ZONE, y + QUIET_ZONE])
    return originalSetBoolean(x, y, value)
  }

  const totalCodewords = version.getTotalCodewords()
  MatrixUtil.embedDataBits(new BitArray(totalCodewords * 8), 0, matrix)

  // `order` puede traer, al final, unos pocos «bits de relleno» (remainder
  // bits del JISX0510: algunas versiones no llenan un numero exacto de
  // palabras de 8 bits) que no pertenecen a ninguna palabra de codigo real:
  // se descartan sin contarlos ni desgastarlos.
  const dataOrder = order.slice(0, totalCodewords * 8)
  const codewords = []
  for (let i = 0; i < dataOrder.length; i += 8) codewords.push(dataOrder.slice(i, i + 8))

  for (const [x, y] of order) {
    const localX = x - QUIET_ZONE
    const localY = y - QUIET_ZONE
    assert.ok(
      localX >= 0 && localX < symbolWidth && localY >= 0 && localY < symbolWidth,
      `celda de dato (${x},${y}) cae fuera del simbolo real (${symbolWidth}x${symbolWidth} ` +
        `+ zona tranquila de ${QUIET_ZONE}): la geometria esta mal.`,
    )
    assert.ok(
      !functional.has(`${localX}:${localY}`),
      `celda de dato (${x},${y}) coincide con un patron estructural: la mascara de ` +
        'funcionales esta mal.',
    )
  }
  assert.strictEqual(
    codewords.length,
    totalCodewords,
    `version ${version.getVersionNumber()} declara ${totalCodewords} palabras de codigo ` +
      `y se contaron ${codewords.length}.`,
  )

  const ecBlocks = version.getECBlocksForLevel(QRCodeDecoderErrorCorrectionLevel.Q)
  const dataCodewords = totalCodewords - ecBlocks.getTotalECCodewords()

  return { codewords, version, symbolWidth, dataCodewords }
}

/**
 * Elige, con la semilla dada, que palabras de codigo se corrompen, y
 * devuelve UNA celda por palabra elegida (basta: ver el comentario de
 * `resolveFractions()`, mas arriba, sobre por que un bit por palabra).
 */
function pickWornCells(codewords, fraction, seed) {
  const pool = codewords.map((_, index) => index)
  const random = mulberry32(seed)
  const count = Math.round(pool.length * fraction)

  // Fisher-Yates parcial: solo se mezclan las `count` posiciones finales,
  // que es lo unico que hace falta para una seleccion sin reemplazo.
  let last = pool.length - 1
  while (last > pool.length - 1 - count && last > 0) {
    const swapWith = Math.floor(random() * (last + 1))
    const tmp = pool[last]
    pool[last] = pool[swapWith]
    pool[swapWith] = tmp
    last -= 1
  }

  return pool.slice(pool.length - count).map((index) => codewords[index][0])
}

/** Matriz de modulos del QR, con nivel de correccion Q (RF-QR-05). */
function encode(contents) {
  const hints = new Map()
  hints.set(EncodeHintType.ERROR_CORRECTION, QRCodeDecoderErrorCorrectionLevel.Q)
  // MISMO valor que `QUIET_ZONE`: es lo que `codewordCellOrder` resta de
  // `matrix.getWidth()` para llegar al simbolo real.
  hints.set(EncodeHintType.MARGIN, QUIET_ZONE)
  hints.set(EncodeHintType.CHARACTER_SET, 'ISO-8859-1')
  // Anchura y altura 0: ZXing devuelve la matriz sin escalar.
  return new QRCodeWriter().encode(contents, BarcodeFormat.QR_CODE, 0, 0, hints)
}

/**
 * Pinta la matriz centrada sobre un plano de luminancia blanco.
 *
 * @param occludeFraction fraccion del LADO PADDED del QR (con zona tranquila)
 *        que se tapa con un cuadrado blanco, para la variante degradada
 *        (medido en area real del simbolo: comentario de `resolveFractions()`,
 *        mas arriba). Se evitan los tres patrones de deteccion de las
 *        esquinas, que la correccion de errores NO protege.
 */
function renderLuma(matrix, occludeFraction = 0) {
  const luma = new Uint8Array(WIDTH * HEIGHT).fill(LUMA_WHITE)

  const modules = matrix.getWidth()
  const scale = Math.floor(Math.min(WIDTH, HEIGHT) / modules)
  const side = modules * scale
  const originX = Math.floor((WIDTH - side) / 2)
  const originY = Math.floor((HEIGHT - side) / 2)

  for (let my = 0; my < modules; my += 1) {
    for (let mx = 0; mx < modules; mx += 1) {
      if (!matrix.get(mx, my)) continue
      for (let dy = 0; dy < scale; dy += 1) {
        const row = (originY + my * scale + dy) * WIDTH + originX + mx * scale
        luma.fill(LUMA_BLACK, row, row + scale)
      }
    }
  }

  if (occludeFraction > 0) {
    const patch = Math.floor(side * occludeFraction)
    // Zona central-baja: lejos de las tres esquinas con patron de deteccion.
    const patchX = originX + Math.floor((side - patch) / 2)
    const patchY = originY + Math.floor(side * 0.55)
    for (let dy = 0; dy < patch && patchY + dy < HEIGHT; dy += 1) {
      const row = (patchY + dy) * WIDTH + patchX
      luma.fill(LUMA_WHITE, row, row + patch)
    }
  }

  return luma
}

function writeY4m(file, luma) {
  const chromaSize = (WIDTH / 2) * (HEIGHT / 2)
  const chroma = Buffer.alloc(chromaSize, CHROMA_NEUTRAL)
  const header = Buffer.from(
    `YUV4MPEG2 W${WIDTH} H${HEIGHT} F${FPS}:1 It A1:1 C420mpeg2\n`,
    'ascii',
  )
  const frameMarker = Buffer.from('FRAME\n', 'ascii')

  const parts = [header]
  for (let index = 0; index < FRAMES; index += 1) {
    parts.push(frameMarker, Buffer.from(luma), chroma, chroma)
  }

  writeFileSync(file, Buffer.concat(parts))
  return parts.reduce((total, part) => total + part.byteLength, 0)
}

/**
 * Como `renderLuma`, pero invirtiendo exactamente las celdas de `wornCells`
 * -una por palabra de codigo corrompida (`pickWornCells`)- en vez de tapar
 * un parche contiguo.
 */
function renderLumaWorn(matrix, wornCells) {
  const wornKeys = new Set(wornCells.map(([x, y]) => `${x}:${y}`))
  const luma = new Uint8Array(WIDTH * HEIGHT).fill(LUMA_WHITE)

  const modules = matrix.getWidth()
  const scale = Math.floor(Math.min(WIDTH, HEIGHT) / modules)
  const side = modules * scale
  const originX = Math.floor((WIDTH - side) / 2)
  const originY = Math.floor((HEIGHT - side) / 2)

  for (let my = 0; my < modules; my += 1) {
    for (let mx = 0; mx < modules; mx += 1) {
      const dark = wornKeys.has(`${mx}:${my}`) ? !matrix.get(mx, my) : matrix.get(mx, my)
      if (!dark) continue
      for (let dy = 0; dy < scale; dy += 1) {
        const row = (originY + my * scale + dy) * WIDTH + originX + mx * scale
        luma.fill(LUMA_BLACK, row, row + scale)
      }
    }
  }

  return luma
}

try {
  mkdirSync(outputDir, { recursive: true })
  const { occlusionFraction, wearFraction } = resolveFractions()
  const matrix = encode(payload)
  log(`Payload: ${payload}`)
  log(`Matriz (con zona tranquila): ${matrix.getWidth()}x${matrix.getHeight()} modulos.`)

  const clean = path.join(outputDir, 'qr-video.y4m')
  const degraded = path.join(outputDir, 'qr-video-degraded.y4m')
  const worn = path.join(outputDir, 'qr-video-worn.y4m')
  const blank = path.join(outputDir, 'qr-video-blank.y4m')

  const cleanBytes = writeY4m(clean, renderLuma(matrix, 0))
  const degradedBytes = writeY4m(degraded, renderLuma(matrix, occlusionFraction))

  const { codewords, version, symbolWidth, dataCodewords } = codewordCellOrder(matrix.getWidth())
  log(
    `Simbolo real: version ${version.getVersionNumber()} (${symbolWidth}x${symbolWidth} modulos ` +
      `+ zona tranquila de ${QUIET_ZONE}), correccion Q, ${codewords.length} palabras de codigo ` +
      `(${dataCodewords} de datos).`,
  )

  // Solo con el payload POR DEFECTO: uno pasado por `KIOSK_E2E_QR_PAYLOAD`
  // puede -legitimamente- producir otra version. Ver `EXPECTED_DEFAULT_SYMBOL`.
  if (process.env['KIOSK_E2E_QR_PAYLOAD'] === undefined) {
    assert.strictEqual(
      version.getVersionNumber(),
      EXPECTED_DEFAULT_SYMBOL.version,
      'el payload por defecto ya no produce version ' +
        `${EXPECTED_DEFAULT_SYMBOL.version}: la tabla de desgaste del README esta caducada, ` +
        'hay que remedirla.',
    )
    assert.strictEqual(symbolWidth, EXPECTED_DEFAULT_SYMBOL.symbolWidth)
    assert.strictEqual(codewords.length, EXPECTED_DEFAULT_SYMBOL.totalCodewords)
    assert.strictEqual(dataCodewords, EXPECTED_DEFAULT_SYMBOL.dataCodewords)
  }

  const wornCells = pickWornCells(codewords, wearFraction, WEAR_SEED)
  log(
    `Desgaste repartido: ${wornCells.length}/${codewords.length} palabras de codigo ` +
      `corrompidas (fraccion ${wearFraction}, semilla 0x${WEAR_SEED.toString(16)}).`,
  )
  const wornBytes = writeY4m(worn, renderLumaWorn(matrix, wornCells))

  // Blanco liso, SIN codigo: nadie ha acercado una tarjeta todavia. La usa el
  // E2E de disposicion de pantalla (`layout.spec.ts`), que necesita la
  // pantalla de espera estable e indefinidamente para medir su geometria —
  // con `qr-video.y4m` el bucle de decodificacion la sustituye por la
  // confirmacion en cuanto lee el QR, y esa carrera es justo lo que ese E2E
  // no puede permitirse.
  const blankBytes = writeY4m(blank, new Uint8Array(WIDTH * HEIGHT).fill(LUMA_WHITE))

  log(`Escrito e2e/fixtures/qr-video.y4m (${(cleanBytes / 1024 / 1024).toFixed(1)} MB)`)
  log(`Escrito e2e/fixtures/qr-video-degraded.y4m (${(degradedBytes / 1024 / 1024).toFixed(1)} MB)`)
  log(`Escrito e2e/fixtures/qr-video-worn.y4m (${(wornBytes / 1024 / 1024).toFixed(1)} MB)`)
  log(`Escrito e2e/fixtures/qr-video-blank.y4m (${(blankBytes / 1024 / 1024).toFixed(1)} MB)`)
} catch (error) {
  process.stderr.write('[qr-fixture] No se ha podido generar el video: ' + String(error) + '\n')
  process.stderr.write(
    '[qr-fixture] Que hacer: revisa que KIOSK_E2E_QR_PAYLOAD quepa en un QR, que ' +
      'KIOSK_E2E_QR_WEAR/KIOSK_E2E_QR_OCCLUSION sean numeros entre 0 y 1, y vuelve a intentarlo.\n',
  )
  process.exit(1)
}
