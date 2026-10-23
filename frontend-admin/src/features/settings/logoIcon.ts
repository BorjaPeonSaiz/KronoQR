// ¿Sirve el logotipo guardado como icono de la tablet? (V21-6-UX-3)
//
// El manifiesto del quiosco publica el logotipo como icono SOLO si es un PNG cuadrado de al
// menos 512 px de lado (`GetKioskManifestHandler`); en cualquier otro caso la tablet se instala
// con los iconos del fabricante, sin ningun mensaje. Aqui se mide el logotipo que el servidor
// sirve ya (`GET /api/v1/branding/logo`) para decirlo en el panel de Marca ANTES de que alguien
// lo descubra en la tablet. Es un aviso, no un bloqueo: el logotipo sigue siendo valido para las
// tres aplicaciones y los informes.

/** Debe coincidir con `GetKioskManifestHandler::MINIMUM_ICON_SIDE`. */
export const MINIMUM_ICON_SIDE = 512

export type LogoIconIssue = 'notPng' | 'notSquare' | 'tooSmall' | 'transparent'

export interface LogoFacts {
  /** `Content-Type` del logotipo servido (p. ej. `image/png`, `image/svg+xml`). */
  readonly mime: string
  /** Pixeles; 0 si el formato no es de pixeles (SVG) o no se pudo leer. */
  readonly width: number
  readonly height: number
  /** Algun pixel con transparencia. Solo se mide en PNG legibles. */
  readonly hasTransparency: boolean
}

/** Los motivos por los que el logotipo NO sirve (o sirve a medias) como icono. */
export function logoIconIssues(facts: LogoFacts): LogoIconIssue[] {
  if (facts.mime !== 'image/png') return ['notPng']

  const issues: LogoIconIssue[] = []
  if (facts.width !== facts.height) issues.push('notSquare')
  if (Math.min(facts.width, facts.height) < MINIMUM_ICON_SIDE) issues.push('tooSmall')
  if (facts.hasTransparency) issues.push('transparent')

  return issues
}

/** Lo unico que `readLogoFacts` necesita del navegador; inyectable para probar sin DOM real. */
export interface LogoReaderDeps {
  readonly fetch: (url: string) => Promise<Response>
  readonly decode: (
    blob: Blob,
  ) => Promise<{ width: number; height: number; hasTransparency: boolean }>
}

/** Decodifica en el navegador y busca pixeles no opacos en una copia reducida (128 px). */
async function decodeInBrowser(
  blob: Blob,
): Promise<{ width: number; height: number; hasTransparency: boolean }> {
  const bitmap = await createImageBitmap(blob)
  try {
    const side = 128
    const canvas = document.createElement('canvas')
    canvas.width = side
    canvas.height = side
    const context = canvas.getContext('2d')
    let hasTransparency = false
    if (context !== null) {
      context.drawImage(bitmap, 0, 0, side, side)
      const { data } = context.getImageData(0, 0, side, side)
      for (let index = 3; index < data.length; index += 4) {
        if ((data[index] ?? 255) < 250) {
          hasTransparency = true
          break
        }
      }
    }

    return { width: bitmap.width, height: bitmap.height, hasTransparency }
  } finally {
    bitmap.close()
  }
}

const BROWSER_DEPS: LogoReaderDeps = {
  fetch: (url) => fetch(url, { credentials: 'same-origin' }),
  decode: decodeInBrowser,
}

/**
 * Lee el logotipo servido. `null` si no se puede medir (sin red, formato que el navegador no
 * decodifica): en ese caso NO se avisa de nada, porque un aviso sin medida seria ruido.
 */
export async function readLogoFacts(
  url: string,
  deps: LogoReaderDeps = BROWSER_DEPS,
): Promise<LogoFacts | null> {
  try {
    const response = await deps.fetch(url)
    if (!response.ok) return null
    const blob = await response.blob()
    const mime = (response.headers.get('Content-Type') ?? blob.type).split(';')[0]?.trim() ?? ''
    if (mime !== 'image/png') return { mime, width: 0, height: 0, hasTransparency: false }

    return { mime, ...(await deps.decode(blob)) }
  } catch {
    return null
  }
}
