// Marca blanca en tiempo de ejecucion (RF-PD-08, tarea 5.8, regla dura 13).
//
// Las tres SPA reciben la marca de `GET /api/v1/branding` y la aplican aqui
// SOBRE LOS TOKENS `--kq-*` DE `theme.css`, sin recompilar nada por cliente
// (ADR-017, docs/06-guia-visual.md §7). Como las utilidades de Tailwind son
// `@theme inline`, una custom property sobreescrita en `:root` llega a todas
// las clases `*-kq-*` sin mas.
//
// Que se toca y que no. El cliente elige UN color de acento. De el se derivan
// los tokens de la familia primaria de cada contexto —el claro del panel y el
// portal, el oscuro del quiosco— y NADA MAS: superficies, texto, bordes, los
// estados semanticos (success/warning/danger) y los cinco colores de
// confirmacion del quiosco siguen siendo los del producto (doc 06 §7). Los
// tonos derivados (texto sobre el acento, fondo tintado, acento aclarado para
// el fondo oscuro del quiosco) se calculan buscando el primer tono que alcanza
// el minimo WCAG 2.2 AA de la pareja, con la misma formula que mide la paleta
// del producto (`contrast.ts`). Lo que no se puede garantizar por calculo —el
// acento como color GRANDE sobre las superficies del producto— se AVISA, no se
// impone (paso 9 de la tarea): `contrastWarnings` pasa el acento por las
// parejas de `themePairs.ts` y devuelve las que no llegan.
//
// El valor por defecto ES el producto: con `accentColor: null` no se
// sobreescribe ningun token y el sistema visual del doc 06 queda intacto.

import { contrastRatio, parseHexColor, WCAG_AA_MINIMUM, type Rgb } from './contrast'
import { kioskPairs, lightPairs, type ThemePair } from './themePairs'

export interface LocalePolicy {
  /** Idioma con el que se sirven las aplicaciones cuando nadie elige otro. */
  readonly default: string
  /** Idiomas que la instalacion ofrece. Nunca vacio. */
  readonly available: readonly string[]
}

/** La marca tal como la entrega `GET /api/v1/branding`, en camelCase. */
export interface Branding {
  readonly applicationName: string
  /** `null` = el cliente no ha elegido color: no se toca ningun token. */
  readonly accentColor: string | null
  /** URL relativa del logotipo con su huella en `v`, o `null` si no hay. */
  readonly logoUrl: string | null
  readonly locales: LocalePolicy
}

/**
 * Lo que se enseña mientras no llega —o no puede llegar— la respuesta del
 * servidor. Coincide con los valores de serie del catalogo de
 * `installation_settings`: el producto, nunca la marca de otro cliente.
 */
export const PRODUCT_BRANDING: Branding = Object.freeze({
  applicationName: 'KronoQR',
  accentColor: null,
  logoUrl: null,
  locales: Object.freeze({ default: 'es', available: Object.freeze(['es', 'en']) }),
})

/**
 * El acento de serie del catalogo (`BRANDING_ACCENT_COLOR`): el
 * `primary-strong` de theme.css. El panel lo escribe cuando el cliente quiere
 * «volver al color del producto», porque no hay forma de retirar una clave
 * desde la API. Vive aqui, y solo aqui, para que el frontend no tenga dos
 * sitios con el mismo hexadecimal.
 */
export const PRODUCT_ACCENT_COLOR = '#b8542a'

/** Contexto visual: el panel y el portal son claros; el quiosco es oscuro. */
export type ThemeMode = 'light' | 'kiosk'

/** Resuelve el valor actual de un token `--kq-color-*` del tema base. */
export type TokenResolver = (token: string) => string

const ACCENT = /^#[0-9a-f]{6}$/i
/** La URL que publica el contrato: relativa, del propio origen, con o sin huella. */
const LOGO_URL = /^\/api\/v1\/branding\/logo(?:\?v=[0-9a-f]{12})?$/

