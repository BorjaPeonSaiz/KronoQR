// Presentacion legible del contexto de una incidencia (RF-PA-05).
//
// El contrato (`IncidentContext`, `docs/api/openapi.yaml`) es deliberadamente
// abierto: un mapa de enteros o cadenas cuyas claves dependen del tipo y que
// puede crecer sin tocar el esquema. Confirma tres grupos de claves:
// `rest_minutes`/`worked_minutes`, cada una emparejada con
// `threshold_minutes`; `skew_seconds`, sin decir con que se empareja; desde
// RN-18 el contexto de `out_of_order_scan` (`scan_id`, `occurred_at` del
// primer escaneo que no cuadro, `scans`, el recuento); y desde RF-PR-06/RN-16
// (tarea 3.11) los dos patrones anomalos de uso de credencial que distingue
// `context.pattern`: `kiosk_coincidence` y `impossible_sequence`.
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
//
// SIN NINGUNA PALABRA QUE CALIFIQUE (RF-PR-06: el sistema aporta el indicio,
// nunca la conclusion). Las frases de `kiosk_coincidence` e
// `impossible_sequence` dicen que quiosco, que persona y que momentos, nunca
// «fraude», «sospechoso» ni «engaño» - ni aqui ni en `es.json`/`en.json`.
//
// `kiosk_coincidence` NO LLEVA UNA LISTA DE OCURRENCIAS: `IncidentContext`
// solo admite un valor ESCALAR por clave -entero o cadena de hasta 64
// caracteres (`docs/api/openapi.yaml`, ADR-012: «ni objetos ni listas»)-, asi
// que el backend resume la serie en cuatro escalares en vez de una lista:
// `first_coincidence_at`/`last_coincidence_at` (el primer y el ultimo dia con
// coincidencia dentro de la ventana revisada) y `last_gap_seconds`/
// `min_gap_seconds` (la diferencia de la coincidencia mas reciente y la mas
// ajustada de toda la serie). Los pares concretos, dia a dia, quedan en
// `audit_log` y en el log tecnico del comando -no en el contexto de la
// incidencia-, que es donde vive el detalle que no cabe en un escalar.
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
  /**
   * Presente solo en la linea que nombra a la otra persona de una
   * `kiosk_coincidence` (RF-PR-06): el uuid al que debe apuntar el enlace al
   * filtro por empleado de la bandeja (`/incidents?employee=<uuid>`, el mismo
   * que usa `WorkDayCard.vue`). El NOMBRE nunca viaja en el contexto (regla
   * dura 21) y este modulo -puro, sin Vue- no lo resuelve: la otra persona
   * puede ser de un departamento distinto del de quien revisa, y adivinar su
   * nombre con un directorio cargado para otro proposito seria exactamente la
   * ampliacion de finalidad que la minimizacion prohibe. Quien monta el
   * componente Vue convierte esto en un enlace; el texto ya lleva el uuid
   * acortado para cuando no haga falta ni eso.
   */
  counterpartEmployeeUuid?: string
}

function metricLine(key: string, value: string, t: Translate): ContextLine {
  return {
    key,
    text: t('incidents.context.metric', { metric: t(`incidents.context.metrics.${key}`), value }),
  }
}

/** Los 8 primeros caracteres de un UUID, para cuando no hay nombre que enseñar. */
function shortUuid(uuid: string): string {
  return `${uuid.slice(0, 8)}…`
}

/**
 * Un instante UTC en la zona del centro, con SEGUNDOS (a diferencia de
 * `formatInstant`, que los omite a proposito para el resto de la bandeja).
 * `impossible_sequence` (RN-16) los necesita: un salto de 45 s entre dos
 * fichajes se lee como la misma hora con precision de minuto, y sin segundos
 * la frase parece decir que los dos momentos son identicos cuando no lo son.
 */
function formatInstantWithSeconds(value: string, timeZone: string, locale: string): string {
  const instant = new Date(value)

  if (Number.isNaN(instant.getTime())) {
    return ''
  }

  return new Intl.DateTimeFormat(locale, {
    timeZone,
    year: '2-digit',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
  }).format(instant)
}

