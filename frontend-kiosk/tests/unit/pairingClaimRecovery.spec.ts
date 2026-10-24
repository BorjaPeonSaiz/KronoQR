// V6-KI-02: la respuesta del `claim` se pierde en la red. El servidor admite repetir
// la recogida entre 10 y 60 s despues (mismo secreto, mismo origen): la tablet no
// descarta la solicitud ni pide un codigo nuevo, y se queda con el ultimo `paired`.

import { describe, expect, it, vi } from 'vitest'
import {
  CLAIM_RECOVERY_WINDOW_MS,
  createPairingFlow,
} from '@/features/pairing/application/pairingFlow'
import type { PairingState } from '@/features/pairing/application/pairingFlow'
import { createApiClient } from '@/shared/api/client'
import type { ApiClient } from '@/shared/api/client'
import type { PairingCompleted, PairingRejected, PairingRequested } from '@/shared/api/types'

const REQUESTED: PairingRequested = {
  pairing_id: '0199f3c1-4a2b-7e55-9c10-8d7e6f5a4b32',
  pairing_secret: '9x2Kd4pQ7vLmN8tZbYcF1wQ8sE3rT6uI0oP5aS7dXyZ',
  code: '483921',
  expires_at: '2026-09-07T10:10:00.000Z',
  poll_interval_seconds: 5,
}

const COMPLETED: PairingCompleted = {
  status: 'paired',
  device: { uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81', name: 'Recepcion' },
  token: { value: '92|token-nuevo', expires_at: '2026-12-06T10:07:00.000Z' },
}

const REJECTED: PairingRejected = {
  type: 'urn:kronoqr:problem:pairing-rejected',
  title: 'Emparejamiento no valido',
  status: 422,
  detail: 'La solicitud de emparejamiento no se ha podido completar.',
}

function flush(): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, 0))
}

function bench() {
  let nowMs = Date.parse('2026-09-07T10:00:00.000Z')
  let nextId = 1
  const timers = new Map<number, () => void>()
  const states: PairingState[] = []
  const paired: PairingCompleted[] = []
  const signals: Array<AbortSignal | undefined> = []
  const requestPairing = vi.fn<ApiClient['requestPairing']>(async () => ({
    outcome: 'ok',
    data: REQUESTED,
  }))
  const claimPairing = vi.fn<ApiClient['claimPairing']>(async (_body, options) => {
    signals.push(options?.signal)
    return { outcome: 'ok', data: { status: 'pending' } }
  })
  const flow = createPairingFlow({
    api: { requestPairing, claimPairing },
    appVersion: '2.3.0',
    clock: { now: () => new Date(nowMs) },
    setTimer: (handler) => {
      const id = nextId
      nextId += 1
      timers.set(id, handler)
      return id
    },
    clearTimer: (id) => {
      timers.delete(id)
    },
    onStateChange: (state) => states.push(state),
    onPaired: (result) => paired.push(result),
  })
  return {
    flow,
    requestPairing,
    claimPairing,
    states,
    paired,
    signals,
    advance: (ms: number) => {
      nowMs += ms
    },
    /** Dispara el unico sondeo programado y deja asentarse la cadena async. */
    async tick(): Promise<void> {
      const entry = [...timers.entries()][0]
      if (entry === undefined) throw new Error('no hay sondeo programado')
      timers.delete(entry[0])
      entry[1]()
      for (let index = 0; index < 4; index += 1) await flush()
    },
    pendingTimers: () => timers.size,
  }
}

async function started(b: ReturnType<typeof bench>): Promise<void> {
  b.flow.start()
  await flush()
  await flush()
}

