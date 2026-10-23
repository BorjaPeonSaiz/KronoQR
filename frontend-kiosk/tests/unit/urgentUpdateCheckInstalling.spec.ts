// V21-3-KI-4 — una comprobacion urgente que encuentra la version nueva TODAVIA
// INSTALANDOSE no es una comprobacion «en vacio» (RF-KI-07).
//
// `registration.update()` resuelve en cuanto el navegador ha descargado el
// `sw.js` nuevo y ha empezado a instalarlo, no cuando termina. La instalacion
// precachea ~1 460 KiB (29 entradas): en una wifi de hotel puede tardar mas que
// la propia comprobacion. Hasta que acaba no llega `onNeedRefresh`, y si en ese
// hueco se anota la comprobacion como vacia, seis seguidas hacen que la tablet
// se rinda (`gave_up`) con la version nueva a medio descargar.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { RegisterSWOptions } from 'virtual:pwa-register'
import type { RegisterSWLoader } from '@/sw/registerServiceWorker'
import { registerServiceWorker } from '@/sw/registerServiceWorker'

/** Un `ServiceWorkerRegistration` cuyo `update()` deja lo que diga la prueba en `installing`. */
interface FakeRegistration {
  installing: ServiceWorker | null
  waiting: ServiceWorker | null
  /** Cada `update()` pedido, para esperar a que resuelva sin cronometros. */
  readonly updates: Promise<void>[]
  update(): Promise<void>
}

const INSTALLING_WORKER = { state: 'installing' } as unknown as ServiceWorker

function registrationWhoseUpdateFinds(found: ServiceWorker | null): FakeRegistration {
  const registration: FakeRegistration = {
    installing: null,
    waiting: null,
    updates: [],
    update: () => {
      const done = Promise.resolve().then(() => {
        registration.installing = found
      })
      registration.updates.push(done)
      return done
    },
  }
  return registration
}

/** `registerSW` que entrega la `registration` y guarda las opciones para disparar `onNeedRefresh`. */
function loaderFor(
  registration: FakeRegistration,
  captured: { options?: RegisterSWOptions },
): RegisterSWLoader {
  return () =>
    Promise.resolve({
      registerSW: (options) => {
        captured.options = options
        options?.onRegisteredSW?.('/sw.js', registration as unknown as ServiceWorkerRegistration)
        return async () => {}
      },
    })
}

beforeEach(() => {
  Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: {} })
})

afterEach(() => {
  delete (navigator as { serviceWorker?: unknown }).serviceWorker
})

describe('V21-3-KI-4 — comprobacion urgente con el precacheo en curso (RF-KI-07)', () => {
  it('si la version nueva se esta instalando, la comprobacion no cuenta como vacia', async () => {
    const onUrgentCheckEmpty = vi.fn()
    const fake = registrationWhoseUpdateFinds(INSTALLING_WORKER)
    const registration = await registerServiceWorker({
      urgentState: () => 'urgent',
      onUrgentCheckEmpty,
      loadRegisterSW: loaderFor(fake, {}),
    })
    await Promise.all(fake.updates)

    expect(fake.updates).toHaveLength(1)
    expect(onUrgentCheckEmpty).not.toHaveBeenCalled()
    registration.dispose()
  })

  it('si no hay nada instalandose ni esperando, la comprobacion si cuenta como vacia', async () => {
    const onUrgentCheckEmpty = vi.fn()
    const registration = await registerServiceWorker({
      urgentState: () => 'urgent',
      onUrgentCheckEmpty,
      loadRegisterSW: loaderFor(registrationWhoseUpdateFinds(null), {}),
    })

    await vi.waitFor(() => expect(onUrgentCheckEmpty).toHaveBeenCalledTimes(1))
    registration.dispose()
  })

  it('cuando la instalacion acaba, la version queda pendiente y se intenta aplicar', async () => {
    const captured: { options?: RegisterSWOptions } = {}
    const canApply = vi.fn(() => false)
    const registration = await registerServiceWorker({
      urgentState: () => 'urgent',
      canApply,
      loadRegisterSW: loaderFor(registrationWhoseUpdateFinds(INSTALLING_WORKER), captured),
    })

    captured.options?.onNeedRefresh?.()

    expect(registration.needsRefresh()).toBe(true)
    expect(canApply).toHaveBeenCalledWith(true)
    registration.dispose()
  })
})
