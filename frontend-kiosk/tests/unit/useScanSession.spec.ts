import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useScanSession } from '@/features/scan/composables/useScanSession'
import type { ScanPipeline } from '@/features/scan/application/scanPipeline'
import type { FeedbackTone, ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import {
  CONFIRMATION_DISPLAY_MS,
  SCAN_SOUND_SERVER_WAIT_MS,
} from '@/features/scan/domain/scanOutcome'
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

function rejected(scanId: string): ScanConfirmation {
  return { kind: 'rejected', scanId, occurredAt: at }
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
  it('pinta la confirmacion en el mismo turno del bucle y MUDA (el tono espera al servidor)', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({ pipeline: pipelineReturning(pending('s1')), sound })

    session.accept('FH1.a3.token.sig')

    expect(session.confirmation.value?.scanId).toBe('s1')
    expect(played).toEqual([])
    session.dismiss()
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

// RF-AT-05, RF-KI-06, RNF-P-03 (V7-KI-01): la pantalla es inmediata y muda; el
// tono es el del desenlace del servidor dentro de SCAN_SOUND_SERVER_WAIT_MS.
describe('sonido de la sesion: tras la respuesta del servidor', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  function scanned(options: { offline?: boolean } = {}): {
    session: ReturnType<typeof useScanSession>
    played: FeedbackTone[]
  } {
    const { sound, played } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1')),
      sound,
      isOffline: () => options.offline === true,
    })
    session.accept('FH1.a3.token.sig')
    return { session, played }
  }

  it('aceptado antes del plazo: UN `entry` y nada mas, ni al vencer el plazo', () => {
    const { session, played } = scanned()

    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS - 500)
    expect(played).toEqual([])
    session.settle(accepted('s1'))
    expect(played).toEqual(['entry'])

    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS * 3)
    expect(played).toEqual(['entry'])
    session.dismiss()
  })

  it('una salida suena `exit`', () => {
    const { session, played } = scanned()

    session.settle({ ...accepted('s1'), action: 'clock_out' } as ScanConfirmation)

    expect(played).toEqual(['exit'])
    session.dismiss()
  })

  it('respuesta DESPUES del plazo: un `pending` y la pantalla se actualiza sin sonar', () => {
    const { session, played } = scanned()

    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS)
    expect(played).toEqual(['pending'])

    session.settle(accepted('s1'))
    expect(session.confirmation.value?.kind).toBe('accepted')
    expect(played).toEqual(['pending'])
    session.dismiss()
  })

  it('rechazo DESPUES del plazo: pending y error (nunca dos tonos de exito, pero el rechazo si se oye)', () => {
    const { session, played } = scanned()

    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS)
    expect(played).toEqual(['pending'])

    session.settle(rejected('s1'))
    expect(session.confirmation.value?.kind).toBe('rejected')
    expect(played).toEqual(['pending', 'error'])

    // Y una vez, no mas.
    session.settle(rejected('s1'))
    expect(played).toEqual(['pending', 'error'])
    session.dismiss()
  })

  it('un antirrebote tras el plazo sigue sin sonar (solo el rechazo es excepcion)', () => {
    const { session, played } = scanned()

    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS)
    session.settle({
      kind: 'debounced',
      scanId: 's1',
      occurredAt: at,
      displayName: 'Lucia G.',
      workedMinutes: 10,
      lastAcceptedAt: at,
    })

    expect(played).toEqual(['pending'])
    session.dismiss()
  })

  it('A en espera y B ilegible: el pending de A y el error de B no suenan en el mismo turno', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1'), {
        kind: 'unreadable',
        scanId: 'u1',
        occurredAt: at,
      }),
      sound,
    })

    session.accept('FH1.a3.uno.sig')
    vi.advanceTimersByTime(500)
    session.accept('basura')
    expect(played).toEqual(['pending'])

    vi.advanceTimersByTime(300)
    expect(played).toEqual(['pending', 'error'])
    session.dismiss()
  })

  it('dismiss cancela el tono local desfasado', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1'), {
        kind: 'unreadable',
        scanId: 'u1',
        occurredAt: at,
      }),
      sound,
    })

    session.accept('FH1.a3.uno.sig')
    session.accept('basura')
    session.dismiss()
    vi.advanceTimersByTime(1_000)

    expect(played).toEqual(['pending'])
  })

  it('sin red de antemano: `pending` en el acto, sin esperar al plazo ni repetirlo', () => {
    const { session, played } = scanned({ offline: true })

    expect(played).toEqual(['pending'])
    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS * 2)
    session.settle(accepted('s1'))
    expect(played).toEqual(['pending'])
    session.dismiss()
  })

  it('rechazo dentro del plazo: `error`, una sola vez', () => {
    const { session, played } = scanned()

    session.settle(rejected('s1'))
    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS * 2)

    expect(session.confirmation.value?.kind).toBe('rejected')
    expect(played).toEqual(['error'])
    session.dismiss()
  })

  it('antirrebote del servidor dentro del plazo: aviso (`notice`)', () => {
    const { session, played } = scanned()

    session.settle({
      kind: 'debounced',
      scanId: 's1',
      occurredAt: at,
      displayName: 'Lucia G.',
      workedMinutes: 10,
      lastAcceptedAt: at,
    })

    expect(played).toEqual(['notice'])
    session.dismiss()
  })

  it('una lectura que no es tarjeta KronoQR suena a error en el acto (decision local)', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning({ kind: 'unreadable', scanId: 'u1', occurredAt: at }),
      sound,
    })

    session.accept('no-es-una-tarjeta')

    expect(played).toEqual(['error'])
    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS * 2)
    expect(played).toEqual(['error'])
    session.dismiss()
  })

  it('una respuesta de otro escaneo no suena ni cambia la espera del actual', () => {
    const { session, played } = scanned()

    session.settle(accepted('otro'))
    expect(played).toEqual([])
    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS)
    expect(played).toEqual(['pending'])
    session.dismiss()
  })

  it('si fichan otros antes de que el servidor decida, el anterior cierra con `pending` y no queda espera huerfana', () => {
    const { sound, played } = soundSpy()
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1'), pending('s2')),
      sound,
    })

    session.accept('FH1.a3.uno.sig')
    vi.advanceTimersByTime(500)
    session.accept('FH1.a3.dos.sig')
    expect(played).toEqual(['pending'])

    session.settle(accepted('s2'))
    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS * 2)
    expect(played).toEqual(['pending', 'entry'])
    session.dismiss()
  })

  it('un audio que falla no rompe el fichaje (regla dura 19)', () => {
    const session = useScanSession({
      pipeline: pipelineReturning(pending('s1')),
      sound: {
        play: () => {
          throw new Error('audio bloqueado')
        },
        unlock: () => undefined,
        dispose: () => undefined,
      },
    })

    session.accept('FH1.a3.token.sig')
    expect(() => session.settle(accepted('s1'))).not.toThrow()
    expect(session.confirmation.value?.kind).toBe('accepted')
    session.dismiss()
  })

  it('dismiss cancela la espera: al desmontar no suena nada', () => {
    const { session, played } = scanned()

    session.dismiss()
    vi.advanceTimersByTime(SCAN_SOUND_SERVER_WAIT_MS * 2)

    expect(played).toEqual([])
  })

  describe('via del PIN (present/settle)', () => {
    function verifying(scanId: string): ScanConfirmation {
      return { kind: 'verifying', scanId, occurredAt: at }
    }

    function pinSession(): { session: ReturnType<typeof useScanSession>; played: FeedbackTone[] } {
      const { sound, played } = soundSpy()
      return { session: useScanSession({ pipeline: pipelineReturning(), sound }), played }
    }

    it('«Comprobando…» es mudo y el desenlace suena una sola vez', () => {
      const { session, played } = pinSession()

      session.present(verifying('p1'))
      expect(played).toEqual([])
      session.settle(accepted('p1'))
      expect(played).toEqual(['entry'])
      session.dismiss()
    })

    it('un rechazo del PIN suena `error`', () => {
      const { session, played } = pinSession()

      session.present(verifying('p1'))
      session.settle(rejected('p1'))

      expect(played).toEqual(['error'])
      session.dismiss()
    })

    it('plazo vencido: `pending` y la respuesta tardia no vuelve a sonar', () => {
      const { session, played } = pinSession()

      session.present(verifying('p1'))
      session.settle(pending('p1'))
      session.settle(accepted('p1'))

      expect(played).toEqual(['pending'])
      session.dismiss()
    })

    it('plazo vencido y rechazo tardio: `pending` y luego `error`', () => {
      const { session, played } = pinSession()

      session.present(verifying('p1'))
      session.settle(pending('p1'))
      session.settle(rejected('p1'))

      expect(played).toEqual(['pending', 'error'])
      session.dismiss()
    })

    it('un desenlace ya conocido al pintar (respuesta dentro de la gracia) suena en el acto', () => {
      const { session, played } = pinSession()

      session.present(accepted('p1'))

      expect(played).toEqual(['entry'])
      session.dismiss()
    })

    it('sin red: `pending` en el acto', () => {
      const { session, played } = pinSession()

      session.present(pending('p1'))

      expect(played).toEqual(['pending'])
      session.dismiss()
    })
  })
})
