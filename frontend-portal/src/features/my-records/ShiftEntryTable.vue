<script setup lang="ts">
// Los tramos vigentes de una jornada propia y su total (RF-ID-05, RL-05).
//
// Lo que esta tabla se toma en serio:
//
//  - **Las partes tienen que sumar el total.** El pie enseña la suma de los
//    tramos y, si el servidor declara otro total para el dia, enseña los dos y
//    avisa: nunca se elige uno en silencio (RN-06, regla dura 7).
//  - **Las dos marcas de cada fichaje** (regla dura 9): la hora a la que se
//    ficho y la hora a la que el servidor la recibio. Cuando se diferencian, es
//    que el fichaje viajo en la cola del quiosco sin conexion.
//  - **La hora local se lee, no se convierte** (regla dura 3): el servidor la
//    manda ya resuelta en la zona del centro. Si un tramo se ficho en otro
//    centro -un traslado no reescribe donde ocurrieron las jornadas- se dice.
//  - **Un turno nocturno es UN tramo** (regla dura 4). No se parte: se marca
//    que la salida cae en el dia siguiente.
import {
  formatInstant,
  formatLocalTime,
  formatUtcTime,
  formatZoneLabel,
  minutesBetween,
  readLocalTimestamp,
} from '@kronoqr/web-kit/datetime'
import { durationParts, sumShiftMinutes } from '@kronoqr/web-kit/workdayTotals'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ClockingSource, ShiftEntryStatus, WorkDayShiftEntry } from '@/shared/api/types'

const props = defineProps<{
  entries: readonly WorkDayShiftEntry[]
  /** El total que declara el servidor para el dia. */
  totalMinutes: number
  /** Zona del centro de la jornada. */
  timeZone: string
  /** Jornada a la que pertenecen los tramos, para detectar la salida del dia siguiente. */
  workDate: string
}>()

const { t, locale } = useI18n()

/** A partir de este desfase, la marca no llego en el acto: viajo encolada. */
const QUEUE_NOTICE_MINUTES = 5

interface MarkView {
  local: string
  utc: string
  nextDay: boolean
  recordedAt: string | null
  queueDelayMinutes: number | null
}

interface EntryRow {
  uuid: string
  status: ShiftEntryStatus
  source: ClockingSource
  outSource: ClockingSource | null
  durationMinutes: number | null
  otherTimeZone: string | null
  clockIn: MarkView
  clockOut: MarkView | null
}

function mark(utcValue: string, localValue: string, recordedAt: string | null): MarkView {
  const parts = readLocalTimestamp(localValue)
  const delay = recordedAt === null ? null : minutesBetween(utcValue, recordedAt)

  return {
    local: formatLocalTime(localValue),
    utc: formatUtcTime(utcValue),
    nextDay: parts !== null && parts.date !== props.workDate,
    recordedAt:
      recordedAt === null ? null : formatInstant(recordedAt, props.timeZone, locale.value),
    queueDelayMinutes: delay !== null && delay >= QUEUE_NOTICE_MINUTES ? delay : null,
  }
}

const rows = computed<EntryRow[]>(() =>
  props.entries.map((entry) => ({
    uuid: entry.uuid,
    status: entry.status,
    source: entry.clock_in_source,
    outSource: entry.clock_out_source,
    durationMinutes: entry.duration_minutes,
    otherTimeZone: entry.time_zone === props.timeZone ? null : entry.time_zone,
    clockIn: mark(entry.clocked_in_at, entry.clocked_in_at_local, entry.clocked_in_recorded_at),
    clockOut:
      entry.clocked_out_at === null || entry.clocked_out_at_local === null
        ? null
        : mark(entry.clocked_out_at, entry.clocked_out_at_local, entry.clocked_out_recorded_at),
  })),
)

const summedMinutes = computed(() => sumShiftMinutes(props.entries))
const totalsAgree = computed(() => summedMinutes.value === props.totalMinutes)

/** La etiqueta corta de la zona ese dia: en marzo puede no ser la misma que en julio. */
const zoneLabel = computed(() =>
  formatZoneLabel(`${props.workDate}T12:00:00Z`, props.timeZone, locale.value),
)

function duration(minutes: number): string {
  return t('myRecords.duration', durationParts(minutes))
}
</script>

