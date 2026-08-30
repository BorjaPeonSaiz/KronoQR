<script setup lang="ts">
// Bandeja de incidencias (RF-PA-05, RF-PR-01).
//
// El responsable trabaja lo pendiente de su departamento, con un flujo de
// resolucion y nota obligatoria. Los filtros -estado, tipo, severidad,
// departamento- los resuelve el servidor con el alcance del rol dentro de la
// consulta (RF-ID-03): esta pantalla no filtra nada por su cuenta.
//
// LA ANTIGUEDAD SE CALCULA CONTRA EL RELOJ DEL SERVIDOR (regla dura 3), igual
// que la presencia en vivo de la 2.4: `meta.generated_at` mas lo transcurrido
// en el reloj local, nunca `Date.now()` a secas.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { useQuery } from '@tanstack/vue-query'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { listDepartments } from '@/shared/api/organisation.api'
import type { Incident, IncidentSeverity, IncidentStatus, IncidentType } from '@/shared/api/types'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import IncidentTable from './IncidentTable.vue'
import { useIncidentsStore } from './incidents.store'
import ResolveIncidentDialog from './ResolveIncidentDialog.vue'

/** Cada cuanto se repinta la antiguedad. Un minuto de resolucion basta. */
const CLOCK_TICK_MS = 30_000

const STATUSES: readonly IncidentStatus[] = ['open', 'resolved', 'dismissed']
const TYPES: readonly IncidentType[] = [
  'open_shift_expired',
  'short_shift',
  'long_shift',
  'missing_break',
  'insufficient_rest',
  'clock_skew',
  'missing_clock_out',
  'anomalous_pattern',
]
const SEVERITIES: readonly IncidentSeverity[] = ['high', 'medium', 'low']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const store = useIncidentsStore()

const { data: departments } = useQuery({
  queryKey: ['departments'] as const,
  queryFn: listDepartments,
})
const departmentOptions = computed(() => departments.value?.data ?? [])

const statusFilter = ref<IncidentStatus>('open')
const typeFilter = ref<IncidentType | ''>('')
const severityFilter = ref<IncidentSeverity | ''>('')
const departmentFilter = ref<number | ''>('')

/**
 * El enlace desde el detalle de jornada de una persona llega con
 * `?employee=<uuid>`: la bandeja se abre ya acotada a esa persona (RF-PA-03 +
 * RF-PA-05). No hay control visible para esto -no es uno de los filtros del
 * formulario- pero se puede quitar con un boton propio.
 */
const employeeUuidFilter = ref<string | null>(
  typeof route.query['employee'] === 'string' ? route.query['employee'] : null,
)

const now = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | undefined

const serverNowMs = computed(() => store.serverNowMs(now.value))

const resolving = ref<Incident | null>(null)

function currentQuery() {
  return {
    status: statusFilter.value,
    ...(typeFilter.value === '' ? {} : { type: typeFilter.value }),
    ...(severityFilter.value === '' ? {} : { severity: severityFilter.value }),
    ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
    ...(employeeUuidFilter.value === null ? {} : { employeeUuid: employeeUuidFilter.value }),
  }
}

function submitFilters(): void {
  void store.applyFilters(currentQuery())
}

watch([statusFilter, typeFilter, severityFilter, departmentFilter], submitFilters)

/** El nombre para el aviso del filtro por persona: solo cuando ya ha llegado alguna fila suya. */
const employeeFilterName = computed(() => {
  if (employeeUuidFilter.value === null) {
    return null
  }

  return (
    store.entries.find((entry) => entry.employee.uuid === employeeUuidFilter.value)?.employee
      .full_name ?? null
  )
})

function clearEmployeeFilter(): void {
  employeeUuidFilter.value = null
  void router.replace({ query: { ...route.query, employee: undefined } })
  submitFilters()
}

watch(
  () => store.meta?.total,
  (total) => {
    if (total !== undefined) {
      announce(t('incidents.announce.results', { count: total }))
    }
  },
)

function goToPage(page: number): void {
  void store.goToPage(page)
}

