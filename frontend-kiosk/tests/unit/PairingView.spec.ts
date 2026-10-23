// Pantalla de emparejamiento (tarea 5.6, RF-PD-06).
//
// Mismo patron que `PinView.spec.ts`: se monta la pantalla de verdad, con
// `fetch` sustituido por un enrutador por URL, y se comprueba lo que ve y hace
// quien esta vinculando el quiosco -- no los internos de la maquina de
// estados, que tiene su propia bateria en `pairingFlow.spec.ts`.

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createWebHistory } from 'vue-router'
import PairingView from '@/features/pairing/ui/PairingView.vue'
import { routes } from '@/router'
import { createAppI18n } from '@/shared/i18n'

const PAIRING_ID = '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32'
const PAIRING_SECRET = '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'
const DEVICE_UUID = '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81'
const TOKEN_VALUE = '92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'

interface FetchScript {
  /** Sondeos de `/pair/claim` ya atendidos, para decidir cuando pasar a `paired`. */
  claimCalls: number
  /** A partir de este numero de llamada, `claim` devuelve `paired`. */
  pairAtCall: number
  /** Cuantas veces se ha pedido un codigo (`/pair`), para comprobar «Generar otro». */
  pairRequests: number
  /** Respuesta de `GET /api/v1/branding`: JSON, `fail` (sin red) o `hang` (no contesta). */
  branding?: Record<string, unknown> | 'fail' | 'hang'
}

function installFetch(script: FetchScript): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)

      if (url.endsWith('/api/v1/branding')) {
        if (script.branding === undefined || script.branding === 'fail') {
          throw new TypeError('Failed to fetch')
        }
        if (script.branding === 'hang') return new Promise<Response>(() => undefined)
        return new Response(JSON.stringify(script.branding), { status: 200 })
      }

      if (url.includes('/api/v1/kiosk/pair/claim')) {
        script.claimCalls += 1
        if (script.claimCalls >= script.pairAtCall) {
          return new Response(
            JSON.stringify({
              status: 'paired',
              device: { uuid: DEVICE_UUID, name: 'Recepcion' },
              token: { value: TOKEN_VALUE, expires_at: '2026-12-06T10:07:00Z' },
            }),
            { status: 200 },
          )
        }
        return new Response(JSON.stringify({ status: 'pending' }), { status: 200 })
      }

      if (url.includes('/api/v1/kiosk/pair')) {
        script.pairRequests += 1
        return new Response(
          JSON.stringify({
            pairing_id: PAIRING_ID,
            pairing_secret: PAIRING_SECRET,
            code: script.pairRequests === 1 ? '483921' : '750314',
            expires_at: new Date(Date.now() + 10 * 60_000).toISOString(),
            // Cadencia rapida para que la prueba no dependa de esperas reales largas.
            poll_interval_seconds: 1,
          }),
          { status: 201 },
        )
      }

      throw new TypeError('Failed to fetch')
    }),
  )
}

async function render() {
  const router = createRouter({ history: createWebHistory(), routes })
  await router.push('/pair')
  await router.isReady()

  const wrapper = mount(PairingView, { global: { plugins: [createAppI18n('es'), router] } })
  return { wrapper, router }
}

afterEach(() => {
  localStorage.removeItem('kronoqr.kiosk.branding')
  document.documentElement.style.cssText = ''
  vi.unstubAllGlobals()
  localStorage.removeItem('kronoqr.kiosk.device_token')
  localStorage.removeItem('kronoqr.kiosk.device_id')
  localStorage.removeItem('kronoqr.kiosk.device_token_expires_at')
  localStorage.removeItem('kronoqr.kiosk.device_name')
})

