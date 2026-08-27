import { describe, expect, it, vi } from 'vitest'
import { createScanPipeline } from '@/features/scan/application/scanPipeline'
import type {
  QueuedScan,
  ScanSubmissionPort,
  ScanSubmissionResult,
} from '@/features/scan/application/ports'
import type { ScanConfirmation } from '@/features/scan/domain/scanOutcome'
import { isUuidV7 } from '@/shared/ids/uuidV7'
import { fixedClock } from '@/shared/time/clock'
import type { ScanAccepted, ScanDebounced } from '@/shared/api/types'

const PAYLOAD = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'
const OTHER_PAYLOAD = 'FH1.a3.Wp7Lm2Qx8ZrT4vNc9YbK1e.3Bq7Rt9WzX2mK6pL'

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

const accepted: ScanAccepted = {
  scan_id: 'placeholder',
  action: 'clock_in',
  employee_display_name: 'Lucia G.',
  work_date: '2026-08-14',
  occurred_at: '2026-08-14T05:02:00.000Z',
  recorded_at: '2026-08-14T05:02:00.412Z',
  worked_minutes: 0,
}

const debounced: ScanDebounced = {
  scan_id: 'placeholder',
  action: 'debounced',
  employee_display_name: 'Lucia G.',
  occurred_at: '2026-08-14T05:02:20.000Z',
  recorded_at: '2026-08-14T05:02:20.208Z',
  worked_minutes: 240,
  last_accepted_at: '2026-08-14T05:02:00.000Z',
}

