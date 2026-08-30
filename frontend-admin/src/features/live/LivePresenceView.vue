<script setup lang="ts">
// Presencia en tiempo real (RF-PA-01, RF-PA-02).
//
// Quien esta dentro ahora mismo, con la hora de entrada, el tiempo transcurrido
// y el quiosco de origen; filtros por departamento y estado y busqueda por
// nombre, resueltos por el servidor con el alcance del rol dentro de la
// consulta (RF-ID-03). La vista solo lee: rectificar es otra pantalla y otro
// ambito.
//
// EL TIEMPO REAL DEGRADA BIEN Y LO ANUNCIA (principio de `frontend-panel`,
// RNF-D-03, ADR-011). El indicador de arriba dice siempre por que via se esta
// actualizando la lista; cuando el canal cae, el aviso es visible y con
// `role="status"`, no un icono gris.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { useQuery } from '@tanstack/vue-query'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { listDepartments } from '@/shared/api/organisation.api'
import type { LivePresenceStatus } from '@/shared/api/types'
import PresenceTable from './PresenceTable.vue'
import { useLivePresenceStore } from './presence.store'

const SEARCH_DEBOUNCE_MS = 300
/** Cada cuanto se repinta el tiempo transcurrido. Un minuto de resolucion basta y no hace parpadear nada. */
const CLOCK_TICK_MS = 30_000
const STATUSES: readonly LivePresenceStatus[] = ['present', 'absent']

const { t, locale } = useI18n()
const store = useLivePresenceStore()

const { data: departments } = useQuery({
  queryKey: ['departments'] as const,
  queryFn: listDepartments,
})
const departmentOptions = computed(() => departments.value?.data ?? [])

const departmentFilter = ref<number | ''>('')
const statusFilter = ref<LivePresenceStatus>('present')
const searchInput = ref('')

const now = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | undefined
let searchDebounce: ReturnType<typeof setTimeout> | undefined

const serverNowMs = computed(() => store.serverNowMs(now.value))

const transportLabel = computed(() => {
  const seconds = Math.round(store.pollIntervalMs / 1_000)

  if (store.transport === 'realtime' && store.realtimeState === 'live') {
    return t('live.transport.live')
  }

  if (store.meta !== null && !store.realtimeAvailable) {
    return t('live.transport.disabled', { seconds })
  }

  if (store.realtimeState === 'connecting' && store.transport !== 'polling') {
    return t('live.transport.connecting')
  }

  return t('live.transport.polling', { seconds })
})

const degraded = computed(
  () =>
    store.transport === 'polling' ||
    (store.transport === 'realtime' && store.realtimeState !== 'live'),
)

const generatedAtLabel = computed(() =>
  store.meta === null ? '' : formatInstant(store.meta.generated_at, store.timeZone, locale.value),
)

function submitFilters(): void {
  void store.applyFilters({
    status: statusFilter.value,
    ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
    ...(searchInput.value.trim() === '' ? {} : { q: searchInput.value.trim() }),
  })
}

watch([departmentFilter, statusFilter], submitFilters)

watch(searchInput, () => {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(submitFilters, SEARCH_DEBOUNCE_MS)
})

watch(
  () => store.meta?.present_count,
  (count) => {
    if (count !== undefined) {
      announce(t('live.announce.counts', { present: count, absent: store.meta?.absent_count ?? 0 }))
    }
  },
)

watch(degraded, (isDegraded, wasDegraded) => {
  if (isDegraded && !wasDegraded && store.transport !== 'idle') {
    announce(transportLabel.value)
  }
})

onMounted(() => {
  void store.connect()
  clockTimer = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MS)
})

onUnmounted(() => {
  clearInterval(clockTimer)
  clearTimeout(searchDebounce)
  store.disconnect()
})

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('live.title') }}</h1>
        <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('live.subtitle') }}</p>
      </div>

      <p
        role="status"
        aria-live="polite"
        class="rounded-full px-3 py-1 text-sm"
        :class="
          degraded ? 'bg-kq-warning-soft text-kq-warning' : 'bg-kq-success-soft text-kq-success'
        "
        data-test="transport"
        :data-degraded="degraded ? 'true' : 'false'"
      >
        {{ transportLabel }}
      </p>
    </div>

    <dl v-if="store.meta !== null" class="mt-4 flex flex-wrap gap-6" data-test="counts">
      <div>
        <dt class="text-sm text-kq-text-muted">{{ t('live.counts.present') }}</dt>
        <dd class="text-3xl font-bold tabular-nums" data-test="present-count">
          {{ store.meta.present_count }}
        </dd>
      </div>
      <div>
        <dt class="text-sm text-kq-text-muted">{{ t('live.counts.absent') }}</dt>
        <dd class="text-3xl font-bold tabular-nums" data-test="absent-count">
          {{ store.meta.absent_count }}
        </dd>
      </div>
      <div>
        <dt class="text-sm text-kq-text-muted">{{ t('live.counts.snapshot') }}</dt>
        <dd class="text-kq-text" data-test="generated-at">
          {{ t('live.counts.snapshotAt', { moment: generatedAtLabel, zone: store.timeZone }) }}
        </dd>
      </div>
    </dl>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent="submitFilters">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('live.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="live-search-filter" class="font-medium">{{ t('live.filters.search') }}</label>
          <input
            id="live-search-filter"
            v-model="searchInput"
            type="search"
            maxlength="100"
            :placeholder="t('live.filters.searchPlaceholder')"
            :class="selectClass"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="live-status-filter" class="font-medium">{{ t('live.filters.status') }}</label>
          <select id="live-status-filter" v-model="statusFilter" :class="selectClass">
            <option v-for="status of STATUSES" :key="status" :value="status">
              {{ t(`live.status.${status}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="live-department-filter" class="font-medium">
            {{ t('live.filters.department') }}
          </label>
          <select id="live-department-filter" v-model="departmentFilter" :class="selectClass">
            <option value="">{{ t('live.filters.departmentAll') }}</option>
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

    <LoadingPanel v-if="store.loading" :label="t('live.loading')" class="mt-4" />

    <ErrorNotice
      v-else-if="store.error !== null && store.meta === null"
      :error="store.error"
      class="mt-4"
    />

    <template v-else-if="store.meta !== null">
      <EmptyState
        v-if="store.entries.length === 0"
        class="mt-4"
        :title="t(`live.empty.${statusFilter}.title`)"
        :description="t(`live.empty.${statusFilter}.description`)"
      />

      <PresenceTable
        v-else
        class="mt-4"
        :entries="store.entries"
        :time-zone="store.timeZone"
        :server-now-ms="serverNowMs"
      />
    </template>
  </section>
</template>
