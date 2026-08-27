// Pantalla del fichaje de respaldo por PIN (tarea 1.12, RF-AT-11).
//
// Mismo patron que `ScanView.spec.ts`: se monta la pantalla de verdad, con
// `fetch` global sustituido por un enrutador por URL, y se comprueba lo que
// ve y hace un empleado -- no los internos de la cola, que ya tienen su
// propia bateria de pruebas.

import { mount } from '@vue/test-utils'
import sodium from 'libsodium-wrappers'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createRouter, createWebHistory } from 'vue-router'
import PinView from '@/features/pin/ui/PinView.vue'
import { routes } from '@/router'
import { disposeOfflineQueue } from '@/features/offline/useOfflineQueue'
import { createAppI18n } from '@/shared/i18n'

let publicKeyBase64: string

beforeAll(async () => {
  await sodium.ready
  const keypair = sodium.crypto_box_keypair()
  publicKeyBase64 = sodium.to_base64(keypair.publicKey, sodium.base64_variants.ORIGINAL)
})

function installFetch(pinSealingPublicKey: string | null): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = String(input)

      if (url.includes('/api/v1/kiosk/roster')) {
        return new Response(
          JSON.stringify({
            generated_at: '2026-08-14T04:00:00.000Z',
            entries: [],
            pin_sealing_public_key: pinSealingPublicKey,
          }),
          { status: 200 },
        )
      }
      if (url.includes('/api/v1/kiosk/heartbeat')) {
        return new Response(JSON.stringify({ server_time: new Date().toISOString() }), {
          status: 200,
        })
      }
      if (url.includes('/api/v1/scan/pin')) {
        const body = JSON.parse(String(init?.body ?? '{}')) as {
          scan_id: string
          occurred_at: string
        }
        return new Response(
          JSON.stringify({
            scan_id: body.scan_id,
            action: 'clock_in',
            employee_display_name: 'Lucia G.',
            work_date: body.occurred_at.slice(0, 10),
            occurred_at: body.occurred_at,
            recorded_at: new Date().toISOString(),
            worked_minutes: 0,
          }),
          { status: 200 },
        )
      }
      throw new TypeError('Failed to fetch')
    }),
  )
}

async function render() {
  // Sin token de dispositivo el padron no se pide NUNCA (RL-12, ver
  // `cachedRoster.spec.ts`): el quiosco de pruebas tiene que estar
  // «emparejado» para que la clave publica del PIN llegue a alguna parte.
  localStorage.setItem('kronoqr.kiosk.device_token', 'device-token-de-prueba')

  const router = createRouter({ history: createWebHistory(), routes })
  await router.push('/pin')
  await router.isReady()

  const wrapper = mount(PinView, { global: { plugins: [createAppI18n('es'), router] } })
  return { wrapper, router }
}

/** Pulsa el boton del teclado numerico cuyo texto es exactamente ese digito. */
async function pressDigits(wrapper: Awaited<ReturnType<typeof render>>['wrapper'], pin: string) {
  for (const digit of pin) {
    const button = wrapper.findAll('button').find((candidate) => candidate.text() === digit)
    await button?.trigger('click')
  }
}

beforeEach(async () => {
  await disposeOfflineQueue()
})

afterEach(async () => {
  vi.unstubAllGlobals()
  localStorage.removeItem('kronoqr.kiosk.device_token')
  await disposeOfflineQueue()
})

describe('pantalla de PIN — visibilidad (ADR-017)', () => {
  it('no ofrece nada y vuelve a la pantalla de tarjeta si la instalacion no tiene PIN', async () => {
    installFetch(null)
    const { wrapper, router } = await render()

    // El componente esta montado suelto (sin `<RouterView>`), asi que lo que
    // se comprueba es la REDIRECCION que el ha pedido, no que desaparezca de
    // un DOM que aqui nadie controla: eso es lo que hace de verdad el router
    // de la aplicacion real.
    await vi.waitFor(() => expect(router.currentRoute.value.name).toBe('home'))

    wrapper.unmount()
  })

  it('redirige de inmediato en una segunda visita, sin esperar a otro ciclo del padron', async () => {
    // Repite el caso de revision: `pinSealingKnown` no puede inferirse de si
    // `pinSealingPublicKey` es `null`, porque una instalacion sin PIN que YA
    // resolvio el padron (esta pantalla no es la primera vez que se visita)
    // tiene exactamente el mismo valor `null` que una que todavia no lo sabe.
    installFetch(null)

    // Primera visita: agota el ciclo de arranque del controlador (singleton
    // de la tablet, no se destruye entre navegaciones dentro de la SPA).
    const first = await render()
    await vi.waitFor(() => expect(first.router.currentRoute.value.name).toBe('home'))
    first.wrapper.unmount()

    // Segunda visita SIN pasar por `disposeOfflineQueue()`: el mismo
    // controlador ya sabe, desde antes de montarse esta pantalla, que la
    // instalacion no ofrece PIN. Si `pinSealingKnown` se infiriera de la
    // clave (el fallo corregido), esta pantalla se quedaria con el teclado
    // inactivo hasta el siguiente refresco de `ROSTER_REFRESH_MS` (30 min),
    // que este `timeout` corto no llega a cubrir.
    const second = await render()
    await vi.waitFor(() => expect(second.router.currentRoute.value.name).toBe('home'), {
      timeout: 100,
      interval: 5,
    })
    second.wrapper.unmount()
  })
})

