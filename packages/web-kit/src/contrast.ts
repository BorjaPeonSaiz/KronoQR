// Contraste WCAG 2.2 (criterios 1.4.3 y 1.4.11), calculado con la formula de
// luminancia relativa de la recomendacion, no estimado a ojo.
//
// Lo usa la prueba `tests/unit/theme.spec.ts` para verificar cada pareja
// texto/fondo declarada en `themePairs.ts` contra los tokens de `theme.css`, y
// lo podra usar la tarea 5.8 (marca blanca) para avisar cuando los colores que
// configure un cliente no alcancen el minimo (paso 9 de esa tarea).

export interface Rgb {
  readonly r: number
  readonly g: number
  readonly b: number
}

/** Que exige WCAG 2.2 AA a cada tipo de pareja. */
export type ContrastRequirement = 'text' | 'large' | 'decorative'

/**
 * Minimos de WCAG 2.2 AA por tipo de uso:
 *   - `text`: texto normal (1.4.3), >= 4.5:1.
 *   - `large`: texto grande (>= 24 px, o >= 19 px en negrita) y componentes de
 *     interfaz o graficos con significado (1.4.3 y 1.4.11), >= 3:1.
 *   - `decorative`: sin exigencia. Solo se admite en elementos que no
 *     transmiten informacion (una franja, un fondo de ilustracion).
 */
export const WCAG_AA_MINIMUM: Readonly<Record<ContrastRequirement, number>> = {
  text: 4.5,
  large: 3,
  decorative: 1,
}

const HEX_COLOR = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i

/** Acepta `#rgb` y `#rrggbb`. Lanza si la cadena no es un color hexadecimal. */
export function parseHexColor(hex: string): Rgb {
  const value = hex.trim()
  if (!HEX_COLOR.test(value)) {
    throw new Error(`Not a hexadecimal colour: "${hex}"`)
  }

  const digits = value.slice(1)
  const expanded =
    digits.length === 3
      ? digits
          .split('')
          .map((d) => d + d)
          .join('')
      : digits

  return {
    r: Number.parseInt(expanded.slice(0, 2), 16),
    g: Number.parseInt(expanded.slice(2, 4), 16),
    b: Number.parseInt(expanded.slice(4, 6), 16),
  }
}

function linearize(channel: number): number {
  const c = channel / 255
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4
}

/** Luminancia relativa segun WCAG 2.2, entre 0 (negro) y 1 (blanco). */
export function relativeLuminance(color: Rgb | string): number {
  const { r, g, b } = typeof color === 'string' ? parseHexColor(color) : color
  return 0.2126 * linearize(r) + 0.7152 * linearize(g) + 0.0722 * linearize(b)
}

/** Relacion de contraste entre dos colores, de 1 a 21. El orden no importa. */
export function contrastRatio(a: Rgb | string, b: Rgb | string): number {
  const la = relativeLuminance(a)
  const lb = relativeLuminance(b)
  const lighter = Math.max(la, lb)
  const darker = Math.min(la, lb)
  return (lighter + 0.05) / (darker + 0.05)
}

/** `true` si la pareja cumple el minimo de AA para ese uso. */
export function meetsWcagAa(
  foreground: Rgb | string,
  background: Rgb | string,
  requirement: ContrastRequirement,
): boolean {
  return contrastRatio(foreground, background) >= WCAG_AA_MINIMUM[requirement]
}
