// Objetivos tactiles minimos (RF-KI-06, doc 01 §6.5): 48x48 px, para guantes
// y para una sola mano. Compartido por todas las pantallas que lo comprueban
// (`scan.spec.ts`, `pin.spec.ts`, `pin-lockout.spec.ts`, `diagnostics.spec.ts`).

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
