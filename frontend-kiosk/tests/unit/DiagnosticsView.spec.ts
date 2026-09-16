// Pantalla de diagnostico (RF-KI-08, tarea 3.3).
//
// Mismo patron que `PairingView.spec.ts`/`ScanView.spec.ts`: se monta la
// pantalla de verdad, con `fetch` sustituido por un doble que siempre falla
// (esta pantalla tiene que funcionar SIN red, asi que fallar es justamente el
// caso que importa) y una camara falsa minima.

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createWebHistory } from 'vue-router'
import DiagnosticsView from '@/features/diagnostics/ui/DiagnosticsView.vue'
import { routes } from '@/router'
import { createApiClient } from '@/shared/api/client'
import { sha256Hex } from '@/shared/crypto/sha256'
import { createAppI18n } from '@/shared/i18n'
import { disposeOfflineQueue, getOfflineQueueController } from '@/features/offline/useOfflineQueue'
import { getErrorReporter } from '@/shared/telemetry/errorReporter'

const DEVICE_ID = '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81'
const SERVICE_CODE = '48392017'

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
          getSettings: vi.fn(() => ({ width: 1280, height: 720 })),
        }
        return { getTracks: () => [track], getVideoTracks: () => [track] } as unknown as MediaStream
      }),
    },
  })
}

async function render() {
  const router = createRouter({ history: createWebHistory(), routes })
  await router.push('/diagnostics')
  await router.isReady()

  const wrapper = mount(DiagnosticsView, { global: { plugins: [createAppI18n('es'), router] } })
  await wrapper.vm.$nextTick()
  return { wrapper, router }
}

async function pressDigits(wrapper: Awaited<ReturnType<typeof render>>['wrapper'], digits: string) {
  for (const digit of digits) {
    const button = wrapper.findAll('button').find((candidate) => candidate.text() === digit)
    await button?.trigger('click')
  }
}

beforeEach(async () => {
  await disposeOfflineQueue()
  localStorage.clear()
  localStorage.setItem('kronoqr.kiosk.device_token', 'device-token-de-prueba')
  localStorage.setItem('kronoqr.kiosk.device_id', DEVICE_ID)
  installCamera()
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => {
      throw new TypeError('Failed to fetch')
    }),
  )
})

afterEach(async () => {
  vi.unstubAllGlobals()
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
  await disposeOfflineQueue()
})

