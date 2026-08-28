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

export interface ThemePair {
  /** Token del primer plano (texto, icono, borde), p. ej. `--kq-color-text`. */
  readonly foreground: string
  /** Token del fondo sobre el que se pinta. */
  readonly background: string
  readonly requirement: ContrastRequirement
  /** Para que se usa esa pareja; aparece en el nombre de la prueba. */
  readonly use: string
}

const pair = (
  foreground: string,
  background: string,
  requirement: ContrastRequirement,
  use: string,
): ThemePair => ({ foreground, background, requirement, use })

/** Parejas del panel y del portal (fondo claro). */
export const lightPairs: readonly ThemePair[] = [
  // Texto sobre superficies.
  pair('--kq-color-text', '--kq-color-surface', 'text', 'body text on page background'),
  pair('--kq-color-text', '--kq-color-surface-raised', 'text', 'body text on cards and dialogs'),
  pair('--kq-color-text', '--kq-color-surface-alt', 'text', 'body text on alternate rows'),
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
    'label of a solid primary button',
  ),
  pair('--kq-color-primary-strong', '--kq-color-surface', 'text', 'links and brand text on page'),
  pair(
    '--kq-color-primary-strong',
    '--kq-color-surface-raised',
    'text',
    'links and brand text on cards',
  ),
  pair(
    '--kq-color-on-primary-soft',
    '--kq-color-primary-soft',
    'text',
    'label of a primary chip or selected row',
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
]

export const themePairs: readonly ThemePair[] = [...lightPairs, ...kioskPairs]
