<script setup lang="ts">
// La lista de presencia (RF-PA-01): una fila por persona del alcance.
//
// VIRTUALIZADA A PARTIR DE UN UMBRAL. Con 500 empleados (doc 02, Anexo A) pintar
// 500 filas en cada mensaje del WebSocket es lo que rompe el LCP de 1,5 s
// (RNF-P-04); por debajo del umbral se pintan todas, que es mas simple, se
// prueba en jsdom sin medir nada y no cambia lo que ve nadie.
//
// LAS HORAS SE MUESTRAN EN LA ZONA DEL CENTRO, con la zona escrita en la
// cabecera (regla dura 3). El tiempo transcurrido se calcula contra el reloj del
// servidor que entrega el store, nunca contra el del navegador.
import { formatZoneLabel, minutesBetween, todayInZone } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useSessionStore } from '@/features/auth/session.store'
import type { LivePresenceEntry } from '@/shared/api/types'
import { useVirtualRows } from '@/shared/virtualization/useVirtualRows'

const props = defineProps<{
  entries: readonly LivePresenceEntry[]
  timeZone: string
  /** El «ahora» del servidor, en milisegundos desde la epoca. */
  serverNowMs: number
}>()

const ROW_HEIGHT_PX = 52

const { t, locale } = useI18n()
const session = useSessionStore()

/**
 * `WorkDayJournalPolicy::readers()`: administracion, RRHH y responsable de
 * departamento. El auditor NO lee el detalle de jornada (decision pendiente del
 * propietario): un enlace que acaba en 403 crea frustracion, no la evita. La
 * autorizacion real es del servidor.
 */
const canReadWorkDays = computed(() =>
  session.roles.some(
    (role) => role === 'admin' || role === 'rrhh' || role === 'responsable_departamento',
  ),
)

const scroller = ref<HTMLElement | null>(null)

const items = computed(() => props.entries)

const { visibleRows, totalHeight } = useVirtualRows(items, {
  rowHeight: ROW_HEIGHT_PX,
  scroller,
  key: (entry) => entry.employee_uuid,
  overscan: 12,
})

const zoneLabel = computed(() =>
  props.entries[0]?.clocked_in_at
    ? formatZoneLabel(props.entries[0].clocked_in_at, props.timeZone, locale.value)
    : props.timeZone,
)

const timeFormatter = computed(
  () =>
    new Intl.DateTimeFormat(locale.value, {
      timeZone: props.timeZone,
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    }),
)

function clockedInLabel(entry: LivePresenceEntry): string {
  if (entry.clocked_in_at === null) {
    return t('live.table.notInside')
  }

  const instant = new Date(entry.clocked_in_at)

  if (Number.isNaN(instant.getTime())) {
    return ''
  }

  const sameDay =
    new Intl.DateTimeFormat('en-CA', { timeZone: props.timeZone, dateStyle: 'short' }).format(
      instant,
    ) ===
    new Intl.DateTimeFormat('en-CA', { timeZone: props.timeZone, dateStyle: 'short' }).format(
      new Date(props.serverNowMs),
    )

  const time = timeFormatter.value.format(instant)

  return sameDay ? time : t('live.table.previousDay', { time })
}

function elapsedLabel(entry: LivePresenceEntry): string {
  if (entry.clocked_in_at === null) {
    return '—'
  }

  const minutes =
    minutesBetween(entry.clocked_in_at, new Date(props.serverNowMs).toISOString()) ?? 0

  return t('live.duration', durationParts(minutes))
}

/**
 * El detalle de jornada de esa persona en el dia de su entrada, en la zona del
 * centro (regla dura 3); si no esta dentro, en el dia actual del servidor. El
 * rango viaja en la URL: sin el, el servidor devolveria los ultimos 31 dias.
 */
function workdaysLink(entry: LivePresenceEntry): {
  name: string
  params: { uuid: string }
  query: { from: string; to: string }
} {
  const reference =
    entry.clocked_in_at === null ? new Date(props.serverNowMs) : new Date(entry.clocked_in_at)
  const day = todayInZone(
    props.timeZone,
    Number.isNaN(reference.getTime()) ? new Date(props.serverNowMs) : reference,
  )

  return {
    name: 'employee-workdays',
    params: { uuid: entry.employee_uuid },
    query: { from: day, to: day },
  }
}

function originLabel(entry: LivePresenceEntry): string {
  if (entry.origin === null) {
    return '—'
  }

  const key = `live.origin.${entry.origin}`

  return t(key) === key ? entry.origin : t(key)
}
</script>

<template>
  <div
    ref="scroller"
    tabindex="0"
    role="region"
    :aria-label="t('live.table.caption', { zone: zoneLabel })"
    class="max-h-[70vh] overflow-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    data-test="presence-table"
  >
    <div
      role="table"
      :aria-rowcount="entries.length + 1"
      :aria-label="t('live.table.caption', { zone: zoneLabel })"
      class="min-w-[56rem]"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-10 border-b border-kq-border bg-kq-surface-alt text-left text-sm font-semibold"
      >
        <div
          role="row"
          aria-rowindex="1"
          class="grid grid-cols-[2fr_1.2fr_1fr_1fr_1.4fr] gap-2 px-3 py-2"
        >
          <span role="columnheader">{{ t('live.table.name') }}</span>
          <span role="columnheader">{{ t('live.table.department') }}</span>
          <span role="columnheader">{{ t('live.table.since', { zone: zoneLabel }) }}</span>
          <span role="columnheader">{{ t('live.table.elapsed') }}</span>
          <span role="columnheader">{{ t('live.table.origin') }}</span>
        </div>
      </div>

      <div role="rowgroup" class="relative" :style="{ height: `${totalHeight}px` }">
        <div
          v-for="row of visibleRows"
          :key="row.key"
          role="row"
          :aria-rowindex="row.index + 2"
          class="absolute left-0 grid w-full grid-cols-[2fr_1.2fr_1fr_1fr_1.4fr] items-center gap-2 border-b border-kq-border px-3"
          :style="{ transform: `translateY(${row.start}px)`, height: `${ROW_HEIGHT_PX}px` }"
          data-test="presence-entry"
          :data-employee="row.item.employee_uuid"
          :data-status="row.item.status"
        >
          <span role="cell" class="truncate font-medium" data-test="entry-name">
            <RouterLink
              v-if="canReadWorkDays"
              :to="workdaysLink(row.item)"
              class="underline decoration-kq-border-strong underline-offset-2"
              data-test="entry-workdays-link"
              :aria-label="t('live.table.openWorkDay', { name: row.item.full_name })"
            >
              {{ row.item.full_name }}
            </RouterLink>
            <template v-else>{{ row.item.full_name }}</template>
          </span>
          <span role="cell" class="truncate text-kq-text-muted">
            {{ row.item.department?.name ?? t('live.table.noDepartment') }}
          </span>
          <span role="cell" class="tabular-nums" data-test="entry-since">
            {{ clockedInLabel(row.item) }}
          </span>
          <span role="cell" class="tabular-nums" data-test="entry-elapsed">
            {{ elapsedLabel(row.item) }}
          </span>
          <span role="cell" class="truncate text-kq-text-muted" data-test="entry-origin">
            <template v-if="row.item.status === 'present'">
              {{ originLabel(row.item) }}
              <template v-if="row.item.device !== null"> · {{ row.item.device.name }}</template>
            </template>
            <template v-else>{{ t('live.table.notInside') }}</template>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
