// Presentacion pura (sin Vue) de la vista de cumplimiento (RF-PA-06, tarea
// 3.4). Mismo patron que `devices/devicePresentation.ts` e
// `incidents/incidentPresentation.ts`: se prueba sin montar `ComplianceView`.
//
// NADA SE CALCULA AQUI (regla dura 7). Los minutos medidos, el umbral y la
// diferencia vienen los tres del servidor, que es el unico que compara contra
// el perfil de cumplimiento (regla dura 14). Lo unico que hace este modulo es:
//
//  1. Traducir minutos a horas y minutos (`durationParts`, compartido con el
//     resto del panel: `@kronoqr/web-kit/workdayTotals`).
//  2. Decidir la CLAVE de i18n de cada hallazgo -el nombre de la regla, si la
//     diferencia es lo que falta o lo que sobra, el motivo de una suspension-
//     a partir de un valor YA enumerado por el contrato, nunca de un numero.
//  3. Agrupar los hallazgos por regla, en el orden que trae `meta.rules[]`:
//     una reordenacion de lo que ya llego, no una segunda cuenta.
import { durationParts, type DurationParts } from '@kronoqr/web-kit/workdayTotals'
import type {
  ComplianceFinding,
  ComplianceRuleName,
  ComplianceRuleStatus,
} from '@/shared/api/types'

/** La clave i18n del nombre de una regla, compartida por la tarjeta y la tabla. */
export function ruleLabelKey(rule: ComplianceRuleName): string {
  return `complianceSummary.rules.${rule}.label`
}

/**
 * Si la diferencia de un hallazgo es lo que FALTA (RN-10, el unico defecto: un
 * descanso corto) o lo que SOBRA (RN-11, RN-12, RN-17: los tres son excesos).
 * Puro: la regla ya lo determina por si sola, sin mirar ningun minuto.
 */
export function differenceDirection(rule: ComplianceRuleName): 'missing' | 'excess' {
  return rule === 'insufficient_rest' ? 'missing' : 'excess'
}

/** La clave i18n de la etiqueta «faltan»/«sobran» de un hallazgo. */
export function differenceLabelKey(rule: ComplianceRuleName): string {
  return `complianceSummary.difference.${differenceDirection(rule)}`
}

/** Las tres duraciones de un hallazgo, ya partidas en horas y minutos (`HH:MM`). */
export interface FindingDurations {
  measured: DurationParts
  threshold: DurationParts
  difference: DurationParts
}

export function findingDurations(finding: ComplianceFinding): FindingDurations {
  return {
    measured: durationParts(finding.measured_minutes),
    threshold: durationParts(finding.threshold_minutes),
    difference: durationParts(finding.difference_minutes),
  }
}

/**
 * La clave i18n del motivo por el que una regla no se evalua, o `null` cuando
 * si se evalua. Hoy solo existe `break_clocking_disabled` (RN-12, ADR-024,
 * tarea 3.5: RN-12 se evalua unicamente cuando el fichaje de pausa esta
 * activado), pero el catalogo del contrato es el que decide, no esta lista.
 */
export function suspensionReasonKey(rule: ComplianceRuleStatus): string | null {
  return rule.suspension_reason === null
    ? null
    : `complianceSummary.suspensionReasons.${rule.suspension_reason}`
}

/** Un grupo de hallazgos de la MISMA regla, con su fila de `meta.rules[]` al lado. */
export interface ComplianceFindingsGroup {
  rule: ComplianceRuleStatus
  findings: readonly ComplianceFinding[]
}

/**
 * Los hallazgos agrupados por regla, en el ORDEN de `meta.rules[]`: el mismo
 * orden que las cuatro tarjetas, para que tarjeta y tabla cuenten la misma
 * historia de arriba a abajo. Una regla sin ningun hallazgo en este periodo no
 * genera un grupo vacio -su recuento ya esta en la tarjeta-.
 */
export function groupFindingsByRule(
  findings: readonly ComplianceFinding[],
  rules: readonly ComplianceRuleStatus[],
): ComplianceFindingsGroup[] {
  return rules
    .map((rule) => ({
      rule,
      findings: findings.filter((finding) => finding.rule === rule.rule),
    }))
    .filter((group) => group.findings.length > 0)
}

/**
 * El periodo medido de un hallazgo, como una unica fecha (jornada) o un par de
 * fechas (semana): `work_date` y `week` son excluyentes en el contrato. `null`
 * no deberia ocurrir nunca -el servidor siempre manda uno de los dos-, y aqui
 * se enseña como cadena vacia, nunca inventando una fecha.
 */
export interface FindingPeriod {
  kind: 'day' | 'week'
  /** `work_date`, o `week.starts_on` en una semana. */
  from: string
  /** Igual que `from` en una jornada; `week.ends_on` en una semana. */
  to: string
}

export function findingPeriod(finding: ComplianceFinding): FindingPeriod | null {
  if (finding.work_date !== null) {
    return { kind: 'day', from: finding.work_date, to: finding.work_date }
  }

  if (finding.week !== null) {
    return { kind: 'week', from: finding.week.starts_on, to: finding.week.ends_on }
  }

  return null
}

/** Adonde lleva «Ver incidencia» (RF-PA-05): la bandeja acotada a esa persona, igual que desde el registro horario. */
export function incidentsLinkFor(employeeUuid: string): {
  name: string
  query: Record<string, string>
} {
  return { name: 'incidents', query: { employee: employeeUuid } }
}

/**
 * Adonde lleva el nombre de la persona: el registro horario **al dia del
 * aviso**, no a los ultimos 31 dias que resuelve el servidor por omision.
 *
 * Sin el rango, un hallazgo de hace dos meses aterrizaria fuera de pantalla y
 * quien sigue el enlace veria «sin jornadas» en vez del dia (o la semana) que
 * vino a mirar. `EmployeeWorkDaysView` lee `?from=`/`?to=` igual que
 * `IncidentsView` lee `?employee=`.
 */
export function workdaysLinkFor(finding: ComplianceFinding): {
  name: string
  params: { uuid: string }
  query: { from: string; to: string }
} {
  const period = findingPeriod(finding)

  return {
    name: 'employee-workdays',
    params: { uuid: finding.employee.uuid },
    query: { from: period?.from ?? '', to: period?.to ?? '' },
  }
}
