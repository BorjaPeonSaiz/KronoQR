// La tuberia del PIN. Hermana de `scanPipeline.spec.ts`: misma disciplina de
// pruebas, con la comprobacion que este encargo exige en plata: lo que sale
// hacia la cola nunca lleva el PIN en claro en NINGUN campo, en ningun caso.

import { describe, expect, it, vi } from 'vitest'
import { createPinPipeline } from '@/features/pin/application/pinPipeline'
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
  it('confirma «pendiente» sin decir si sera entrada o salida', async () => {
    const { port } = recorder()
    const pipeline = createPinPipeline({
      submission: port,
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)

    expect(confirmation.kind).toBe('pending')
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

  it('refresca la pantalla con el desenlace real del servidor', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createPinPipeline({
      submission: { submit: async () => ({ kind: 'accepted', response: accepted }) },
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      onSettled: (confirmation) => settled.push(confirmation),
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]).toMatchObject({ kind: 'accepted', action: 'clock_in' })
  })

  it('trata el anti-rebote como desenlace aceptado (ADR-031)', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createPinPipeline({
      submission: { submit: async () => ({ kind: 'debounced', response: debounced }) },
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      onSettled: (confirmation) => settled.push(confirmation),
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]).toMatchObject({ kind: 'debounced', workedMinutes: 240 })
  })

  it('convierte un rechazo del servidor en un mensaje generico, sin causa (regla dura 17)', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createPinPipeline({
      submission: { submit: async () => ({ kind: 'rejected' }) },
      deviceId: 'dev-1',
      publicKey: PUBLIC_KEY,
      seal: fakeSeal,
      onSettled: (confirmation) => settled.push(confirmation),
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]?.kind).toBe('rejected')
    expect(Object.keys(settled[0] ?? {}).sort()).toEqual(['kind', 'occurredAt', 'scanId'])
  })

  it('un rechazo pinta la hora en que la persona pulso «confirmar», no la de la respuesta', async () => {
    // Mismo comportamiento que `scanPipeline` (via `settleFrom` compartido):
    // el rechazo no trae `occurred_at` del servidor, asi que lo unico
    // honesto es el instante que la persona vivio delante de la tablet.
    let nowMs = Date.parse('2026-08-14T05:59:02.000Z')
    const settled: ScanConfirmation[] = []
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
      onSettled: (confirmation) => settled.push(confirmation),
    })

    await pipeline.submit('E7QK2MXPR', RAW_PIN)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]?.occurredAt).toEqual(new Date('2026-08-14T05:59:02.000Z'))
  })

  it('sobrevive a un puerto de envio que lanza', async () => {
    const errors: string[] = []
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
    })

    const confirmation = await pipeline.submit('E7QK2MXPR', RAW_PIN)
    await vi.waitFor(() => expect(errors).toEqual(['submit_failed']))

    expect(confirmation.kind).toBe('pending')
  })
})
