// Parejas texto/fondo del sistema visual (theme.css) con el uso que se les da y
// el minimo WCAG 2.2 AA que ese uso exige. Es la tabla de contrastes de
// docs/06-guia-visual.md §2, en forma ejecutable: `tests/unit/theme.spec.ts`
// lee `theme.css`, resuelve cada token y mide cada pareja con `contrast.ts`.
//
// Si un token de color no aparece en ninguna pareja, la prueba falla: un color
// que nadie ha medido no entra en la paleta. Si se añade un uso nuevo (poner
// texto atenuado sobre un fondo tintado, por ejemplo), se añade aqui su pareja
// ANTES de usarlo en una plantilla.
//
// La tarea 5.8 (marca blanca) reutiliza esta tabla para avisar cuando los
// colores que configure un cliente no alcancen el minimo.
import type { ContrastRequirement } from './contrast'
import type { PairState } from './pairContrast'

export interface ThemePair {
  /** Token del primer plano (texto, icono, borde), p. ej. `--kq-color-text`. */
  readonly foreground: string
  /** Token del fondo sobre el que se pinta. */
  readonly background: string
  readonly requirement: ContrastRequirement
  /** Para que se usa esa pareja; aparece en el nombre de la prueba. */
  readonly use: string
  /**
   * Estado de interaccion si la pareja solo existe en uno (hover con filtro,
   * deshabilitado con opacidad). Sin estado = en reposo.
   */
  readonly state?: PairState
}

const pair = (
  foreground: string,
  background: string,
  requirement: ContrastRequirement,
  use: string,
  state?: PairState,
): ThemePair =>
  state === undefined
    ? { foreground, background, requirement, use }
    : { foreground, background, requirement, use, state }

/** Clave unica de una pareja: la misma pareja en reposo y en hover son dos filas. */
export function pairKey(item: ThemePair): string {
  const state = item.state
  if (state === undefined) return `${item.foreground}/${item.background}`
  return `${item.foreground}/${item.background}/${state.kind}:${state.shade ?? ''}:${state.opacity ?? ''}`
}

/** `hover:brightness-95` de un boton solido: el filtro oscurece fondo y texto. */
const HOVER_BRIGHTNESS: PairState = { kind: 'hover', shade: 0.95 }

/**
 * Parejas de un control deshabilitado a cada opacidad usada en las plantillas
 * (`aria-disabled:opacity-60`, `disabled:opacity-40`...). Requisito `inactive`.
 */
function disabledPairs(
  bases: ReadonlyArray<readonly [string, string, string]>,
  opacities: readonly number[],
  ground: string,
): ThemePair[] {
  return opacities.flatMap((opacity) =>
    bases.map(([foreground, background, what]) =>
      pair(
        foreground,
        background,
        'inactive',
        `disabled ${what} at ${Math.round(opacity * 100)} % opacity`,
        { kind: 'disabled', opacity, ground },
      ),
    ),
  )
}

