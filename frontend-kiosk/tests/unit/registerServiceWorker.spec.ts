// Registro del service worker y su puerta de actualizacion (RF-KI-07, tarea
// 3.12).
//
// `virtual:pwa-register` NO RESUELVE BAJO VITEST EN ESTE ENTORNO (limitacion
// del cargador de modulos de Vitest en Windows con el modulo virtual que sirve
// `vite-plugin-pwa` en modo "dev" — la PWA real lo sirve sin problema en cada
// build; es la propia prueba «sin soporte» de mas abajo, ya existente antes de
// esta tarea, la que evitaba el problema saltandose el import por completo).
// Por eso `registerServiceWorker` acepta `loadRegisterSW` inyectable: estas
// pruebas pasan un doble que se comporta como el modulo real (llama a
// `onNeedRefresh`, devuelve la funcion de aplicar), y as[i] se prueba el
// camino de verdad -`onNeedRefresh` -> temporizador de reintento -> puerta ->
// `update(true)`- sin depender de esa resolucion rota.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { RegisterSWLoader } from '@/sw/registerServiceWorker'
import { isUpdatePending, registerServiceWorker } from '@/sw/registerServiceWorker'

/**
 * jsdom NO IMPLEMENTA `navigator.serviceWorker` (comprobado: `'serviceWorker'
 * in navigator` es `false` de serie). `registerServiceWorker()` trata eso
 * exactamente igual que un navegador sin soporte -devuelve el `noop` en la
 * primera linea-, asi que sin este polyfill NINGUNA de las pruebas de este
 * fichero llegaria mas alla de esa comprobacion, sin que el fallo lo dijera
 * (el "sin soporte" de la primera prueba queda cubierto aparte, borrando
 * `navigator` entero).
 */
function installServiceWorkerSupport(): void {
  Object.defineProperty(navigator, 'serviceWorker', {
    configurable: true,
    value: {},
  })
}

beforeEach(() => {
  installServiceWorkerSupport()
})

afterEach(() => {
  delete (navigator as { serviceWorker?: unknown }).serviceWorker
  delete window.__KRONOQR_ENABLE_TEST_HOOKS__
  delete window.__kronoqrTest
  vi.useRealTimers()
})

/**
 * Doble de `import('virtual:pwa-register')`: registra sincronamente y avisa
 * de que hay una version pendiente en un microtask (como lo haria el
 * `Workbox` real tras confirmar el registro), y la funcion de aplicar marca
 * `applied.value` cuando se la llama con `reloadPage: true` -exactamente lo
 * que hace la de verdad al completar la recarga-.
 */
function fakeLoadRegisterSW(applied: { value: boolean }): RegisterSWLoader {
  return () =>
    Promise.resolve({
      registerSW: (opts) => {
        queueMicrotask(() => opts?.onNeedRefresh?.())
        return async (reloadPage) => {
          if (reloadPage === true) applied.value = true
        }
      },
    })
}

