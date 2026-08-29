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
import { formatZoneLabel } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { LivePresenceEntry } from '@/shared/api/types'

const props = defineProps<{
  entries: readonly LivePresenceEntry[]
  timeZone: string
  /** El «ahora» del servidor, en milisegundos desde la epoca. */
  serverNowMs: number
}>()

/** A partir de cuantas filas merece la pena no pintarlas todas. */
const VIRTUALIZE_FROM = 80
const ROW_HEIGHT_PX = 52

const { t, locale } = useI18n()

const scroller = ref<HTMLElement | null>(null)

const virtual = computed(() => props.entries.length >= VIRTUALIZE_FROM)

const virtualizer = useVirtualizer(
  computed(() => ({
    count: virtual.value ? props.entries.length : 0,
    getScrollElement: () => scroller.value,
    estimateSize: () => ROW_HEIGHT_PX,
    overscan: 12,
  })),
)

const visibleRows = computed(() =>
  virtual.value
    ? virtualizer.value
        .getVirtualItems()
        .map((item) => ({
          entry: props.entries[item.index],
          start: item.start,
          key: String(item.key),
        }))
        .filter(
          (row): row is { entry: LivePresenceEntry; start: number; key: string } =>
            row.entry !== undefined,
        )
    : props.entries.map((entry, index) => ({
        entry,
        start: index * ROW_HEIGHT_PX,
        key: entry.employee_uuid,
      })),
)

const totalHeight = computed(() =>
  virtual.value ? virtualizer.value.getTotalSize() : props.entries.length * ROW_HEIGHT_PX,
)

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

  const minutes = Math.floor((props.serverNowMs - Date.parse(entry.clocked_in_at)) / 60_000)

  return t('live.duration', durationParts(minutes))
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
    class="max-h-[70vh] overflow-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    data-test="presence-table"
  >
    <div
      role="table"
      :aria-label="t('live.table.caption', { zone: zoneLabel })"
      class="min-w-[56rem]"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-10 border-b border-kq-border bg-kq-surface-alt text-left text-sm font-semibold"
      >
        <div role="row" class="grid grid-cols-[2fr_1.2fr_1fr_1fr_1.4fr] gap-2 px-3 py-2">
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
          class="absolute left-0 grid w-full grid-cols-[2fr_1.2fr_1fr_1fr_1.4fr] items-center gap-2 border-b border-kq-border px-3"
          :style="{ transform: `translateY(${row.start}px)`, height: `${ROW_HEIGHT_PX}px` }"
          data-test="presence-entry"
          :data-employee="row.entry.employee_uuid"
          :data-status="row.entry.status"
        >
          <span role="cell" class="truncate font-medium" data-test="entry-name">
            {{ row.entry.full_name }}
          </span>
          <span role="cell" class="truncate text-kq-text-muted">
            {{ row.entry.department?.name ?? t('live.table.noDepartment') }}
          </span>
          <span role="cell" class="tabular-nums" data-test="entry-since">
            {{ clockedInLabel(row.entry) }}
          </span>
          <span role="cell" class="tabular-nums" data-test="entry-elapsed">
            {{ elapsedLabel(row.entry) }}
          </span>
          <span role="cell" class="truncate text-kq-text-muted" data-test="entry-origin">
            <template v-if="row.entry.status === 'present'">
              {{ originLabel(row.entry) }}
              <template v-if="row.entry.device !== null"> · {{ row.entry.device.name }}</template>
            </template>
            <template v-else>{{ t('live.table.notInside') }}</template>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