describe('pantalla de diagnostico — sin huella cacheada (decision 7)', () => {
  it('se abre directamente, sin pedir codigo, y lo dice en cabecera', async () => {
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid="diagnostics-gate"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="diagnostics-content"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="diagnostics-no-code"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('muestra las secciones principales', async () => {
    const { wrapper } = await render()

    for (const section of ['camera', 'network', 'queue', 'roster', 'token', 'version']) {
      expect(wrapper.find(`[data-testid="diagnostics-section-${section}"]`).exists()).toBe(true)
    }

    wrapper.unmount()
  })
})

describe('pantalla de diagnostico — con huella cacheada (decision 6)', () => {
  beforeEach(() => {
    localStorage.setItem(
      'kronoqr.kiosk.service_code_hash',
      sha256Hex(`${DEVICE_ID}:${SERVICE_CODE}`),
    )
  })

  it('pide codigo antes de mostrar nada', async () => {
    const { wrapper } = await render()

    expect(wrapper.find('[data-testid="diagnostics-gate"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="diagnostics-content"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('el codigo correcto abre el contenido', async () => {
    const { wrapper } = await render()

    await pressDigits(wrapper, SERVICE_CODE)
    await wrapper.get('[data-testid="diagnostics-code-confirm"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="diagnostics-content"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="diagnostics-gate"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('un codigo incorrecto no abre nada, y avisa de forma generica', async () => {
    const { wrapper } = await render()

    await pressDigits(wrapper, '00000000')
    await wrapper.get('[data-testid="diagnostics-code-confirm"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="diagnostics-content"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Código incorrecto')

    wrapper.unmount()
  })

  it('cinco fallos bloquean la puerta 60 s', async () => {
    const { wrapper } = await render()

    for (let attempt = 0; attempt < 5; attempt += 1) {
      await pressDigits(wrapper, '00000000')
      await wrapper.get('[data-testid="diagnostics-code-confirm"]').trigger('click')
      await wrapper.vm.$nextTick()
    }

    expect(wrapper.find('[data-testid="diagnostics-locked"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="diagnostics-content"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('el token nunca aparece en claro', async () => {
    const { wrapper } = await render()

    await pressDigits(wrapper, SERVICE_CODE)
    await wrapper.get('[data-testid="diagnostics-code-confirm"]').trigger('click')
    await wrapper.vm.$nextTick()

    expect(wrapper.html()).not.toContain('device-token-de-prueba')

    wrapper.unmount()
  })
})

describe('vuelta al fichaje (decision 8)', () => {
  it('«Volver a fichar» navega a la pantalla de fichaje', async () => {
    const { wrapper, router } = await render()

    await wrapper.get('[data-testid="diagnostics-back"]').trigger('click')

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))
    wrapper.unmount()
  })

  it('vuelve sola a los 120 s sin interaccion', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()

    await vi.advanceTimersByTimeAsync(120_000)
    await wrapper.vm.$nextTick()

    expect(router.currentRoute.value.name).toBe('home')
    wrapper.unmount()
    vi.useRealTimers()
  })

  it('un pointerdown suelto en el fondo NO reinicia la cuenta (revision de la 3.3, segunda vuelta)', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()

    // Antes de la revision, cualquier `pointerdown` en `<main>` reiniciaba el
    // temporizador. Aqui se dispara el evento a mano sobre el contenedor -sin
    // pasar por ningun boton- y se comprueba que la vuelta automatica sigue
    // llegando a su hora.
    await wrapper.get('[data-testid="diagnostics-view"]').trigger('pointerdown')
    await vi.advanceTimersByTimeAsync(120_000)
    await wrapper.vm.$nextTick()

    expect(router.currentRoute.value.name).toBe('home')
    wrapper.unmount()
    vi.useRealTimers()
  })

  it('pulsar un control real SI reinicia la cuenta de 120 s', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()

    await vi.advanceTimersByTimeAsync(100_000)
    // Un control real: el idioma (solo contiene botones).
    await wrapper.get('[role="group"]').trigger('click')

    await vi.advanceTimersByTimeAsync(100_000)
    await wrapper.vm.$nextTick()
    // Han pasado 200 s desde la apertura, pero solo 100 s desde el ultimo
    // control real: la pantalla sigue en diagnostico.
    expect(router.currentRoute.value.name).toBe('diagnostics')

    await vi.advanceTimersByTimeAsync(20_000)
    await wrapper.vm.$nextTick()
    expect(router.currentRoute.value.name).toBe('home')

    wrapper.unmount()
    vi.useRealTimers()
  })

  it('techo absoluto de 5 min desde la apertura, aunque haya interaccion continua', async () => {
    vi.useFakeTimers()
    const { wrapper, router } = await render()

    // Interaccion cada 100 s -bien dentro de los 120 s de inactividad- dos
    // veces (200 s en total): sin techo absoluto, la pantalla seguiria
    // abierta indefinidamente con solo tocar algo de vez en cuando.
    await vi.advanceTimersByTimeAsync(100_000)
    await wrapper.get('[role="group"]').trigger('click')
    await vi.advanceTimersByTimeAsync(100_000)
    await wrapper.get('[role="group"]').trigger('click')
    await wrapper.vm.$nextTick()
    expect(router.currentRoute.value.name).toBe('diagnostics')

    // A partir de aqui ya no hace falta ninguna interaccion mas: el techo
    // absoluto de 300 s desde la APERTURA (no desde la ultima interaccion)
    // se cumple igual, aunque cada interaccion individual siguiera
    // reiniciando los 120 s de inactividad.
    await vi.advanceTimersByTimeAsync(110_000)
    await wrapper.vm.$nextTick()
    expect(router.currentRoute.value.name).toBe('home')

    wrapper.unmount()
    vi.useRealTimers()
  })
})

describe('latido propio y alcanzabilidad viva (revision de la 3.3, segunda vuelta)', () => {
  it('lanza un latido al abrir, con la tablet emparejada', async () => {
    let heartbeatCalls = 0
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input)
        if (url.includes('/api/v1/kiosk/heartbeat')) {
          heartbeatCalls += 1
          return new Response(
            JSON.stringify({
              server_time: new Date().toISOString(),
              client_errors_accepted: 0,
              service_code_hash: null,
            }),
            { status: 200 },
          )
        }
        throw new TypeError('Failed to fetch')
      }),
    )

    const { wrapper } = await render()
    await vi.waitFor(() => expect(heartbeatCalls).toBeGreaterThanOrEqual(1))

    wrapper.unmount()
  })

  it('«servidor alcanzable» viene del SyncRunner, no del ultimo latido: el latido puede seguir fallando', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL) => {
        const url = String(input)
        // El latido SIEMPRE falla: si «alcanzable» viniera de ahi (fallo
        // corregido), esta fila nunca se moveria de «Se desconoce».
        if (url.includes('/api/v1/kiosk/heartbeat')) throw new TypeError('Failed to fetch')
        if (url.includes('/api/v1/scan')) {
          return new Response(
            JSON.stringify({
              scan_id: 'scan-1',
              action: 'clock_in',
              employee_display_name: 'Lucia G.',
              work_date: '2026-01-01',
              occurred_at: new Date().toISOString(),
              recorded_at: new Date().toISOString(),
              worked_minutes: 0,
            }),
            { status: 200 },
          )
        }
        throw new TypeError('Failed to fetch')
      }),
    )

    const { wrapper } = await render()

    expect(wrapper.get('[data-testid="diagnostics-network-reachable"]').text()).toBe('Se desconoce')

    const api = createApiClient({ deviceToken: () => 'device-token-de-prueba' })
    const reporter = getErrorReporter({ appVersion: '1.0.0', deviceId: DEVICE_ID })
    const controller = getOfflineQueueController({ api, reporter })

    await controller.submission.submit({
      kind: 'qr',
      scan_id: '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32',
      occurred_at: new Date().toISOString(),
      intent: 'auto',
      device_id: DEVICE_ID,
      qr_payload: 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa',
    })

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="diagnostics-network-reachable"]').text()).toBe('Sí'),
    )

    wrapper.unmount()
  })
})
