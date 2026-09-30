// PIN-03 de cara al empleado: «no puedo sellar» y «codigo no valido» son
// cosas distintas y se dicen distinto. Y el boton del PIN no se ofrece si no se
// puede sellar.

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createWebHistory } from 'vue-router'
import { disposeOfflineQueue } from '@/features/offline/useOfflineQueue'
import { resetPinSealingStatus } from '@/features/pin/composables/usePinSealingStatus'
import PinView from '@/features/pin/ui/PinView.vue'
import ScanConfirmationPanel from '@/features/scan/ui/ScanConfirmationPanel.vue'
import ScanView from '@/features/scan/ui/ScanView.vue'
import { routes } from '@/router'
import { createAppI18n } from '@/shared/i18n'
import type { AppLocale } from '@/shared/i18n'

let sealing: 'ready' | 'unavailable' = 'ready'

vi.mock('@/features/pin/infrastructure/pinSealing', async (importOriginal) => {
  const original = await importOriginal<typeof import('@/features/pin/infrastructure/pinSealing')>()
  return { ...original, warmUpSealing: async () => sealing }
})

vi.mock('@zxing/browser', () => ({
  BrowserQRCodeReader: class {
    async decodeFromStream() {
      return { stop: vi.fn() }
    }
  },
}))

function installFetch(): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL) => {
      const url = String(input)
      if (url.includes('/api/v1/kiosk/roster')) {
        return new Response(
          JSON.stringify({
            generated_at: '2026-08-14T04:00:00.000Z',
            entries: [],
            pin_sealing_public_key: 'AAAA',
          }),
          { status: 200 },
        )
      }
      if (url.includes('/api/v1/kiosk/heartbeat')) {
        return new Response(JSON.stringify({ server_time: new Date().toISOString() }), {
          status: 200,
        })
      }
      throw new TypeError('Failed to fetch')
    }),
  )
}

async function withRouter() {
  localStorage.setItem('kronoqr.kiosk.device_token', 'device-token-de-prueba')
  const router = createRouter({ history: createWebHistory(), routes })
  await router.push('/pin')
  await router.isReady()
  return router
}

beforeEach(async () => {
  sealing = 'ready'
  resetPinSealingStatus()
  installFetch()
  await disposeOfflineQueue()
})

afterEach(async () => {
  vi.unstubAllGlobals()
  localStorage.removeItem('kronoqr.kiosk.device_token')
  await disposeOfflineQueue()
})

describe('«PIN no disponible» frente a «codigo no valido» (PIN-03)', () => {
  const at = new Date(2026, 7, 14, 7, 2, 31)

  function panel(kind: 'unavailable' | 'rejected', locale: AppLocale) {
    return mount(ScanConfirmationPanel, {
      props: { confirmation: { kind, scanId: 's1', occurredAt: at } },
      global: { plugins: [createAppI18n(locale)] },
    })
  }

  it.each(['es', 'en'] as const)('dice otra cosa que el rechazo generico (%s)', (locale) => {
    const unavailable = panel('unavailable', locale)
    const rejected = panel('rejected', locale)

    expect(unavailable.get('[data-testid="confirmation-headline"]').text()).not.toBe(
      rejected.get('[data-testid="confirmation-headline"]').text(),
    )
    expect(unavailable.get('[data-testid="confirmation-detail"]').text()).not.toBe(
      rejected.get('[data-testid="confirmation-detail"]').text(),
    )
  })

  it('en espanol manda a la tarjeta o a recepcion, y suena como error', () => {
    const wrapper = panel('unavailable', 'es')

    expect(wrapper.get('[data-testid="confirmation-detail"]').text()).toContain('recepción')
    expect(wrapper.get('[data-testid="scan-confirmation"]').attributes('data-variant')).toBe(
      'error',
    )
  })
})

describe('el boton del PIN solo existe si se puede sellar (PIN-03)', () => {
  it('con sellado disponible, la pantalla de tarjeta ofrece el PIN', async () => {
    const router = await withRouter()
    const wrapper = mount(ScanView, { global: { plugins: [createAppI18n('es'), router] } })

    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid^="pin-entry-link"]').exists()).toBe(true),
    )
    wrapper.unmount()
  })

  it('sin sellado, la pantalla de tarjeta NO ofrece el PIN aunque el padron traiga la clave', async () => {
    sealing = 'unavailable'
    const router = await withRouter()
    const wrapper = mount(ScanView, { global: { plugins: [createAppI18n('es'), router] } })

    // Da tiempo a que el padron y la comprobacion hayan terminado.
    await new Promise((resolve) => setTimeout(resolve, 100))

    expect(wrapper.find('[data-testid^="pin-entry-link"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('la pantalla del PIN avisa ANTES de pedir codigo y digitos', async () => {
    sealing = 'unavailable'
    const router = await withRouter()
    const wrapper = mount(PinView, { global: { plugins: [createAppI18n('es'), router] } })

    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-unavailable"]').exists()).toBe(true),
    )
    expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="pin-unavailable"]').text()).toContain('recepción')
    wrapper.unmount()
  })
})
