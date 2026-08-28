// La tuberia del PIN. Hermana de `scanPipeline.spec.ts`: misma disciplina de
// pruebas, con la comprobacion que este encargo exige en plata: lo que sale
// hacia la cola nunca lleva el PIN en claro en NINGUN campo, en ningun caso.
//
// «Comprobando…» (RF-AT-11): el PIN viaja sellado y solo el servidor lo
// valida, asi que —a diferencia del QR— no hay nada honesto que decir en
// local salvo «se esta comprobando». Estas pruebas fijan el contrato exacto:
//   - con red, la confirmacion INMEDIATA es siempre `verifying`, nunca `pending`.
//   - si el servidor contesta antes de `PIN_VERIFY_TIMEOUT_MS`, se pinta ESE
//     desenlace real (aceptado, anti-rebote o rechazado) via `onSettled`.
//   - si no contesta a tiempo, se pinta `pending` via `onSettled`, y si el
//     resultado real llega mas tarde, se aplica igual que siempre.
//   - sin red, la confirmacion inmediata es `pending` directamente: esperar
//     el plazo no aportaria nada que no se supiera ya (regla dura 19).

import { describe, expect, it, vi } from 'vitest'
import {
  createPinPipeline,
  PIN_VERIFY_GRACE_MS,
  PIN_VERIFY_TIMEOUT_MS,
} from '@/features/pin/application/pinPipeline'
import type {
  QueuedScan,
  ScanSubmissionPort,
  ScanSubmissionResult,
} from '@/features/scan/application/ports'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { isUuidV7 } from '@/shared/ids/uuidV7'
import { fixedClock } from '@/shared/time/clock'
import type { ScanAccepted, ScanDebounced } from '@/shared/api/types'

const RAW_PIN = '483920'
const SEALED_PIN = 'c2VhbGVkLXBpbi1lbnZlbG9wZS1wbGFjZWhvbGRlcg=='
const PUBLIC_KEY = '7cXt0m5rXf8mB2mHnV1kQe0k0f5T2xY3rZq8w9AbCdE='

function recorder(result: ScanSubmissionResult = { kind: 'deferred' }): {
  port: ScanSubmissionPort
  sent: QueuedScan[]
} {
  const sent: QueuedScan[] = []
  return {
    sent,
    port: {
      submit: async (scan) => {
        sent.push(scan)
        return result
      },
    },
  }
}

/** Sellado falso, previsible, para no depender de WebAssembly en estas pruebas. */
const fakeSeal = vi.fn(async () => SEALED_PIN)

const accepted: ScanAccepted = {
  scan_id: 'placeholder',
  action: 'clock_in',
  employee_display_name: 'Lucia G.',
  work_date: '2026-08-14',
  occurred_at: '2026-08-14T05:59:02.000Z',
  recorded_at: '2026-08-14T05:59:02.412Z',
  worked_minutes: 0,
}

const debounced: ScanDebounced = {
  scan_id: 'placeholder',
  action: 'debounced',
  employee_display_name: 'Lucia G.',
  occurred_at: '2026-08-14T05:59:20.000Z',
  recorded_at: '2026-08-14T05:59:20.208Z',
  worked_minutes: 240,
  last_accepted_at: '2026-08-14T05:59:02.000Z',
}

