// Presentacion del contexto de una incidencia (RF-PA-05).
//
// Lo que se afirma: que las dos parejas confirmadas por el contrato
// (`rest_minutes`/`worked_minutes` con `threshold_minutes`) se pintan en horas
// y minutos; que las tres claves de `out_of_order_scan` (RN-18) se pintan
// legibles -`occurred_at` en la zona del centro, `scan_id` tal cual, `scans`
// como numero simple-; y que TODO lo demas -una clave sin pareja,
// `skew_seconds`, una clave que el contrato no ha mencionado nunca- se pinta
// en bruto y no se inventa ninguna unidad ni direccion.
import { describe, expect, it } from 'vitest'
import { describeIncidentContext } from '@/features/incidents/incidentContext'
import type { Translate } from '@/features/incidents/incidentContext'
import { createAppI18n } from '@/shared/i18n'

const i18n = createAppI18n('es')
const t: Translate = (key, params) => String(i18n.global.t(key, params ?? {}))

// Zona distinta de UTC a proposito: si el codigo usara la zona del navegador
// en vez de la que se le pasa, esta prueba lo destaparia (regla dura 3).
const ZONE = 'Europe/Madrid'
const LOCALE = 'es'

function describeWithZone(context: Parameters<typeof describeIncidentContext>[0]) {
  return describeIncidentContext(context, t, ZONE, LOCALE)
}

describe('describeIncidentContext', () => {
  it('empareja el descanso con su umbral, en horas y minutos', () => {
    const lines = describeWithZone({ rest_minutes: 420, threshold_minutes: 720 })

    expect(lines).toEqual([{ key: 'rest_minutes', text: 'descanso 7 h 00 min de 12 h 00 min' }])
  })

  it('empareja lo trabajado con su umbral', () => {
    const lines = describeWithZone({ worked_minutes: 541, threshold_minutes: 540 })

    expect(lines).toEqual([{ key: 'worked_minutes', text: 'trabajado 9 h 01 min de 9 h 00 min' }])
  })

  it('sin threshold_minutes, ninguna metrica de minutos se empareja: todo en bruto', () => {
    const lines = describeWithZone({ rest_minutes: 420 })

    expect(lines).toEqual([{ key: 'rest_minutes', text: 'rest_minutes: 420' }])
  })

  it('una clave que el contrato no confirma se pinta en bruto, sin inventar unidad', () => {
    const lines = describeWithZone({ skew_seconds: 42, threshold_minutes: 15 })

    // `skew_seconds` no esta en la lista confirmada: se pinta tal cual, no se
    // empareja con `threshold_minutes` aunque los dos numeros existan.
    expect(lines).toEqual([
      { key: 'skew_seconds', text: 'skew_seconds: 42' },
      { key: 'threshold_minutes', text: 'threshold_minutes: 15' },
    ])
  })

  it('un contexto vacio no produce ninguna linea', () => {
    expect(describeWithZone({})).toEqual([])
  })

  it('RN-18: el contexto de out_of_order_scan se pinta legible, la hora en la zona del centro', () => {
    const lines = describeWithZone({
      scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
      occurred_at: '2026-03-14T13:50:00Z',
      scans: 2,
    })

    expect(lines).toEqual([
      { key: 'occurred_at', text: 'hora del fichaje: 14/3/26, 14:50' },
      { key: 'scan_id', text: 'identificador del escaneo: 0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90' },
      { key: 'scans', text: 'escaneos fuera de orden: 2' },
    ])
  })
})