/**
 * Valida la respuesta de `GET /api/v1/branding` y la traduce a `Branding`.
 * Devuelve `null` ante cualquier forma inesperada: quien llama decide si se
 * queda con `PRODUCT_BRANDING` o con lo que tenia guardado. Nunca lanza.
 */
export function parseBranding(value: unknown): Branding | null {
  if (typeof value !== 'object' || value === null) return null
  const record = value as Record<string, unknown>

  const applicationName = record['application_name']
  if (typeof applicationName !== 'string' || applicationName.trim() === '') return null

  const accentColor = record['accent_color']
  if (accentColor !== null && (typeof accentColor !== 'string' || !ACCENT.test(accentColor))) {
    return null
  }

  // Solo la ruta del propio servidor: el valor acaba en `<img src>` y en el
  // favicon, y la copia del quiosco vive en localStorage, donde cualquiera con
  // las herramientas de desarrollo podria dejar una URL de otro origen y
  // convertir cada arranque de la tablet en una baliza (RS-04).
  const logoUrl = record['logo_url']
  if (logoUrl !== null && (typeof logoUrl !== 'string' || !LOGO_URL.test(logoUrl))) return null

  const locales = record['locales']
  if (typeof locales !== 'object' || locales === null) return null
  const policy = locales as Record<string, unknown>
  const fallback = policy['default']
  const available = policy['available']
  if (typeof fallback !== 'string' || fallback === '') return null
  if (!Array.isArray(available) || available.length === 0) return null
  if (!available.every((item): item is string => typeof item === 'string' && item !== '')) {
    return null
  }

  return {
    applicationName,
    accentColor: accentColor === null ? null : accentColor.toLowerCase(),
    logoUrl,
    locales: { default: fallback, available: [...available] },
  }
}

// ---------------------------------------------------------------------------
// Aritmetica de color. Solo lo que hace falta para derivar tonos legibles.
// ---------------------------------------------------------------------------

function toHex({ r, g, b }: Rgb): string {
  const channel = (value: number): string =>
    Math.round(Math.min(255, Math.max(0, value)))
      .toString(16)
      .padStart(2, '0')
  return `#${channel(r)}${channel(g)}${channel(b)}`
}

/** Mezcla lineal: `weight` = 1 devuelve `a`, 0 devuelve `b`. */
function mix(a: Rgb, b: Rgb, weight: number): Rgb {
  return {
    r: a.r * weight + b.r * (1 - weight),
    g: a.g * weight + b.g * (1 - weight),
    b: a.b * weight + b.b * (1 - weight),
  }
}

/**
 * Acerca `color` a `target` (blanco para aclarar, negro para oscurecer) en
 * pasos de un 2,5 % hasta que alcance `minimum` sobre `background`. Si ni el
 * propio `target` llega, devuelve `target`: es lo mas lejos que se puede ir.
 */
function adjustUntil(color: Rgb, target: Rgb, background: Rgb, minimum: number): Rgb {
  const STEPS = 40
  for (let step = 0; step <= STEPS; step += 1) {
    const candidate = mix(target, color, step / STEPS)
    if (contrastRatio(candidate, background) >= minimum) return candidate
  }
  return target
}

/** De entre los candidatos, el que mas contrasta con `background`. */
function bestOn(background: Rgb, candidates: readonly Rgb[]): Rgb {
  let best = candidates[0] ?? background
  let bestRatio = -1
  for (const candidate of candidates) {
    const ratio = contrastRatio(candidate, background)
    if (ratio > bestRatio) {
      best = candidate
      bestRatio = ratio
    }
  }
  return best
}

// ---------------------------------------------------------------------------
// Derivacion de tokens.
// ---------------------------------------------------------------------------

const WHITE: Rgb = { r: 255, g: 255, b: 255 }
const BLACK: Rgb = { r: 0, g: 0, b: 0 }

/** Tokens que esta capa puede sobreescribir, por contexto. Nada fuera de aqui. */
export const MANAGED_TOKENS: Readonly<Record<ThemeMode, readonly string[]>> = {
  light: [
    '--kq-color-primary',
    '--kq-color-primary-strong',
    '--kq-color-primary-soft',
    '--kq-color-on-primary',
    '--kq-color-on-primary-soft',
    '--kq-color-focus',
  ],
  kiosk: [
    '--kq-color-kiosk-primary',
    '--kq-color-kiosk-primary-strong',
    '--kq-color-kiosk-on-primary',
  ],
}

