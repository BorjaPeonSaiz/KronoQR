// Codifica un texto como QR y lo devuelve como un trazado SVG.
//
// Compartido por el quiosco (QR de la politica de privacidad, RF-KI-09) y el
// panel (QR del `otpauth://` del alta de segundo factor, RS-06): las dos
// pantallas necesitan exactamente lo mismo —convertir un texto en un `<path>`
// que Vue pueda enlazar—, y antes de esta migracion el panel habria tenido que
// repetir el fichero o anadir su propia libreria de QR (ADR-036).
//
// POR QUE UN TRAZADO Y NO `v-html` CON EL SVG DE ZXing. La CSP del §7.2 no
// admite `unsafe-inline`, y meter marcado generado en el DOM con `v-html` es
// justo la puerta que esa CSP existe para cerrar. Un atributo `d` enlazado es
// datos, no marcado: Vue lo escapa y no hay nada que inyectar.
//
// POR QUE CARGA DIFERIDA. Ninguna de las dos pantallas necesita el codificador
// de QR para lo primero que hace —escanear en el quiosco, teclear la
// contrasena en el panel—. Se carga cuando alguien abre el dialogo del QR, y
// asi no gasta presupuesto de bundle critico de ninguna de las dos SPA.

export interface QrPath {
  /** Lado del `viewBox`, en modulos (incluye la zona de silencio). */
  readonly size: number
  /** Trazado de los modulos oscuros. */
  readonly path: string
}

/**
 * @param contents texto a codificar: la URL de la politica de privacidad en el
 *        quiosco, la URI `otpauth://` del segundo factor en el panel.
 * @returns `null` si el codificador no se pudo cargar o el texto no cabe. Quien
 *          llama debe seguir ofreciendo el texto sin codificar (el enlace de la
 *          politica, el secreto en base32): ninguna de las dos pantallas depende
 *          de que el QR se pinte.
 */
export async function renderQrPath(contents: string): Promise<QrPath | null> {
  if (contents === '') return null

  try {
    const { BarcodeFormat, EncodeHintType, QRCodeWriter, QRCodeDecoderErrorCorrectionLevel } =
      await import('@zxing/library')

    const hints = new Map<number, unknown>()
    // Nivel Q, el mismo que las tarjetas (RF-QR-05): el movil de quien lo lea
    // puede estar a contraluz delante de una pantalla brillante.
    hints.set(EncodeHintType.ERROR_CORRECTION, QRCodeDecoderErrorCorrectionLevel.Q)
    hints.set(EncodeHintType.MARGIN, 2)

    // Anchura y altura 0: ZXing devuelve la matriz de modulos sin escalar, que es
    // exactamente lo que hace falta para un `viewBox`.
    const matrix = new QRCodeWriter().encode(contents, BarcodeFormat.QR_CODE, 0, 0, hints)

    const width = matrix.getWidth()
    const height = matrix.getHeight()
    let path = ''
    for (let y = 0; y < height; y += 1) {
      for (let x = 0; x < width; x += 1) {
        if (matrix.get(x, y)) path += `M${x} ${y}h1v1h-1z`
      }
    }

    return path === '' ? null : { size: Math.max(width, height), path }
  } catch {
    return null
  }
}
