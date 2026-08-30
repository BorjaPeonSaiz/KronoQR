<script setup lang="ts">
// Filas de la bandeja de incidencias (RF-PA-05).
//
// VIRTUALIZADA A PARTIR DE UN UMBRAL, igual que `features/live/PresenceTable.vue`
// (mismo motivo, doc 02 Anexo A: 500 empleados y meses de historico). El
// contrato limita `per_page` a 100, asi que en la practica casi nunca se
// virtualiza dentro de una pagina, pero el componente no depende de eso.
//
// LA SEVERIDAD SE DICE CON PALABRAS, NO SOLO CON COLOR (WCAG 2.2 AA, 1.4.1):
// el texto («Alta», «Media», «Baja») va siempre junto al color de fondo.
//
// LA ANTIGUEDAD SE CALCULA CONTRA EL RELOJ DEL SERVIDOR (regla dura 3): la
// vista pasa `serverNowMs`, nunca se usa `Date.now()` aqui dentro.
import { formatInstant, formatZoneLabel } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { useVirtualizer } from '@tanstack/vue-virtual'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { ATTENDANCE_READ } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { Incident } from '@/shared/api/types'
import { severityBadgeClass } from './incidentPresentation'

const props = defineProps<{
  entries: readonly Incident[]
  timeZone: string
  /** El «ahora» del servidor, en milisegundos desde la epoca. */
  serverNowMs: number
}>()

const emit = defineEmits<{ resolve: [incident: Incident] }>()

/** A partir de cuantas filas merece la pena no pintarlas todas. */
const VIRTUALIZE_FROM = 80
const ROW_HEIGHT_PX = 84

const { t, locale } = useI18n()
const session = useSessionStore()

// El registro horario exige `attendance:read`: sin el, el enlace a la jornada
// llevaria a un 403 que la interfaz puede evitar de antemano (regla dura 18,
// el filtro es cortesia, no seguridad).
const canViewWorkday = computed(() => session.can(ATTENDANCE_READ))

const scroller = ref<HTMLElement | null>(null)

const virtual = computed(() => props.entries.length >= VIRTUALIZE_FROM)

const virtualizer = useVirtualizer(
  computed(() => ({
    count: virtual.value ? props.entries.length : 0,
    getScrollElement: () => scroller.value,
    estimateSize: () => ROW_HEIGHT_PX,
    overscan: 10,
  })),
)

const visibleRows = computed(() =>
  virtual.value
    ? virtualizer.value
        .getVirtualItems()
        .map((item) => ({
          incident: props.entries[item.index],
          start: item.start,
          key: String(item.key),
        }))
        .filter(
          (row): row is { incident: Incident; start: number; key: string } =>
            row.incident !== undefined,
        )
    : props.entries.map((incident, index) => ({
        incident,
        start: index * ROW_HEIGHT_PX,
        key: String(incident.id),
      })),
)

const totalHeight = computed(() =>
  virtual.value ? virtualizer.value.getTotalSize() : props.entries.length * ROW_HEIGHT_PX,
)

const zoneLabel = computed(() =>
  props.entries[0] === undefined
    ? props.timeZone
    : formatZoneLabel(props.entries[0].detected_at, props.timeZone, locale.value),
)

function ageLabel(incident: Incident): string {
  const minutes = Math.max(
    Math.floor((props.serverNowMs - Date.parse(incident.detected_at)) / 60_000),
    0,
  )

  return t('incidents.table.ageValue', {
    duration: t('incidents.duration', durationParts(minutes)),
  })
}

function detectedAtLabel(incident: Incident): string {
  return formatInstant(incident.detected_at, props.timeZone, locale.value)
}
</script>

<template>
  <div
    ref="scroller"
    class="max-h-[70vh] overflow-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    data-test="incident-table"
  >
    <div
      role="table"
      :aria-label="t('incidents.table.caption', { zone: zoneLabel })"
      class="min-w-[64rem]"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-10 border-b border-kq-border bg-kq-surface-alt text-left text-sm font-semibold"
      >
        <div
          role="row"
          class="grid grid-cols-[0.9fr_1.6fr_2fr_1fr_1.5fr_1.2fr_1fr] gap-3 px-3 py-2"
        >
          <span role="columnheader">{{ t('incidents.table.severity') }}</span>
          <span role="columnheader">{{ t('incidents.table.type') }}</span>
          <span role="columnheader">{{ t('incidents.table.employee') }}</span>
          <span role="columnheader">{{ t('incidents.table.workDate') }}</span>
          <span role="columnheader">{{ t('incidents.table.age') }}</span>
          <span role="columnheader">{{ t('incidents.table.assignedTo') }}</span>
          <span role="columnheader">{{ t('incidents.table.actions') }}</span>
        </div>
      </div>

      <div role="rowgroup" class="relative" :style="{ height: `${totalHeight}px` }">
        <div
          v-for="row of visibleRows"
          :key="row.key"
          role="row"
          class="absolute left-0 grid w-full grid-cols-[0.9fr_1.6fr_2fr_1fr_1.5fr_1.2fr_1fr] items-center gap-3 border-b border-kq-border px-3 py-2"
          :style="{ transform: `translateY(${row.start}px)`, height: `${ROW_HEIGHT_PX}px` }"
          data-test="incident-row"
          :data-incident-id="row.incident.id"
        >
          <span role="cell">
            <span
              class="inline-block rounded-full px-3 py-1 text-sm font-semibold"
              :class="severityBadgeClass(row.incident.severity)"
            >
              {{ t(`incidents.severities.${row.incident.severity}`) }}
            </span>
          </span>

          <span role="cell" class="truncate" data-test="incident-type">
            {{ t(`incidents.types.${row.incident.type}`) }}
          </span>

          <span role="cell" class="truncate" data-test="incident-employee">
            <span class="block font-medium">
              {{ row.incident.employee.full_name }}
              <span class="font-mono text-sm text-kq-text-muted">
                {{ row.incident.employee.employee_code }}
              </span>
            </span>
            <span class="block text-sm text-kq-text-muted">
              {{ row.incident.employee.department?.name ?? t('incidents.table.noDepartment') }}
            </span>
          </span>

          <span role="cell">
            <RouterLink
              v-if="canViewWorkday"
              :to="{ name: 'employee-workdays', params: { uuid: row.incident.employee.uuid } }"
              class="text-kq-primary-strong underline"
            >
              {{ row.incident.work_date }}
            </RouterLink>
            <span v-else>{{ row.incident.work_date }}</span>
          </span>

          <span role="cell" class="tabular-nums" data-test="incident-age">
            <span class="block">{{ ageLabel(row.incident) }}</span>
            <span class="block text-sm text-kq-text-muted">
              {{ t('incidents.table.detectedAt', { moment: detectedAtLabel(row.incident) }) }}
            </span>
          </span>

          <span role="cell" class="truncate text-kq-text-muted">
            {{ row.incident.assigned_to?.name ?? t('incidents.table.unassigned') }}
          </span>

          <span role="cell">
            <button
              v-if="row.incident.status === 'open'"
              type="button"
              class="rounded-kq-sm bg-kq-primary-strong px-3 py-2 text-sm font-semibold text-kq-on-primary"
              data-test="resolve-button"
              @click="emit('resolve', row.incident)"
            >
              {{ t('incidents.table.resolve') }}
            </button>
            <span v-else class="text-sm text-kq-text-muted">
              {{ t(`incidents.status.${row.incident.status}`) }}
            </span>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
