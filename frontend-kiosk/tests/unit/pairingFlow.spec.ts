// Maquina de estados del emparejamiento (RF-PD-06, tarea 5.6).
//
// El reloj y los temporizadores se inyectan (igual que en `syncRunner.spec.ts`)
// para que caducidad y sondeo sean deterministas: nada de esta prueba depende
// de la hora a la que se ejecuta ni de esperas reales.

import { describe, expect, it, vi } from 'vitest'
import { createPairingFlow } from '@/features/pairing/application/pairingFlow'
import type { PairingState } from '@/features/pairing/application/pairingFlow'
import type { ApiClient, ApiResult } from '@/shared/api/client'
import type {
  PairingClaim,
  PairingCompleted,
  PairingRejected,
  PairingRequested,
} from '@/shared/api/types'
import { fixedClock } from '@/shared/time/clock'

const PAIRING_ID = '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32'
const PAIRING_SECRET = '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ'

function requested(overrides: Partial<PairingRequested> = {}): PairingRequested {
  return {
    pairing_id: PAIRING_ID,
    pairing_secret: PAIRING_SECRET,
    code: '483921',
    expires_at: '2026-09-07T10:12:00.000Z',
    poll_interval_seconds: 5,
    ...overrides,
  }
}

const PENDING: PairingClaim = { status: 'pending' }

