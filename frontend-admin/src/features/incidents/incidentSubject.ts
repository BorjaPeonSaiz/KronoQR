// A quien (o a que) se refiere una incidencia. Casi siempre es una persona, pero la incidencia
// `discarded_scan` sin persona (2.3.0, ADR-061: `employee` nulo) no tiene ninguna: se identifica
// por el quiosco (`context.device_uuid`) y la hora del fichaje (`context.occurred_at`), que son
// lo que el administrador cruza con el cuadrante. Nunca se inventa un nombre.
import { formatInstant } from '@kronoqr/web-kit/datetime'
import type { Incident } from '@/shared/api/types'

type Translate = (key: string, named?: Record<string, unknown>) => string

/** Identificador corto del quiosco, o `null` si el contexto no lo trae. */
export function incidentDevice(incident: Incident): string | null {
  const uuid = incident.context['device_uuid']

  return typeof uuid === 'string' && uuid !== '' ? `${uuid.slice(0, 8)}…` : null
}

/** La hora del fichaje en la zona del centro, o `null` si el contexto no la trae. */
export function incidentScanMoment(
  incident: Incident,
  timeZone: string,
  locale: string,
): string | null {
  const occurredAt = incident.context['occurred_at']

  return typeof occurredAt === 'string' ? formatInstant(occurredAt, timeZone, locale) : null
}

/** El nombre de la persona o, sin persona, «Fichaje sin persona en {quiosco}». */
export function incidentSubject(incident: Incident, t: Translate): string {
  if (incident.employee !== null) {
    return incident.employee.full_name
  }

  return t('incidents.noPerson.label', {
    device: incidentDevice(incident) ?? t('incidents.noPerson.unknownDevice'),
  })
}
