// Medicion de una pareja de `themePairs.ts` con su ESTADO: el color que de
// verdad llega a la pantalla, no el del token en reposo.
//
//   - `hover` con `brightness-95` (el filtro oscurece fondo Y texto del control).
//   - `disabled` con `opacity-N` (el control entero se compone sobre el fondo de
//     la pagina, asi que texto y fondo se acercan al `ground`).
//
// El navegador compone en 8 bits por canal: cada color se redondea ANTES de
// medir, igual que `branding.ts` redondea el acento antes de medirlo:
// el valor que se mide es el que se pinta.
import { mix, parseHexColor, toHex, type Rgb } from './contrast'

export type PairStateKind = 'hover' | 'disabled'

export interface PairState {
  readonly kind: PairStateKind
  /** Factor de `brightness-NN` (0,95 = `hover:brightness-95`), sobre texto y fondo. */
  readonly shade?: number
  /** Opacidad del control (0,6 = `opacity-60`), compuesto sobre `ground`. */
  readonly opacity?: number
  /** Token sobre el que se compone el control apagado. Por omision, el fondo de la pareja. */
  readonly ground?: string
}

const scale = (color: Rgb, factor: number): Rgb => ({
  r: color.r * factor,
  g: color.g * factor,
  b: color.b * factor,
})

/**
 * Los dos colores `#rrggbb` que se pintan de verdad para una pareja en su
 * estado. Sin estado, los propios tokens resueltos.
 */
export function paintedColors(
  foreground: string,
  background: string,
  state: PairState | undefined,
  resolve: (token: string) => string,
): { readonly foreground: string; readonly background: string } {
  let fg = parseHexColor(resolve(foreground))
  let bg = parseHexColor(resolve(background))

  if (state?.shade !== undefined) {
    fg = scale(fg, state.shade)
    bg = scale(bg, state.shade)
  }
  if (state?.opacity !== undefined) {
    const ground = parseHexColor(resolve(state.ground ?? background))
    fg = mix(fg, ground, state.opacity)
    bg = mix(bg, ground, state.opacity)
  }

  return { foreground: toHex(fg), background: toHex(bg) }
}