/**
 * `kiosk_coincidence` (RF-PR-06, tarea 3.11; rediseño de la segunda vuelta,
 * decision 13): un hallazgo por PERSONA, no por par -de ahi que sea un
 * `counterpart_employee_uuid` (la contraparte principal, con mas dias) y un
 * `counterpart_count` (cuantas personas distintas en total, ⩾ 1)-. El patron,
 * el quiosco por nombre, el enlace a la contraparte principal (nunca su
 * nombre, y «y N mas» cuando `counterpart_count` pasa de 1), los dias de
 * coincidencia de esa contraparte frente al minimo, la ventana aplicada, el
 * primer y el ultimo dia de TODA la serie de la persona, y el hueco mas
 * estrecho de toda la serie frente al del ultimo dia -ver la nota del
 * encabezado del fichero sobre por que son escalares y no una lista-.
 */
function describeKioskCoincidence(
  context: IncidentContext,
  t: Translate,
  timeZone: string,
  locale: string,
  consumed: Set<string>,
  lines: ContextLine[],
): void {
  lines.push({
    key: 'pattern',
    text: t('incidents.context.patternLine', {
      pattern: t('incidents.context.patterns.kiosk_coincidence'),
    }),
  })
  consumed.add('pattern')

  const deviceName = context['device_name']

  if (typeof deviceName === 'string') {
    lines.push(metricLine('device_name', deviceName, t))
    consumed.add('device_name')
    consumed.add('device_id')
  }

  const counterpartUuid = context['counterpart_employee_uuid']
  const counterpartCount = context['counterpart_count']

  if (typeof counterpartUuid === 'string') {
    // `extra` es cuanta gente MAS, aparte de la contraparte principal ya
    // nombrada: `counterpart_count` cuenta a todo el mundo, incluida ella.
    // Sin `counterpart_count` (contexto antiguo o incompleto) se trata como
    // una sola contraparte, igual que antes de la decision 13.
    const extra = typeof counterpartCount === 'number' ? Math.max(0, counterpartCount - 1) : 0
    const key =
      extra === 0
        ? 'incidents.context.kioskCoincidence.counterpartLink'
        : extra === 1
          ? 'incidents.context.kioskCoincidence.counterpartLinkOneMore'
          : 'incidents.context.kioskCoincidence.counterpartLinkManyMore'

    lines.push({
      key: 'counterpart_employee_uuid',
      text: t(key, { uuid: shortUuid(counterpartUuid), count: extra }),
      counterpartEmployeeUuid: counterpartUuid,
    })
    consumed.add('counterpart_employee_uuid')
    consumed.add('counterpart_count')
  }

  const coincidenceDays = context['coincidence_days']
  const minRepeats = context['min_repeats']

  if (typeof coincidenceDays === 'number' && typeof minRepeats === 'number') {
    lines.push({
      key: 'coincidence_days',
      text: t('incidents.context.kioskCoincidence.coincidenceDays', {
        days: coincidenceDays,
        min: minRepeats,
      }),
    })
    consumed.add('coincidence_days')
    consumed.add('min_repeats')
  }

  const windowSeconds = context['window_seconds']

  if (typeof windowSeconds === 'number') {
    lines.push({
      key: 'window_seconds',
      text: t('incidents.context.kioskCoincidence.windowSeconds', { seconds: windowSeconds }),
    })
    consumed.add('window_seconds')
  }

  const firstCoincidenceAt = context['first_coincidence_at']

  if (typeof firstCoincidenceAt === 'string') {
    lines.push(
      metricLine('first_coincidence_at', formatInstant(firstCoincidenceAt, timeZone, locale), t),
    )
    consumed.add('first_coincidence_at')
  }

  const lastCoincidenceAt = context['last_coincidence_at']

  if (typeof lastCoincidenceAt === 'string') {
    lines.push(
      metricLine('last_coincidence_at', formatInstant(lastCoincidenceAt, timeZone, locale), t),
    )
    consumed.add('last_coincidence_at')
  }

  const lastGapSeconds = context['last_gap_seconds']
  const minGapSeconds = context['min_gap_seconds']

  if (typeof lastGapSeconds === 'number' && typeof minGapSeconds === 'number') {
    lines.push({
      key: 'last_gap_seconds',
      text: t('incidents.context.kioskCoincidence.gapRange', {
        last: lastGapSeconds,
        min: minGapSeconds,
      }),
    })
    consumed.add('last_gap_seconds')
    consumed.add('min_gap_seconds')
  }

  // `occurred_at`/`scan_id`/`scans` de arriba no colisionan: `kiosk_coincidence`
  // no lleva esas claves sueltas.
}

