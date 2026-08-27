<script setup lang="ts">
// Una jornada completa: sus banderas, su total, sus tramos y su historico
// (RF-PA-03).
//
// El total del dia se enseña en horas y minutos, **nunca en decimal**: `8,08 h`
// no se puede comprobar contra una nomina de un vistazo y ademas invita a
// redondear. La suma detallada, y el aviso de si no cuadra, los pone la tabla de
// tramos.
//
// Las dos banderas del contrato se pintan con palabras y no solo con color
// (WCAG 2.2 AA, 1.4.1): un turno abierto significa que el total todavia va a
// subir, y una incidencia que alguien tiene que mirar el dia. Ninguna de las dos
// es un error del que haya que avisar a gritos.
import { formatCivilDate, formatInstantWithZone, formatZoneLabel } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkDayDetail } from '@/shared/api/types'
import CorrectionHistory from './CorrectionHistory.vue'
import ShiftEntryTable from './ShiftEntryTable.vue'

const props = defineProps<{ day: WorkDayDetail }>()

const { t, locale } = useI18n()

const heading = computed(() => formatCivilDate(props.day.work_date, locale.value))

const zoneLabel = computed(() =>
  formatZoneLabel(`${props.day.work_date}T12:00:00Z`, props.day.time_zone, locale.value),
)

const total = computed(() => t('workdays.duration', durationParts(props.day.total_minutes)))

const recalculatedAt = computed(() =>
  props.day.recalculated_at === null
    ? null
    : formatInstantWithZone(props.day.recalculated_at, props.day.time_zone, locale.value),
)
</script>

<template>
  <article data-test="workday" class="rounded border border-slate-300 bg-slate-50 p-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold">
          {{ heading }}
          <span class="text-base font-normal text-slate-600">
            ({{ day.time_zone }}, {{ zoneLabel }})
          </span>
        </h2>
        <p class="mt-1 flex flex-wrap gap-2">
          <span
            v-if="day.has_open_shift"
            data-test="flag-open-shift"
            class="rounded-full border border-amber-500 bg-amber-50 px-3 py-1 text-sm font-semibold text-amber-900"
          >
            {{ t('workdays.day.flags.openShift') }}
          </span>
          <span
            v-if="day.has_incident"
            data-test="flag-incident"
            class="rounded-full border border-red-500 bg-red-50 px-3 py-1 text-sm font-semibold text-red-900"
          >
            {{ t('workdays.day.flags.incident') }}
          </span>
        </p>
      </div>

      <p class="text-right">
        <span class="block text-sm text-slate-600">{{ t('workdays.day.total') }}</span>
        <span class="text-2xl font-bold tabular-nums" data-test="day-total">{{ total }}</span>
      </p>
    </header>

    <p v-if="day.has_open_shift" class="mt-2 text-slate-700">
      {{ t('workdays.day.flags.openShiftHint') }}
    </p>
    <p v-if="day.has_incident" class="mt-1 text-slate-700">
      {{ t('workdays.day.flags.incidentHint') }}
    </p>

    <ShiftEntryTable
      class="mt-4"
      :entries="day.shift_entries"
      :total-minutes="day.total_minutes"
      :time-zone="day.time_zone"
      :work-date="day.work_date"
    />

    <p class="mt-2 text-sm text-slate-600">
      {{
        recalculatedAt === null
          ? t('workdays.day.recalculatedNever')
          : t('workdays.day.recalculatedAt', { moment: recalculatedAt })
      }}
    </p>

    <CorrectionHistory class="mt-4" :corrections="day.corrections" :time-zone="day.time_zone" />
  </article>
</template>