describe('tuberia del PIN (RF-AT-11)', () => {
  it('con red, confirma «Comprobando…» al instante: nunca «pendiente» de entrada', async () => {
    const { port } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(confirmation.kind).toBe('verifying')
  })

  it('sin red, confirma «pendiente» directamente: esperar el plazo no aportaria nada honesto', async () => {
    const { port } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      isOffline: () => true,
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(confirmation.kind).toBe('pending')
  })

  it('sin red, «pendiente» llega sin esperar la ventana de gracia del PIN', async () => {
    // Reloj falso SIN avanzarlo ni un milisegundo: si el camino sin red
    // esperase `PIN_VERIFY_GRACE_MS` (o cualquier otro plazo) antes de
    // resolver, este `await` se quedaria colgado para siempre y la prueba
    // fallaria por tiempo agotado, no por una asercion.
    vi.useFakeTimers()
    try {
      const { port } = recorder()
      const pipeline = createPinPipeline({
        submission: port,
        deviceId: 'dev-1',
        publicKey: PUBLIC_KEY,
        seal: fakeSeal,
        isOffline: () => true,
      })

      const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

      expect(confirmation.kind).toBe('pending')
    } finally {
      vi.useRealTimers()
    }
  })

  it('el objeto que viaja al puerto de envio SOLO lleva el sobre sellado, nunca el PIN', async () => {
    const { port, sent } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(sent).toHaveLength(1)
    const queued = sent[0]
    expect(queued?.kind).toBe('pin')

    // Serializado completo: el PIN no aparece en NINGUN campo, ni siquiera por
    // accidente en uno que no le corresponda.
    const serialized = JSON.stringify(queued)
    expect(serialized).not.toContain(RAW_PIN)

    if (queued?.kind === 'pin') {
      expect(queued.pin_sealed).toBe(SEALED_PIN)
      expect(queued.employee_code).toBe('E7QK2MXPR')
    } else {
      throw new Error('se esperaba un QueuedPinScan')
    }
  })

  it('sella ANTES de encolar: la llamada al sellado ocurre con la clave de la instalacion', async () => {
    const { port } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
    })
    fakeSeal.mockClear()

    await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(fakeSeal).toHaveBeenCalledWith(RAW_PIN, PUBLIC_KEY)
  })

  it('genera un scan_id UUID v7 al encolar', async () => {
    const { port, sent } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(isUuidV7(confirmation.scanId)).toBe(true)
    expect(sent[0]?.scan_id).toBe(confirmation.scanId)
  })

  it('captura occurred_at en UTC en el momento de teclear, no en el de enviar', async () => {
    const { port, sent } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      clock: fixedClock(new Date('2026-08-14T05:59:02.000Z')),
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(sent[0]?.occurred_at).toBe('2026-08-14T05:59:02.000Z')
  })

  it('escribe intent auto y el device_id, igual que el escaneo de tarjeta', async () => {
    const { port, sent } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'kiosk-recepcion-01',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(sent[0]?.intent).toBe('auto')
    expect(sent[0]?.device_id).toBe('kiosk-recepcion-01')
  })

  it('un sellado que falla se convierte en rechazo generico, sin el PIN en el contexto de error', async () => {
    const { port, sent } = recorder()
    const errors: Array<{ code: string; context: Record<string, unknown> }> = []
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: async () => {
        throw new Error('WebAssembly no disponible')
      },
      onError: (code, context) => errors.push({ code, context }),
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(confirmation.kind).toBe('rejected')
    expect(sent).toHaveLength(0) // nunca llego a encolarse nada
    expect(errors).toHaveLength(1)
    expect(errors[0]?.code).toBe('seal_failed')
    expect(JSON.stringify(errors[0]?.context)).not.toContain(RAW_PIN)
  })

  // La ventana de gracia (RF-AT-11, hallazgo de revision): en el despliegue
  // habitual (servidor on-premise, misma VLAN) la respuesta llega en 50-200
  // ms. Pintar «Comprobando…» y sustituirlo de inmediato por el desenlace
  // real seria un parpadeo — dos pintados, dos sonidos, por un unico
  // fichaje. Estas pruebas fijan el contrato exacto de `PIN_VERIFY_GRACE_MS`.
  describe('ventana de gracia (RF-AT-11)', () => {
    /** Envio que contesta al cabo de `delayMs`, controlado por reloj falso. */
    function delayedSubmission(delayMs: number, result: ScanSubmissionResult): ScanSubmissionPort {
      return {
        submit: () =>
          new Promise((resolve) => {
            setTimeout(() => resolve(result), delayMs)
          }),
      }
    }

    it('respuesta aceptada a 100 ms: submit() la resuelve directamente, sin «Comprobando…» y sin onSettled', async () => {
      vi.useFakeTimers()
      try {
        const settled: ScanConfirmation[] = []
        const pipeline = createPinPipeline({
          submission: delayedSubmission(100, { kind: 'accepted', response: accepted }),
          deviceId: 'dev-1',
          publicKey: PUBLIC_KEY,
          seal: fakeSeal,
          onSettled: (confirmation) => settled.push(confirmation),
        })

        const submitPromise = pipeline.submit('E7QK2MXPR', RAW_PIN)
        await vi.advanceTimersByTimeAsync(100)
        const confirmation = await submitPromise

        expect(confirmation).toMatchObject({ kind: 'accepted', action: 'clock_in' })
        // Nadie llama a `onSettled` para un desenlace que `submit()` ya
        // entrego: un unico pintado, un unico sonido.
        expect(settled).toHaveLength(0)
      } finally {
        vi.useRealTimers()
      }
    })

    it('rechazo a 100 ms: submit() lo resuelve directamente, nunca «verifying» ni «pending» (regla dura 17)', async () => {
      vi.useFakeTimers()
      try {
        const settled: ScanConfirmation[] = []
        const pipeline = createPinPipeline({
          submission: delayedSubmission(100, { kind: 'rejected' }),
          deviceId: 'dev-1',
          publicKey: PUBLIC_KEY,
          seal: fakeSeal,
          onSettled: (confirmation) => settled.push(confirmation),
        })

        const submitPromise = pipeline.submit('E7QK2MXPR', RAW_PIN)
        await vi.advanceTimersByTimeAsync(100)
        const confirmation = await submitPromise

        expect(confirmation.kind).toBe('rejected')
        expect(Object.keys(confirmation).sort()).toEqual(['kind', 'occurredAt', 'scanId'])
        expect(settled).toHaveLength(0)
      } finally {
        vi.useRealTimers()
      }
    })

    it('anti-rebote a 100 ms: se trata como desenlace aceptado (ADR-031), directo y sin onSettled', async () => {
      vi.useFakeTimers()
      try {
        const settled: ScanConfirmation[] = []
        const pipeline = createPinPipeline({
          submission: delayedSubmission(100, { kind: 'debounced', response: debounced }),
          deviceId: 'dev-1',
          publicKey: PUBLIC_KEY,
          seal: fakeSeal,
          onSettled: (confirmation) => settled.push(confirmation),
        })

        const submitPromise = pipeline.submit('E7QK2MXPR', RAW_PIN)
        await vi.advanceTimersByTimeAsync(100)
        const confirmation = await submitPromise

        expect(confirmation).toMatchObject({ kind: 'debounced', workedMinutes: 240 })
        expect(settled).toHaveLength(0)
      } finally {
        vi.useRealTimers()
      }
    })

    it('respuesta a 800 ms: agota la gracia («Comprobando…») y el desenlace real llega despues, por onSettled', async () => {
      vi.useFakeTimers()
      try {
        const settled: ScanConfirmation[] = []
        const pipeline = createPinPipeline({
          submission: delayedSubmission(800, { kind: 'accepted', response: accepted }),
          deviceId: 'dev-1',
          publicKey: PUBLIC_KEY,
          seal: fakeSeal,
          onSettled: (confirmation) => settled.push(confirmation),
        })

        const submitPromise = pipeline.submit('E7QK2MXPR', RAW_PIN)

        // A los 300 ms se agota la gracia sin respuesta: `submit()` resuelve
        // «Comprobando…», y todavia no hay nada asentado.
        await vi.advanceTimersByTimeAsync(PIN_VERIFY_GRACE_MS)
        const confirmation = await submitPromise
        expect(confirmation.kind).toBe('verifying')
        expect(settled).toHaveLength(0)

        // A los 800 ms (500 ms mas tarde) contesta el servidor: un unico
        // desenlace, por onSettled.
        await vi.advanceTimersByTimeAsync(800 - PIN_VERIFY_GRACE_MS)
        expect(settled).toHaveLength(1)
        expect(settled[0]).toMatchObject({ kind: 'accepted', action: 'clock_in' })
      } finally {
        vi.useRealTimers()
      }
    })
  })

  it('un rechazo pinta la hora en que la persona pulso «confirmar», no la de la respuesta', async () => {
    // Mismo comportamiento que `scanPipeline` (via `settleFrom` compartido):
    // el rechazo no trae `occurred_at` del servidor, asi que lo unico
    // honesto es el instante que la persona vivio delante de la tablet. La
    // respuesta llega DENTRO de la gracia (sin retraso artificial), asi que
    // `submit()` la entrega directamente.
    let nowMs = Date.parse('2026-08-14T05:59:02.000Z')
    const pipeline = createPinPipeline({
      submission: {
        submit: async () => {
          // El servidor tarda: el reloj avanza ANTES de contestar, como
          // pasaria con un fichaje que espero en la cola offline.
          nowMs += 20_000
          return { kind: 'rejected' }
        },
      },
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      clock: { now: () => new Date(nowMs) },
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(confirmation.occurredAt).toEqual(new Date('2026-08-14T05:59:02.000Z'))
  })

  it('sobrevive a un puerto de envio que lanza: se rinde a «pendiente» via onSettled', async () => {
    const errors: string[] = []
    const settled: ScanConfirmation[] = []
    const pipeline = createPinPipeline({
      submission: {
        submit: async () => {
          throw new TypeError('boom')
        },
      },
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      onError: (code) => errors.push(code),
      onSettled: (confirmation) => settled.push(confirmation),
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)
    expect(confirmation.kind).toBe('verifying')

    await vi.waitFor(() => expect(errors).toEqual(['submit_failed']))
    await vi.waitFor(() => expect(settled).toHaveLength(1))
    expect(settled[0]?.kind).toBe('pending')
  })

  it('sin respuesta en el plazo, se rinde a «pendiente»; si el resultado llega tarde, se aplica igual que hoy', async () => {
    vi.useFakeTimers()
    try {
      const settled: ScanConfirmation[] = []
      // Sin `null`: dejarlo nulo hasta que el ejecutor de la promesa lo asigne
      // hace que TypeScript, al capturarlo en el cierre, pierda el
      // estrechamiento y lo trate como `never` en usos posteriores. Un no-op
      // inicial evita el problema sin recurrir a aserciones de tipo.
      let resolveSubmission: (result: ScanSubmissionResult) => void = () => undefined
      const submission: ScanSubmissionPort = {
        submit: () =>
          new Promise<ScanSubmissionResult>((resolve) => {
            resolveSubmission = resolve
          }),
      }
      const pipeline = createPinPipeline({
        submission,
        deviceId: 'dev-1',
        publicKey: PUBLIC_KEY,
        seal: fakeSeal,
        onSettled: (confirmation) => settled.push(confirmation),
      })

      // `submit()` no resuelve hasta que la ventana de gracia se agota (aqui
      // nunca contesta el servidor): sin avanzar el reloj falso ese primer
      // tramo, el `await` se quedaria colgado para siempre.
      const submitPromise = pipeline.submit('E7QK2MXPR', RAW_PIN)
      await vi.advanceTimersByTimeAsync(PIN_VERIFY_GRACE_MS)
      const confirmation = await submitPromise
      expect(confirmation.kind).toBe('verifying')
      expect(settled).toHaveLength(0)

      // Justo antes del plazo, todavia no se ha rendido.
      await vi.advanceTimersByTimeAsync(PIN_VERIFY_TIMEOUT_MS - 1)
      expect(settled).toHaveLength(0)

      // Al vencer el plazo exacto, pasa a «pendiente».
      await vi.advanceTimersByTimeAsync(1)
      expect(settled).toHaveLength(1)
      expect(settled[0]?.kind).toBe('pending')

      // La respuesta real llega despues: se aplica igual que hoy (sin
      // reemplazar lo que ya se pinto de golpe, solo anadiendo el desenlace).
      resolveSubmission({ kind: 'accepted', response: accepted })
      await vi.advanceTimersByTimeAsync(0)

      expect(settled).toHaveLength(2)
      expect(settled[1]).toMatchObject({ kind: 'accepted', action: 'clock_in' })
    } finally {
      vi.useRealTimers()
    }
  })
})
