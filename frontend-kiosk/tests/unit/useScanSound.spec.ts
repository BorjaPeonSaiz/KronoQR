import { describe, expect, it, vi } from 'vitest'
import { createScanSound } from '@/features/scan/composables/useScanSound'
import type { FeedbackTone } from '@/features/scan/domain/scanOutcome'

interface FakeOscillator {
  type: OscillatorType
  frequencies: number[]
  connect: ReturnType<typeof vi.fn>
  disconnect: ReturnType<typeof vi.fn>
  frequency: { setValueAtTime: (value: number) => void }
  start: ReturnType<typeof vi.fn>
  stop: ReturnType<typeof vi.fn>
  onended: null | (() => void)
}

interface FakeGain {
  gain: {
    setValueAtTime: ReturnType<typeof vi.fn>
    exponentialRampToValueAtTime: ReturnType<typeof vi.fn>
  }
  connect: ReturnType<typeof vi.fn>
  disconnect: ReturnType<typeof vi.fn>
}

function recordingContext(state: AudioContextState = 'running') {
  const oscillators: FakeOscillator[] = []
  const gains: FakeGain[] = []

  const raw = {
    state,
    currentTime: 0,
    destination: {},
    resume: vi.fn(async () => undefined),
    close: vi.fn(async () => undefined),
    createOscillator(): FakeOscillator {
      const node: FakeOscillator = {
        type: 'sine',
        frequencies: [],
        frequency: { setValueAtTime: (value: number) => node.frequencies.push(value) },
        connect: vi.fn(),
        disconnect: vi.fn(),
        start: vi.fn(),
        stop: vi.fn(),
        onended: null,
      }
      oscillators.push(node)
      return node
    },
    createGain(): FakeGain {
      const node: FakeGain = {
        gain: { setValueAtTime: vi.fn(), exponentialRampToValueAtTime: vi.fn() },
        connect: vi.fn(),
        disconnect: vi.fn(),
      }
      gains.push(node)
      return node
    },
  }

  return { context: raw as unknown as AudioContext, oscillators, gains, raw }
}

function signature(tone: FeedbackTone): string {
  const recorder = recordingContext()
  createScanSound({ audioContextFactory: () => recorder.context }).play(tone)
  return recorder.oscillators.map((node) => `${node.type}:${node.frequencies.join('/')}`).join('|')
}

describe('sonidos del quiosco', () => {
  it('entrada, salida y error son inconfundibles entre si', () => {
    expect(new Set([signature('entry'), signature('exit'), signature('error')]).size).toBe(3)
  })

  it('los cinco desenlaces suenan distinto', () => {
    const all: FeedbackTone[] = ['entry', 'exit', 'pending', 'notice', 'error']
    expect(new Set(all.map(signature)).size).toBe(5)
  })

  it('la entrada sube y la salida baja: se distinguen sin mirar la pantalla', () => {
    const entry = recordingContext()
    createScanSound({ audioContextFactory: () => entry.context }).play('entry')
    const entryFreqs = entry.oscillators.flatMap((node) => node.frequencies)

    const exit = recordingContext()
    createScanSound({ audioContextFactory: () => exit.context }).play('exit')
    const exitFreqs = exit.oscillators.flatMap((node) => node.frequencies)

    expect(entryFreqs[1]).toBeGreaterThan(entryFreqs[0] ?? 0)
    expect(exitFreqs[1]).toBeLessThan(exitFreqs[0] ?? 0)
  })

  it('reutiliza UN solo AudioContext: crear uno por pitido es la fuga clasica', () => {
    const factory = vi.fn(() => recordingContext().context)
    const sound = createScanSound({ audioContextFactory: factory })

    sound.play('entry')
    sound.play('exit')
    sound.play('error')

    expect(factory).toHaveBeenCalledTimes(1)
  })

  it('desconecta cada nodo al terminar, para que el grafo no crezca en 8 h', () => {
    const recorder = recordingContext()
    createScanSound({ audioContextFactory: () => recorder.context }).play('entry')

    expect(recorder.oscillators).toHaveLength(2)
    for (const node of recorder.oscillators) {
      expect(node.onended).toBeTypeOf('function')
      node.onended?.()
      expect(node.disconnect).toHaveBeenCalled()
    }
    for (const gain of recorder.gains) {
      expect(gain.disconnect).toHaveBeenCalled()
    }
  })

  it('intenta desbloquear el audio y avisa una sola vez si sigue bloqueado', () => {
    const blocked: string[] = []
    const recorder = recordingContext('suspended')
    const sound = createScanSound({
      audioContextFactory: () => recorder.context,
      onBlocked: (context) => blocked.push(String(context['audio_state'])),
    })

    sound.play('entry')
    sound.play('exit')

    expect(recorder.raw.resume).toHaveBeenCalled()
    expect(blocked).toEqual(['suspended'])
  })

  it('en un navegador sin Web Audio no suena y NO impide fichar', () => {
    const sound = createScanSound({ audioContextFactory: () => null })

    expect(() => sound.play('entry')).not.toThrow()
    expect(() => sound.unlock()).not.toThrow()
    expect(() => sound.dispose()).not.toThrow()
  })

  it('cierra el contexto al desecharlo', () => {
    const recorder = recordingContext()
    const sound = createScanSound({ audioContextFactory: () => recorder.context })
    sound.play('entry')
    sound.dispose()

    expect(recorder.raw.close).toHaveBeenCalled()
  })
})