/** Parejas del panel y del portal (fondo claro). */
export const lightPairs: readonly ThemePair[] = [
  // Texto sobre superficies.
  pair('--kq-color-text', '--kq-color-surface', 'text', 'body text on page background'),
  pair('--kq-color-text', '--kq-color-surface-raised', 'text', 'body text on cards and dialogs'),
  pair(
    '--kq-color-text',
    '--kq-color-surface-alt',
    'text',
    'body text on alternate rows and on a hovered nav link',
  ),
  pair('--kq-color-text-muted', '--kq-color-surface', 'text', 'secondary text on page background'),
  pair('--kq-color-text-muted', '--kq-color-surface-raised', 'text', 'secondary text on cards'),
  pair(
    '--kq-color-text-muted',
    '--kq-color-surface-alt',
    'text',
    'secondary text on alternate rows',
  ),

  // Bordes: el decorativo no delimita nada; el de controles si (1.4.11).
  pair('--kq-color-border', '--kq-color-surface', 'decorative', 'dividers on page background'),
  pair('--kq-color-border', '--kq-color-surface-raised', 'decorative', 'dividers on cards'),
  pair(
    '--kq-color-border',
    '--kq-color-surface-alt',
    'decorative',
    'dividers of table headers and framed sections',
  ),
  pair('--kq-color-border', '--kq-color-primary-soft', 'decorative', 'dividers of a selected row'),
  pair(
    '--kq-color-border-strong',
    '--kq-color-surface',
    'large',
    'input and outlined-button borders on page',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-surface-raised',
    'large',
    'input and outlined-button borders on cards',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-surface-alt',
    'large',
    'input borders on alternate rows',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-primary-soft',
    'large',
    'control borders on a selected row',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-accent-soft',
    'large',
    'control borders on an accent panel',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-success-soft',
    'large',
    'control borders on a success notice',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-warning-soft',
    'large',
    'control borders on a warning notice',
  ),
  pair(
    '--kq-color-border-strong',
    '--kq-color-danger-soft',
    'large',
    'control borders on an error notice',
  ),

  // Primario.
  pair('--kq-color-primary', '--kq-color-surface', 'large', 'large accents and icons on page'),
  pair(
    '--kq-color-primary',
    '--kq-color-surface-raised',
    'large',
    'large accents and icons on cards',
  ),
  pair('--kq-color-primary', '--kq-color-surface-alt', 'large', 'large accents on alternate rows'),
  pair(
    '--kq-color-on-primary',
    '--kq-color-primary-strong',
    'text',
    'label of a solid primary button and of the active nav link',
  ),
  pair('--kq-color-primary-strong', '--kq-color-surface', 'text', 'links and brand text on page'),
  pair(
    '--kq-color-primary-strong',
    '--kq-color-surface-raised',
    'text',
    'links and brand text on cards',
  ),
  pair(
    '--kq-color-primary-strong',
    '--kq-color-surface-alt',
    'text',
    'links and brand text on table headers, alternate rows and a hovered link',
  ),
  pair(
    '--kq-color-primary-strong',
    '--kq-color-primary-soft',
    'text',
    'links and brand text on a selected row',
  ),
  pair(
    '--kq-color-on-primary',
    '--kq-color-primary-strong',
    'text',
    'label of a hovered solid primary button (brightness 95 %)',
    HOVER_BRIGHTNESS,
  ),
  pair(
    '--kq-color-on-primary-soft',
    '--kq-color-primary-soft',
    'text',
    'label of a primary chip, selected row or pressed nav link',
  ),
  pair('--kq-color-text', '--kq-color-primary-soft', 'text', 'body text on a selected row'),
  pair(
    '--kq-color-text-muted',
    '--kq-color-primary-soft',
    'text',
    'secondary text on a selected row',
  ),

  // Acento salvia: decorativo sobre crema, componente solo sobre blanco.
  pair(
    '--kq-color-accent',
    '--kq-color-surface',
    'decorative',
    'illustrations and stripes on page',
  ),
  pair('--kq-color-accent', '--kq-color-surface-raised', 'large', 'large icons on cards'),
  pair('--kq-color-on-accent-soft', '--kq-color-accent-soft', 'text', 'label of an accent chip'),
  pair('--kq-color-text', '--kq-color-accent-soft', 'text', 'body text on an accent panel'),
  pair(
    '--kq-color-text-muted',
    '--kq-color-accent-soft',
    'text',
    'secondary text on an accent panel',
  ),

  // Estados: como texto sobre superficies, como texto sobre su fondo tintado,
  // y con su texto de contraste cuando son fondo solido.
  pair('--kq-color-success', '--kq-color-surface', 'text', 'success text on page'),
  pair('--kq-color-success', '--kq-color-surface-raised', 'text', 'success text on cards'),
  pair('--kq-color-success', '--kq-color-surface-alt', 'text', 'success text on alternate rows'),
  pair('--kq-color-success', '--kq-color-success-soft', 'text', 'label of a success badge'),
  pair('--kq-color-on-success', '--kq-color-success', 'text', 'label of a solid success control'),
  pair('--kq-color-text', '--kq-color-success-soft', 'text', 'body text on a success notice'),
  pair(
    '--kq-color-text-muted',
    '--kq-color-success-soft',
    'text',
    'secondary text on a success notice',
  ),
  pair('--kq-color-warning', '--kq-color-surface', 'text', 'warning text on page'),
  pair('--kq-color-warning', '--kq-color-surface-raised', 'text', 'warning text on cards'),
  pair('--kq-color-warning', '--kq-color-surface-alt', 'text', 'warning text on alternate rows'),
  pair('--kq-color-warning', '--kq-color-warning-soft', 'text', 'label of a warning badge'),
  pair('--kq-color-on-warning', '--kq-color-warning', 'text', 'label of a solid warning control'),
  pair('--kq-color-text', '--kq-color-warning-soft', 'text', 'body text on a warning notice'),
  pair(
    '--kq-color-text-muted',
    '--kq-color-warning-soft',
    'text',
    'secondary text on a warning notice',
  ),
  pair('--kq-color-danger', '--kq-color-surface', 'text', 'error text on page'),
  pair('--kq-color-danger', '--kq-color-surface-raised', 'text', 'error text on cards'),
  pair('--kq-color-danger', '--kq-color-surface-alt', 'text', 'error text on alternate rows'),
  pair('--kq-color-danger', '--kq-color-danger-soft', 'text', 'label of an error badge'),
  pair('--kq-color-on-danger', '--kq-color-danger', 'text', 'label of a solid destructive button'),
  pair('--kq-color-text', '--kq-color-danger-soft', 'text', 'body text on an error notice'),
  pair(
    '--kq-color-text-muted',
    '--kq-color-danger-soft',
    'text',
    'secondary text on an error notice',
  ),

  // Foco visible contra las superficies donde se dibuja el anillo.
  pair('--kq-color-focus', '--kq-color-surface', 'large', 'focus ring on page'),
  pair('--kq-color-focus', '--kq-color-surface-raised', 'large', 'focus ring on cards'),
  pair('--kq-color-focus', '--kq-color-surface-alt', 'large', 'focus ring on alternate rows'),
  // Margen: hoy el anillo cae sobre la cabecera (outline-offset 2px); cuenta si el offset baja a 0.
  pair('--kq-color-focus', '--kq-color-primary-soft', 'large', 'focus ring on a pressed nav link'),
  pair('--kq-color-focus', '--kq-color-accent-soft', 'large', 'focus ring on an accent panel'),
  pair('--kq-color-focus', '--kq-color-success-soft', 'large', 'focus ring on a success notice'),
  pair('--kq-color-focus', '--kq-color-warning-soft', 'large', 'focus ring on a warning notice'),
  pair('--kq-color-focus', '--kq-color-danger-soft', 'large', 'focus ring on an error notice'),

  // Estado deshabilitado: WCAG 1.4.3 y 1.4.11 eximen al control inactivo, asi que el
  // minimo es el suelo de producto (1,5:1, `inactive`), no 4,5 ni 3. Se mide el color
  // PINTADO: el control entero, con la opacidad de la plantilla, sobre la pagina.
  ...disabledPairs(
    [
      ['--kq-color-on-primary', '--kq-color-primary-strong', 'solid primary button'],
      ['--kq-color-text', '--kq-color-surface', 'text button on the page'],
      ['--kq-color-text', '--kq-color-surface-raised', 'outlined button on a card'],
    ],
    [0.5, 0.6],
    '--kq-color-surface',
  ),
]

