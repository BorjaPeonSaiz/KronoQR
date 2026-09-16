<script setup lang="ts">
// Los hallazgos de la vista de cumplimiento (RF-PA-06), una tabla SEMANTICA
// por regla -no virtualizada: el contrato no pagina esta vista porque los
// hallazgos son escasos (solo incumplimientos) y el techo lo pone el rango de
// fechas, no el volumen de filas (`REPORTING_COMPLIANCE_MAX_RANGE_DAYS`).
//
// NADA SE CALCULA AQUI (regla dura 7): `HH:MM` sale de `durationParts` sobre
// los minutos que YA manda el servidor, y «faltan»/«sobran» es una etiqueta
// fija por regla (`compliancePresentation.ts`), nunca una comparacion nueva.
//
// EL ENLACE A LA JORNADA SIEMPRE SE ENSEÑA: esta tabla solo aparece dentro de
// `ComplianceView`, que ya exige `attendance:read` -el mismo ambito que
// `/employees/{uuid}/workdays`-, asi que quien ve una fila siempre puede abrir
// el registro de esa persona (a diferencia del enlace a la bandeja de abajo,
// que exige `incidents:*`, un ambito DISTINTO que no todo el mundo con
// `attendance:read` lleva).
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { INCIDENTS_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { ComplianceFinding } from '@/shared/api/types'
import {
  differenceLabelKey,
  findingPeriod,
  incidentsLinkFor,
  ruleLabelKey,
  workdaysLinkFor,
  type ComplianceFindingsGroup,
} from './compliancePresentation'

const props = defineProps<{
  groups: readonly ComplianceFindingsGroup[]
  timeZone: string
}>()

const { t, locale } = useI18n()
const session = useSessionStore()

// La bandeja exige `incidents:*`: sin el, el enlace llevaria a un 403 que la
// interfaz puede evitar de antemano (regla dura 18, cortesia y no seguridad).
const canOpenInbox = computed(() => session.can(INCIDENTS_MANAGE))

interface Amount {
  /** `HH:MM`, listo para pintar. */
  text: string
  /** Los minutos exactos, para el `title`: quien necesita el numero para una nomina no tiene que sumar. */
  title: string
}

function amount(minutes: number): Amount {
  return { text: t('complianceSummary.duration', durationParts(minutes)), title: `${minutes} min` }
}

/** El periodo medido de un hallazgo, ya formateado: una fecha, o el intervalo de una semana. */
function periodLabel(finding: ComplianceFinding): string {
  const period = findingPeriod(finding)

  if (period === null) {
    return ''
  }

  if (period.kind === 'day') {
    return formatCivilDate(period.from, locale.value)
  }

  return `${formatCivilDate(period.from, locale.value)} – ${formatCivilDate(period.to, locale.value)}`
}

/** Todo lo que pinta una fila, calculado una sola vez por hallazgo. */
interface FindingRow {
  finding: ComplianceFinding
  period: string
  measured: Amount
  threshold: Amount
  difference: Amount
  differenceLabel: string
}

function rowOf(finding: ComplianceFinding): FindingRow {
  return {
    finding,
    period: periodLabel(finding),
    measured: amount(finding.measured_minutes),
    threshold: amount(finding.threshold_minutes),
    difference: amount(finding.difference_minutes),
    differenceLabel: t(differenceLabelKey(finding.rule)),
  }
}
</script>

<template>
  <div class="mt-4 flex flex-col gap-8">
    <section v-for="group of groups" :key="group.rule.rule" data-test="compliance-group">
      <h3 class="text-lg font-semibold">
        {{ t(ruleLabelKey(group.rule.rule)) }}
        <span class="font-normal text-kq-text-muted">({{ group.findings.length }})</span>
      </h3>

      <div class="mt-2 overflow-x-auto">
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('complianceSummary.table.caption', {
                rule: t(ruleLabelKey(group.rule.rule)),
                timeZone: props.timeZone,
              })
            }}
          </caption>
          <thead>
            <tr class="border-b border-kq-border-strong">
              <th scope="col" class="sticky left-0 z-10 bg-kq-surface py-2 pr-3 font-semibold">
                {{ t('complianceSummary.table.employee') }}
              </th>
              <th scope="col" class="py-2 pr-3 font-semibold">
                {{ t('complianceSummary.table.period') }}
              </th>
              <th scope="col" class="py-2 pr-3 text-right font-semibold">
                {{ t('complianceSummary.table.measured') }}
              </th>
              <th scope="col" class="py-2 pr-3 text-right font-semibold">
                {{ t('complianceSummary.table.threshold') }}
              </th>
              <th scope="col" class="py-2 pr-3 text-right font-semibold">
                {{ t('complianceSummary.table.difference') }}
              </th>
              <th scope="col" class="py-2 text-right font-semibold">
                {{ t('complianceSummary.table.incident') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(row, index) of group.findings.map(rowOf)"
              :key="`${row.finding.employee.uuid}-${row.finding.work_date ?? row.finding.week?.starts_on ?? index}`"
              class="border-b border-kq-border"
              data-test="compliance-finding-row"
            >
              <th scope="row" class="sticky left-0 z-1 bg-kq-surface py-2 pr-3 font-normal">
                <RouterLink
                  :to="workdaysLinkFor(row.finding)"
                  class="text-kq-primary-strong underline"
                >
                  {{ row.finding.employee.full_name }}
                  <span class="sr-only">{{ t('complianceSummary.table.viewWorkdays') }}</span>
                </RouterLink>
                <span class="block font-mono text-sm text-kq-text-muted">
                  {{ row.finding.employee.employee_code }}
                </span>
              </th>

              <td class="py-2 pr-3">
                <span>{{ row.period }}</span>
                <span
                  v-if="row.finding.has_open_shift"
                  data-test="finding-open-shift"
                  class="ml-2 inline-block rounded-full bg-kq-warning-soft px-2 py-0.5 text-sm font-semibold text-kq-warning"
                >
                  <span aria-hidden="true">⏳</span>
                  {{ t('complianceSummary.table.openShift') }}
                </span>
              </td>

              <td class="py-2 pr-3 text-right tabular-nums" :title="row.measured.title">
                {{ row.measured.text }}
              </td>

              <td class="py-2 pr-3 text-right tabular-nums" :title="row.threshold.title">
                {{ row.threshold.text }}
              </td>

              <td class="py-2 pr-3 text-right tabular-nums" :title="row.difference.title">
                {{
                  t('complianceSummary.difference.value', {
                    label: row.differenceLabel,
                    duration: row.difference.text,
                  })
                }}
              </td>

              <td class="py-2 text-right">
                <span v-if="row.finding.incident === null" class="text-kq-text-muted">—</span>
                <RouterLink
                  v-else-if="canOpenInbox"
                  :to="incidentsLinkFor(row.finding.employee.uuid)"
                  class="text-kq-primary-strong underline"
                  data-test="finding-incident-link"
                >
                  {{ t('complianceSummary.table.viewIncident') }}
                </RouterLink>
                <span v-else>{{ t(`incidents.status.${row.finding.incident.status}`) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
