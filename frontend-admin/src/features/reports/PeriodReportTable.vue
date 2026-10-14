<script setup lang="ts">
// La tabla del informe por periodo (RF-IN-01, RF-IN-02, RF-IN-03).
//
// AQUI NO SE CALCULA NINGUNA CIFRA. Los minutos y su `HH:MM` vienen los dos del
// servidor, que es el unico que lee la proyeccion de jornadas (regla dura 7).
// Sumar o formatear horas en el navegador seria una segunda forma de calcular lo
// mismo, y el dia que discreparan la pantalla enseñaria una cosa y el CSV de la
// tarea 2.9 otra.
//
// LAS HORAS SE ENSEÑAN EN `HH:MM` Y NUNCA EN DECIMAL. «7,75 h» obliga a
// interpretar y ademas cambia de sentido segun el separador decimal de quien lo
// lea. Los minutos enteros van en el `title` de cada celda, para quien necesite
// el numero exacto.
//
// VIRTUALIZADA A PARTIR DE UN UMBRAL (V5-PA-1), con el mismo mecanismo que
// `features/incidents/IncidentTable.vue` y `features/live/PresenceTable.vue`: el
// servidor admite hasta 20 000 filas por informe y pintarlas todas bloquea el
// navegador. Solo se pinta la ventana visible; `aria-rowcount` declara el total
// y `aria-rowindex` la posicion de cada fila (PA7-003). El contenedor con
// desplazamiento es una region enfocable y con nombre (AX7-01).
//
// COSTE: la virtualizacion rompe la impresion del navegador y el «buscar en la
// pagina» (solo existe en el DOM lo que se ve). Para eso esta la exportacion
// (RF-IN-04), que lleva TODAS las filas.
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PeriodReportRow } from '@/shared/api/types'

const props = defineProps<{
  rows: readonly PeriodReportRow[]
  /** Zona del centro, para el encabezado. Los periodos ya vienen expresados en ella. */
  timeZone: string
}>()

/** A partir de cuantas filas merece la pena no pintarlas todas. */
const VIRTUALIZE_FROM = 80
/** Alto fijo de fila: la celda del sujeto lleva nombre y codigo en dos lineas. */
const ROW_HEIGHT_PX = 64

const { t } = useI18n()

const scroller = ref<HTMLElement | null>(null)

const virtual = computed(() => props.rows.length >= VIRTUALIZE_FROM)

const virtualizer = useVirtualizer(
  computed(() => ({
    count: virtual.value ? props.rows.length : 0,
    getScrollElement: () => scroller.value,
    estimateSize: () => ROW_HEIGHT_PX,
    overscan: 10,
  })),
)

interface VisibleRow {
  row: PeriodReportRow
  index: number
  start: number
  key: string
}

const visibleRows = computed<VisibleRow[]>(() =>
  virtual.value
    ? virtualizer.value
        .getVirtualItems()
        .map((item) => ({
          row: props.rows[item.index],
          index: item.index,
          start: item.start,
          key: String(item.key),
        }))
        .filter((visible): visible is VisibleRow => visible.row !== undefined)
    : props.rows.map((row, index) => ({
        row,
        index,
        start: index * ROW_HEIGHT_PX,
        key: String(index),
      })),
)

const totalHeight = computed(() =>
  virtual.value ? virtualizer.value.getTotalSize() : props.rows.length * ROW_HEIGHT_PX,
)

/**
 * La etiqueta del sujeto de una fila.
 *
 * `label` viene nulo en el cubo de quien no tiene departamento, y ahi el texto
 * lo pone el cliente: el servidor no inventa un «Sin departamento» en castellano
 * porque no sabe en que idioma se esta pintando.
 */
function subjectLabel(row: PeriodReportRow): string {
  return row.subject.label ?? t('reports.table.withoutDepartment')
}

/**
 * El periodo, escrito de la forma mas corta que siga siendo exacta.
 *
 * Un cubo de un solo dia se escribe una vez; los demas, con sus dos extremos.
 * Los extremos vienen **ya recortados al rango pedido**, asi que lo que se lee
 * es exactamente lo que se ha contado.
 */
function periodLabel(row: PeriodReportRow): string {
  return row.period.from === row.period.to
    ? row.period.from
    : `${row.period.from} → ${row.period.to}`
}

/** Con signo, para que una desviacion negativa se lea como tal. */
function deviationClass(row: PeriodReportRow): string {
  return row.deviation_minutes < 0 ? 'text-kq-text-muted' : 'text-kq-text'
}

const hasSubjectColumn = computed(() => props.rows.some((row) => row.subject.kind !== 'site'))

