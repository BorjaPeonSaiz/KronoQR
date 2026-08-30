// Presentacion legible del contexto de una incidencia (RF-PA-05).
//
// El contrato (`IncidentContext`, `docs/api/openapi.yaml`) es deliberadamente
// abierto: un mapa de enteros cuyas claves dependen del tipo y que puede crecer
// sin tocar el esquema. Confirma solo dos parejas —`rest_minutes` y
// `worked_minutes`, cada una con `threshold_minutes`— y menciona `skew_seconds`
// sin decir con que se empareja.
//
// NUNCA SE INVENTA UNA PAREJA QUE EL CONTRATO NO CONFIRMA. Adivinar que
// `open_minutes` va con `threshold_minutes` para `open_shift_expired`, o que
// `skew_seconds` va con un `threshold_seconds` que nadie ha visto, es exactamente
// el error que produce una frase que dice lo contrario de lo que paso. Lo que no
// esta confirmado se pinta en bruto: la clave tal cual y el numero, sin
// traducir ninguna de las dos cosas.
//
// Puro y sin Vue (como `workdayTotals.ts`, ADR-036): recibe la funcion de
// traduccion como parametro para poder probarse sin montar ningun componente.
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

/**
 * Traduce el contexto de una incidencia a lineas legibles.
 *
 * Empareja cada metrica de minutos CONFIRMADA con `threshold_minutes` y la
 * pinta como «metrica valor de umbral» (p. ej. «descanso 7 h 00 de 12 h 00»,
 * regla dura 3: horas y minutos, nunca decimales). El resto de claves —
 * `threshold_minutes` sin ninguna metrica conocida al lado, `skew_seconds`,
 * cualquier clave nueva— se pinta en bruto, tal cual llega.
 */
export function describeIncidentContext(context: IncidentContext, t: Translate): ContextLine[] {
  const thresholdMinutes = context['threshold_minutes']
  const consumed = new Set<string>()
  const lines: ContextLine[] = []

  if (thresholdMinutes !== undefined) {
    for (const key of KNOWN_MINUTE_METRICS) {
      const value = context[key]

      if (value === undefined) {
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

  for (const [key, value] of Object.entries(context)) {
    if (consumed.has(key)) {
      continue
    }

    lines.push({ key, text: t('incidents.context.raw', { key, value }) })
  }

  return lines
}