describe('recuperacion de un `claim` cuya respuesta se perdio (V6-KI-02)', () => {
  it('respuesta perdida, rechazo «demasiado pronto» y despues `paired`: no pide codigo nuevo y entrega el token UNA vez', async () => {
    const b = bench()
    await started(b)

    // 1.er claim: el servidor lo recoge pero la respuesta no llega.
    b.claimPairing.mockResolvedValueOnce({ outcome: 'failed', cause: 'network' })
    await b.tick()
    // 2.o a los 5 s: aun antes de los 10 s, el servidor da el rechazo de siempre.
    b.advance(5_000)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'rejected', problem: REJECTED })
    await b.tick()
    expect(b.requestPairing).toHaveBeenCalledTimes(1)
    expect(b.states.at(-1)).toMatchObject({ kind: 'waiting', code: '483921' })
    // 3.o a los 10 s: dentro de la ventana, el servidor reemite el token.
    b.advance(5_000)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'ok', data: COMPLETED })
    await b.tick()

    expect(b.paired).toEqual([COMPLETED])
    expect(b.states.at(-1)).toMatchObject({ kind: 'paired' })
    expect(b.requestPairing).toHaveBeenCalledTimes(1)
    // Y deja de sondear al recibirlo.
    expect(b.pendingTimers()).toBe(0)
  })

  it('pasada la ventana, un rechazo ya es concluyente: pide un codigo nuevo', async () => {
    const b = bench()
    await started(b)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'failed', cause: 'timeout' })
    await b.tick()

    b.advance(CLAIM_RECOVERY_WINDOW_MS + 1_000)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'rejected', problem: REJECTED })
    await b.tick()

    expect(b.requestPairing).toHaveBeenCalledTimes(2)
    expect(b.states.some((s) => s.kind === 'requesting' && s.reason === 'rejected')).toBe(true)
  })

  it('sin un fallo previo, un rechazo sigue siendo inmediato (regla dura 17)', async () => {
    const b = bench()
    await started(b)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'rejected', problem: REJECTED })

    await b.tick()

    expect(b.requestPairing).toHaveBeenCalledTimes(2)
  })

  it('«sin conexion» (la peticion ni salio) no abre la ventana de recuperacion', async () => {
    const b = bench()
    await started(b)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'failed', cause: 'offline' })
    await b.tick()
    b.claimPairing.mockResolvedValueOnce({ outcome: 'rejected', problem: REJECTED })
    await b.tick()

    expect(b.requestPairing).toHaveBeenCalledTimes(2)
  })

  it('la recuperacion vale aunque el codigo ya haya caducado en el reloj de la tablet', async () => {
    const b = bench()
    await started(b)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'failed', cause: 'network' })
    await b.tick()
    // Pasa la caducidad del codigo (10:10) mientras se espera.
    b.advance(11 * 60_000 - 55_000)
    b.claimPairing.mockResolvedValueOnce({ outcome: 'ok', data: COMPLETED })
    await b.tick()

    expect(b.claimPairing).toHaveBeenCalledTimes(2)
    expect(b.paired).toEqual([COMPLETED])
  })

  it('nunca dos `claim` en vuelo: el nuevo aborta el anterior', async () => {
    const b = bench()
    await started(b)
    // El primero se queda colgado hasta que lo abortan.
    b.claimPairing.mockImplementationOnce((_body, options) => {
      b.signals.push(options?.signal)
      return new Promise((resolve) => {
        options?.signal?.addEventListener('abort', () =>
          resolve({ outcome: 'failed', cause: 'timeout' }),
        )
      })
    })
    await b.tick()
    expect(b.signals[0]?.aborted).toBe(false)

    b.flow.requestNewCode()
    await flush()
    await flush()

    expect(b.signals[0]?.aborted).toBe(true)
  })

  it('stop() aborta el `claim` en vuelo', async () => {
    const b = bench()
    await started(b)
    b.claimPairing.mockImplementationOnce((_body, options) => {
      b.signals.push(options?.signal)
      return new Promise((resolve) => {
        options?.signal?.addEventListener('abort', () =>
          resolve({ outcome: 'failed', cause: 'timeout' }),
        )
      })
    })
    await b.tick()

    b.flow.stop()

    expect(b.signals[0]?.aborted).toBe(true)
  })
})

describe('el cliente HTTP respeta la senal de cancelacion del `claim`', () => {
  it('abortada desde fuera, se resuelve como `timeout` sin lanzar', async () => {
    const fetchImpl = ((_url: string, init?: RequestInit) =>
      new Promise((_resolve, reject) => {
        init?.signal?.addEventListener('abort', () =>
          reject(new DOMException('aborted', 'AbortError')),
        )
      })) as unknown as typeof fetch
    const client = createApiClient({ fetchImpl, timeoutMs: 60_000 })
    const controller = new AbortController()

    const pending = client.claimPairing(
      { pairing_id: REQUESTED.pairing_id, pairing_secret: REQUESTED.pairing_secret },
      { signal: controller.signal },
    )
    controller.abort()

    expect(await pending).toEqual({ outcome: 'failed', cause: 'timeout' })
  })
})
