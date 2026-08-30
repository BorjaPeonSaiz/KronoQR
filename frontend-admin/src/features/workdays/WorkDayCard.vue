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
import { RouterLink } from 'vue-router'
import { INCIDENTS_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { severityBadgeClass } from '@/features/incidents/incidentPresentation'
import type { WorkDayDetail } from '@/shared/api/types'
import CorrectionHistory from './CorrectionHistory.vue'
import ShiftEntryTable from './ShiftEntryTable.vue'

const props = defineProps<{
  day: WorkDayDetail
  /** Para el enlace a la bandeja filtrada por persona (RF-PA-05). */
  employeeUuid: string
}>()

const { t, locale } = useI18n()
const session = useSessionStore()

// La bandeja exige `incidents:*`: sin el, el enlace llevaria a un 403 que la
// interfaz puede evitar de antemano (regla dura 18, cortesia y no seguridad).
const canOpenInbox = computed(() => session.can(INCIDENTS_MANAGE))

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
  <article data-test="workday" class="rounded-kq border border-kq-border bg-kq-surface-alt p-4">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
      <div>
        <h2 class="text-xl font-bold">
          {{ heading }}
          <span class="text-base font-normal text-kq-text-muted">
            ({{ day.time_zone }}, {{ zoneLabel }})
          </span>
        </h2>
        <p class="mt-1 flex flex-wrap gap-2">
          <span
            v-if="day.has_open_shift"
            data-test="flag-open-shift"
            class="rounded-full bg-kq-warning-soft px-3 py-1 text-sm font-semibold text-kq-warning"
          >
            {{ t('workdays.day.flags.openShift') }}
          </span>
          <span
            v-if="day.has_incident"
            data-test="flag-incident"
            class="rounded-full bg-kq-danger-soft px-3 py-1 text-sm font-semibold text-kq-danger"
          >
            {{ t('workdays.day.flags.incident') }}
          </span>
        </p>
      </div>

      <p class="text-right">
        <span class="block text-sm text-kq-text-muted">{{ t('workdays.day.total') }}</span>
        <span class="text-2xl font-bold tabular-nums" data-test="day-total">{{ total }}</span>
      </p>
    </header>

    <p v-if="day.has_open_shift" class="mt-2 text-kq-text-muted">
      {{ t('workdays.day.flags.openShiftHint') }}
    </p>
    <p v-if="day.has_incident" class="mt-1 text-kq-text-muted">
      {{ t('workdays.day.flags.incidentHint') }}
    </p>

    <!-- La ficha minima de cada incidencia (RF-PA-05): distinta de la bandera
         de arriba, que solo dice si algun tramo quedo `anomalous` (RN-07/08).
         Una jornada puede tener incidencias sin ningun tramo anomalo -RN-10
         mira el descanso ENTRE jornadas- y al reves. -->
    <section class="mt-4" data-test="workday-incidents">
      <h3 class="font-semibold">{{ t('incidents.workday.heading') }}</h3>

      <p v-if="day.incidents.length === 0" class="mt-1 text-kq-text-muted">
        {{ t('incidents.workday.none') }}
      </p>

      <ul v-else class="mt-2 flex flex-wrap gap-2">
        <li v-for="incident of day.incidents" :key="incident.id">
          <span
            class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-semibold"
            :class="severityBadgeClass(incident.severity)"
            data-test="workday-incident-badge"
          >
            {{ t(`incidents.types.${incident.type}`) }}
            <span class="font-normal">({{ t(`incidents.status.${incident.status}`) }})</span>
          </span>
        </li>
      </ul>

      <!-- `text-kq-text`, no `text-kq-primary-strong`: ese tono solo esta
           medido en contraste de texto sobre `surface`/`surface-raised`
           (doc 06 §6, `themePairs.ts`), y esta tarjeta es `surface-alt`. El
           subrayado sigue distinguiendolo como enlace sin depender del color. -->
      <RouterLink
        v-if="canOpenInbox"
        :to="{ name: 'incidents', query: { employee: employeeUuid } }"
        class="mt-2 inline-block font-semibold text-kq-text underline"
      >
        {{ t('incidents.workday.linkToInbox') }}
      </RouterLink>
    </section>

    <ShiftEntryTable
      class="mt-4"
      :entries="day.shift_entries"
      :total-minutes="day.total_minutes"
      :time-zone="day.time_zone"
      :work-date="day.work_date"
    />

    <p class="mt-2 text-sm text-kq-text-muted">
      {{
        recalculatedAt === null
          ? t('workdays.day.recalculatedNever')
          : t('workdays.day.recalculatedAt', { moment: recalculatedAt })
      }}
    </p>

    <CorrectionHistory class="mt-4" :corrections="day.corrections" :time-zone="day.time_zone" />
  </article>
</template>
