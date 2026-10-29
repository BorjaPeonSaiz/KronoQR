<script setup lang="ts">
// La tabla del informe por periodo (RF-IN-01, RF-IN-02, RF-IN-03).
//
// AQUI NO SE CALCULA NINGUNA CIFRA. Los minutos y su `HH:MM` vienen los dos del
// servidor, que es el unico que lee la proyeccion de jornadas (regla dura 7).
// Sumar o formatear horas en el navegador seria una segunda forma de calcular lo
// mismo, y el dia que discreparan la pantalla enseñaria una cosa y el CSV otra.
//
// LAS HORAS SE ENSEÑAN EN `HH:MM` Y NUNCA EN DECIMAL. «7,75 h» obliga a
// interpretar y ademas cambia de sentido segun el separador decimal de quien lo
// lea. Los minutos enteros van en el `title` de cada celda, para quien necesite
// el numero exacto.
//
// VIRTUALIZADA A PARTIR DE UN UMBRAL, con `useVirtualRows`, el mismo
// mecanismo de las demas tablas largas del panel: el
// servidor admite hasta 20 000 filas por informe y pintarlas todas bloquea el
// navegador. Solo se pinta la ventana visible; `aria-rowcount` declara el total
// y `aria-rowindex` la posicion de cada fila. El contenedor con
// desplazamiento es una region enfocable y con nombre.
//
// COSTE: la virtualizacion rompe la impresion del navegador y el «buscar en la
// pagina» (solo existe en el DOM lo que se ve). Para eso esta la exportacion
// (RF-IN-04), que lleva TODAS las filas.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PeriodReportRow } from '@/shared/api/types'
import { useVirtualRows } from '@/shared/virtualization/useVirtualRows'

const props = defineProps<{
  rows: readonly PeriodReportRow[]
  /** Zona del centro, para el encabezado. Los periodos ya vienen expresados en ella. */
  timeZone: string
  /**
   * Cuantas filas tendra el informe cuando termine de llegar, si aun llegan: `aria-rowcount`
   * declara el total y no las que hay (la lectura por Worker entrega las primeras filas antes
   * que las demas, `periodReportChunks.ts`). Sin valor, las que hay.
   */
  totalRows?: number | undefined
}>()

/** Alto fijo de fila: la celda del sujeto lleva nombre y codigo en dos lineas. */
const ROW_HEIGHT_PX = 64

const { t } = useI18n()

const scroller = ref<HTMLElement | null>(null)

const items = computed(() => props.rows)

const { visibleRows, totalHeight } = useVirtualRows(items, {
  rowHeight: ROW_HEIGHT_PX,
  scroller,
  overscan: 3,
})

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

/** Anchos minimos de las columnas, en rem: de ellos salen la rejilla y el ancho de la tabla. */
const SUBJECT_COLUMN_REM = 11
const PERIOD_COLUMN_REM = 9
/**
 * Las doce columnas de cifras, en el orden de la cabecera. Las que llevan una palabra
 * larga («Contratadas», «Contracted», «incidencia») son mas anchas: partir una
 * palabra a mitad no es fiable (depende del diccionario de guionado del navegador) y
 * una cabecera que se monta sobre la vecina se lee mal. El resto parte por espacios.
 */
const METRIC_COLUMN_REMS: readonly number[] = [
  6.25, 6.25, 6.25, 4.5, 4.5, 4.5, 4.5, 4.5, 6.25, 4.5, 4.5, 6.25,
]
/** `gap-3` entre columnas y `pr-3` al final de la fila. */
const GAP_REM = 0.75

/** La rejilla de columnas, la misma en la cabecera y en cada fila. */
const gridTemplate = computed(
  () =>
    `${hasSubjectColumn.value ? `minmax(${SUBJECT_COLUMN_REM}rem,1.6fr) ` : ''}minmax(${PERIOD_COLUMN_REM}rem,1.4fr) ${METRIC_COLUMN_REMS.map((rem) => `minmax(${rem}rem,1fr)`).join(' ')}`,
)

/**
 * Lo que mide la rejilla con todas las columnas en su minimo. La tabla no puede
 * ser mas estrecha: las filas van en `absolute w-full`, y una tabla mas corta que
 * su rejilla las dejaria desbordadas por la derecha, con la ultima franja fuera
 * de la cabecera fija.
 */
const gridMinWidth = computed(() => {
  const columns = METRIC_COLUMN_REMS.length + 1 + (hasSubjectColumn.value ? 1 : 0)
  const columnsRem =
    (hasSubjectColumn.value ? SUBJECT_COLUMN_REM : 0) +
    PERIOD_COLUMN_REM +
    METRIC_COLUMN_REMS.reduce((total, rem) => total + rem, 0)

  return `${columnsRem + (columns - 1) * GAP_REM + GAP_REM}rem`
})

const caption = computed(() => t('reports.table.caption', { timeZone: props.timeZone }))
</script>

<template>
  <div
    ref="scroller"
    tabindex="0"
    role="region"
    :aria-label="caption"
    class="mt-4 max-h-[70vh] relative overflow-auto"
    data-test="period-report-table"
  >
    <div
      role="table"
      :aria-rowcount="(totalRows ?? rows.length) + 1"
      :aria-busy="totalRows !== undefined"
      :aria-label="caption"
      class="text-left"
      :style="{ minWidth: `max(100%, ${gridMinWidth})` }"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-10 border-b border-kq-border-strong bg-kq-surface font-semibold"
      >
        <div
          role="row"
          aria-rowindex="1"
          class="grid items-end gap-3 py-2 pr-3 hyphens-auto break-words"
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
          <!-- RF-GP-04: las ausencias registradas no cuentan como
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
            <span class="block truncate" :title="subjectLabel(visible.item)">{{
              subjectLabel(visible.item)
            }}</span>
            <span
              v-if="visible.item.subject.employee_code !== null"
              class="block truncate text-sm text-kq-text-muted"
              >{{ visible.item.subject.employee_code }}</span
            >
          </span>
          <span role="cell" class="tabular-nums">{{ periodLabel(visible.item) }}</span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :title="`${visible.item.worked_minutes} min`"
          >
            {{ visible.item.worked }}
          </span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :title="`${visible.item.contracted_minutes} min`"
          >
            {{ visible.item.contracted }}
          </span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :class="deviationClass(visible.item)"
            :title="`${visible.item.deviation_minutes} min`"
          >
            {{ visible.item.deviation }}
          </span>
          <span
            role="cell"
            class="text-right tabular-nums"
            :title="`${visible.item.overtime_minutes} min`"
          >
            {{ visible.item.overtime }}
          </span>
          <span role="cell" class="text-right tabular-nums">{{ visible.item.shift_count }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.item.days_with_activity
          }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.item.days_without_activity
          }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.item.open_shift_days
          }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.item.incident_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.item.absence_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{ visible.item.holiday_days }}</span>
          <span role="cell" class="text-right tabular-nums">{{
            visible.item.unjustified_absence_days
          }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
