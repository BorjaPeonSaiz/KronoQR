// @vitest-environment jsdom
// R5-KI-02 — muchos ciclos de ocultar y mostrar, no uno (RF-KI-02; doc 03 §4.3:
// «una fuga aqui tumba la tablet a media tarde y no aparece en pruebas de 5
// minutos»).
//
// Las pruebas de liberacion de `useQrScanner.spec.ts` son de un solo ciclo y
// `cameraLifecycleRace.spec.ts` llega a tres reinicios. Un turno de 12 h con la
// pantalla apagandose cada pausa son decenas de ciclos, y lo que se acumula -una
// pista que no se para, un perro guardian que no se limpia, una escucha de mas-
// solo se ve sumando. Aqui son 300 ciclos con camara y decodificador simulados,
// contando lo que sigue vivo. La prueba de 12 h en la tablet real es la del
// runbook (`alta-nuevo-quiosco.md` §4.5); esta es la que corre en cada CI.

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { ref } from 'vue'
import { useQrScanner } from '@/features/scan/composables/useQrScanner'
import { withSetup } from './support/withSetup'

const SOAK_CYCLES = 300

const decoderStops: Array<ReturnType<typeof vi.fn>> = []

vi.mock('@zxing/browser', () => ({
  BrowserQRCodeReader: class {
    async decodeFromStream() {
      const stop = vi.fn()
      decoderStops.push(stop)
      return { stop }
    }
  },
}))

/** Camara simulada que sabe cuantas pistas siguen vivas y cual fue el maximo a la vez. */
const camera = { live: 0, peakLive: 0, opened: 0 }

function installCamera(): void {
  Object.defineProperty(navigator, 'mediaDevices', {
    configurable: true,
    value: {
      getUserMedia: vi.fn(async () => {
        camera.opened += 1
        camera.live += 1
        camera.peakLive = Math.max(camera.peakLive, camera.live)
        let stopped = false
        const track = {
          kind: 'video',
          stop: () => {
            if (stopped) return
            stopped = true
            camera.live -= 1
          },
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
}

function setVisibility(value: DocumentVisibilityState): void {
  Object.defineProperty(document, 'visibilityState', { value, configurable: true })
  document.dispatchEvent(new Event('visibilitychange'))
}

beforeEach(() => {
  decoderStops.length = 0
  camera.live = 0
  camera.peakLive = 0
  camera.opened = 0
  installCamera()
  Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
})

afterEach(() => {
  Object.defineProperty(navigator, 'mediaDevices', { value: undefined, configurable: true })
  vi.restoreAllMocks()
})

/** Monta el escaner, lo arranca y le hace `SOAK_CYCLES` ocultaciones y vueltas. */
async function soak() {
  const video = ref<HTMLVideoElement | null>(document.createElement('video'))
  const scanner = withSetup(() =>
    useQrScanner({ video, onDecoded: vi.fn(), onDiagnostic: vi.fn() }),
  )
  await scanner.result.start()
  for (let cycle = 0; cycle < SOAK_CYCLES; cycle += 1) {
    setVisibility('hidden')
    setVisibility('visible')
    // `start()` se une al arranque que acaba de lanzar la vuelta a la vista: se
    // espera a ESE, sin sondear.
    await scanner.result.start()
  }
  return scanner
}

describe(`R5-KI-02 — ${SOAK_CYCLES} ciclos de ocultar y mostrar el escaner (RF-KI-02)`, () => {
  it('nunca hay dos camaras abiertas a la vez', async () => {
    const scanner = await soak()

    expect(camera.peakLive).toBe(1)
    expect(scanner.result.state.value).toBe('scanning')
    scanner.wrapper.unmount()
  })

  it('cada vuelta abre exactamente una camara: 1 al arrancar y 1 por ciclo', async () => {
    const scanner = await soak()

    expect(camera.opened).toBe(301)
    scanner.wrapper.unmount()
  })

  it('al desmontar no queda ninguna pista viva ni ningun bucle de decodificacion', async () => {
    const scanner = await soak()

    scanner.wrapper.unmount()

    expect(camera.live).toBe(0)
    expect(decoderStops).toHaveLength(301)
    expect(decoderStops.filter((stop) => stop.mock.calls.length === 0)).toEqual([])
  })

  it('el perro guardian se limpia en cada ciclo: tantos intervalos parados como creados', async () => {
    const created = vi.spyOn(globalThis, 'setInterval')
    const cleared = vi.spyOn(globalThis, 'clearInterval')
    const scanner = await soak()

    scanner.wrapper.unmount()

    expect(created).toHaveBeenCalledTimes(301)
    expect(cleared).toHaveBeenCalledTimes(301)
  })

  it('la escucha de visibilidad es una sola y se quita al desmontar', async () => {
    const added = vi.spyOn(document, 'addEventListener')
    const removed = vi.spyOn(document, 'removeEventListener')
    const scanner = await soak()

    scanner.wrapper.unmount()

    expect(added.mock.calls.filter(([type]) => type === 'visibilitychange')).toHaveLength(1)
    expect(removed.mock.calls.filter(([type]) => type === 'visibilitychange')).toHaveLength(1)
  })
})
