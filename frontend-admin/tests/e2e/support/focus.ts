// Recorridos de teclado para las pruebas de foco (V21-7-QA-3).
//
// Las pruebas de foco tenian bucles y un `if … break` dentro del propio test
// (§3.5: un `if` en un test es una rama que nadie prueba). Aqui se pulsa una
// tecla un numero FIJO de veces —nunca «hasta que»— y se devuelve lo que se vio
// tras cada pulsacion; la prueba compara esa traza con la esperada, escrita
// con valores explicitos.

import type { Locator, Page } from '@playwright/test'

/** Donde quedo el foco tras una pulsacion. */
export interface FocusStep {
  /** El foco sigue dentro del contenedor observado. */
  readonly inside: boolean
  /** Descriptor estable del elemento enfocado (`data-test`, `name`, `id` o etiqueta). */
  readonly element: string
}

/** Un segmento interno de un campo y el anillo que pinta en ese momento. */
export interface RingStep {
  readonly inside: boolean
  /** El campo pinta anillo: `outline-style` distinto de `none` y `outline-width` distinto de `0px`. */
  readonly ring: boolean
}

/** Tope de pulsaciones de cualquier traza: ninguna prueba puede quedarse pulsando. */
export const FOCUS_MAX_PRESSES = 30

function assertBounded(presses: number): void {
  if (!Number.isInteger(presses) || presses < 1 || presses > FOCUS_MAX_PRESSES) {
    throw new Error(
      `Una traza de foco va de 1 a ${FOCUS_MAX_PRESSES} pulsaciones; se pidieron ${presses}.`,
    )
  }
}

/**
 * Pulsa `key` exactamente `presses` veces y, tras cada una, anota si el foco
 * sigue dentro de `container` y en que elemento esta. `document.activeElement`
 * se retargetea al `input` aunque el foco este en un segmento interno de un
 * campo de fecha, donde `:focus` no coincide.
 */
export async function focusTrail(
  page: Page,
  container: Locator,
  key: string,
  presses: number,
): Promise<FocusStep[]> {
  assertBounded(presses)
  const steps: FocusStep[] = []

  for (let press = 0; press < presses; press += 1) {
    await page.keyboard.press(key)
    steps.push(
      await container.evaluate((root) => {
        const active = document.activeElement
        const element =
          active === null
            ? 'ninguno'
            : (active.getAttribute('data-test') ??
              active.getAttribute('name') ??
              (active.id !== '' ? `#${active.id}` : active.tagName.toLowerCase()))

        return { inside: root.contains(active), element }
      }),
    )
  }

  return steps
}

/**
 * Sobre un campo con segmentos internos (fecha y hora), anota si el foco esta
 * en el campo y que anillo pinta ANTES de pulsar nada y tras cada una de las
 * `presses` pulsaciones de `key`: la traza tiene `presses + 1` pasos.
 */
export async function ringTrail(
  page: Page,
  field: Locator,
  key: string,
  presses: number,
): Promise<RingStep[]> {
  assertBounded(presses)
  const read = (): Promise<RingStep> =>
    field.evaluate((element) => {
      const style = getComputedStyle(element)

      return {
        inside: element.matches(':focus-within'),
        ring: style.outlineStyle !== 'none' && style.outlineWidth !== '0px',
      }
    })

  const steps: RingStep[] = [await read()]

  for (let press = 0; press < presses; press += 1) {
    await page.keyboard.press(key)
    steps.push(await read())
  }

  return steps
}

/**
 * Cuantas paradas de Tab da un campo de fecha y hora: el numero de segmentos internos
 * (dia, mes, año, hora, minuto...) mas, segun el navegador y la plataforma, el selector. NO es
 * una constante: Chromium de Windows y el de Linux de la CI no coinciden, y una prueba que
 * escriba el numero a mano se rompe en una de las dos. Se mide en el propio navegador, con el
 * foco ya en el campo, y se deja el foco fuera de el. Tope: `FOCUS_MAX_PRESSES`.
 */
export async function datetimeStops(page: Page, field: Locator): Promise<number> {
  const trail = await ringTrail(page, field, 'Tab', FOCUS_MAX_PRESSES)
  const stops = trail.findIndex((step) => !step.inside)

  if (stops < 1) {
    throw new Error('El foco no salio del campo de fecha y hora dentro del tope de pulsaciones.')
  }

  return stops
}
