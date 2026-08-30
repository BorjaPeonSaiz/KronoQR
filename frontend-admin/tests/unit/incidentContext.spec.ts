// Presentacion del contexto de una incidencia (RF-PA-05).
//
// Lo que se afirma: que las dos parejas confirmadas por el contrato
// (`rest_minutes`/`worked_minutes` con `threshold_minutes`) se pintan en horas
// y minutos, y que TODO lo demas -una clave sin pareja, `skew_seconds`, una
// clave que el contrato no ha mencionado nunca- se pinta en bruto y no se
// inventa ninguna unidad ni direccion.
import { describe, expect, it } from 'vitest'
import { describeIncidentContext } from '@/features/incidents/incidentContext'
import type { Translate } from '@/features/incidents/incidentContext'
import { createAppI18n } from '@/shared/i18n'

const i18n = createAppI18n('es')
const t: Translate = (key, params) => String(i18n.global.t(key, params ?? {}))

describe('describeIncidentContext', () => {
  it('empareja el descanso con su umbral, en horas y minutos', () => {
    const lines = describeIncidentContext({ rest_minutes: 420, threshold_minutes: 720 }, t)

    expect(lines).toEqual([{ key: 'rest_minutes', text: 'descanso 7 h 00 min de 12 h 00 min' }])
  })

  it('empareja lo trabajado con su umbral', () => {
    const lines = describeIncidentContext({ worked_minutes: 541, threshold_minutes: 540 }, t)

    expect(lines).toEqual([{ key: 'worked_minutes', text: 'trabajado 9 h 01 min de 9 h 00 min' }])
  })

  it('sin threshold_minutes, ninguna metrica de minutos se empareja: todo en bruto', () => {
    const lines = describeIncidentContext({ rest_minutes: 420 }, t)

    expect(lines).toEqual([{ key: 'rest_minutes', text: 'rest_minutes: 420' }])
  })

  it('una clave que el contrato no confirma se pinta en bruto, sin inventar unidad', () => {
    const lines = describeIncidentContext({ skew_seconds: 42, threshold_minutes: 15 }, t)

    // `skew_seconds` no esta en la lista confirmada: se pinta tal cual, no se
    // empareja con `threshold_minutes` aunque los dos numeros existan.
    expect(lines).toEqual([
      { key: 'skew_seconds', text: 'skew_seconds: 42' },
      { key: 'threshold_minutes', text: 'threshold_minutes: 15' },
    ])
  })

  it('un contexto vacio no produce ninguna linea', () => {
    expect(describeIncidentContext({}, t)).toEqual([])
  })
})
