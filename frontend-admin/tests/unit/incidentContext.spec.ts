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

  // RF-PR-06, RN-16 (tarea 3.11): los dos patrones anomalos de uso de
  // credencial que distingue `context.pattern`. `kiosk_coincidence` no lleva
  // una lista de ocurrencias -`IncidentContext` no admite objetos ni listas,
  // ver la nota de cabecera de `incidentContext.ts`-: el backend resume la
  // serie en `first_coincidence_at`/`last_coincidence_at`/`last_gap_seconds`/
  // `min_gap_seconds`, los cuatro escalares que se prueban aqui.
  describe('RF-PR-06: kiosk_coincidence', () => {
    it('quiosco por nombre, enlace a la contraparte principal, dias frente al minimo, ventana y el hueco mas estrecho de la serie', () => {
      const lines = describeWithZone({
        pattern: 'kiosk_coincidence',
        device_id: 3,
        device_name: 'Recepción',
        counterpart_employee_uuid: '0199f0aa-4444-7000-8000-0123456789ae',
        // Una sola contraparte (segunda vuelta, decision 13): sin «y N más».
        counterpart_count: 1,
        coincidence_days: 5,
        min_repeats: 3,
        window_seconds: 10,
        first_coincidence_at: '2026-03-10T05:00:00Z',
        last_coincidence_at: '2026-03-14T05:02:00Z',
        last_gap_seconds: 4,
        min_gap_seconds: 3,
      })

      expect(lines).toEqual([
        { key: 'pattern', text: 'Patrón: coincidencia en el mismo quiosco' },
        { key: 'device_name', text: 'quiosco: Recepción' },
        {
          key: 'counterpart_employee_uuid',
          text: 'Con otra persona (0199f0aa…) — ver sus incidencias en la bandeja',
          counterpartEmployeeUuid: '0199f0aa-4444-7000-8000-0123456789ae',
        },
        { key: 'coincidence_days', text: 'Días con coincidencia: 5 de un mínimo de 3' },
        { key: 'window_seconds', text: 'Ventana aplicada: 10 s o menos entre los dos fichajes' },
        { key: 'first_coincidence_at', text: 'primera coincidencia: 10/3/26, 6:00' },
        { key: 'last_coincidence_at', text: 'última coincidencia: 14/3/26, 6:02' },
        // `last_gap_seconds` NO es un maximo: es el hueco del ultimo dia, que
        // puede ser mayor que el mas estrecho de toda la serie (aqui, 4 s
        // frente a los 3 s mas ajustados que se vieron en algun dia anterior).
        {
          key: 'last_gap_seconds',
          text: 'Hueco más estrecho de la serie: 3 s · último día: 4 s',
        },
      ])

      // `device_id` y `counterpart_count` no se repiten en bruto.
      expect(lines.some((line) => line.key === 'device_id')).toBe(false)
      expect(lines.some((line) => line.key === 'counterpart_count')).toBe(false)
    })

    it('counterpart_count = 2: «y una más», en singular', () => {
      const lines = describeWithZone({
        pattern: 'kiosk_coincidence',
        device_name: 'Recepción',
        counterpart_employee_uuid: '0199f0aa-4444-7000-8000-0123456789ae',
        counterpart_count: 2,
        coincidence_days: 3,
        min_repeats: 3,
        window_seconds: 10,
      })

      const counterpartLine = lines.find((line) => line.key === 'counterpart_employee_uuid')

      expect(counterpartLine?.text).toBe(
        'Con otra persona y una más (0199f0aa…) — ver sus incidencias en la bandeja',
      )
      expect(counterpartLine?.counterpartEmployeeUuid).toBe('0199f0aa-4444-7000-8000-0123456789ae')
    })

    it('counterpart_count = 4: «y 3 más», en plural', () => {
      const lines = describeWithZone({
        pattern: 'kiosk_coincidence',
        device_name: 'Recepción',
        counterpart_employee_uuid: '0199f0aa-4444-7000-8000-0123456789ae',
        counterpart_count: 4,
        coincidence_days: 3,
        min_repeats: 3,
        window_seconds: 10,
      })

      const counterpartLine = lines.find((line) => line.key === 'counterpart_employee_uuid')

      expect(counterpartLine?.text).toBe(
        'Con otra persona y 3 más (0199f0aa…) — ver sus incidencias en la bandeja',
      )
    })

    it('sin ninguna palabra que califique: nunca «fraude» ni «sospechoso»', () => {
      const lines = describeWithZone({
        pattern: 'kiosk_coincidence',
        device_name: 'Cocina',
        counterpart_employee_uuid: '0199f0aa-4444-7000-8000-0123456789ae',
        counterpart_count: 3,
        coincidence_days: 4,
        min_repeats: 3,
        window_seconds: 9,
      })
      const text = lines.map((line) => line.text).join(' ')

      expect(text).not.toMatch(/fraude/i)
      expect(text).not.toMatch(/sospechos/i)
      expect(text).not.toMatch(/enga(ñ|n)/i)
    })
  })

  describe('RN-16: impossible_sequence', () => {
    it('los dos quioscos, los dos momentos y el intervalo frente al minimo de transito', () => {
      const lines = describeWithZone({
        pattern: 'impossible_sequence',
        from_device_id: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81',
        from_device_name: 'Recepción',
        to_device_id: '0199f3c9-2c8e-7a44-8e02-3c4d5e6f7a92',
        to_device_name: 'Cocina',
        first_occurred_at: '2026-03-14T13:50:00Z',
        second_occurred_at: '2026-03-14T13:50:45Z',
        gap_seconds: 45,
        transit_seconds: 120,
        first_scan_id: '0199f0c2-2a5b-7c3e-9b21-4d5e6f7a8ba1',
        second_scan_id: '0199f0c2-3b6c-7c3e-9b21-4d5e6f7a8bb2',
      })

      expect(lines).toEqual([
        { key: 'pattern', text: 'Patrón: secuencia imposible entre dos quioscos' },
        { key: 'from_device_name', text: 'quiosco de origen: Recepción' },
        { key: 'to_device_name', text: 'quiosco de destino: Cocina' },
        // Con segundos (decision 14): sin ellos, 45 s de diferencia se leerian
        // como la misma hora, que es exactamente lo que RN-16 no puede decir.
        { key: 'first_occurred_at', text: 'primer fichaje: 14/3/26, 14:50:00' },
        { key: 'second_occurred_at', text: 'segundo fichaje: 14/3/26, 14:50:45' },
        {
          key: 'gap_seconds',
          text: 'Intervalo entre los dos fichajes: 45 s (mínimo de tránsito: 120 s)',
        },
        {
          key: 'first_scan_id',
          text: 'identificador del primer escaneo: 0199f0c2-2a5b-7c3e-9b21-4d5e6f7a8ba1',
        },
        {
          key: 'second_scan_id',
          text: 'identificador del segundo escaneo: 0199f0c2-3b6c-7c3e-9b21-4d5e6f7a8bb2',
        },
      ])

      // La misma persona en dos quioscos: ninguna linea enlaza a una contrapartida.
      expect(lines.every((line) => line.counterpartEmployeeUuid === undefined)).toBe(true)
    })
  })

  it('mezcla claves conocidas de un patron con una clave que el contrato no ha confirmado nunca', () => {
    const lines = describeWithZone({
      pattern: 'kiosk_coincidence',
      device_name: 'Recepción',
      coincidence_days: 3,
      min_repeats: 3,
      window_seconds: 10,
      // Una clave inventada, como si el contrato creciera manana sin que este
      // modulo se hubiera enterado: cae al bruto, con su nombre tal cual.
      confidence_score: 87,
    })

    expect(lines).toEqual([
      { key: 'pattern', text: 'Patrón: coincidencia en el mismo quiosco' },
      { key: 'device_name', text: 'quiosco: Recepción' },
      { key: 'coincidence_days', text: 'Días con coincidencia: 3 de un mínimo de 3' },
      { key: 'window_seconds', text: 'Ventana aplicada: 10 s o menos entre los dos fichajes' },
      { key: 'confidence_score', text: 'confidence_score: 87' },
    ])
  })
})
