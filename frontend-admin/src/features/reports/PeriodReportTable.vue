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
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PeriodReportRow } from '@/shared/api/types'

const props = defineProps<{
  rows: readonly PeriodReportRow[]
  /** Zona del centro, para el encabezado. Los periodos ya vienen expresados en ella. */
  timeZone: string
}>()

const { t } = useI18n()

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
</script>

<template>
  <table class="w-full border-collapse text-left">
    <caption class="sr-only">
      {{
        t('reports.table.caption', { timeZone })
      }}
    </caption>
    <thead>
      <tr class="border-b border-kq-border-strong">
        <th v-if="hasSubjectColumn" scope="col" class="py-2 pr-3 font-semibold">
          {{ t('reports.table.subject') }}
        </th>
        <th scope="col" class="py-2 pr-3 font-semibold">{{ t('reports.table.period') }}</th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.worked') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.contracted') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.deviation') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.overtime') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.shifts') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.daysWithActivity') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.daysWithoutActivity') }}
        </th>
        <th scope="col" class="py-2 pr-3 text-right font-semibold">
          {{ t('reports.table.openShiftDays') }}
        </th>
        <th scope="col" class="py-2 text-right font-semibold">
          {{ t('reports.table.incidentDays') }}
        </th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="(row, index) of rows"
        :key="`${row.subject.employee_uuid ?? row.subject.department_id ?? 'site'}-${row.period.from}-${index}`"
        class="border-b border-kq-border"
        data-test="report-row"
      >
        <th v-if="hasSubjectColumn" scope="row" class="py-2 pr-3 font-normal">
          {{ subjectLabel(row) }}
          <span
            v-if="row.subject.employee_code !== null"
            class="block text-sm text-kq-text-muted"
            >{{ row.subject.employee_code }}</span
          >
        </th>
        <td class="py-2 pr-3 tabular-nums">{{ periodLabel(row) }}</td>
        <td class="py-2 pr-3 text-right tabular-nums" :title="`${row.worked_minutes} min`">
          {{ row.worked }}
        </td>
        <td class="py-2 pr-3 text-right tabular-nums" :title="`${row.contracted_minutes} min`">
          {{ row.contracted }}
        </td>
        <td
          class="py-2 pr-3 text-right tabular-nums"
          :class="deviationClass(row)"
          :title="`${row.deviation_minutes} min`"
        >
          {{ row.deviation }}
        </td>
        <td class="py-2 pr-3 text-right tabular-nums" :title="`${row.overtime_minutes} min`">
          {{ row.overtime }}
        </td>
        <td class="py-2 pr-3 text-right tabular-nums">{{ row.shift_count }}</td>
        <td class="py-2 pr-3 text-right tabular-nums">{{ row.days_with_activity }}</td>
        <td class="py-2 pr-3 text-right tabular-nums">{{ row.days_without_activity }}</td>
        <td class="py-2 pr-3 text-right tabular-nums">{{ row.open_shift_days }}</td>
        <td class="py-2 text-right tabular-nums">{{ row.incident_days }}</td>
      </tr>
    </tbody>
  </table>
</template>
