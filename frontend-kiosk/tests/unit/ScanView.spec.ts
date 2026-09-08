import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ScanView from '@/features/scan/ui/ScanView.vue'
import { createAppI18n } from '@/shared/i18n'

type DecodeCallback = (result: { getText: () => string } | undefined, error?: unknown) => void

const decodeCallbacks: DecodeCallback[] = []
const trackStops: Array<ReturnType<typeof vi.fn>> = []
let torchCapable = false

vi.mock('@zxing/browser', () => ({
  BrowserQRCodeReader: class {
    async decodeFromStream(
      _stream: MediaStream,
      _preview: HTMLVideoElement,
      callback: DecodeCallback,
    ) {
      decodeCallbacks.push(callback)
      return { stop: vi.fn() }
    }
  },
}))

function installCamera(available = true): void {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: vi.fn(async () => {
        if (!available) throw new DOMException('denied', 'NotAllowedError')
        const stop = vi.fn()
        trackStops.push(stop)
        const track = {
          kind: 'video',
          stop,
          applyConstraints: vi.fn(async () => undefined),
          getCapabilities: vi.fn(() => (torchCapable ? { torch: true } : {})),
        }
        return {
          getTracks: () => [track],
          getVideoTracks: () => [track],
        } as unknown as MediaStream
      }),
    },
  })
}

function render() {
  return mount(ScanView, { global: { plugins: [createAppI18n('es')] } })
}

