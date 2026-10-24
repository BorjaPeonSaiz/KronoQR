// @vitest-environment jsdom
// KT5 — el `import()` de `@zxing/browser` que FALLA (RF-KI-02, regla dura 19).
//
// El decodificador es un trozo aparte que llega por `import()` (Anexo A). Si ese
// trozo no carga -red cortada en el primer arranque, un trozo que ya no existe
// tras una actualizacion, una cache corrupta-, la camara ya esta abierta: lo que
// se protege aqui es que se suelte, que el escaner diga «no disponible» en vez
// de quedarse en «arrancando» para siempre, y que se avise con un codigo propio
// para que el panel lo vea. Fichero aparte porque el `vi.mock` es de todo el
// fichero: en `useQrScanner.spec.ts` el decodificador carga bien.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useQrScanner } from '@/features/scan/composables/useQrScanner'
import { withSetup } from './support/withSetup'

vi.mock('@zxing/browser', () => {
  throw new TypeError('Failed to fetch dynamically imported module: /assets/esm-C15lTpaU.js')
})

const trackStops: Array<ReturnType<typeof vi.fn>> = []

beforeEach(() => {
  trackStops.length = 0
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: vi.fn(async () => {
        const stop = vi.fn()
        trackStops.push(stop)
        const track = {
          kind: 'video',
          stop,
          applyConstraints: vi.fn(async () => undefined),
          getCapabilities: vi.fn(() => ({})),
        }
        return {
          getTracks: () => [track],
          getVideoTracks: () => [track],
        } as unknown as MediaStream
      }),
    },
  })
  Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
})

afterEach(() => {
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
})

function mountScanner() {
  const onDiagnostic = vi.fn()
  const video = ref<HTMLVideoElement | null>(document.createElement('video'))
  return {
    onDiagnostic,
    ...withSetup(() => useQrScanner({ video, onDecoded: vi.fn(), onDiagnostic })),
  }
}

describe('KT5 — el decodificador no carga (RF-KI-02)', () => {
  it('el escaner queda «no disponible», no «arrancando» para siempre', async () => {
    const scanner = mountScanner()

    await scanner.result.start()

    expect(scanner.result.state.value).toBe('unavailable')
    scanner.wrapper.unmount()
  })

  it('suelta la camara que ya habia abierto: ningun sensor encendido sin bucle', async () => {
    const scanner = mountScanner()

    await scanner.result.start()

    expect(trackStops).toHaveLength(1)
    expect(trackStops[0]).toHaveBeenCalled()
    scanner.wrapper.unmount()
  })

  it('avisa con `scanner.decoder_load_failed` y el tipo de error', async () => {
    const scanner = mountScanner()

    await scanner.result.start()

    // Vitest envuelve en un `Error` lo que lanza la fabrica del `vi.mock`: es el
    // `name` que llega, como llegaria `TypeError` en el navegador.
    expect(scanner.onDiagnostic).toHaveBeenCalledTimes(1)
    expect(scanner.onDiagnostic).toHaveBeenCalledWith(
      'scanner.decoder_load_failed',
      expect.objectContaining({ error_type: 'Error' }),
    )
    scanner.wrapper.unmount()
  })

  it('al volver a mostrarse la pagina lo reintenta y, si sigue sin cargar, vuelve a soltar la camara', async () => {
    const scanner = mountScanner()
    await scanner.result.start()

    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
    document.dispatchEvent(new Event('visibilitychange'))
    await vi.waitFor(() => expect(scanner.onDiagnostic).toHaveBeenCalledTimes(2))

    expect(trackStops).toHaveLength(2)
    expect(trackStops[1]).toHaveBeenCalled()
    expect(scanner.result.state.value).toBe('unavailable')
    scanner.wrapper.unmount()
  })
})
