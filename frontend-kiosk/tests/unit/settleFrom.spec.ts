// `settleFrom` es la traduccion unica, compartida por `scanPipeline` (QR) y
// `pinPipeline` (PIN, RF-AT-11), de la respuesta del servidor a lo que se
// pinta en pantalla. Vivia duplicada en las dos tuberias y ya habia
// divergido en la rama `rejected`: esta prueba fija el comportamiento unico.

import { describe, expect, it } from 'vitest'
import { settleFrom } from '@/features/scan/application/settleFrom'
import type { ScanSubmissionResult } from '@/features/scan/application/ports'
import type { ScanAccepted, ScanDebounced } from '@/shared/api/types'

const SCAN_ID = '01927c3a-0000-7000-8000-000000000001'
const ATTEMPT_AT = new Date('2026-08-14T05:59:02.000Z')

const accepted: ScanAccepted = {
  scan_id: SCAN_ID,
  action: 'clock_in',
  employee_display_name: 'Lucia G.',
  work_date: '2026-08-14',
  occurred_at: '2026-08-14T05:59:02.000Z',
  recorded_at: '2026-08-14T05:59:07.412Z',
  worked_minutes: 0,
}

const debounced: ScanDebounced = {
  scan_id: SCAN_ID,
  action: 'debounced',
  employee_display_name: 'Lucia G.',
  occurred_at: '2026-08-14T05:59:20.000Z',
  recorded_at: '2026-08-14T05:59:25.208Z',
  worked_minutes: 240,
  last_accepted_at: '2026-08-14T05:59:02.000Z',
}

describe('settleFrom — traduccion unica del desenlace del servidor', () => {
  it('accepted: usa el occurred_at que trae la respuesta, no el del intento', () => {
    const result: ScanSubmissionResult = { kind: 'accepted', response: accepted }

    expect(settleFrom(result, SCAN_ID, ATTEMPT_AT)).toMatchObject({
      kind: 'accepted',
      scanId: SCAN_ID,
      occurredAt: new Date(accepted.occurred_at),
      action: 'clock_in',
      displayName: 'Lucia G.',
      workedMinutes: 0,
      workDate: '2026-08-14',
    })
  })

  it('debounced: usa el occurred_at que trae la respuesta', () => {
    const result: ScanSubmissionResult = { kind: 'debounced', response: debounced }

    expect(settleFrom(result, SCAN_ID, ATTEMPT_AT)).toMatchObject({
      kind: 'debounced',
      scanId: SCAN_ID,
      occurredAt: new Date(debounced.occurred_at),
      displayName: 'Lucia G.',
      workedMinutes: 240,
      lastAcceptedAt: new Date(debounced.last_accepted_at),
    })
  })

  it('rejected: usa el instante del INTENTO, no hay occurred_at que traer del servidor', () => {
    const result: ScanSubmissionResult = { kind: 'rejected' }

    const confirmation = settleFrom(result, SCAN_ID, ATTEMPT_AT)

    expect(confirmation).toEqual({ kind: 'rejected', scanId: SCAN_ID, occurredAt: ATTEMPT_AT })
    // Sin causa, sin campo extra por el que deducirla (regla dura 17).
    expect(Object.keys(confirmation ?? {}).sort()).toEqual(['kind', 'occurredAt', 'scanId'])
  })

  it('deferred: null — sigue en cola, la pantalla ya dice «pendiente» y no se toca', () => {
    const result: ScanSubmissionResult = { kind: 'deferred' }

    expect(settleFrom(result, SCAN_ID, ATTEMPT_AT)).toBeNull()
  })
})
