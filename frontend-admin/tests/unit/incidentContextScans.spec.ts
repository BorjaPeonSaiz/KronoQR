// Contexto de las incidencias scan_before_revocation (RN-20) y discarded_scan
// (RN-22) y sus textos (RF-PA-05).
import { describe, expect, it } from 'vitest'
import { describeIncidentContext } from '@/features/incidents/incidentContext'
import type { Translate } from '@/features/incidents/incidentContext'
import { createAppI18n } from '@/shared/i18n'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'

const i18n = createAppI18n('es')
const t: Translate = (key, params) => String(i18n.global.t(key, params ?? {}))
const ZONE = 'Europe/Madrid'
const LOCALE = 'es'

describe('describeIncidentContext — fichaje anterior a la retirada y fichaje descartado', () => {
  it('scan_before_revocation: etiqueta de intentos sin «PIN» y la retirada traducida', () => {
    const lines = describeIncidentContext(
      {
        scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        occurred_at: '2026-03-14T13:50:00Z',
        attempts: 2,
        max_sync_delay_seconds: 7200,
        withdrawal: 'offboarding',
      },
      t,
      ZONE,
      LOCALE,
      'scan_before_revocation',
    )

    expect(lines.map((line) => line.key)).toEqual([
      'occurred_at',
      'scan_id',
      'attempts',
      'max_sync_delay_seconds',
      'withdrawal',
    ])
    expect(lines.find((line) => line.key === 'attempts')?.text).toBe(
      'fichajes rechazados de la jornada: 2',
    )
    expect(lines.find((line) => line.key === 'withdrawal')?.text).toBe(
      'qué se retiró: la persona está de baja',
    )
  })

  it('rejected_pin_scan conserva la etiqueta de intentos por PIN', () => {
    const lines = describeIncidentContext({ attempts: 4 }, t, ZONE, LOCALE, 'rejected_pin_scan')

    expect(lines).toEqual([{ key: 'attempts', text: 'intentos por PIN sin registrar: 4' }])
  })

  it('discarded_scan: pinta las claves del contrato con etiqueta, sin dejar nada en bruto', () => {
    const lines = describeIncidentContext(
      {
        scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b91',
        occurred_at: '2026-03-14T13:50:00Z',
        device_uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81',
        origin: 'sync',
        http_status: 400,
        problem: '',
        attribution: 'employee_code',
        reports: 3,
      },
      t,
      ZONE,
      LOCALE,
      'discarded_scan',
    )

    expect(lines.map((line) => line.key)).toEqual([
      'occurred_at',
      'scan_id',
      'attribution',
      'device_uuid',
      'origin',
      'http_status',
      'problem',
      'reports',
    ])
    expect(lines.find((line) => line.key === 'attribution')?.text).toBe(
      'atribuido por: el código de empleado',
    )
    expect(lines.find((line) => line.key === 'http_status')?.text).toBe(
      'código de respuesta del servidor: 400',
    )
    expect(lines.find((line) => line.key === 'problem')?.text).toBe('problema declarado: Sin valor')
  })

  it('un valor de retirada que el contrato aun no conoce se enseña en bruto', () => {
    const lines = describeIncidentContext({ withdrawal: 'otra-cosa' }, t, ZONE, LOCALE)

    expect(lines).toEqual([{ key: 'withdrawal', text: 'qué se retiró: otra-cosa' }])
  })

  it('los dos tipos nuevos tienen texto en español e inglés', () => {
    expect(es.incidents.types.scan_before_revocation).toBe(
      'Fichaje anterior a la retirada de la credencial',
    )
    expect(es.incidents.types.discarded_scan).toBe('Fichaje descartado por el quiosco')
    expect(en.incidents.types.scan_before_revocation.length).toBeGreaterThan(5)
    expect(en.incidents.types.discarded_scan.length).toBeGreaterThan(5)
  })
})