/** Parejas del quiosco (fondo oscuro, lectura a distancia). */
export const kioskPairs: readonly ThemePair[] = [
  pair('--kq-color-kiosk-text', '--kq-color-kiosk-surface', 'text', 'kiosk text on wall screen'),
  pair(
    '--kq-color-kiosk-text',
    '--kq-color-kiosk-surface-raised',
    'text',
    'kiosk text on raised panels',
  ),
  pair('--kq-color-kiosk-text-muted', '--kq-color-kiosk-surface', 'text', 'kiosk secondary text'),
  pair(
    '--kq-color-kiosk-text-muted',
    '--kq-color-kiosk-surface-raised',
    'text',
    'kiosk secondary text on panels',
  ),
  pair('--kq-color-kiosk-border', '--kq-color-kiosk-surface', 'large', 'kiosk control borders'),
  pair(
    '--kq-color-kiosk-border',
    '--kq-color-kiosk-surface-raised',
    'large',
    'kiosk control borders on panels',
  ),
  pair('--kq-color-kiosk-primary', '--kq-color-kiosk-surface', 'large', 'kiosk large accents'),
  pair(
    '--kq-color-kiosk-primary',
    '--kq-color-kiosk-surface-raised',
    'large',
    'kiosk large accents on panels',
  ),
  pair('--kq-color-kiosk-primary-strong', '--kq-color-kiosk-surface', 'text', 'kiosk accent text'),
  pair(
    '--kq-color-kiosk-primary-strong',
    '--kq-color-kiosk-surface-raised',
    'text',
    'kiosk accent text on panels',
  ),
  pair(
    '--kq-color-kiosk-on-primary',
    '--kq-color-kiosk-primary-strong',
    'text',
    'label of a solid kiosk button',
  ),
  pair('--kq-color-kiosk-focus', '--kq-color-kiosk-surface', 'large', 'kiosk focus ring'),
  pair(
    '--kq-color-kiosk-focus',
    '--kq-color-kiosk-surface-raised',
    'large',
    'kiosk focus ring on panels',
  ),

  // Teclado numerico y botones del quiosco apagados (`disabled:opacity-40`).
  ...disabledPairs(
    [
      ['--kq-color-kiosk-on-primary', '--kq-color-kiosk-primary-strong', 'solid kiosk button'],
      ['--kq-color-kiosk-text', '--kq-color-kiosk-surface-raised', 'kiosk key'],
      ['--kq-color-kiosk-text-muted', '--kq-color-kiosk-surface-raised', 'kiosk secondary key'],
    ],
    [0.4],
    '--kq-color-kiosk-surface',
  ),
]

