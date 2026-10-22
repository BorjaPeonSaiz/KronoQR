// La pasada de axe del quiosco (doc 01 §6.5; doc 02 §9.2 y §9.4).
//
// UNA sola definicion para todas las pruebas, con las etiquetas WCAG de A y AA
// hasta la 2.2 MAS `best-practice` (V7-QA-3): antes cada fichero declaraba las
// suyas, ninguno miraba las buenas practicas y `pairing.spec.ts` ni siquiera
// desactivaba la misma regla que los demas.
//
// Criterio: CERO violaciones criticas o graves. Las de impacto menor no
// bloquean: axe marca como «minor» cosas que en una pantalla de quiosco sin
// teclado no significan nada.
//
// REGLA DESACTIVADA, CON MOTIVO. `video-caption` exige una pista de subtitulos
// en todo `<video>`. Aqui el `<video>` no es contenido audiovisual: es el visor
// EN VIVO de la camara, se abre con `audio: false` y va marcado `aria-hidden`.
// No hay nada que subtitular, y anadir un `<track>` vacio para contentar a la
// herramienta seria falsear el resultado en lugar de arreglarlo.

import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'

/** Una violacion tal y como la devuelve `analyze()`. */
type Result = Awaited<ReturnType<AxeBuilder['analyze']>>['violations'][number]

/** WCAG A y AA hasta la 2.2 y las buenas practicas de axe (V7-QA-3). */
export const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']

/** Ver el comentario de cabecera. */
export const AXE_DISABLED_RULES = ['video-caption']

function describeViolation(violation: Result): string {
  const targets = violation.nodes.map((node) => node.target.join(' ')).join(', ')

  return `${violation.id} (${violation.impact ?? 'sin impacto'}): ${violation.help} → ${targets}`
}

export async function expectNoBlockingViolations(page: Page): Promise<void> {
  const results = await new AxeBuilder({ page })
    .withTags(AXE_TAGS)
    .disableRules(AXE_DISABLED_RULES)
    .analyze()

  const blocking = results.violations.filter(
    (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  )

  expect(blocking, blocking.map(describeViolation).join('\n')).toEqual([])
}
