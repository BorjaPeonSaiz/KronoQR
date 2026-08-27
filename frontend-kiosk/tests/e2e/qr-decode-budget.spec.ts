// RNF-P-03 — tiempo de decodificacion del QR.
//
// EL REQUISITO Y LO QUE ESTA PRUEBA PUEDE Y NO PUEDE DECIR
//
// El Anexo A del doc 01 fija «< 300 ms en tablet de gama media». Esta prueba no
// corre en una tablet de gama media: corre en el portatil de quien la ejecuta o
// en un runner de GitHub. Afirmar aqui los 300 ms seria afirmar algo que la
// maquina que mide no puede saber — y peor: pasaria siempre, sea cual sea el
// codigo, porque en un escritorio hay un orden de magnitud de margen.
//
// Lo que SI comprueba, y es lo que se erosiona sin que nadie mire:
//
//   1. Que el QR de una tarjeta real —formato `FH1`, nivel de correccion Q,
//      RF-QR-05— se decodifica de verdad con el decodificador que lleva el
//      quiosco, no con otro.
//   2. Que decodificarlo cuesta un tiempo del orden que se espera, con un
//      presupuesto holgado sobre la medicion real. Lo que caza no es un 10 % de
//      empeoramiento: es la regresion de un orden de magnitud —alguien que
//      decodifique a resolucion completa, que quite la pista de formato y deje
//      probar los quince lectores 1D, o que cambie de libreria— que es
//      exactamente lo que llevaria los 300 ms de la tablet por delante.
//
// LO QUE QUEDA ABIERTO, Y SE DECLARA: la medicion de los 300 ms en la tablet real
// necesita la tablet. Es del mismo tipo que la prueba de resistencia de 12 h: no
// la puede cerrar ninguna maquina de integracion continua.
//
// POR QUE AQUI Y NO EN VITEST. La matriz de trazabilidad (doc 02 §9.6) explora
// las etiquetas de Pest y las de Playwright; una prueba de Vitest no la ve
// ninguna de las dos, asi que RNF-P-03 seguiria figurando sin prueba. La
// medicion es de Node —no necesita pagina— y Playwright es aqui el ejecutor.

import { expect, test } from '@playwright/test'
import {
  BarcodeFormat,
  BinaryBitmap,
  EncodeHintType,
  HybridBinarizer,
  QRCodeDecoderErrorCorrectionLevel,
  QRCodeReader,
  QRCodeWriter,
  RGBLuminanceSource,
} from '@zxing/library'
import { FIXTURE_PAYLOAD } from './support/kiosk'

/** Resolucion a la que el quiosco pide los fotogramas de la camara. */
const FRAME_WIDTH = 640
const FRAME_HEIGHT = 480

/** Cuanto del fotograma ocupa la tarjeta cuando alguien la acerca. */
const CARD_COVERAGE = 0.6

/**
 * Presupuesto de esta maquina, no el de la tablet.
 *
 * **De donde sale el numero, que es lo que hace util a un presupuesto.** Medido
 * en el equipo de desarrollo: mediana de 1,1 ms con `QRCodeReader`. Y medida
 * tambien la regresion que hay que cazar: usar `MultiFormatReader` sin acotar el
 * formato —que es lo que sale de copiar el primer ejemplo de la documentacion de
 * ZXing— sube la mediana a 22 ms, porque prueba antes los quince lectores de
 * codigo de barras 1D y falla en todos.
 *
 * 20 ms queda por encima de la medicion (factor 18, holgura de sobra para un
 * runner cargado, y la mediana de 21 muestras absorbe los picos) y por debajo de
 * la regresion (22 ms), que es la unica posicion desde la que un presupuesto
 * sirve para algo. Un 100 ms comodo habria dejado pasar exactamente el error que
 * mas probable es cometer.
 */
const HOST_BUDGET_MS = 20

/** Mediciones por corrida. Impar, para que la mediana sea un valor medido. */
const SAMPLES = 21

/** Decodificaciones previas que no se cuentan: JIT y calentamiento de cache. */
const WARMUP = 5

/**
 * El mapa de bits de luminancia de un fotograma con la tarjeta delante.
 *
 * Se genera desde el mismo payload que lleva el video de la camara simulada, asi
 * que lo que se mide es el QR que el quiosco ve de verdad y no uno de juguete de
 * cuatro caracteres —que se decodifica en una fraccion del tiempo y haria pasar
 * cualquier presupuesto—.
 */
