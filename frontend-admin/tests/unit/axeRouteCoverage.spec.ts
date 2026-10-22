// Arquitectura de pruebas: cada ruta del panel tiene su pasada de axe
// (V21-7-QA-1, R7-QA-01, V7-QA-1). Las verificaciones de cada version
// encontraban pantallas nuevas sin axe porque nada obligaba a añadirlo; ahora
// una ruta nueva sin entrada en `tests/e2e/support/axeRoutes.ts` rompe aqui.
import { readdirSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import type { RouteRecordRaw } from 'vue-router'
import { createMemoryHistory, createRouter } from 'vue-router'
import { describe, expect, it } from 'vitest'
import { routes } from '@/router'
import { AXE_ROUTE_PASSES } from '../e2e/support/axeRoutes'

/** Las rutas que se pueden visitar: las hojas con nombre, sin las que solo redirigen. */
function visitableRouteNames(records: readonly RouteRecordRaw[]): string[] {
  return records.flatMap((record) =>
    record.children !== undefined
      ? visitableRouteNames(record.children)
      : record.redirect === undefined
        ? [String(record.name)]
        : [],
  )
}

describe('pasada de axe por ruta (@RQ-14)', () => {
  it('el dataset de axe tiene exactamente una entrada por ruta visitable del router', () => {
    const declared = Object.keys(AXE_ROUTE_PASSES).sort()

    expect(declared).toEqual(visitableRouteNames(routes).sort())
  })

  it.each(Object.entries(AXE_ROUTE_PASSES))(
    'la pasada «%s» visita de verdad esa ruta',
    (name, pass) => {
      const router = createRouter({ history: createMemoryHistory(), routes })

      expect(router.resolve(pass.path).name).toBe(name)
    },
  )
})

const AXE_E2E_DIR = resolve(__dirname, '../e2e')

/** Las pruebas E2E que construyen su propio `AxeBuilder` en vez de usar `support/axe.ts`. */
function specsWithTheirOwnAxeBuilder(): string[] {
  return readdirSync(AXE_E2E_DIR)
    .filter((name) => name.endsWith('.spec.ts'))
    .filter((name) =>
      readFileSync(join(AXE_E2E_DIR, name), 'utf8').includes('@axe-core/playwright'),
    )
}

describe('una sola definicion de la pasada de axe (V7-QA-3)', () => {
  it('ninguna prueba E2E importa axe por su cuenta: todas pasan por support/axe.ts, con best-practice', () => {
    expect(specsWithTheirOwnAxeBuilder()).toEqual([])
  })
})
