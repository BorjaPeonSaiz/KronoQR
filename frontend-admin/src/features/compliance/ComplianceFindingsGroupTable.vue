<script setup lang="ts">
// Los hallazgos de UNA regla de la vista de cumplimiento (RF-PA-06).
//
// VIRTUALIZADA A PARTIR DE UN UMBRAL (RC1), con `useVirtualRows`, el mismo
// mecanismo de las demas tablas largas del panel.
// El contrato no pagina esta vista y el rango admite meses de historico de toda
// la plantilla: con miles de hallazgos, pintarlos todos —cada uno con tres
// duraciones y un periodo formateados— bloquea el navegador. Solo se calculan y
// se pintan las filas de la ventana visible (`rowOf` se invoca por fila visible,
// no por hallazgo). `aria-rowcount` declara el total y `aria-rowindex` la
// posicion de cada fila (PA7-003); el contenedor es una region enfocable y con
// nombre (AX7-01).
//
// NADA SE CALCULA AQUI (regla dura 7): `HH:MM` sale de `durationParts` sobre
// los minutos que YA manda el servidor, y «faltan»/«sobran» es una etiqueta
// fija por regla (`compliancePresentation.ts`), nunca una comparacion nueva.
//
// EL ENLACE A LA JORNADA SIEMPRE SE ENSEÑA: esta tabla solo aparece dentro de
// `ComplianceView`, que ya exige `attendance:read` -el mismo ambito que
// `/employees/{uuid}/workdays`-, asi que quien ve una fila siempre puede abrir
// el registro de esa persona (a diferencia del enlace a la bandeja, que exige
// `incidents:*`, un ambito DISTINTO que no todo el mundo con `attendance:read`
// lleva).
//
// COSTE: la virtualizacion rompe la impresion y el «buscar en la pagina» con
// muchos hallazgos. Esta vista no tiene exportacion: se acota con los filtros
// de regla y departamento o un periodo mas corto, y el caso concreto se abre
// por el nombre de la persona.
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { INCIDENTS_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { ComplianceFinding } from '@/shared/api/types'
import { useVirtualRows } from '@/shared/virtualization/useVirtualRows'
import {
  differenceLabelKey,
  findingPeriod,
  incidentsLinkFor,
  ruleLabelKey,
  workdaysLinkFor,
  type ComplianceFindingsGroup,
} from './compliancePresentation'

const props = defineProps<{
  group: ComplianceFindingsGroup
  timeZone: string
}>()

/**
 * Alto fijo de fila: nombre y codigo en dos lineas, y periodo y etiqueta de turno
 * abierto en otras dos (la etiqueta va debajo del periodo, nunca a su lado).
 */
const ROW_HEIGHT_PX = 72

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

/** Todo lo que pinta una fila, calculado una sola vez por hallazgo visible. */
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

const findings = computed(() => props.group.findings)

const scroller = ref<HTMLElement | null>(null)

const { visibleRows, totalHeight } = useVirtualRows(findings, {
  rowHeight: ROW_HEIGHT_PX,
  scroller,
})

/** Lo que pinta cada fila visible: `rowOf` se invoca solo para ellas. */
const entries = computed(() =>
  visibleRows.value.map((visible) => ({
    row: rowOf(visible.item),
    index: visible.index,
    start: visible.start,
    key: visible.key,
  })),
)

const caption = computed(() =>
  t('complianceSummary.table.caption', {
    rule: t(ruleLabelKey(props.group.rule.rule)),
    timeZone: props.timeZone,
  }),
)

const GRID_TEMPLATE =
  'minmax(12rem,1.8fr) minmax(13rem,1.5fr) repeat(3, minmax(6rem,1fr)) minmax(7rem,1fr)'
</script>

<template>
  <div
    ref="scroller"
    tabindex="0"
    role="region"
    :aria-label="caption"
    class="mt-2 max-h-[70vh] relative overflow-auto"
    data-test="compliance-table"
  >
    <div
      role="table"
      :aria-rowcount="findings.length + 1"
      :aria-label="caption"
      class="min-w-4xl text-left"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-20 border-b border-kq-border-strong bg-kq-surface font-semibold"
      >
        <div
          role="row"
          aria-rowindex="1"
          class="grid items-end gap-3 py-2 pr-3"
          :style="{ gridTemplateColumns: GRID_TEMPLATE }"
        >
          <span role="columnheader" class="sticky left-0 z-10 bg-kq-surface">
            {{ t('complianceSummary.table.employee') }}
          </span>
          <span role="columnheader">{{ t('complianceSummary.table.period') }}</span>
          <span role="columnheader" class="text-right">
            {{ t('complianceSummary.table.measured') }}
          </span>
          <span role="columnheader" class="text-right">
            {{ t('complianceSummary.table.threshold') }}
          </span>
          <span role="columnheader" class="text-right">
            {{ t('complianceSummary.table.difference') }}
          </span>
          <span role="columnheader" class="text-right">
            {{ t('complianceSummary.table.incident') }}
          </span>
        </div>
      </div>

      <div role="rowgroup" class="relative" :style="{ height: `${totalHeight}px` }">
        <div
          v-for="entry of entries"
          :key="entry.key"
          role="row"
          :aria-rowindex="entry.index + 2"
          class="absolute left-0 grid w-full items-center gap-3 border-b border-kq-border py-2 pr-3"
          :style="{
            gridTemplateColumns: GRID_TEMPLATE,
            transform: `translateY(${entry.start}px)`,
            height: `${ROW_HEIGHT_PX}px`,
          }"
          data-test="compliance-finding-row"
        >
          <span role="rowheader" class="sticky left-0 z-1 min-w-0 bg-kq-surface">
            <RouterLink
              :to="workdaysLinkFor(entry.row.finding)"
              class="block truncate text-kq-primary-strong underline"
            >
              {{ entry.row.finding.employee.full_name }}
              <span class="sr-only">{{ t('complianceSummary.table.viewWorkdays') }}</span>
            </RouterLink>
            <span class="block truncate font-mono text-sm text-kq-text-muted">
              {{ entry.row.finding.employee.employee_code }}
            </span>
          </span>

          <span role="cell" class="flex min-w-0 flex-col items-start">
            <span class="whitespace-nowrap">{{ entry.row.period }}</span>
            <span
              v-if="entry.row.finding.has_open_shift"
              data-test="finding-open-shift"
              class="mt-1 inline-block whitespace-nowrap rounded-full bg-kq-warning-soft px-2 py-0.5 text-sm font-semibold text-kq-warning"
            >
              <span aria-hidden="true">⏳</span>
              {{ t('complianceSummary.table.openShift') }}
            </span>
          </span>

          <span role="cell" class="text-right tabular-nums" :title="entry.row.measured.title">
            {{ entry.row.measured.text }}
          </span>

          <span role="cell" class="text-right tabular-nums" :title="entry.row.threshold.title">
            {{ entry.row.threshold.text }}
          </span>

          <span role="cell" class="text-right tabular-nums" :title="entry.row.difference.title">
            {{
              t('complianceSummary.difference.value', {
                label: entry.row.differenceLabel,
                duration: entry.row.difference.text,
              })
            }}
          </span>

          <span role="cell" class="text-right">
            <span v-if="entry.row.finding.incident === null" class="text-kq-text-muted">—</span>
            <RouterLink
              v-else-if="canOpenInbox"
              :to="incidentsLinkFor(entry.row.finding.employee.uuid)"
              class="text-kq-primary-strong underline"
              data-test="finding-incident-link"
            >
              {{ t('complianceSummary.table.viewIncident') }}
            </RouterLink>
            <span v-else>{{ t(`incidents.status.${entry.row.finding.incident.status}`) }}</span>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