describe('registro del service worker', () => {
  it('sin soporte de service worker no rompe nada y no aplica nada', async () => {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator')
    // @ts-expect-error se elimina a proposito para simular un navegador sin soporte
    delete globalThis.navigator

    const registration = await registerServiceWorker({ canApply: vi.fn(() => true) })

    expect(registration.needsRefresh()).toBe(false)
    expect(await registration.applyUpdate()).toBe(false)
    registration.dispose()

    if (original !== undefined) Object.defineProperty(globalThis, 'navigator', original)
  })

  it('el gancho de pruebas esta apagado si nadie deja la señal antes de arrancar (nunca en produccion)', async () => {
    const applied = { value: false }
    const registration = await registerServiceWorker({
      canApply: () => true,
      loadRegisterSW: fakeLoadRegisterSW(applied),
    })

    expect(window.__kronoqrTest).toBeUndefined()

    registration.dispose()
  })

  describe('puerta de actualizacion (canApply)', () => {
    it('dentro de la ventana, aplica en cuanto avisa de que hay version pendiente', async () => {
      const applied = { value: false }
      const registration = await registerServiceWorker({
        canApply: () => true,
        loadRegisterSW: fakeLoadRegisterSW(applied),
      })

      await vi.waitFor(() => expect(applied.value).toBe(true))
      expect(registration.needsRefresh()).toBe(false)
      expect(isUpdatePending()).toBe(false)

      registration.dispose()
    })

    it('fuera de la ventana, queda pendiente y no aplica nada', async () => {
      const applied = { value: false }
      const registration = await registerServiceWorker({
        canApply: () => false,
        loadRegisterSW: fakeLoadRegisterSW(applied),
      })

      // Dos vueltas de microtask: una para que `registerSW` avise, otra para
      // que `startRetryTimer` complete su primer intento (denegado).
      await Promise.resolve()
      await Promise.resolve()

      expect(applied.value).toBe(false)
      expect(registration.needsRefresh()).toBe(true)
      expect(isUpdatePending()).toBe(true)

      registration.dispose()
    })

    it('reevalua la puerta cada minuto mientras hay una version pendiente (decision 10, tarea 3.12)', async () => {
      vi.useFakeTimers()
      const applied = { value: false }
      let allow = false
      const registration = await registerServiceWorker({
        canApply: () => allow,
        loadRegisterSW: fakeLoadRegisterSW(applied),
        retryIntervalMs: 60_000,
      })

      await vi.advanceTimersByTimeAsync(0)
      expect(applied.value).toBe(false)
      expect(registration.needsRefresh()).toBe(true)

      // La ventana sigue cerrada 30 s despues: todavia nada.
      await vi.advanceTimersByTimeAsync(30_000)
      expect(applied.value).toBe(false)

      // Se abre, y el siguiente ciclo del minuto la aplica sin que nadie
      // vuelva a pedirlo.
      allow = true
      await vi.advanceTimersByTimeAsync(30_000)
      expect(applied.value).toBe(true)
      expect(registration.needsRefresh()).toBe(false)

      registration.dispose()
    })

    it('no deja un temporizador de reintento vivo si la puerta deja pasar en el primer intento (decision 16)', async () => {
      // La prueba anterior comprobaba que `canApply` no volvia a llamarse, y
      // eso era CIERTO incluso con el temporizador filtrado (el guardian de
      // `pending` ya cortaba antes de llegar a `canApply`): no probaba lo que
      // decia. Aqui se espia `setInterval`/`clearInterval` directamente: el
      // intervalo de reintento tiene que quedar limpiado, no solo inofensivo.
      vi.useFakeTimers()
      const setIntervalSpy = vi.spyOn(globalThis, 'setInterval')
      const clearIntervalSpy = vi.spyOn(globalThis, 'clearInterval')
      const applied = { value: false }
      const registration = await registerServiceWorker({
        canApply: () => true,
        loadRegisterSW: fakeLoadRegisterSW(applied),
        retryIntervalMs: 60_000,
      })

      await vi.advanceTimersByTimeAsync(0)
      expect(applied.value).toBe(true)

      // Dos intervalos armados en total: el de la comprobacion horaria
      // (`checkTimer`, que se queda vivo a proposito) y el de reintento
      // (`retryTimer`, armado ANTES del primer intento). El segundo tiene que
      // haberse limpiado en cuanto `applyUpdate` decidio aplicar: sin la
      // correccion de la decision 16, `retryTimer` se asignaba DESPUES del
      // intento y `stopRetryTimer()` no encontraba nada que limpiar.
      expect(setIntervalSpy).toHaveBeenCalledTimes(2)
      expect(clearIntervalSpy).toHaveBeenCalledTimes(1)

      registration.dispose()
      setIntervalSpy.mockRestore()
      clearIntervalSpy.mockRestore()
    })

    it('si `update(true)` falla, la version SIGUE pendiente y el reintento sigue vivo (decision 16)', async () => {
      vi.useFakeTimers()
      const onError = vi.fn()
      const updateSpy = vi.fn(async () => {
        throw new Error('el service worker no confirmo el control a tiempo')
      })
      const registration = await registerServiceWorker({
        canApply: () => true,
        onError,
        retryIntervalMs: 60_000,
        loadRegisterSW: () =>
          Promise.resolve({
            registerSW: (opts) => {
              queueMicrotask(() => opts?.onNeedRefresh?.())
              return updateSpy
            },
          }),
      })

      await vi.advanceTimersByTimeAsync(0)

      // Nada se aplico de verdad: sigue pendiente, y quien pregunta por el
      // fallo se entera (nunca una promesa rechazada suelta).
      expect(registration.needsRefresh()).toBe(true)
      expect(onError).toHaveBeenCalledTimes(1)
      expect(updateSpy).toHaveBeenCalledTimes(1)

      // Y el reintento sigue vivo: un minuto despues, lo intenta de nuevo.
      await vi.advanceTimersByTimeAsync(60_000)
      expect(updateSpy).toHaveBeenCalledTimes(2)

      registration.dispose()
    })
  })

  it('la comprobacion horaria llama a `registration.update()` de verdad (decision 16)', async () => {
    vi.useFakeTimers()
    const registrationUpdateSpy = vi.fn(() => Promise.resolve())
    const fakeRegistration = {
      update: registrationUpdateSpy,
    } as unknown as ServiceWorkerRegistration
    const registration = await registerServiceWorker({
      checkForUpdateIntervalMs: 60 * 60_000,
      loadRegisterSW: () =>
        Promise.resolve({
          registerSW: (opts) => {
            opts?.onRegisteredSW?.('/sw.js', fakeRegistration)
            return async () => {}
          },
        }),
    })

    expect(registrationUpdateSpy).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(60 * 60_000)
    expect(registrationUpdateSpy).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(60 * 60_000)
    expect(registrationUpdateSpy).toHaveBeenCalledTimes(2)

    registration.dispose()
  })

  it('un fallo de `registration.update()` no rompe nada: se reintenta en el siguiente ciclo', async () => {
    vi.useFakeTimers()
    const registrationUpdateSpy = vi.fn(() => Promise.reject(new Error('network')))
    const fakeRegistration = {
      update: registrationUpdateSpy,
    } as unknown as ServiceWorkerRegistration
    const registration = await registerServiceWorker({
      checkForUpdateIntervalMs: 60 * 60_000,
      loadRegisterSW: () =>
        Promise.resolve({
          registerSW: (opts) => {
            opts?.onRegisteredSW?.('/sw.js', fakeRegistration)
            return async () => {}
          },
        }),
    })

    await vi.advanceTimersByTimeAsync(2 * 60 * 60_000)
    expect(registrationUpdateSpy).toHaveBeenCalledTimes(2)

    registration.dispose()
  })

  describe('gancho de pruebas E2E (`src/sw/testHooks.ts`)', () => {
    it('con la señal activada, fuerza «pendiente» y respeta la puerta real', async () => {
      window.__KRONOQR_ENABLE_TEST_HOOKS__ = true
      const applied = { value: false }
      const registration = await registerServiceWorker({
        canApply: () => true,
        // Sin doble aqui: el gancho de pruebas sustituye la version pendiente
        // por su propia cuenta cuando `update` sigue en `null`.
        loadRegisterSW: () => Promise.resolve({ registerSW: () => async () => {} }),
      })

      expect(window.__kronoqrTest).toBeDefined()
      window.__kronoqrTest?.simulateUpdateAvailable()
      await vi.waitFor(() => expect(window.__kronoqrTest?.hasAppliedUpdate()).toBe(true))
      expect(applied.value).toBe(false) // el marcador es el del gancho, no el del doble

      registration.dispose()
    })
  })
})
