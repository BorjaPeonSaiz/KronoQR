// La pasada de axe de las pruebas E2E de las tres SPA (doc 01 §6.5: WCAG 2.2 AA;
// doc 02 §9.2 y §9.4). UNA sola definicion: antes el panel, el portal y el quiosco la
// copiaban y habian divergido (V7-QA-3: etiquetas distintas, reglas desactivadas distintas).
//
// Solo la importan las pruebas de Playwright; no entra en ningun paquete de la aplicacion.
// `@axe-core/playwright` y `@playwright/test` los aporta cada SPA como dependencia de
// desarrollo (se resuelven por el arbol de `node_modules` compartido).
//
// Criterio por omision: CERO violaciones criticas o graves; `failOn: 'any'` bloquea tambien
// las menores. Una regla desactivada se pide con `disabledRules` y SIEMPRE con su motivo
// escrito junto a quien la pide.
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
  /** Reglas de axe desactivadas, con su motivo escrito en quien las pide. */
  readonly disabledRules?: readonly string[]
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
  const builder = new AxeBuilder({ page })
    .withTags(AXE_TAGS)
    .disableRules([...(options.disabledRules ?? [])])
  const scoped = options.include === undefined ? builder : builder.include(options.include)

  const results = await scoped.analyze()
  const blocking = results.violations.filter(BLOCKS[options.failOn ?? 'serious'])

  expect(blocking, blocking.map(describeViolation).join('\n')).toEqual([])
}