describe('tuberia del escaneo', () => {
  it('confirma en el mismo turno del bucle: sin await en el camino critico', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({ submission: port, deviceId: 'dev-1' })

    const confirmation = pipeline.handleDecoded(PAYLOAD)

    // Devuelve un valor, no una promesa. Esto es lo que garantiza los 300 ms.
    expect(confirmation).not.toBeNull()
    expect(confirmation?.kind).toBe('pending')
    // Y el envio ya se ha lanzado, sin haberlo esperado.
    expect(sent).toHaveLength(1)
  })

  it('genera un scan_id UUID v7 al ENCOLAR, no al enviar', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({ submission: port, deviceId: 'dev-1' })

    const confirmation = pipeline.handleDecoded(PAYLOAD)

    expect(isUuidV7(confirmation?.scanId ?? '')).toBe(true)
    expect(sent[0]?.scan_id).toBe(confirmation?.scanId)
  })

  it('captura occurred_at en UTC en el momento del escaneo', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({
      submission: port,
      deviceId: 'dev-1',
      clock: fixedClock(new Date('2026-08-14T05:02:00.000Z')),
    })

    pipeline.handleDecoded(PAYLOAD)

    expect(sent[0]?.occurred_at).toBe('2026-08-14T05:02:00.000Z')
  })

  it('escribe intent auto y el device_id desde la v1 del registro (ADR-024)', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({ submission: port, deviceId: 'kiosk-recepcion-01' })

    pipeline.handleDecoded(PAYLOAD)

    expect(sent[0]?.intent).toBe('auto')
    expect(sent[0]?.device_id).toBe('kiosk-recepcion-01')
  })

  it('ENCOLA aunque el padron no reconozca la tarjeta: degradacion honesta', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({
      submission: port,
      deviceId: 'dev-1',
      roster: { displayNameFor: () => null },
    })

    const confirmation = pipeline.handleDecoded(PAYLOAD)

    expect(sent).toHaveLength(1)
    expect(confirmation).toMatchObject({ kind: 'pending', displayName: null })
  })

  it('saluda por su nombre cuando el padron cacheado lo resuelve', () => {
    const { port } = recorder()
    const pipeline = createScanPipeline({
      submission: port,
      deviceId: 'dev-1',
      roster: { displayNameFor: () => 'Lucia G.' },
    })

    expect(pipeline.handleDecoded(PAYLOAD)).toMatchObject({
      kind: 'pending',
      displayName: 'Lucia G.',
    })
  })

  it('rechaza en local lo que no tiene forma de tarjeta, sin gastar cola ni red', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({ submission: port, deviceId: 'dev-1' })

    expect(pipeline.handleDecoded('https://wifi.hotel.example')).toMatchObject({
      kind: 'unreadable',
    })
    expect(sent).toHaveLength(0)
  })

  it('ignora la relectura inmediata del mismo codigo: un gesto, un fichaje', () => {
    const { port, sent } = recorder()
    let nowMs = Date.parse('2026-08-14T05:02:00.000Z')
    const pipeline = createScanPipeline({
      submission: port,
      deviceId: 'dev-1',
      clock: { now: () => new Date(nowMs) },
    })

    expect(pipeline.handleDecoded(PAYLOAD)).not.toBeNull()
    nowMs += 300
    expect(pipeline.handleDecoded(PAYLOAD)).toBeNull()
    nowMs += 400
    expect(pipeline.handleDecoded(PAYLOAD)).toBeNull()
    expect(sent).toHaveLength(1)
  })

  it('vuelve a aceptar el mismo codigo pasada la ventana', () => {
    const { port, sent } = recorder()
    let nowMs = Date.parse('2026-08-14T05:02:00.000Z')
    const pipeline = createScanPipeline({
      submission: port,
      deviceId: 'dev-1',
      clock: { now: () => new Date(nowMs) },
      repeatWindowMs: 1000,
    })

    pipeline.handleDecoded(PAYLOAD)
    nowMs += 1500
    expect(pipeline.handleDecoded(PAYLOAD)).not.toBeNull()
    expect(sent).toHaveLength(2)
    expect(sent[0]?.scan_id).not.toBe(sent[1]?.scan_id)
  })

  it('no confunde a dos personas seguidas: otra tarjeta pasa aunque sea inmediata', () => {
    const { port, sent } = recorder()
    const pipeline = createScanPipeline({ submission: port, deviceId: 'dev-1' })

    pipeline.handleDecoded(PAYLOAD)
    expect(pipeline.handleDecoded(OTHER_PAYLOAD)).not.toBeNull()
    expect(sent).toHaveLength(2)
  })

  it('refresca la pantalla con el desenlace real del servidor', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createScanPipeline({
      submission: { submit: async () => ({ kind: 'accepted', response: accepted }) },
      deviceId: 'dev-1',
      onSettled: (confirmation) => settled.push(confirmation),
    })

    pipeline.handleDecoded(PAYLOAD)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]).toMatchObject({
      kind: 'accepted',
      action: 'clock_in',
      displayName: 'Lucia G.',
      workedMinutes: 0,
      workDate: '2026-08-14',
    })
  })

  it('trata el anti-rebote como desenlace aceptado, no como error (ADR-031)', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createScanPipeline({
      submission: { submit: async () => ({ kind: 'debounced', response: debounced }) },
      deviceId: 'dev-1',
      onSettled: (confirmation) => settled.push(confirmation),
    })

    pipeline.handleDecoded(PAYLOAD)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]).toMatchObject({ kind: 'debounced', workedMinutes: 240 })
  })

  it('deja la confirmacion «pendiente» intacta cuando no hay red', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createScanPipeline({
      submission: { submit: async () => ({ kind: 'deferred' }) },
      deviceId: 'dev-1',
      onSettled: (confirmation) => settled.push(confirmation),
    })

    pipeline.handleDecoded(PAYLOAD)
    await Promise.resolve()
    await Promise.resolve()

    expect(settled).toHaveLength(0)
  })

  it('sobrevive a un puerto de envio que lanza', async () => {
    const errors: string[] = []
    const pipeline = createScanPipeline({
      submission: {
        submit: async () => {
          throw new TypeError('boom')
        },
      },
      deviceId: 'dev-1',
      onError: (code) => errors.push(code),
    })

    const confirmation = pipeline.handleDecoded(PAYLOAD)
    await vi.waitFor(() => expect(errors).toEqual(['submit_failed']))

    // Lo importante: el empleado vio su confirmacion igualmente.
    expect(confirmation?.kind).toBe('pending')
  })

  it('convierte un rechazo del servidor en un mensaje generico', async () => {
    const settled: ScanConfirmation[] = []
    const pipeline = createScanPipeline({
      submission: { submit: async () => ({ kind: 'rejected' }) },
      deviceId: 'dev-1',
      onSettled: (confirmation) => settled.push(confirmation),
    })

    pipeline.handleDecoded(PAYLOAD)
    await vi.waitFor(() => expect(settled).toHaveLength(1))

    expect(settled[0]?.kind).toBe('rejected')
    // Sin causa, sin campo extra por el que deducirla (regla dura 17).
    expect(Object.keys(settled[0] ?? {}).sort()).toEqual(['kind', 'occurredAt', 'scanId'])
  })
})
