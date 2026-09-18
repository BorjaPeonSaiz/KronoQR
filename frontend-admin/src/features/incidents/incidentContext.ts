// Presentacion legible del contexto de una incidencia (RF-PA-05).
//
// El contrato (`IncidentContext`, `docs/api/openapi.yaml`) es deliberadamente
// abierto: un mapa de enteros o cadenas cuyas claves dependen del tipo y que
// puede crecer sin tocar el esquema. Confirma tres grupos de claves:
// `rest_minutes`/`worked_minutes`, cada una emparejada con
// `threshold_minutes`; `skew_seconds`, sin decir con que se empareja; y desde
// RN-18 el contexto de `out_of_order_scan` (`scan_id`, `occurred_at` del
// primer escaneo que no cuadro, `scans`, el recuento).
//
// NUNCA SE INVENTA UNA PAREJA QUE EL CONTRATO NO CONFIRMA. Adivinar que
// `open_minutes` va con `threshold_minutes` para `open_shift_expired`, o que
// `skew_seconds` va con un `threshold_seconds` que nadie ha visto, es exactamente
// el error que produce una frase que dice lo contrario de lo que paso. Lo que no
// esta confirmado se pinta en bruto: la clave tal cual y el valor, sin
// traducir ninguna de las dos cosas.
//
// Puro y sin Vue (como `workdayTotals.ts`, ADR-036): recibe la funcion de
// traduccion, la zona horaria y el idioma como parametros para poder probarse
// sin montar ningun componente. LA HORA SE LEE EN LA ZONA DEL CENTRO (regla
// dura 3): nunca la del navegador de quien mira, y `occurred_at` -un instante
// UTC, RN-18- se convierte aqui con la misma `timeZone` que el resto de la
// bandeja, nunca con una supuesta por defecto.
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import type { IncidentContext } from '@/shared/api/types'

export type Translate = (key: string, params?: Record<string, unknown>) => string

/**
 * Metricas en minutos confirmadas por el contrato, cada una emparejada con
 * `threshold_minutes`. Anadir una aqui sin que el contrato la confirme es
 * exactamente lo que este modulo existe para no hacer.
 */
const KNOWN_MINUTE_METRICS: readonly string[] = ['rest_minutes', 'worked_minutes']

function formatMinutes(value: number, t: Translate): string {
  return t('incidents.duration', durationParts(value))
}

export interface ContextLine {
  /** Clave del contexto que explica esta linea: para `:key` y para las pruebas. */
  key: string
  text: string
}

function metricLine(key: string, value: string, t: Translate): ContextLine {
  return {
    key,
    text: t('incidents.context.metric', { metric: t(`incidents.context.metrics.${key}`), value }),
  }
}

/**
 * Traduce el contexto de una incidencia a lineas legibles.
 *
 * Empareja cada metrica de minutos CONFIRMADA con `threshold_minutes` y la
 * pinta como «metrica valor de umbral» (p. ej. «descanso 7 h 00 de 12 h 00»,
 * regla dura 3: horas y minutos, nunca decimales). Traduce ademas, sueltas y
 * con su propia etiqueta, las tres claves de `out_of_order_scan` (RN-18):
 * `occurred_at` en la zona del centro, `scan_id` tal cual -es un UUID, no hay
 * nada que formatear- y `scans` como numero simple, nunca como duracion. El
 * resto de claves -`threshold_minutes` sin ninguna metrica conocida al lado,
 * `skew_seconds`, cualquier clave nueva- se pinta en bruto, tal cual llega.
 */
export function describeIncidentContext(
  context: IncidentContext,
  t: Translate,
  timeZone: string,
  locale: string,
): ContextLine[] {
  const thresholdMinutes = context['threshold_minutes']
  const consumed = new Set<string>()
  const lines: ContextLine[] = []

  // `typeof === 'number'` y no `!== undefined`: desde RN-18 el contrato admite
  // tambien cadenas en el contexto (`scan_id`, `occurred_at` de la incidencia
  // `out_of_order_scan`). Una cadena nunca es una duracion en minutos, asi que
  // cae al pintado especifico o al bruto de abajo, nunca a una frase que diria
  // «descanso 0199f0c2-… de 12 h 00».
  if (typeof thresholdMinutes === 'number') {
    for (const key of KNOWN_MINUTE_METRICS) {
      const value = context[key]

      if (typeof value !== 'number') {
        continue
      }

      lines.push({
        key,
        text: t('incidents.context.pair', {
          metric: t(`incidents.context.metrics.${key}`),
          value: formatMinutes(value, t),
          threshold: formatMinutes(thresholdMinutes, t),
        }),
      })
      consumed.add(key)
      consumed.add('threshold_minutes')
    }
  }

  const occurredAt = context['occurred_at']

  if (typeof occurredAt === 'string') {
    lines.push(metricLine('occurred_at', formatInstant(occurredAt, timeZone, locale), t))
    consumed.add('occurred_at')
  }

  const scanId = context['scan_id']

  if (typeof scanId === 'string') {
    lines.push(metricLine('scan_id', scanId, t))
    consumed.add('scan_id')
  }

  const scans = context['scans']

  if (typeof scans === 'number') {
    lines.push(metricLine('scans', String(scans), t))
    consumed.add('scans')
  }

  for (const [key, value] of Object.entries(context)) {
    if (consumed.has(key)) {
      continue
    }

    lines.push({ key, text: t('incidents.context.raw', { key, value }) })
  }

  return lines
}
