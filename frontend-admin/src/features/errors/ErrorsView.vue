<script setup lang="ts">
// Historico de errores agrupado por huella (RF-PD-15, tarea 5.12).
//
// Escrita para el IT del cliente, «que vea que esta fallando y desde cuando,
// sin conocer el sistema» (doc 02 §8.2.1): filtros de origen, nivel, estado y
// periodo; cada fila con su nivel, su recuento y un bloque «que hacer» al
// expandirla.
//
// EL BOTON DE RESOLVER SE OCULTA PARA UN ACTOR DE SOPORTE, PERO ES UNA
// CORTESIA, NO SEGURIDAD (regla dura 18): la señal disponible en el cliente es
// el ambito `support:*`. Un administrador de verdad lo lleva siempre
// (abilities `['*']`, que `hasAbility` reconoce para cualquier ambito); un
// acceso de soporte con alcance `diagnostics` o `read_only` NO lo lleva nunca
// (decision 8 de la ficha: solo `admin` puede resolver), asi que ocultar el
// boton en esos dos casos acierta. El unico caso sin señal es un acceso de
// soporte con alcance `configuration`, que actua como administrador y por
// tanto es indistinguible por ambito: el boton se enseña y, si se usa, el
// servidor lo rechaza con `403`, que `ErrorNotice` traduce con claridad.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { SUPPORT_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { ErrorEvent, ErrorLevel, ErrorSource } from '@/shared/api/types'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import ErrorTable from './ErrorTable.vue'
import type { ErrorEventStatus } from './errors.api'
import { useErrorEventsStore } from './errors.store'
import type { ErrorPeriodPreset } from './errorPresentation'
import { periodBounds } from './errorPresentation'

/** Cada cuanto se repinta la antiguedad de `last_seen_at`. */
const CLOCK_TICK_MS = 30_000

const SOURCES: readonly ErrorSource[] = [
  'api',
  'worker',
  'scheduler',
  'console',
  'kiosk',
  'admin',
  'portal',
]
const LEVELS: readonly ErrorLevel[] = ['error', 'critical']
const STATUSES: readonly ErrorEventStatus[] = ['open', 'resolved', 'all']

const { t } = useI18n()
const session = useSessionStore()
const store = useErrorEventsStore()

/** Ver la nota de cabecera: el unico caso sin señal es el alcance `configuration`. */
const canResolve = computed(() => session.can(SUPPORT_MANAGE))

const sourceFilter = ref<ErrorSource | ''>('')
const levelFilter = ref<ErrorLevel | ''>('')
const statusFilter = ref<ErrorEventStatus>('open')
const periodPreset = ref<ErrorPeriodPreset>('7')
const customFrom = ref('')
const customTo = ref('')

const now = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | undefined

const serverNowMs = computed(() => store.serverNowMs(now.value))

function currentQuery() {
  // El reloj del SERVIDOR extrapolado, no `now.value` a secas (hallazgo I4):
  // con un PC atrasado, `periodBounds` calculado sobre el reloj local dejaba
  // fuera errores recien creados que el servidor ya conocia.
  const bounds = periodBounds(
    periodPreset.value,
    { from: customFrom.value, to: customTo.value },
    serverNowMs.value,
  )

  return {
    status: statusFilter.value,
    ...(sourceFilter.value === '' ? {} : { source: sourceFilter.value }),
    ...(levelFilter.value === '' ? {} : { level: levelFilter.value }),
    ...bounds,
  }
}

function submitFilters(): void {
  void store.applyFilters(currentQuery())
}

watch([sourceFilter, levelFilter, statusFilter, periodPreset], submitFilters)

/** El periodo personalizado solo se aplica cuando las dos fechas estan escritas. */
watch([customFrom, customTo], () => {
  if (periodPreset.value === 'custom' && customFrom.value !== '' && customTo.value !== '') {
    submitFilters()
  }
})

watch(
  () => store.meta?.total,
  (total) => {
    if (total !== undefined) {
      announce(t('errorEvents.announce.results', { count: total }))
    }
  },
)

function goToPage(page: number): void {
  void store.goToPage(page)
}

const resolving = ref<ErrorEvent | null>(null)
const resolveBusy = ref(false)
const resolveError = ref<unknown>(null)

function openResolve(entry: ErrorEvent): void {
  resolving.value = entry
  resolveError.value = null
}

function closeResolve(): void {
  resolving.value = null
  resolveError.value = null
}