/** La rejilla de columnas, la misma en la cabecera y en cada fila. */
const gridTemplate = computed(
  () =>
    `${hasSubjectColumn.value ? 'minmax(11rem,1.6fr) ' : ''}minmax(9rem,1.4fr) repeat(12, minmax(4.5rem,1fr))`,
)

const caption = computed(() => t('reports.table.caption', { timeZone: props.timeZone }))
</script>

<template>
  <div
    ref="scroller"
    tabindex="0"
    role="region"
    :aria-label="caption"
    class="mt-4 max-h-[70vh] overflow-auto"
    data-test="period-report-table"
  >
    <div
      role="table"
      :aria-rowcount="rows.length + 1"
      :aria-label="caption"
      class="min-w-7xl text-left"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-10 border-b border-kq-border-strong bg-kq-surface font-semibold"
      >
        <div
          role="row"
          aria-rowindex="1"
          class="grid items-end gap-3 py-2 pr-3"
          :style="{ gridTemplateColumns: gridTemplate }"
        >
          <span v-if="hasSubjectColumn" role="columnheader">{{ t('reports.table.subject') }}</span>
          <span role="columnheader">{{ t('reports.table.period') }}</span>
          <span role="columnheader" class="text-right">{{ t('reports.table.worked') }}</span>
          <span role="columnheader" class="text-right">{{ t('reports.table.contracted') }}</span>
          <span role="columnheader" class="text-right">{{ t('reports.table.deviation') }}</span>
          <span role="columnheader" class="text-right">{{ t('reports.table.overtime') }}</span>
          <span role="columnheader" class="text-right">{{ t('reports.table.shifts') }}</span>
          <span role="columnheader" class="text-right">
            {{ t('reports.table.daysWithActivity') }}
          </span>
          <span role="columnheader" class="text-right">
            {{ t('reports.table.daysWithoutActivity') }}
          </span>
          <span role="columnheader" class="text-right">{{ t('reports.table.openShiftDays') }}</span>
          <span role="columnheader" class="text-right">{{ t('reports.table.incidentDays') }}</span>
          <!-- RF-GP-04 (tarea 3.10): las ausencias registradas no cuentan como
               absentismo no justificado, y el calendario de festivos del
               perfil de cumplimiento tampoco. `title` explica cada columna:
               son tres numeros que se prestan a confundirse entre si. -->
          <span role="columnheader" class="text-right" :title="t('reports.table.absenceDaysTitle')">
            {{ t('reports.table.absenceDays') }}
          </span>
          <span role="columnheader" class="text-right" :title="t('reports.table.holidayDaysTitle')">
            {{ t('reports.table.holidayDays') }}
          </span>
          <span
            role="columnheader"
            class="text-right"
            :title="t('reports.table.unjustifiedAbsenceDaysTitle')"
          >
            {{ t('reports.table.unjustifiedAbsenceDays') }}
          </span>
        </div>
      </div>

      <div role="rowgroup" class="relative" :style="{ height: `${totalHeight}px` }">
        <div
          v-for="visible of visibleRows"
          :key="visible.key"
          role="row"
          :aria-rowindex="visible.index + 2"
          class="absolute left-0 grid w-full items-center gap-3 border-b border-kq-border py-2 pr-3"
          :style="{
            gridTemplateColumns: gridTemplate,
            transform: `translateY(${visible.start}px)`,
            height: `${ROW_HEIGHT_PX}px`,
          }"
          data-test="report-row"
        >
          <span v-if="hasSubjectColumn" role="rowheader" class="min-w-0">
            <span class="block truncate" :title="subjectLabel(visible.row)">{{
              subjectLabel(visible.row)
            }}</span>
            <span
              v-if="visible.row.subject.employee_code !== null"
              class="block truncate text-sm text-kq-text-muted"
              >{{ visible.row.subject.employee_code }}</span
            >
          </span>
          <span role="cell" class="tabular-nums">{{ periodLabel(visible.row) }}</span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :title="`${visible.row.worked_minutes} min`"
          >
            {{ visible.row.worked }}
          </span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :title="`${visible.row.contracted_minutes} min`"
          >
            {{ visible.row.contracted }}
          </span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :class="deviationClass(visible.row)"
            :title="`${visible.row.deviation_minutes} min`"
          >
            {{ visible.row.deviation }}
          </span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :title="`${visible.row.overtime_minutes} min`"
          >
            {{ visible.row.overtime }}
          </span>
          <span role="cell" class="text-right tabular-nums">{{ visible.row.shift_count }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.row.days_with_activity
          }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.row.days_without_activity
          }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.row.open_shift_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.row.incident_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.row.absence_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.row.holiday_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.row.unjustified_absence_days
          }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
