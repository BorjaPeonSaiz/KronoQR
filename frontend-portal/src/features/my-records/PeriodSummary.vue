<script setup lang="ts">
// El resumen de arriba de «Mi registro» (RF-ID-05, RL-05): cuantas horas lleva
// el periodo y como va hoy, antes de cualquier detalle.
//
//  - **El total del periodo es la suma de los `total_minutes` que declara el
//    servidor**, jornada a jornada, y se enseña en horas y minutos, nunca en
//    decimal. No se recalcula desde los tramos: el total del dia lo declara el
//    servidor (RN-06, regla dura 7) y la tabla de cada jornada ya contrasta
//    las dos cifras.
//  - **Si el numero puede cambiar, se dice aqui**, no solo en la tarjeta del
//    dia: un turno abierto, una revision pendiente o un dia cuyo total no
//    cuadra con sus tramos hacen provisional la suma entera.
//  - **«Hoy» lo da el servidor** (`today`, la zona del centro, regla dura 3);
//    si no se sabe, no se inventa con el reloj del telefono.
import { durationParts, sumShiftMinutes } from '@kronoqr/web-kit/workdayTotals'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkDayDetail } from '@/shared/api/types'

const props = defineProps<{
  days: readonly WorkDayDetail[]
  from: string
  to: string
  /** Hoy en el centro (`YYYY-MM-DD`), o `null` si el servidor aun no lo ha dicho. */
  today: string | null
}>()

const { t, locale } = useI18n()

function duration(minutes: number): string {
  return t('myRecords.duration', durationParts(minutes))
}

const periodTotal = computed(() =>
  duration(props.days.reduce((sum, day) => sum + day.total_minutes, 0)),
)

/** Una jornada cuyo total todavia puede cambiar o no se puede dar por bueno. */
function isProvisional(day: WorkDayDetail): boolean {
  return (
    day.has_open_shift ||
    day.has_incident ||
    sumShiftMinutes(day.shift_entries) !== day.total_minutes
  )
}

const provisionalCount = computed(() => props.days.filter(isProvisional).length)

const todayInPeriod = computed(
  () => props.today !== null && props.today >= props.from && props.today <= props.to,
)

const todayDay = computed(() => props.days.find((day) => day.work_date === props.today) ?? null)

const todayHeading = computed(() =>
  props.today === null ? '' : formatCivilDate(props.today, locale.value),
)
</script>

<template>
  <section
    data-test="period-summary"
    :aria-label="t('myRecords.summary.label')"
    class="mt-4 grid gap-4 sm:grid-cols-2"
  >
    <div class="min-w-0 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft">
      <h2 class="text-lg font-medium text-kq-text">{{ t('myRecords.summary.periodTotal') }}</h2>
      <p
        class="mt-1 font-heading text-4xl font-bold tabular-nums text-kq-primary-strong"
        data-test="period-total"
      >
        {{ periodTotal }}
      </p>
      <p class="mt-1 text-kq-text-muted" data-test="period-days">
        {{ t('myRecords.summary.days', { count: days.length, from, to }) }}
      </p>
      <p
        v-if="provisionalCount > 0"
        data-test="period-provisional"
        class="mt-2 rounded-kq border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
      >
        {{ t('myRecords.summary.provisional', { count: provisionalCount }) }}
      </p>
    </div>

    <div
      v-if="todayInPeriod"
      data-test="today-summary"
      class="min-w-0 rounded-kq border border-kq-border-strong bg-kq-surface-raised p-4 shadow-kq-soft"
    >
      <h2 class="text-lg font-medium text-kq-text">{{ t('myRecords.summary.today') }}</h2>
      <p class="text-sm text-kq-text-muted">{{ todayHeading }}</p>

      <template v-if="todayDay !== null">
        <p
          class="mt-1 font-heading text-4xl font-bold tabular-nums text-kq-primary-strong"
          data-test="today-total"
        >
          {{ duration(todayDay.total_minutes) }}
        </p>
        <p v-if="todayDay.has_open_shift" class="mt-2 text-kq-warning" data-test="today-open">
          <span class="font-semibold">{{ t('myRecords.day.flags.openShift') }}.</span>
          {{ t('myRecords.day.flags.openShiftHint') }}
        </p>
        <p v-if="todayDay.has_incident" class="mt-2 text-kq-danger" data-test="today-incident">
          <span class="font-semibold">{{ t('myRecords.day.flags.incident') }}.</span>
          {{ t('myRecords.day.flags.incidentHint') }}
        </p>
      </template>
      <p v-else class="mt-1 text-lg text-kq-text" data-test="today-empty">
        {{ t('myRecords.summary.todayEmpty') }}
      </p>
    </div>
  </section>
</template>
