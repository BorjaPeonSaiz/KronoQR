// Revocacion del dispositivo entre pantallas (RF-PD-06, revision de la 3.3,
// segunda vuelta).
//
// EL FALLO QUE ESTO PRUEBA. `getOfflineQueueController` es un singleton por
// tablet que congela sus opciones en la PRIMERA llamada. Antes de esta
// revision, `onDeviceRevoked` era una de esas opciones: si `DiagnosticsView`
// se montaba ANTES que `ScanView` -una tablet emparejada, recargada
// directamente sobre `/diagnostics`, que el guard del router exceptua-, el
// controlador se creaba SIN el callback de `ScanView`, y una desvinculacion
// posterior desde el panel no limpiaba el token ni navegaba a `/pair`: la
// tablet se quedaba fichando contra un token muerto. Ahora `onDeviceRevoked`
// es una SUSCRIPCION (como `onSyncing`/`onReachability`): cada pantalla se
// engancha la suya, y el orden de montaje deja de importar.

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createWebHistory } from 'vue-router'
import DiagnosticsView from '@/features/diagnostics/ui/DiagnosticsView.vue'
import ScanView from '@/features/scan/ui/ScanView.vue'
import { routes } from '@/router'
import { disposeOfflineQueue } from '@/features/offline/useOfflineQueue'
import { createAppI18n } from '@/shared/i18n'

const DEVICE_TOKEN_KEY = 'kronoqr.kiosk.device_token'

vi.mock('@zxing/browser', () => ({
  BrowserQRCodeReader: class {
    async decodeFromStream() {
      // Nunca decodifica nada: esta prueba no es del escaneo.
      return { stop: vi.fn() }
    }
  },
}))

function installCamera(): void {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: vi.fn(async () => {
        const track = {
          kind: 'video',
          stop: vi.fn(),
          applyConstraints: vi.fn(async () => undefined),
          getCapabilities: vi.fn(() => ({})),
          getSettings: vi.fn(() => ({})),
        }
        return { getTracks: () => [track], getVideoTracks: () => [track] } as unknown as MediaStream
      }),
    },
  })
}

/**
 * El latido SIEMPRE `401`; el padron y cualquier otra cosa fallan por RED
 * (`TypeError`), que es NEUTRO para el contador de revocacion
 * (`deviceRevocation.ts`): si el padron respondiera `200`, su propio exito
 * reiniciaria el contador a cero entre los dos `401` del latido y esta
 * prueba nunca llegaria al umbral.
 */
function installHeartbeatUnauthorized(): { heartbeatCalls: number } {
  const state = { heartbeatCalls: 0 }
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes('/api/v1/kiosk/heartbeat')) {
        state.heartbeatCalls += 1
        return new Response(
          JSON.stringify({
            type: 'urn:kronoqr:problem:unauthenticated',
            title: 'No autenticado',
            status: 401,
          }),
          { status: 401 },
        )
      }
      throw new TypeError('Failed to fetch')
    }),
  )
  return state
}

beforeEach(async () => {
  await disposeOfflineQueue()
  localStorage.clear()
  installCamera()
})

afterEach(async () => {
  vi.unstubAllGlobals()
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
  localStorage.clear()
  await disposeOfflineQueue()
})

describe('la revocacion no depende de que pantalla se monto primero', () => {
  it('diagnostico primero, luego ScanView: dos 401 seguidos navegan a /pair', async () => {
    localStorage.setItem(DEVICE_TOKEN_KEY, 'device-token-de-prueba')
    const state = installHeartbeatUnauthorized()

    const router = createRouter({ history: createWebHistory(), routes })
    await router.push('/diagnostics')
    await router.isReady()

    // `DiagnosticsView` es la PRIMERA en crear el controlador singleton: si
    // el fallo corregido siguiera ahi, seria la unica con el callback de
    // revocacion, y `ScanView` (montada despues) no tendria ninguno.
    const diagnostics = mount(DiagnosticsView, {
      global: { plugins: [createAppI18n('es'), router] },
    })
    await vi.waitFor(() => expect(state.heartbeatCalls).toBeGreaterThanOrEqual(1))
    diagnostics.unmount()

    expect(localStorage.getItem(DEVICE_TOKEN_KEY)).not.toBeNull()

    await router.push('/')
    const scan = mount(ScanView, { global: { plugins: [createAppI18n('es'), router] } })

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('pair'), {
      timeout: 5_000,
      interval: 20,
    })
    expect(localStorage.getItem(DEVICE_TOKEN_KEY)).toBeNull()

    scan.unmount()
  })
})