<template>
  <div>
    <!-- Cero tramos vigentes no es «no hay datos»: puede ser un dia cuyos tramos
         se anularon, y entonces el historico de correcciones lo cuenta entero. -->
    <p
      v-if="entries.length === 0"
      data-test="entries-empty"
      class="rounded-kq border border-dashed border-kq-border-strong bg-kq-surface-raised p-4 text-kq-text-muted"
    >
      {{ t('myRecords.entries.empty') }}
    </p>

    <div
      v-else
      class="overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    >
      <table class="w-full border-collapse text-left">
        <caption class="sr-only">
          {{
            t('myRecords.entries.caption', { date: workDate, zone: timeZone })
          }}
        </caption>
        <thead class="border-b border-kq-border bg-kq-surface-alt">
          <tr>
            <th scope="col" class="px-3 py-2">
              {{ t('myRecords.entries.in', { zone: zoneLabel }) }}
            </th>
            <th scope="col" class="px-3 py-2">
              {{ t('myRecords.entries.out', { zone: zoneLabel }) }}
            </th>
            <th scope="col" class="px-3 py-2">{{ t('myRecords.entries.duration') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('myRecords.entries.source') }}</th>
          </tr>
        </thead>

        <tbody>
          <tr v-for="row of rows" :key="row.uuid" class="border-b border-kq-border align-top">
            <th scope="row" class="px-3 py-2 font-medium">
              <span class="text-lg tabular-nums">{{ row.clockIn.local }}</span>
              <span v-if="row.otherTimeZone !== null" class="ml-1 text-sm font-normal">
                ({{ row.otherTimeZone }})
              </span>
              <span class="block text-sm font-normal text-kq-text-muted">
                {{ t('myRecords.entries.utc', { time: row.clockIn.utc }) }}
              </span>
              <span
                v-if="row.clockIn.queueDelayMinutes !== null"
                class="mt-1 block text-sm font-normal text-kq-warning"
              >
                {{
                  t('myRecords.entries.queued', {
                    delay: duration(row.clockIn.queueDelayMinutes),
                  })
                }}
              </span>
            </th>

            <td class="px-3 py-2">
              <template v-if="row.clockOut === null">
                <span class="text-lg">{{ t('myRecords.entries.open') }}</span>
                <span class="block text-sm text-kq-text-muted">
                  {{ t('myRecords.entries.openHint') }}
                </span>
              </template>
              <template v-else>
                <span class="text-lg tabular-nums">{{ row.clockOut.local }}</span>
                <span v-if="row.clockOut.nextDay" class="ml-1 text-sm text-kq-text-muted">
                  {{ t('myRecords.entries.nextDay') }}
                </span>
                <span class="block text-sm text-kq-text-muted">
                  {{ t('myRecords.entries.utc', { time: row.clockOut.utc }) }}
                </span>
                <span
                  v-if="row.clockOut.queueDelayMinutes !== null"
                  class="mt-1 block text-sm text-kq-warning"
                >
                  {{
                    t('myRecords.entries.queued', {
                      delay: duration(row.clockOut.queueDelayMinutes),
                    })
                  }}
                </span>
              </template>
            </td>

            <td class="px-3 py-2 tabular-nums" data-test="entry-duration">
              <template v-if="row.durationMinutes === null">
                {{ t('myRecords.entries.openDuration') }}
              </template>
              <template v-else>{{ duration(row.durationMinutes) }}</template>
            </td>

            <td class="px-3 py-2">
              {{ t(`myRecords.sources.${row.source}`) }}
              <span v-if="row.outSource !== null && row.outSource !== row.source" class="block">
                {{ t(`myRecords.sources.${row.outSource}`) }}
              </span>
            </td>
          </tr>
        </tbody>

        <tfoot class="border-t-2 border-kq-border-strong bg-kq-surface-alt">
          <tr>
            <th scope="row" colspan="2" class="px-3 py-2 text-right">
              {{ t('myRecords.entries.sum') }}
            </th>
            <td class="px-3 py-2 font-semibold tabular-nums" data-test="summed-total">
              {{ duration(summedMinutes) }}
            </td>
            <td class="px-3 py-2"></td>
          </tr>
          <tr v-if="!totalsAgree">
            <th scope="row" colspan="2" class="px-3 py-2 text-right">
              {{ t('myRecords.entries.declared') }}
            </th>
            <td class="px-3 py-2 font-semibold tabular-nums" data-test="declared-total">
              {{ duration(totalMinutes) }}
            </td>
            <td class="px-3 py-2"></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <p
      v-if="!totalsAgree"
      data-test="totals-mismatch"
      class="mt-2 rounded-kq border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      {{ t('myRecords.entries.mismatch') }}
    </p>
  </div>
</template>
