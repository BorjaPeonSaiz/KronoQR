// Presentacion compartida de la severidad de una incidencia (RF-PA-05).
//
// La usan la bandeja (`IncidentTable.vue`) y la marca incrustada en el detalle
// de jornada (`workdays/WorkDayCard.vue`): el mismo hecho tiene que
// verse igual en los dos sitios, y una paleta declarada dos veces es una que
// diverge la primera vez que alguien retoca solo una.
//
// Ningun color propio (doc 06 §7): todo sale de los tokens `--kq-*` que ya
// existen para estado de exito/aviso/peligro. La severidad `low` no es un
// error ni un aviso, asi que usa el tinte primario suave en vez de inventar un
// token «info» que el resto del panel no tiene.
import type { Incident, IncidentSeverity } from '@/shared/api/types'

const SEVERITY_CLASSES: Record<IncidentSeverity, string> = {
  high: 'bg-kq-danger-soft text-kq-danger',
  medium: 'bg-kq-warning-soft text-kq-warning',
  low: 'bg-kq-primary-soft text-kq-on-primary-soft',
}

export function severityBadgeClass(severity: IncidentSeverity): string {
  return SEVERITY_CLASSES[severity]
}

/**
 * Cierre del sistema (RN-19): una `rejected_pin_scan` que se cierra sola como
 * `resolved` cuando la persona ficha bien despues. Es el unico cierre sin
 * persona: `resolved_by` y `resolution_note` llegan `null` (contrato,
 * `Incident.resolved_by`). Se distingue por eso y no por el tipo, para que el
 * dia que otro cierre automatico lo use el texto siga siendo verdad solo si el
 * servidor lo declara asi.
 */
export function isClosedBySystem(
  incident: Pick<Incident, 'status' | 'resolved_by' | 'resolution_note'>,
): boolean {
  return (
    incident.status === 'resolved' &&
    incident.resolved_by === null &&
    incident.resolution_note === null
  )
}