describe('pantalla de emparejamiento — RF-PD-06', () => {
  it('pide un codigo al montarse y lo muestra grande y agrupado', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0 })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('483 921'),
    )

    wrapper.unmount()
  })

  it('sondea, y al confirmarse guarda el token y navega a la pantalla de fichaje', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 2, pairRequests: 0 })
    const { wrapper, router } = await render()

    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'), {
      timeout: 5_000,
      interval: 50,
    })

    expect(localStorage.getItem('kronoqr.kiosk.device_token')).toBe(TOKEN_VALUE)
    expect(localStorage.getItem('kronoqr.kiosk.device_id')).toBe(DEVICE_UUID)
    // Tarea 3.3 (RF-KI-08): la pantalla de diagnostico necesita la caducidad
    // del token y el nombre del quiosco, solo para mostrarlos.
    expect(localStorage.getItem('kronoqr.kiosk.device_token_expires_at')).toBe(
      '2026-12-06T10:07:00Z',
    )
    expect(localStorage.getItem('kronoqr.kiosk.device_name')).toBe('Recepcion')

    wrapper.unmount()
  })

  it('«Generar otro codigo» pide una solicitud nueva sin esperar a que caduque', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0 })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('483 921'),
    )

    await wrapper.get('[data-testid="pairing-new-code"]').trigger('click')

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('750 314'),
    )

    wrapper.unmount()
  })

  it('todos los objetivos tactiles cumplen el minimo de 48 px (RF-KI-06)', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0 })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pairing-new-code"]').exists()).toBe(true),
    )

    for (const button of wrapper.findAll('button')) {
      expect(button.classes()).toContain('kiosk-touch')
    }

    wrapper.unmount()
  })

  it('el estado se anuncia a un lector de pantalla', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0 })
    const { wrapper } = await render()

    const status = wrapper.get('[data-testid="pairing-status"]')
    expect(status.attributes('role')).toBe('status')
    expect(status.attributes('aria-live')).toBe('polite')

    wrapper.unmount()
  })
})

describe('marca del cliente antes de vincular — RF-PD-08, DC11', () => {
  const HOTEL = {
    application_name: 'Hotel Marina',
    accent_color: '#0B6E4F',
    logo_url: null,
    locales: { default: 'es', available: ['es', 'en'] },
    privacy_notice: { controller_name: null, policy_url: null, retention_years: 4 },
  }

  it('pide la marca publica y muestra el nombre del cliente, sin esperar al emparejamiento', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0, branding: HOTEL })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="brand-name"]').text()).toBe('Hotel Marina'),
    )
    expect(document.title).toContain('Hotel Marina')
    // Se guarda la copia para el proximo arranque sin red.
    expect(localStorage.getItem('kronoqr.kiosk.branding')).not.toBeNull()
    // Y el codigo aparece igualmente.
    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('483 921'),
    )

    wrapper.unmount()
  })

  it('si la marca falla, sigue con la del producto y el emparejamiento no se detiene', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0, branding: 'fail' })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('483 921'),
    )
    expect(wrapper.get('[data-testid="brand-name"]').text()).toBe('KronoQR')
    expect(localStorage.getItem('kronoqr.kiosk.branding')).toBeNull()

    wrapper.unmount()
  })

  it('si la marca no contesta nunca, el codigo se muestra igual', async () => {
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0, branding: 'hang' })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('483 921'),
    )

    wrapper.unmount()
  })

  it('una marca invalida se ignora y no se guarda', async () => {
    installFetch({
      claimCalls: 0,
      pairAtCall: 999,
      pairRequests: 0,
      branding: { application_name: 42, accent_color: 'rojo' },
    })
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="pairing-code"]').text()).toBe('483 921'),
    )
    expect(wrapper.get('[data-testid="brand-name"]').text()).toBe('KronoQR')
    expect(localStorage.getItem('kronoqr.kiosk.branding')).toBeNull()

    wrapper.unmount()
  })

  it('con copia guardada, la pinta de inmediato aunque la red falle', async () => {
    localStorage.setItem('kronoqr.kiosk.branding', JSON.stringify(HOTEL))
    installFetch({ claimCalls: 0, pairAtCall: 999, pairRequests: 0, branding: 'fail' })
    const { wrapper } = await render()

    expect(wrapper.get('[data-testid="brand-name"]').text()).toBe('Hotel Marina')

    wrapper.unmount()
  })
})
