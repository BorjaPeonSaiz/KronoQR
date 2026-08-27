import { describe, expect, it, vi } from 'vitest'
import { useScanSession } from '@/features/scan/composables/useScanSession'
import type { ScanPipeline } from '@/features/scan/application/scanPipeline'
import type { FeedbackTone, ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { CONFIRMATION_DISPLAY_MS } from '@/features/scan/domain/scanOutcome'
import type { ScanSound } from '@/features/scan/composables/useScanSound'

const at = new Date('2026-08-14T05:02:00.000Z')

function pending(scanId: string): ScanConfirmation {
  return { kind: 'pending', scanId, occurredAt: at, displayName: 'Lucia G.' }
}

function accepted(scanId: string): ScanConfirmation {
  return {
    kind: 'accepted',
    scanId,
    occurredAt: at,
    action: 'clock_in',
    displayName: 'Lucia G.',
    workedMinutes: 0,
    workDate: '2026-08-14',
  }
}

function soundSpy(): { sound: ScanSound; played: FeedbackTone[] } {
  const played: FeedbackTone[] = []
  return {
    played,
    sound: { play: (tone) => played.push(tone), unlock: () => undefined, dispose: () => undefined },
  }
}

function pipelineReturning(...results: Array<ScanConfirmation | null>): ScanPipeline {
  let index = 0
  return {
    handleDecoded: () => {
      const next = results[index] ?? null
      index += 1
      return next
    },
  }
}

describe('sesion de escaneo', () => {
  it('pinta y hace sonar la confirmacion en el mismo turno del bucle', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({ pipeline: pipelineReturning(pending('s1')), sound })

    session.accept('FH1.a3.token.sig')

    expect(session.confirmation.value?.scanId).toBe('s1')
    expect(played).toEqual(['pending'])
  })

  it('mide la latencia del camino critico (RNF-P-03: < 300 ms)', () => {
    const { sound } = soundSpy()
    let clockMs = 1000
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1')),
      sound,
      monotonicNow: () => clockMs++,
    })

    session.accept('FH1.a3.token.sig')

    expect(session.lastLatencyMs.value).not.toBeNull()
    expect(session.lastLatencyMs.value ?? Number.MAX_SAFE_INTEGER).toBeLessThan(300)
  })

  it('no ensena nada ni suena si la lectura fue una repeticion', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({ pipeline: pipelineReturning(null), sound })

    session.accept('FH1.a3.token.sig')

    expect(session.confirmation.value).toBeNull()
    expect(played).toEqual([])
  })

  it('actualiza la pantalla con el total real cuando responde el servidor', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({ pipeline: pipelineReturning(pending('s1')), sound })

    session.accept('FH1.a3.token.sig')
    session.settle(accepted('s1'))

    expect(session.confirmation.value?.kind).toBe('accepted')
    // Un unico pitido por fichaje: el segundo llegaria cuando la persona ya se
    // ha dado la vuelta.
    expect(played).toEqual(['pending'])
  })

  it('una respuesta tardia NO pisa la confirmacion de la siguiente persona', () => {
    const { sound } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1'), pending('s2')),
      sound,
    })

    session.accept('FH1.a3.uno.sig')
    session.accept('FH1.a3.dos.sig')
    // Llega ahora la respuesta del PRIMERO.
    session.settle(accepted('s1'))

    expect(session.confirmation.value?.scanId).toBe('s2')
  })

  it('retira la confirmacion sola, para no hacer esperar al siguiente', () => {
    vi.useFakeTimers()
    const { sound } = soundSpy()
    const session = useScanSession({ pipeline: pipelineReturning(pending('s1')), sound })

    session.accept('FH1.a3.token.sig')
    vi.advanceTimersByTime(CONFIRMATION_DISPLAY_MS.pending + 10)

    expect(session.confirmation.value).toBeNull()
    vi.useRealTimers()
  })

  it('el temporizador del anterior no borra la confirmacion del siguiente', () => {
    vi.useFakeTimers()
    const { sound } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1'), pending('s2')),
      sound,
    })

    session.accept('FH1.a3.uno.sig')
    vi.advanceTimersByTime(CONFIRMATION_DISPLAY_MS.pending - 50)
    session.accept('FH1.a3.dos.sig')
    vi.advanceTimersByTime(100)

    expect(session.confirmation.value?.scanId).toBe('s2')
    vi.useRealTimers()
  })
})