/**
 * `impossible_sequence` (RN-16, tarea 3.11, decision 3): dos escaneos
 * aceptados de la misma credencial en dispositivos distintos separados por
 * menos del tiempo minimo de transito. Un solo caso basta -no es una
 * frecuencia-, asi que no hay contrapartida que enlazar: es la misma persona.
 */
function describeImpossibleSequence(
  context: IncidentContext,
  t: Translate,
  timeZone: string,
  locale: string,
  consumed: Set<string>,
  lines: ContextLine[],
): void {
  lines.push({
    key: 'pattern',
    text: t('incidents.context.patternLine', {
      pattern: t('incidents.context.patterns.impossible_sequence'),
    }),
  })
  consumed.add('pattern')

  const fromDeviceName = context['from_device_name']

  if (typeof fromDeviceName === 'string') {
    lines.push(metricLine('from_device_name', fromDeviceName, t))
    consumed.add('from_device_name')
    consumed.add('from_device_id')
  }

  const toDeviceName = context['to_device_name']

  if (typeof toDeviceName === 'string') {
    lines.push(metricLine('to_device_name', toDeviceName, t))
    consumed.add('to_device_name')
    consumed.add('to_device_id')
  }

  const firstOccurredAt = context['first_occurred_at']

  if (typeof firstOccurredAt === 'string') {
    lines.push(
      metricLine(
        'first_occurred_at',
        formatInstantWithSeconds(firstOccurredAt, timeZone, locale),
        t,
      ),
    )
    consumed.add('first_occurred_at')
  }

  const secondOccurredAt = context['second_occurred_at']

  if (typeof secondOccurredAt === 'string') {
    lines.push(
      metricLine(
        'second_occurred_at',
        formatInstantWithSeconds(secondOccurredAt, timeZone, locale),
        t,
      ),
    )
    consumed.add('second_occurred_at')
  }

  const gapSeconds = context['gap_seconds']
  const transitSeconds = context['transit_seconds']

  if (typeof gapSeconds === 'number' && typeof transitSeconds === 'number') {
    lines.push({
      key: 'gap_seconds',
      text: t('incidents.context.impossibleSequence.gap', {
        gap: gapSeconds,
        min: transitSeconds,
      }),
    })
    consumed.add('gap_seconds')
    consumed.add('transit_seconds')
  }

  const firstScanId = context['first_scan_id']

  if (typeof firstScanId === 'string') {
    lines.push(metricLine('first_scan_id', firstScanId, t))
    consumed.add('first_scan_id')
  }

  const secondScanId = context['second_scan_id']

  if (typeof secondScanId === 'string') {
    lines.push(metricLine('second_scan_id', secondScanId, t))
    consumed.add('second_scan_id')
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

  const pattern = context['pattern']

  if (pattern === 'kiosk_coincidence') {
    describeKioskCoincidence(context, t, timeZone, locale, consumed, lines)
  } else if (pattern === 'impossible_sequence') {
    describeImpossibleSequence(context, t, timeZone, locale, consumed, lines)
  }

  for (const [key, value] of Object.entries(context)) {
    if (consumed.has(key)) {
      continue
    }

    lines.push({ key, text: t('incidents.context.raw', { key, value }) })
  }

  return lines
}