/**
 * Los tokens que un acento sobreescribe en un contexto, con sus valores.
 *
 * `tokens` resuelve los valores BASE del tema (superficies y texto), que
 * nunca se sobreescriben y por eso se pueden leer del documento aunque ya
 * haya una marca aplicada.
 */
export function accentOverrides(
  accent: string,
  mode: ThemeMode,
  tokens: TokenResolver,
): Readonly<Record<string, string>> {
  const color = parseHexColor(accent)
  const text = WCAG_AA_MINIMUM.text

  if (mode === 'kiosk') {
    const surface = parseHexColor(tokens('--kq-color-kiosk-surface'))
    const surfaceRaised = parseHexColor(tokens('--kq-color-kiosk-surface-raised'))
    const kioskText = parseHexColor(tokens('--kq-color-kiosk-text'))
    // Sobre el fondo oscuro de la tablet el acento del cliente se ACLARA, igual
    // que hace la paleta del producto (doc 06 §1.2: el naranja del quiosco es
    // mas claro que el del panel). El acento grande y decorativo hasta 3:1 y
    // el boton solido, que lleva texto encima, hasta 4,5:1; los dos medidos
    // sobre el panel mas claro, que es el mas exigente.
    const primary = adjustUntil(color, WHITE, surfaceRaised, WCAG_AA_MINIMUM.large)
    const strong = adjustUntil(color, WHITE, surfaceRaised, text)
    const onPrimary = bestOn(strong, [surface, kioskText])

    return {
      '--kq-color-kiosk-primary': toHex(primary),
      '--kq-color-kiosk-primary-strong': toHex(strong),
      '--kq-color-kiosk-on-primary': toHex(onPrimary),
    }
  }

  const surfaceRaised = parseHexColor(tokens('--kq-color-surface-raised'))
  const bodyText = parseHexColor(tokens('--kq-color-text'))
  const onPrimary = bestOn(color, [WHITE, bodyText])
  // Fondo tintado: un 10 % de acento sobre la tarjeta blanca. El texto que va
  // encima es el acento oscurecido hasta leerse como texto normal.
  const soft = mix(color, surfaceRaised, 0.1)
  const onSoft = adjustUntil(color, BLACK, soft, text)
  // El anillo de foco no es marca: es lo que permite saber donde esta el
  // teclado (WCAG 2.4.11). Lleva el acento, pero oscurecido hasta verse como
  // componente (3:1) sobre la tarjeta blanca, que es la superficie mas
  // exigente; un acento casi blanco dejaria sin foco al panel entero.
  const focus = adjustUntil(color, BLACK, surfaceRaised, WCAG_AA_MINIMUM.large)

  return {
    '--kq-color-primary': accent.toLowerCase(),
    '--kq-color-primary-strong': accent.toLowerCase(),
    '--kq-color-primary-soft': toHex(soft),
    '--kq-color-on-primary': toHex(onPrimary),
    '--kq-color-on-primary-soft': toHex(onSoft),
    '--kq-color-focus': toHex(focus),
  }
}

export interface ContrastWarning {
  readonly pair: ThemePair
  /** Valores ya resueltos, para que el aviso pueda enseñar los dos colores. */
  readonly foreground: string
  readonly background: string
  readonly ratio: number
  readonly minimum: number
}

/**
 * Las parejas de `themePairs.ts` que dejan de cumplir su minimo con este
 * acento. Solo se evaluan las parejas en las que interviene un token
 * sobreescrito: el resto no cambia y ya esta medido en `theme.spec.ts`.
 *
 * Lista vacia = el acento es tan legible como la paleta del producto. Una
 * lista con elementos NO impide guardarlo (doc 06 §7: se avisa, no se impone).
 */