const COMPLETED: PairingCompleted = {
  status: 'paired',
  device: { uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81', name: 'Recepcion' },
  token: {
    value: '92|Kd2pQ9vLmN4tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
    expires_at: '2026-12-06T10:07:00.000Z',
  },
}

const REJECTED: PairingRejected = {
  type: 'urn:kronoqr:problem:pairing-rejected',
  title: 'Emparejamiento no valido',
  status: 422,
  detail: 'La solicitud de emparejamiento no se ha podido completar.',
}

/** Un solo hueco de temporizador: es todo lo que `pairingFlow` necesita a la vez. */
function fakeTimer() {
  let nextId = 1
  const pending = new Map<number, { handler: () => void; delayMs: number }>()

  return {
    setTimer: (handler: () => void, delayMs: number): number => {
      const id = nextId
      nextId += 1
      pending.set(id, { handler, delayMs })
      return id
    },
    clearTimer: (id: number): void => {
      pending.delete(id)
    },
    /** El unico temporizador vivo (o lanza, si la prueba esperaba uno y no lo hay). */
    only(): { delayMs: number } {
      const entries = [...pending.entries()]
      expect(entries).toHaveLength(1)
      const entry = entries[0]
      if (entry === undefined) throw new Error('unreachable')
      return { delayMs: entry[1].delayMs }
    },
    /** Dispara el temporizador pendiente y deja que su cadena async se asiente. */
    async fire(): Promise<void> {
      const entries = [...pending.entries()]
      const first = entries[0]
      if (first === undefined) throw new Error('no hay temporizador pendiente')
      const [id, entry] = first
      pending.delete(id)
      entry.handler()
      // Varias vueltas de microtareas: `poll`/`requestCode` encadenan un
      // `await` a la API y otro a `setState`/`schedulePoll`.
      await flush()
      await flush()
      await flush()
    },
  }
}

function flush(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

interface Harness {
  readonly requestPairing: ReturnType<typeof vi.fn<ApiClient['requestPairing']>>
  readonly claimPairing: ReturnType<typeof vi.fn<ApiClient['claimPairing']>>
  readonly states: PairingState[]
  readonly paired: PairingCompleted[]
  readonly timer: ReturnType<typeof fakeTimer>
}

function ok<T>(data: T): ApiResult<T, never> {
  return { outcome: 'ok', data }
}

function harness(): Harness {
  // Con implementacion por defecto (no vacios): cada prueba sobreescribe con
  // `mockResolvedValueOnce` las llamadas que le importan, y una llamada de mas
  // que se escapara resolveria a algo razonable en vez de colgarse.
  const requestPairing = vi.fn<ApiClient['requestPairing']>(async () => ok(requested()))
  const claimPairing = vi.fn<ApiClient['claimPairing']>(async () => ok(PENDING))
  const states: PairingState[] = []
  const paired: PairingCompleted[] = []
  const timer = fakeTimer()

  return { requestPairing, claimPairing, states, paired, timer }
}

function buildFlow(h: Harness, nowIso = '2026-09-07T10:00:00.000Z') {
  return createPairingFlow({
    api: { requestPairing: h.requestPairing, claimPairing: h.claimPairing },
    appVersion: '1.4.2',
    clock: fixedClock(new Date(nowIso)),
    setTimer: h.timer.setTimer,
    clearTimer: h.timer.clearTimer,
    onStateChange: (state) => h.states.push(state),
    onPaired: (result) => h.paired.push(result),
  })
}

describe('maquina de emparejamiento (RF-PD-06)', () => {
  it('nunca se queda sin nada que mostrar: pide codigo al arrancar', async () => {
    const h = harness()
    h.requestPairing.mockResolvedValueOnce(ok(requested()))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()

    expect(h.states[0]).toEqual({ kind: 'requesting', reason: 'initial' })
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', code: '483921' })
  })

  it('sondeo respeta poll_interval_seconds del servidor', async () => {
    const h = harness()
    h.requestPairing.mockResolvedValueOnce(ok(requested({ poll_interval_seconds: 5 })))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()

    expect(h.timer.only().delayMs).toBe(5_000)
  })

  it('caducidad -> nuevo codigo, sin intervencion (regla dura 19)', async () => {
    const h = harness() // el reloj de la maquina lo fija `buildFlow`, no `harness`
    h.requestPairing
      .mockResolvedValueOnce(
        ok(requested({ code: '111111', expires_at: '2026-09-07T10:12:00.000Z' })),
      )
      .mockResolvedValueOnce(
        ok(requested({ code: '222222', expires_at: '2026-09-07T10:22:00.000Z' })),
      )
    const flow = buildFlow(h, '2026-09-07T10:12:01.000Z')

    flow.start()
    await flush()
    await flush()
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', code: '111111' })

    await h.timer.fire() // dispara el sondeo: la caducidad la detecta el CLIENTE, sin llamar a claim

    expect(h.claimPairing).not.toHaveBeenCalled()
    expect(h.requestPairing).toHaveBeenCalledTimes(2)
    expect(h.states.some((s) => s.kind === 'requesting' && s.reason === 'expired')).toBe(true)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', code: '222222' })
  })

  it('rechazo -> nuevo codigo, sin intervencion (regla dura 17 + 19)', async () => {
    const h = harness()
    h.requestPairing
      .mockResolvedValueOnce(ok(requested({ code: '111111' })))
      .mockResolvedValueOnce(ok(requested({ code: '222222' })))
    h.claimPairing.mockResolvedValueOnce({ outcome: 'rejected', problem: REJECTED })
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()
    await h.timer.fire()

    expect(h.requestPairing).toHaveBeenCalledTimes(2)
    expect(h.states.some((s) => s.kind === 'requesting' && s.reason === 'rejected')).toBe(true)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', code: '222222' })
  })

  it('paired -> entrega el resultado UNA vez, sin volver a sondear', async () => {
    const h = harness()
    h.requestPairing.mockResolvedValueOnce(ok(requested()))
    h.claimPairing.mockResolvedValueOnce(ok(COMPLETED))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()
    await h.timer.fire()

    expect(h.paired).toEqual([COMPLETED])
    expect(h.states.at(-1)).toEqual({
      kind: 'paired',
      device: COMPLETED.device,
      token: COMPLETED.token,
    })
  })

  it('un 429 sondeando NO rompe el bucle ni pide un codigo nuevo', async () => {
    const h = harness()
    h.requestPairing.mockResolvedValueOnce(ok(requested()))
    h.claimPairing
      .mockResolvedValueOnce({ outcome: 'failed', cause: 'throttled', httpStatus: 429 })
      .mockResolvedValueOnce(ok(PENDING))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()

    await h.timer.fire() // el 429: se reintenta en el MISMO sitio
    expect(h.requestPairing).toHaveBeenCalledTimes(1)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', pairingId: PAIRING_ID })

    await h.timer.fire() // el siguiente sondeo: `pending`, sigue esperando
    expect(h.claimPairing).toHaveBeenCalledTimes(2)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', pairingId: PAIRING_ID })
  })

  it('un 503 sondeando (ServiceUnavailable) tampoco rompe el bucle', async () => {
    // Mismo `ServiceUnavailable` del contrato que `/kiosk/pair`: limite de
    // solicitudes vivas o espacio de codigos agotado. Para `claim` es un
    // fallo de transporte igual que cualquier otro `5xx`, nunca un rechazo.
    const h = harness()
    h.requestPairing.mockResolvedValueOnce(ok(requested()))
    h.claimPairing
      .mockResolvedValueOnce({ outcome: 'failed', cause: 'server', httpStatus: 503 })
      .mockResolvedValueOnce(ok(PENDING))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()

    await h.timer.fire() // el 503: se reintenta en el MISMO sitio
    expect(h.requestPairing).toHaveBeenCalledTimes(1)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', pairingId: PAIRING_ID })

    await h.timer.fire() // el siguiente sondeo: `pending`, sigue esperando
    expect(h.claimPairing).toHaveBeenCalledTimes(2)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', pairingId: PAIRING_ID })
  })

  it('un 503 pidiendo el PRIMER codigo (sin ticket todavia) reintenta a los 5 s por defecto', async () => {
    const h = harness()
    h.requestPairing
      .mockResolvedValueOnce({ outcome: 'failed', cause: 'server', httpStatus: 503 })
      .mockResolvedValueOnce(ok(requested()))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()

    // Nunca en blanco: sigue en «requesting» mientras reintenta.
    expect(h.states.at(-1)).toEqual({ kind: 'requesting', reason: 'initial' })
    expect(h.timer.only().delayMs).toBe(5_000)

    await h.timer.fire()
    expect(h.requestPairing).toHaveBeenCalledTimes(2)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting' })
  })

  it('un 503 pidiendo un codigo nuevo CON un ticket previo reintenta a su cadencia, no a 5 s fijos', async () => {
    // Si el servidor ya dijo una vez «sondea cada 8 s», reintentar la
    // PROXIMA solicitud a ese mismo ritmo es mas fiel que un `5 s` que nadie
    // ha configurado en esta instalacion.
    const h = harness()
    h.requestPairing
      .mockResolvedValueOnce(ok(requested({ poll_interval_seconds: 8 })))
      .mockResolvedValueOnce({ outcome: 'failed', cause: 'server', httpStatus: 503 })
      .mockResolvedValueOnce(ok(requested({ poll_interval_seconds: 8, code: '222222' })))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', pollIntervalSeconds: 8 })

    // «Generar otro codigo»: la segunda solicitud falla con 503.
    flow.requestNewCode()
    await flush()
    await flush()

    expect(h.states.at(-1)).toEqual({ kind: 'requesting', reason: 'manual' })
    expect(h.timer.only().delayMs).toBe(8_000)

    await h.timer.fire()
    expect(h.requestPairing).toHaveBeenCalledTimes(3)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting', code: '222222' })
  })

  it('un fallo de red pidiendo el codigo se reintenta solo, sin dejar la pantalla en blanco', async () => {
    const h = harness()
    h.requestPairing
      .mockResolvedValueOnce({ outcome: 'failed', cause: 'network' })
      .mockResolvedValueOnce(ok(requested()))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()

    // Sigue en «requesting»: nunca un estado vacio.
    expect(h.states.at(-1)).toEqual({ kind: 'requesting', reason: 'initial' })

    await h.timer.fire()
    expect(h.requestPairing).toHaveBeenCalledTimes(2)
    expect(h.states.at(-1)).toMatchObject({ kind: 'waiting' })
  })

  it('«Generar otro codigo» descarta la solicitud viva y pide una nueva', async () => {
    const h = harness()
    h.requestPairing
      .mockResolvedValueOnce(ok(requested({ code: '111111' })))
      .mockResolvedValueOnce(ok(requested({ code: '222222' })))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()
    expect(h.states.at(-1)).toMatchObject({ code: '111111' })

    flow.requestNewCode()
    await flush()
    await flush()

    expect(h.requestPairing).toHaveBeenCalledTimes(2)
    expect(h.states.some((s) => s.kind === 'requesting' && s.reason === 'manual')).toBe(true)
    expect(h.states.at(-1)).toMatchObject({ code: '222222' })
  })

  it('stop() detiene el sondeo: un temporizador que dispara despues no cambia nada', async () => {
    const h = harness()
    h.requestPairing.mockResolvedValueOnce(ok(requested()))
    const flow = buildFlow(h)

    flow.start()
    await flush()
    await flush()
    const statesBeforeStop = h.states.length

    flow.stop()
    // El temporizador de sondeo seguia vivo: se limpia, no queda ninguno.
    expect(() => h.timer.only()).toThrow()

    h.claimPairing.mockResolvedValueOnce(ok(PENDING))
    await flush()
    expect(h.states.length).toBe(statesBeforeStop)
  })
})