function openResolve(incident: Incident): void {
  resolving.value = incident
}

function closeResolve(): void {
  resolving.value = null
}

function onResolved(incident: Incident): void {
  announce(
    t('incidents.announce.resolved', {
      name: incident.employee.full_name,
      outcome: t(`incidents.outcomes.${incident.status}`),
    }),
  )
  resolving.value = null
}

onMounted(() => {
  void store.applyFilters(currentQuery())
  clockTimer = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MS)
})

onUnmounted(() => {
  clearInterval(clockTimer)
})

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div>
      <h1 class="text-2xl font-bold">{{ t('incidents.title') }}</h1>
      <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('incidents.subtitle') }}</p>
    </div>

    <p
      v-if="employeeUuidFilter !== null"
      class="mt-4 flex flex-wrap items-center gap-2 rounded-kq border border-kq-border bg-kq-surface-alt p-3"
      data-test="employee-filter-banner"
    >
      <span>
        {{
          t('incidents.employeeFilter.active', {
            name: employeeFilterName ?? employeeUuidFilter,
          })
        }}
      </span>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm hover:bg-kq-surface-alt"
        @click="clearEmployeeFilter"
      >
        {{ t('incidents.employeeFilter.clear') }}
      </button>
    </p>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent="submitFilters">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('incidents.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="incidents-status-filter" class="font-medium">
            {{ t('incidents.filters.status') }}
          </label>
          <select id="incidents-status-filter" v-model="statusFilter" :class="selectClass">
            <option v-for="status of STATUSES" :key="status" :value="status">
              {{ t(`incidents.status.${status}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="incidents-type-filter" class="font-medium">
            {{ t('incidents.filters.type') }}
          </label>
          <select id="incidents-type-filter" v-model="typeFilter" :class="selectClass">
            <option value="">{{ t('incidents.filters.typeAll') }}</option>
            <option v-for="type of TYPES" :key="type" :value="type">
              {{ t(`incidents.types.${type}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="incidents-severity-filter" class="font-medium">
            {{ t('incidents.filters.severity') }}
          </label>
          <select id="incidents-severity-filter" v-model="severityFilter" :class="selectClass">
            <option value="">{{ t('incidents.filters.severityAll') }}</option>
            <option v-for="severity of SEVERITIES" :key="severity" :value="severity">
              {{ t(`incidents.severities.${severity}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="incidents-department-filter" class="font-medium">
            {{ t('incidents.filters.department') }}
          </label>
          <select id="incidents-department-filter" v-model="departmentFilter" :class="selectClass">
            <option value="">{{ t('incidents.filters.departmentAll') }}</option>
            <option
              v-for="department of departmentOptions"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
        </div>
      </fieldset>
    </form>

    <LoadingPanel v-if="store.loading" :label="t('incidents.loading')" class="mt-4" />

    <ErrorNotice
      v-else-if="store.error !== null && store.meta === null"
      :error="store.error"
      class="mt-4"
    />

    <template v-else-if="store.meta !== null">
      <EmptyState
        v-if="store.entries.length === 0"
        class="mt-4"
        :title="t(`incidents.empty.${statusFilter}.title`)"
        :description="t(`incidents.empty.${statusFilter}.description`)"
      />

      <template v-else>
        <IncidentTable
          class="mt-4"
          :entries="store.entries"
          :time-zone="store.meta.time_zone"
          :server-now-ms="serverNowMs"
          @resolve="openResolve"
        />

        <PaginationBar
          :page="store.meta.page"
          :per-page="store.meta.per_page"
          :total="store.meta.total"
          :total-pages="store.meta.total_pages"
          :label="t('incidents.pagination.label')"
          @update:page="goToPage"
        />
      </template>
    </template>

    <ResolveIncidentDialog
      v-if="resolving !== null"
      :incident="resolving"
      :time-zone="store.meta?.time_zone ?? 'UTC'"
      @resolved="onResolved"
      @cancel="closeResolve"
    />
  </section>
</template>