beforeEach(() => {
  decodeCallbacks.length = 0
  trackStops.length = 0
  torchCapable = false
  installCamera()
  Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
  // Ninguna prueba de este fichero arranca con una copia de la marca de otra
  // (`useBranding` guarda en `localStorage`, RF-PD-08): sin esto, la que
  // escribe una marca de cliente contaminaria a las que van despues.
  localStorage.clear()
  // Sin servidor: todo escaneo queda «pendiente», que es el caso que importa.
  vi.stubGlobal(
    'fetch',
    vi.fn(async () => {
      throw new TypeError('Failed to fetch')
    }),
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
})

describe('pantalla del quiosco', () => {
  it('ensena el aviso de privacidad SIEMPRE, sin tocar nada (RF-KI-09)', () => {
    const wrapper = render()

    expect(wrapper.find('[data-testid="privacy-notice"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('ensena el estado de conexion de forma permanente (RF-KI-04)', () => {
    const wrapper = render()

    expect(wrapper.find('[role="status"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('ofrece los dos idiomas (RF-KI-05)', () => {
    const wrapper = render()

    expect(wrapper.find('[role="group"]').text()).toContain('English')
    wrapper.unmount()
  })

  it('invita a acercar la tarjeta sin pedir ninguna interaccion', () => {
    const wrapper = render()

    expect(wrapper.get('[data-testid="scan-idle"]').text()).toContain('Acerca tu tarjeta')
    wrapper.unmount()
  })

  it('confirma en pantalla un fichaje aunque no haya servidor (regla dura 19)', async () => {
    const wrapper = render()
    await vi.waitFor(() => expect(decodeCallbacks).toHaveLength(1))

    decodeCallbacks[0]?.({ getText: () => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa' })
    await wrapper.vm.$nextTick()

    const panel = wrapper.get('[data-testid="scan-confirmation"]')
    expect(panel.attributes('data-kind')).toBe('pending')
    expect(panel.text()).toContain('Pendiente de validar')
    wrapper.unmount()
  })

  it('confirma en menos de 300 ms (RNF-P-03)', async () => {
    const wrapper = render()
    await vi.waitFor(() => expect(decodeCallbacks).toHaveLength(1))

    decodeCallbacks[0]?.({ getText: () => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa' })
    await wrapper.vm.$nextTick()

    const measured = Number(wrapper.get('[data-testid="scan-latency-ms"]').text())
    expect(measured).toBeLessThan(300)
    wrapper.unmount()
  })

  it('rechaza en generico lo que no es una tarjeta, sin decir por que', async () => {
    const wrapper = render()
    await vi.waitFor(() => expect(decodeCallbacks).toHaveLength(1))

    decodeCallbacks[0]?.({ getText: () => 'https://wifi.hotel.example' })
    await wrapper.vm.$nextTick()

    const panel = wrapper.get('[data-testid="scan-confirmation"]')
    expect(panel.attributes('data-kind')).toBe('unreadable')
    expect(panel.text()).toContain('Código no válido')
    wrapper.unmount()
  })

  it('avisa si no hay camara, pero no deja la pantalla muerta', async () => {
    installCamera(false)
    const wrapper = render()

    await vi.waitFor(() =>
      expect(wrapper.find('[data-testid="camera-failure"]').exists()).toBe(true),
    )
    expect(wrapper.get('[data-testid="camera-failure"]').text()).toContain('Avisa a recepción')
    // Y el aviso de privacidad sigue ahi: es un requisito legal, no un adorno.
    expect(wrapper.find('[data-testid="privacy-notice"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('solo ofrece la linterna si el aparato la tiene', async () => {
    const withoutTorch = render()
    await vi.waitFor(() => expect(decodeCallbacks).toHaveLength(1))
    expect(withoutTorch.find('[data-testid="torch-toggle"]').exists()).toBe(false)
    withoutTorch.unmount()

    torchCapable = true
    const withTorch = render()
    await vi.waitFor(() =>
      expect(withTorch.find('[data-testid="torch-toggle"]').exists()).toBe(true),
    )
    withTorch.unmount()
  })

  it('LIBERA la camara al desmontar la pantalla, ya arrancada', async () => {
    const wrapper = render()
    await vi.waitFor(() => expect(decodeCallbacks).toHaveLength(1))

    wrapper.unmount()

    expect(trackStops[0]).toHaveBeenCalled()
  })

  it('LIBERA la camara aunque el desmontaje pille el arranque a medias', async () => {
    const wrapper = render()
    // Se desmonta con `getUserMedia` todavia resolviendo: el caso que deja el
    // sensor encendido sin que nadie lo note.
    await vi.waitFor(() => expect(trackStops).toHaveLength(1))
    wrapper.unmount()

    await vi.waitFor(() => expect(trackStops[0]).toHaveBeenCalled())
  })

  it('ensena el nombre del producto en la cabecera mientras no llega marca del cliente (RF-PD-08)', () => {
    const wrapper = render()

    expect(wrapper.get('[data-testid="brand-name"]').text()).toBe('KronoQR')
    expect(wrapper.find('[data-testid="brand-logo"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('sustituye la marca del producto por la del cliente en cuanto contesta el servidor (RF-PD-08)', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: unknown) => {
        const url = typeof input === 'string' ? input : (input as Request).url
        if (url.includes('/api/v1/branding')) {
          return new Response(
            JSON.stringify({
              application_name: 'Hotel Marina',
              accent_color: null,
              logo_url: null,
              locales: { default: 'es', available: ['es'] },
            }),
            { status: 200 },
          )
        }
        throw new TypeError('Failed to fetch')
      }),
    )

    const wrapper = render()

    await vi.waitFor(() =>
      expect(wrapper.get('[data-testid="brand-name"]').text()).toBe('Hotel Marina'),
    )
    // Con «Hotel Marina» la instalacion solo ofrece espanol: no hay nada que
    // elegir, y el selector no se pinta en absoluto (doc 06 regla 8).
    expect(wrapper.find('[role="group"]').exists()).toBe(false)

    // La marca del cliente NO se queda en la cabecera: la confirmacion de un
    // fichaje tambien la lleva (bloqueante de revision: `ScanConfirmationPanel`
    // no recibia la prop `branding` desde aqui).
    await vi.waitFor(() => expect(decodeCallbacks).toHaveLength(1))
    decodeCallbacks[0]?.({ getText: () => 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa' })
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="confirmation-brand-name"]').text()).toBe('Hotel Marina')
    wrapper.unmount()
  })
})
