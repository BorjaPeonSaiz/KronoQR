// Objetivos tactiles minimos (RF-KI-06, doc 01 §6.5): 48x48 px, para guantes
// y para una sola mano. Compartido por todas las pantallas que lo comprueban
// (`scan.spec.ts`, `pin.spec.ts`, `pin-lockout.spec.ts`, `diagnostics.spec.ts`,
// `pairing.spec.ts`).

import type { Locator } from '@playwright/test'
import { expect } from '@playwright/test'

/** Cada elemento interactivo visible dentro de `locator` mide al menos 48x48 px. */
export async function expectTouchTargets(locator: Locator): Promise<void> {
  const count = await locator.count()
  expect(count).toBeGreaterThan(0)

  for (let index = 0; index < count; index += 1) {
    const box = await locator.nth(index).boundingBox()
    expect(box, `elemento interactivo ${index} sin caja`).not.toBeNull()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(48)
    expect(box?.width ?? 0).toBeGreaterThanOrEqual(48)
  }
}

/** Una orientacion de la tablet en la que se comprueban los objetivos (KI7-01, KI7-03). */
export interface TabletViewport {
  readonly name: string
  readonly viewport: { readonly width: number; readonly height: number }
}

/**
 * La tablet de pared se monta en apaisado o en vertical segun el hueco, y el
 * manifiesto deja la orientacion libre (tarea 16.4). Hasta la 2.3.0 los
 * objetivos del PIN, del bloqueo y del diagnostico solo se median en apaisado
 * (KI7-03): una regla de CSS que encogiera los botones en vertical pasaba la CI.
 * 600x1024 es la tablet de 10" mas estrecha que se ha medido en local.
 */
export const TABLET_VIEWPORTS: readonly TabletViewport[] = [
  { name: 'apaisada 1280x800', viewport: { width: 1280, height: 800 } },
  { name: 'vertical 800x1280', viewport: { width: 800, height: 1280 } },
  { name: 'vertical 600x1024', viewport: { width: 600, height: 1024 } },
]