export const themePairs: readonly ThemePair[] = [...lightPairs, ...kioskPairs]

/** Blanco del texto de confirmacion del quiosco (`text-white` de Tailwind). */
export const KIOSK_CONFIRMATION_FOREGROUND = '#ffffff'

/**
 * Los cinco fondos de confirmacion del quiosco (entrada, salida, pendiente,
 * aviso, error). Son semanticos, no marca, y viven en `@theme` de
 * `frontend-kiosk/src/assets/main.css` como `--color-kiosk-*` (no son `--kq-*`
 * ni se sobreescriben con la marca blanca). El texto es siempre blanco. Como
 * se leen de lejos y el mensaje es >= 24 px, el minimo es el de texto normal
 * (4.5:1) por prudencia, no el de texto grande.
 *
 * `tests/unit/kioskConfirmation.spec.ts` lee `main.css` y mide cada pareja; un
 * color de confirmacion nuevo sin pareja aqui, o por debajo del minimo, falla.
 */
export const kioskConfirmationPairs: readonly ThemePair[] = [
  pair(KIOSK_CONFIRMATION_FOREGROUND, '--color-kiosk-entry', 'text', 'white on entry confirmation'),
  pair(KIOSK_CONFIRMATION_FOREGROUND, '--color-kiosk-exit', 'text', 'white on exit confirmation'),
  pair(
    KIOSK_CONFIRMATION_FOREGROUND,
    '--color-kiosk-pending',
    'text',
    'white on pending confirmation',
  ),
  pair(
    KIOSK_CONFIRMATION_FOREGROUND,
    '--color-kiosk-notice',
    'text',
    'white on notice confirmation',
  ),
  pair(KIOSK_CONFIRMATION_FOREGROUND, '--color-kiosk-error', 'text', 'white on error confirmation'),
]