export function contrastWarnings(
  accent: string,
  tokens: TokenResolver,
  mode: ThemeMode | 'all' = 'all',
): ContrastWarning[] {
  const modes: ThemeMode[] = mode === 'all' ? ['light', 'kiosk'] : [mode]
  const warnings: ContrastWarning[] = []

  for (const current of modes) {
    const overrides = accentOverrides(accent, current, tokens)
    const pairs = current === 'kiosk' ? kioskPairs : lightPairs
    const resolve = (token: string): string => overrides[token] ?? tokens(token)

    for (const pair of pairs) {
      if (!(pair.foreground in overrides) && !(pair.background in overrides)) continue
      const foreground = resolve(pair.foreground)
      const background = resolve(pair.background)
      const ratio = contrastRatio(foreground, background)
      const minimum = WCAG_AA_MINIMUM[pair.requirement]
      if (ratio < minimum) {
        warnings.push({ pair, foreground, background, ratio, minimum })
      }
    }
  }

  return warnings
}

// ---------------------------------------------------------------------------
// Aplicacion al documento.
// ---------------------------------------------------------------------------

const FAVICON_MARK = 'data-kq-branding'

/** Lee los tokens base del documento. En el navegador, `getComputedStyle`. */
export function readThemeTokens(doc: Document): TokenResolver {
  const style = doc.defaultView?.getComputedStyle(doc.documentElement)
  return (token) => {
    const value = style?.getPropertyValue(token).trim() ?? ''
    if (value === '') throw new Error(`Theme token ${token} is not declared on :root`)
    return value
  }
}

export interface ApplyBrandingOptions {
  readonly mode: ThemeMode
  readonly document?: Document
  /** Por defecto, los tokens base se leen del propio documento. */
  readonly tokens?: TokenResolver
}

/**
 * Pinta la marca: sobreescribe (o limpia) los tokens del contexto, pone el
 * nombre en el titulo de la pestaña y el logotipo como favicon.
 *
 * Idempotente y reversible: aplicar `PRODUCT_BRANDING` despues de una marca
 * con acento devuelve el documento a la paleta del producto. Nunca lanza por
 * un color mal formado: un acento invalido se trata como `null`, porque una
 * excepcion aqui dejaria al quiosco sin pantalla de espera (regla dura 19).
 */
export function applyBranding(branding: Branding, options: ApplyBrandingOptions): void {
  const doc = options.document ?? globalThis.document
  if (doc === undefined) return

  const root = doc.documentElement
  for (const token of MANAGED_TOKENS[options.mode]) root.style.removeProperty(token)

  let branded = false
  if (branding.accentColor !== null && ACCENT.test(branding.accentColor)) {
    try {
      const tokens = options.tokens ?? readThemeTokens(doc)
      const overrides = accentOverrides(branding.accentColor, options.mode, tokens)
      for (const [token, value] of Object.entries(overrides)) root.style.setProperty(token, value)
      branded = true
    } catch {
      // Sin tokens base legibles no hay nada que derivar: se queda el producto.
    }
  }
  root.setAttribute('data-kq-branded', branded ? 'accent' : 'product')

  doc.title = branding.applicationName

  const existing = doc.head.querySelector<HTMLLinkElement>(`link[rel="icon"][${FAVICON_MARK}]`)
  if (branding.logoUrl === null) {
    existing?.remove()
    return
  }
  const link = existing ?? doc.createElement('link')
  link.rel = 'icon'
  link.href = branding.logoUrl
  link.setAttribute(FAVICON_MARK, '')
  if (existing === null) doc.head.append(link)
}

/**
 * Los idiomas que de verdad se pueden ofrecer: los de la instalacion que la
 * aplicacion tiene traducidos, en el orden de `shipped`. Si no queda ninguno
 * —una lista corrupta o un idioma que esta version no trae—, se ofrecen todos
 * los traducidos: una aplicacion sin idiomas no existe.
 */
export function offeredLocales<T extends string>(
  policy: LocalePolicy,
  shipped: readonly T[],
): readonly T[] {
  const offered = shipped.filter((locale) => policy.available.includes(locale))
  return offered.length > 0 ? offered : shipped
}