function frameWithCard(payload: string, quarterTurn = false): RGBLuminanceSource {
  const hints = new Map<number, unknown>()
  hints.set(EncodeHintType.ERROR_CORRECTION, QRCodeDecoderErrorCorrectionLevel.Q)
  hints.set(EncodeHintType.MARGIN, 2)

  const matrix = new QRCodeWriter().encode(payload, BarcodeFormat.QR_CODE, 0, 0, hints)
  const modules = matrix.getWidth()

  const scale = Math.floor((Math.min(FRAME_WIDTH, FRAME_HEIGHT) * CARD_COVERAGE) / modules)
  const side = modules * scale
  const left = Math.floor((FRAME_WIDTH - side) / 2)
  const top = Math.floor((FRAME_HEIGHT - side) / 2)

  // 0xff es blanco: el fondo de la pantalla del quiosco tras la tarjeta.
  const luminance = new Uint8ClampedArray(FRAME_WIDTH * FRAME_HEIGHT).fill(0xff)

  for (let y = 0; y < side; y += 1) {
    for (let x = 0; x < side; x += 1) {
      const column = Math.floor(x / scale)
      const row = Math.floor(y / scale)

      // Un cuarto de vuelta se pinta girando la MATRIZ, no el mapa de bits:
      // `RGBLuminanceSource` no admite rotacion, y ademas asi el fotograma es el
      // que produciria una camara ante una tarjeta girada de verdad.
      const dark = quarterTurn ? matrix.get(row, modules - 1 - column) : matrix.get(column, row)

      if (dark) {
        luminance[(top + y) * FRAME_WIDTH + (left + x)] = 0x00
      }
    }
  }

  return new RGBLuminanceSource(luminance, FRAME_WIDTH, FRAME_HEIGHT)
}

function decodeOnce(source: RGBLuminanceSource, reader: QRCodeReader): string {
  const text = reader.decode(new BinaryBitmap(new HybridBinarizer(source))).getText()
  reader.reset()

  return text
}

test.describe('presupuesto de decodificacion del QR', () => {
  test(
    'decodifica el QR de una tarjeta real muy por debajo del presupuesto de la maquina',
    { tag: ['@RNF-P-03', '@RF-QR-05'] },
    () => {
      const source = frameWithCard(FIXTURE_PAYLOAD)
      const reader = new QRCodeReader()

      // Calentamiento: las primeras decodificaciones pagan el JIT y no
      // representan lo que hace el quiosco, que decodifica miles al dia.
      for (let i = 0; i < WARMUP; i += 1) decodeOnce(source, reader)

      const samples: number[] = []

      for (let i = 0; i < SAMPLES; i += 1) {
        const startedAt = performance.now()
        const text = decodeOnce(source, reader)
        samples.push(performance.now() - startedAt)

        // Que decodifique BIEN es parte de la medicion: un decodificador que
        // devolviera basura seria rapidisimo.
        expect(text).toBe(FIXTURE_PAYLOAD)
      }

      samples.sort((a, b) => a - b)
      const median = samples[Math.floor(SAMPLES / 2)] ?? Number.POSITIVE_INFINITY

      expect(
        median,
        `Mediana de ${median.toFixed(1)} ms sobre ${SAMPLES} decodificaciones. ` +
          `El presupuesto de esta maquina son ${HOST_BUDGET_MS} ms; el de la tablet, ` +
          `300 ms (RNF-P-03), y ese hay que medirlo en la tablet.`,
      ).toBeLessThan(HOST_BUDGET_MS)
    },
  )

  test(
    'sigue decodificando cuando la tarjeta llega girada, que es como llega',
    { tag: ['@RNF-P-03', '@RF-QR-05'] },
    () => {
      // Nadie presenta la tarjeta perfectamente alineada. Si el decodificador
      // solo acertara con el QR recto, el presupuesto de arriba mediria un caso
      // que en la puerta de servicio no ocurre nunca — y el empleado escanearia
      // cuatro veces, que es un fallo de rendimiento aunque cada intento sea
      // rapido.
      const reader = new QRCodeReader()

      expect(decodeOnce(frameWithCard(FIXTURE_PAYLOAD, true), reader)).toBe(FIXTURE_PAYLOAD)
    },
  )
})