async function confirmResolve(): Promise<void> {
  const target = resolving.value

  if (target === null) {
    return
  }

  resolveBusy.value = true
  resolveError.value = null

  try {
    await store.resolve(target.id)
    announce(t('errorEvents.resolve.announceResolved'))
    resolving.value = null
  } catch (caught) {
    resolveError.value = caught
  } finally {
    resolveBusy.value = false
  }
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
      <h1 class="text-2xl font-bold">{{ t('errorEvents.heading') }}</h1>
      <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('errorEvents.intro') }}</p>
    </div>

    <div v-if="store.meta !== null" class="mt-4 flex flex-wrap gap-4" data-test="summary">
      <p class="rounded-kq border border-kq-border bg-kq-surface-raised px-4 py-2">
        {{ t('errorEvents.summary.openErrors', { count: store.meta.open_errors }) }}
      </p>
      <p
        class="rounded-kq border border-kq-danger bg-kq-danger-soft px-4 py-2 text-kq-danger"
        data-test="open-critical"
      >
        {{ t('errorEvents.summary.openCritical', { count: store.meta.open_critical }) }}
      </p>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent="submitFilters">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('errorEvents.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="errors-source-filter" class="font-medium">
            {{ t('errorEvents.filters.source') }}
          </label>
          <select id="errors-source-filter" v-model="sourceFilter" :class="selectClass">
            <option value="">{{ t('errorEvents.filters.sourceAll') }}</option>
            <option v-for="source of SOURCES" :key="source" :value="source">
              {{ t(`errorEvents.sources.${source}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="errors-level-filter" class="font-medium">
            {{ t('errorEvents.filters.level') }}
          </label>
          <select id="errors-level-filter" v-model="levelFilter" :class="selectClass">
            <option value="">{{ t('errorEvents.filters.levelAll') }}</option>
            <option v-for="level of LEVELS" :key="level" :value="level">
              {{ t(`errorEvents.levels.${level}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="errors-status-filter" class="font-medium">
            {{ t('errorEvents.filters.status') }}
          </label>
          <select id="errors-status-filter" v-model="statusFilter" :class="selectClass">
            <option v-for="status of STATUSES" :key="status" :value="status">
              {{ t(`errorEvents.statuses.${status}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="errors-period-filter" class="font-medium">
            {{ t('errorEvents.filters.period') }}
          </label>
          <select id="errors-period-filter" v-model="periodPreset" :class="selectClass">
            <option value="7">{{ t('errorEvents.filters.period7') }}</option>
            <option value="30">{{ t('errorEvents.filters.period30') }}</option>
            <option value="90">{{ t('errorEvents.filters.period90') }}</option>
            <option value="custom">{{ t('errorEvents.filters.periodCustom') }}</option>
          </select>
        </div>

        <template v-if="periodPreset === 'custom'">
          <div class="flex flex-col gap-1">
            <label for="errors-from-filter" class="font-medium">
              {{ t('errorEvents.filters.from') }}
            </label>
            <input
              id="errors-from-filter"
              v-model="customFrom"
              type="date"
              :class="selectClass"
              data-test="period-from"
            />
          </div>
          <div class="flex flex-col gap-1">
            <label for="errors-to-filter" class="font-medium">
              {{ t('errorEvents.filters.to') }}
            </label>
            <input
              id="errors-to-filter"
              v-model="customTo"
              type="date"
              :class="selectClass"
              data-test="period-to"
            />
          </div>
        </template>
      </fieldset>
    </form>

    <LoadingPanel v-if="store.loading" :label="t('errorEvents.loading')" class="mt-4" />

    <ErrorNotice
      v-else-if="store.error !== null && store.meta === null"
      :error="store.error"
      class="mt-4"
    />

    <template v-else-if="store.meta !== null">
      <EmptyState
        v-if="store.entries.length === 0"
        class="mt-4"
        :title="t(`errorEvents.empty.${statusFilter}.title`)"
        :description="t(`errorEvents.empty.${statusFilter}.description`)"
      />

      <template v-else>
        <ErrorTable
          class="mt-4"
          :entries="store.entries"
          :time-zone="store.meta.time_zone"
          :server-now-ms="serverNowMs"
          :can-resolve="canResolve"
          @resolve="openResolve"
        />

        <PaginationBar
          :page="store.meta.page"
          :per-page="store.meta.per_page"
          :total="store.meta.total"
          :total-pages="store.meta.total_pages"
          :label="t('errorEvents.pagination.label')"
          @update:page="goToPage"
        />
      </template>
    </template>

    <ConfirmDialog
      v-if="resolving !== null"
      :title="t('errorEvents.resolve.confirmTitle')"
      :confirm-label="t('errorEvents.resolve.action')"
      :busy="resolveBusy"
      :error="resolveError"
      @cancel="closeResolve"
      @confirm="confirmResolve"
    >
      <p>
        {{
          t('errorEvents.resolve.confirmBody', {
            source: t(`errorEvents.sources.${resolving.source}`),
            level: t(`errorEvents.levels.${resolving.level}`),
            message: resolving.message,
          })
        }}
      </p>
    </ConfirmDialog>
  </section>
</template>