describe('pantalla de PIN — flujo completo (RF-AT-11)', () => {
  it('pide primero el codigo y despues el PIN, con teclado numerico dedicado', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true),
    )
    expect(wrapper.find('[data-testid="pin-step-pin"]').exists()).toBe(false)

    await wrapper.get('[data-testid="pin-code-input"]').setValue('E7QK2MXPR')
    await wrapper.get('[data-testid="pin-code-continue"]').trigger('click')

    expect(wrapper.find('[data-testid="pin-step-pin"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('no deja continuar con un codigo vacio', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()
    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true),
    )

    expect(
      wrapper.get<HTMLButtonElement>('[data-testid="pin-code-continue"]').element.disabled,
    ).toBe(true)

    wrapper.unmount()
  })

  it('«Atras» desde el paso del PIN borra lo tecleado y vuelve al codigo', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()
    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true),
    )

    await wrapper.get('[data-testid="pin-code-input"]').setValue('E7QK2MXPR')
    await wrapper.get('[data-testid="pin-code-continue"]').trigger('click')
    await pressDigits(wrapper, '483')

    await wrapper.get('[data-testid="pin-back-to-code"]').trigger('click')

    expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true)
    // El boton de confirmar solo se activa con los 6 digitos: si el buffer no
    // se hubiera vaciado, volver a este paso y luego al del PIN lo dejaria
    // completo con menos de 6 pulsaciones.
    await wrapper.get('[data-testid="pin-code-continue"]').trigger('click')
    expect(wrapper.get<HTMLButtonElement>('[data-testid="pin-confirm"]').element.disabled).toBe(
      true,
    )

    wrapper.unmount()
  })

  it('el PIN nunca se ve en pantalla: se pintan puntos, no cifras', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()
    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true),
    )

    await wrapper.get('[data-testid="pin-code-input"]').setValue('E7QK2MXPR')
    await wrapper.get('[data-testid="pin-code-continue"]').trigger('click')
    await pressDigits(wrapper, '483920')

    expect(wrapper.text()).not.toContain('483920')
    wrapper.unmount()
  })

  it('confirma en pantalla y encola el fichaje, sin esperar a la respuesta', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()
    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true),
    )

    await wrapper.get('[data-testid="pin-code-input"]').setValue('E7QK2MXPR')
    await wrapper.get('[data-testid="pin-code-continue"]').trigger('click')
    await pressDigits(wrapper, '483920')

    // «Confirmar» se activa con los 6 digitos Y la clave publica ya cargada
    // (esta ultima puede tardar el primer instante tras un arranque en frio,
    // ver `canConfirm` en el componente).
    await vi.waitFor(() =>
      expect(wrapper.get<HTMLButtonElement>('[data-testid="pin-confirm"]').element.disabled).toBe(
        false,
      ),
    )

    await wrapper.get('[data-testid="pin-confirm"]').trigger('click')

    // `confirm()` sella (async, aunque sin red) antes de encolar: una sola
    // `$nextTick()` no basta para esperar esa cadena completa. El mock de
    // `fetch` de esta prueba resuelve tan rapido que el paso «pendiente»
    // puede ya haberse asentado en «aceptado» para cuando se comprueba: lo
    // que importa aqui es que hay confirmacion, no en que fotograma exacto.
    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="scan-confirmation"]').exists()).toBe(true),
    )
    const panel = wrapper.get('[data-testid="scan-confirmation"]')
    expect(['pending', 'accepted']).toContain(panel.attributes('data-kind'))

    wrapper.unmount()
  })

  it('todos los objetivos tactiles cumplen el minimo de 48 px (RF-KI-06)', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()
    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="pin-step-code"]').exists()).toBe(true),
    )

    for (const button of wrapper.findAll('button, a')) {
      expect(button.classes()).toContain('kiosk-touch')
    }

    await wrapper.get('[data-testid="pin-code-input"]').setValue('E7QK2MXPR')
    await wrapper.get('[data-testid="pin-code-continue"]').trigger('click')

    for (const button of wrapper.findAll('button, a')) {
      expect(button.classes()).toContain('kiosk-touch')
    }

    wrapper.unmount()
  })

  it('el aviso de privacidad sigue en pantalla (RF-KI-09)', async () => {
    installFetch(publicKeyBase64)
    const { wrapper } = await render()

    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="privacy-notice"]').exists()).toBe(true),
    )
    wrapper.unmount()
  })
})
