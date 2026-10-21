<script setup lang="ts">
// Filas de la bandeja de incidencias (RF-PA-05).
//
// VIRTUALIZADA A PARTIR DE UN UMBRAL con `useVirtualRows`, como las demas tablas
// largas (mismo motivo, doc 02 Anexo A: 500 empleados y meses de historico). El
// contrato limita `per_page` a 100, asi que en la practica casi nunca se
// virtualiza dentro de una pagina, pero el componente no depende de eso.
//
// LA SEVERIDAD SE DICE CON PALABRAS, NO SOLO CON COLOR (WCAG 2.2 AA, 1.4.1):
// el texto («Alta», «Media», «Baja») va siempre junto al color de fondo.
//
// LA ANTIGUEDAD SE CALCULA CONTRA EL RELOJ DEL SERVIDOR (regla dura 3): la
// vista pasa `serverNowMs`, nunca se usa `Date.now()` aqui dentro.
import { formatInstant, formatZoneLabel, minutesBetween } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePersonName } from '@/shared/people/personName'
import { RouterLink } from 'vue-router'
import { ATTENDANCE_READ } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { Incident } from '@/shared/api/types'
import { useVirtualRows } from '@/shared/virtualization/useVirtualRows'
import { severityBadgeClass } from './incidentPresentation'

const props = defineProps<{
  entries: readonly Incident[]
  timeZone: string
  /** El «ahora» del servidor, en milisegundos desde la epoca. */
  serverNowMs: number
}>()

const emit = defineEmits<{ resolve: [incident: Incident] }>()

const ROW_HEIGHT_PX = 84

const { t, locale } = useI18n()
const { personFullName } = usePersonName()
const session = useSessionStore()

// El registro horario exige `attendance:read`: sin el, el enlace a la jornada
// llevaria a un 403 que la interfaz puede evitar de antemano (regla dura 18,
// el filtro es cortesia, no seguridad).
const canViewWorkday = computed(() => session.can(ATTENDANCE_READ))

const scroller = ref<HTMLElement | null>(null)

const items = computed(() => props.entries)

const { visibleRows, totalHeight } = useVirtualRows(items, {
  rowHeight: ROW_HEIGHT_PX,
  scroller,
  key: (incident) => String(incident.id),
})

const zoneLabel = computed(() =>
  props.entries[0] === undefined
    ? props.timeZone
    : formatZoneLabel(props.entries[0].detected_at, props.timeZone, locale.value),
)

function ageLabel(incident: Incident): string {
  // Sin `Math.max`: `detected_at` es del servidor y `serverNowMs` tambien -el
  // «ahora» ya extrapolado, nunca el reloj crudo del navegador (regla dura
  // 3)-, asi que el primero nunca es posterior al segundo.
  const minutes =
    minutesBetween(incident.detected_at, new Date(props.serverNowMs).toISOString()) ?? 0

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
    tabindex="0"
    role="region"
    :aria-label="t('incidents.table.caption', { zone: zoneLabel })"
    class="max-h-[70vh] overflow-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    data-test="incident-table"
  >
    <div
      role="table"
      :aria-rowcount="entries.length + 1"
      :aria-label="t('incidents.table.caption', { zone: zoneLabel })"
      class="min-w-[64rem]"
    >
      <div
        role="rowgroup"
        class="sticky top-0 z-10 border-b border-kq-border bg-kq-surface-alt text-left text-sm font-semibold"
      >
        <div
          role="row"
          aria-rowindex="1"
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
          :aria-rowindex="row.index + 2"
          class="absolute left-0 grid w-full grid-cols-[0.9fr_1.6fr_2fr_1fr_1.5fr_1.2fr_1fr] items-center gap-3 border-b border-kq-border px-3 py-2"
          :style="{ transform: `translateY(${row.start}px)`, height: `${ROW_HEIGHT_PX}px` }"
          data-test="incident-row"
          :data-incident-id="row.item.id"
        >
          <span role="cell">
            <span
              class="inline-block rounded-full px-3 py-1 text-sm font-semibold"
              :class="severityBadgeClass(row.item.severity)"
            >
              {{ t(`incidents.severities.${row.item.severity}`) }}
            </span>
          </span>

          <span role="cell" class="truncate" data-test="incident-type">
            {{ t(`incidents.types.${row.item.type}`) }}
          </span>

          <span role="cell" class="truncate" data-test="incident-employee">
            <span class="block font-medium">
              {{ personFullName(row.item.employee.full_name) }}
              <span class="font-mono text-sm text-kq-text-muted">
                {{ row.item.employee.employee_code }}
              </span>
            </span>
            <span class="block text-sm text-kq-text-muted">
              {{ row.item.employee.department?.name ?? t('incidents.table.noDepartment') }}
            </span>
          </span>

          <span role="cell">
            <RouterLink
              v-if="canViewWorkday"
              :to="{ name: 'employee-workdays', params: { uuid: row.item.employee.uuid } }"
              class="text-kq-primary-strong underline"
            >
              {{ row.item.work_date }}
            </RouterLink>
            <span v-else>{{ row.item.work_date }}</span>
          </span>

          <span role="cell" class="tabular-nums" data-test="incident-age">
            <span class="block">{{ ageLabel(row.item) }}</span>
            <span class="block text-sm text-kq-text-muted">
              {{ t('incidents.table.detectedAt', { moment: detectedAtLabel(row.item) }) }}
            </span>
          </span>

          <span role="cell" class="truncate text-kq-text-muted">
            {{ row.item.assigned_to?.name ?? t('incidents.table.unassigned') }}
          </span>

          <span role="cell">
            <button
              v-if="row.item.status === 'open'"
              type="button"
              class="rounded-kq-sm bg-kq-primary-strong px-3 py-2 text-sm font-semibold text-kq-on-primary"
              data-test="resolve-button"
              @click="emit('resolve', row.item)"
            >
              {{ t('incidents.table.resolve') }}
            </button>
            <span v-else class="text-sm text-kq-text-muted">
              {{ t(`incidents.status.${row.item.status}`) }}
            </span>
          </span>
        </div>
      </div>
    </div>
  </div>
</template>
