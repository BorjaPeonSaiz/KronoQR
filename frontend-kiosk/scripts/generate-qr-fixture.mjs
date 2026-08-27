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

import { mkdirSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  BarcodeFormat,
  EncodeHintType,
  QRCodeDecoderErrorCorrectionLevel,
  QRCodeWriter,
} from '@zxing/library'

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

// Fraccion del LADO del QR que se tapa en la variante degradada.
//
// MEDIDO, no supuesto: con este payload y este tamano de simbolo, un cuadrado
// OPACO Y CONTIGUO decodifica hasta 0,28 del lado (7,8 % del area) y falla con
// `ChecksumException` a partir de 0,32 (10,2 %).
//
// No contradice el «tolerante a un 25 % de degradacion» del documento 02 §5.1:
// ese 25 % son PALABRAS DE CODIGO repartidas por el simbolo, que es como se
// degrada una tarjeta de verdad —roces, grasa, un doblez—. Un agujero opaco de
// una pieza concentra el dano en pocos bloques Reed-Solomon y es el caso peor
// posible. Conviene tenerlo escrito: si alguien tapa media tarjeta con el dedo,
// el quiosco no la lee, y eso es correcto, no un fallo.
const OCCLUSION_FRACTION = Number(process.env['KIOSK_E2E_QR_OCCLUSION'] ?? '0.28')

const log = (message) => process.stdout.write('[qr-fixture] ' + message + '\n')

/** Matriz de modulos del QR, con nivel de correccion Q (RF-QR-05). */
function encode(contents) {
  const hints = new Map()
  hints.set(EncodeHintType.ERROR_CORRECTION, QRCodeDecoderErrorCorrectionLevel.Q)
  hints.set(EncodeHintType.MARGIN, 4)
  hints.set(EncodeHintType.CHARACTER_SET, 'ISO-8859-1')
  // Anchura y altura 0: ZXing devuelve la matriz sin escalar.
  return new QRCodeWriter().encode(contents, BarcodeFormat.QR_CODE, 0, 0, hints)
}

/**
 * Pinta la matriz centrada sobre un plano de luminancia blanco.
 *
 * @param occludeFraction fraccion del LADO del QR que se tapa con un cuadrado
 *        blanco, para la variante degradada. El nivel Q tolera hasta un 25 % de
 *        area perdida; se tapa menos y se evitan los tres patrones de deteccion
 *        de las esquinas, que la correccion de errores NO protege.
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

try {
  mkdirSync(outputDir, { recursive: true })
  const matrix = encode(payload)
  log(`Payload: ${payload}`)
  log(`Matriz: ${matrix.getWidth()}x${matrix.getHeight()} modulos, correccion Q.`)

  const clean = path.join(outputDir, 'qr-video.y4m')
  const degraded = path.join(outputDir, 'qr-video-degraded.y4m')

  const cleanBytes = writeY4m(clean, renderLuma(matrix, 0))
  const degradedBytes = writeY4m(degraded, renderLuma(matrix, OCCLUSION_FRACTION))

  log(`Escrito e2e/fixtures/qr-video.y4m (${(cleanBytes / 1024 / 1024).toFixed(1)} MB)`)
  log(`Escrito e2e/fixtures/qr-video-degraded.y4m (${(degradedBytes / 1024 / 1024).toFixed(1)} MB)`)
} catch (error) {
  process.stderr.write('[qr-fixture] No se ha podido generar el video: ' + String(error) + '\n')
  process.stderr.write(
    '[qr-fixture] Que hacer: revisa que KIOSK_E2E_QR_PAYLOAD quepa en un QR y vuelve a intentarlo.\n',
  )
  process.exit(1)
}
