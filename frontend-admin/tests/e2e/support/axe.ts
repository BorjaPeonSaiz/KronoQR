// La pasada de axe del panel (doc 01 §6.5: WCAG 2.2 AA; doc 02 §9.2 y §9.4).
//
// UNA sola definicion para todas las pruebas: antes cada fichero declaraba sus
// etiquetas y ninguno miraba `best-practice` (V7-QA-3), asi que `heading-order`
// o la grave `aria-text` no podian hacer fallar nada. Aqui van las etiquetas
// WCAG de A y AA hasta la 2.2 MAS `best-practice`.
//
// Criterio por omision: CERO violaciones criticas o graves. Las pantallas que
// ya estaban limpias del todo piden `failOn: 'any'` para que ni una menor entre.

import AxeBuilder from '@axe-core/playwright'
import type { Page } from '@playwright/test'
import { expect } from '@playwright/test'

/** Una violacion tal y como la devuelve `analyze()`. */
type Result = Awaited<ReturnType<AxeBuilder['analyze']>>['violations'][number]

/** WCAG A y AA hasta la 2.2 y las buenas practicas de axe (V7-QA-3). */
export const AXE_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa', 'best-practice']

export interface AxePassOptions {
  /** Limita el analisis a una zona, p. ej. `[role="dialog"]`. */
  readonly include?: string
  /** `serious` (por omision): bloquean `critical` y `serious`. `any`: bloquea cualquiera. */
  readonly failOn?: 'serious' | 'any'
}

const BLOCKS: Readonly<Record<NonNullable<AxePassOptions['failOn']>, (v: Result) => boolean>> = {
  serious: (violation) => violation.impact === 'critical' || violation.impact === 'serious',
  any: () => true,
}

function describeViolation(violation: Result): string {
  const targets = violation.nodes.map((node) => node.target.join(' ')).join(', ')

  return `${violation.id} (${violation.impact ?? 'sin impacto'}): ${violation.help} → ${targets}`
}

export async function expectNoBlockingViolations(
  page: Page,
  options: AxePassOptions = {},
): Promise<void> {
  const builder = new AxeBuilder({ page }).withTags(AXE_TAGS)
  const scoped = options.include === undefined ? builder : builder.include(options.include)

  const results = await scoped.analyze()
  const blocking = results.violations.filter(BLOCKS[options.failOn ?? 'serious'])

  expect(blocking, blocking.map(describeViolation).join('\n')).toEqual([])
}
