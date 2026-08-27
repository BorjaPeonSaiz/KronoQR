<script setup lang="ts">
// Listado de plantilla (RF-GP-01).
//
// Volumen: 500 empleados. La lista **se pagina en el servidor** (el contrato
// limita `per_page` a 100 justamente para eso), asi que en el DOM nunca hay mas
// de una pagina y no hace falta virtualizar nada. La cache de TanStack Query
// evita volver a pedir la pagina anterior al retroceder.
//
// El listado NO esconde a los cesados por defecto: el historico se conserva
// cuatro años (RL-02) y una inspeccion viene a mirar justo eso. Quien quiera ver
// solo a los activos lo pide con el filtro, que esta a la vista.
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { listDepartments, listSites } from '@/shared/api/organisation.api'
import type { EmployeeProvisioned, EmploymentStatus } from '@/shared/api/types'
import { announce } from '@/shared/ui/announcer'
import EmptyState from '@/shared/ui/EmptyState.vue'
import ErrorNotice from '@/shared/ui/ErrorNotice.vue'
import LoadingPanel from '@/shared/ui/LoadingPanel.vue'
import EmployeeCreateDialog from './EmployeeCreateDialog.vue'
import PinRevealDialog from './PinRevealDialog.vue'
import { listEmployees } from './employees.api'

const PER_PAGE = 25

const { t } = useI18n()

const page = ref(1)
const statusFilter = ref<EmploymentStatus | ''>('')
const siteFilter = ref<number | ''>('')

const creating = ref(false)

// El PIN recien emitido vive AQUI y solo mientras el dialogo esta abierto. No
// entra en la tienda, ni en la cache de consultas, ni en `sessionStorage`
// (RF-ID-09).
const provisioned = ref<EmployeeProvisioned | null>(null)

const { data: sites } = useQuery({ queryKey: ['sites'], queryFn: listSites })
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const query = computed(() => ({
  page: page.value,
  perPage: PER_PAGE,
  ...(statusFilter.value === '' ? {} : { status: statusFilter.value }),
  ...(siteFilter.value === '' ? {} : { siteId: siteFilter.value }),
}))

const {
  data: employees,
  error,
  isPending,
  isFetching,
  refetch,
} = useQuery({
  queryKey: computed(() => ['employees', query.value] as const),
  queryFn: () => listEmployees(query.value),
  placeholderData: keepPreviousData,
})

const rows = computed(() => employees.value?.data ?? [])
const meta = computed(() => employees.value?.meta ?? null)

const siteNames = computed(
  () => new Map((sites.value?.data ?? []).map((site) => [site.id, site.name])),
)
const departmentNames = computed(
  () => new Map((departments.value?.data ?? []).map((item) => [item.id, item.name])),
)

const hasFilters = computed(() => statusFilter.value !== '' || siteFilter.value !== '')

const rangeStart = computed(() =>
  meta.value === null || meta.value.total === 0
    ? 0
    : (meta.value.page - 1) * meta.value.per_page + 1,
)
const rangeEnd = computed(() =>
  meta.value === null ? 0 : Math.min(meta.value.page * meta.value.per_page, meta.value.total),
)

watch(
  () => meta.value?.total,
  (total) => {
    if (total !== undefined) {
      announce(t('employees.announce.results', { count: total }))
    }
  },
)

function resetToFirstPage(): void {
  page.value = 1
}

function goToPage(next: number): void {
  const totalPages = meta.value?.total_pages ?? 1

  page.value = Math.min(Math.max(next, 1), Math.max(totalPages, 1))
}

function onCreated(result: EmployeeProvisioned): void {
  creating.value = false
  provisioned.value = result
  announce(
    t('employees.announce.created', {
      name: fullName(result.employee.first_name, result.employee.last_name),
    }),
  )
  void refetch()
}

function closePinDialog(): void {
  // Al soltar la referencia, el PIN en claro deja de existir en el navegador.
  provisioned.value = null
}

function onPinDelivered(): void {
  announce(t('pin.announce.delivered'))
  closePinDialog()
  void refetch()
}

function fullName(first: string, last: string): string {
  return `${first} ${last}`
}

const selectClass =
  'rounded border border-slate-400 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('employees.title') }}</h1>
        <p class="mt-1 text-slate-700">{{ t('employees.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="rounded bg-slate-900 px-4 py-2 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        @click="creating = true"
      >
        {{ t('employees.actions.create') }}
      </button>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" @submit.prevent>
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('employees.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="employees-status-filter" class="font-medium">
            {{ t('employees.filters.status') }}
          </label>
          <select
            id="employees-status-filter"
            v-model="statusFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('employees.filters.statusAll') }}</option>
            <option value="active">{{ t('employees.status.active') }}</option>
            <option value="suspended">{{ t('employees.status.suspended') }}</option>
            <option value="terminated">{{ t('employees.status.terminated') }}</option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="employees-site-filter" class="font-medium">
            {{ t('employees.filters.site') }}
          </label>
          <select
            id="employees-site-filter"
            v-model="siteFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('employees.filters.siteAll') }}</option>
            <option v-for="site of sites?.data ?? []" :key="site.id" :value="site.id">
              {{ site.name }}
            </option>
          </select>
        </div>
      </fieldset>
    </form>

    <LoadingPanel v-if="isPending" :label="t('employees.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <EmptyState
      v-else-if="rows.length === 0"
      class="mt-4"
      :title="t('employees.empty.title')"
      :description="hasFilters ? t('employees.empty.filtered') : t('employees.empty.description')"
    />

    <template v-else>
      <div class="mt-4 overflow-x-auto rounded border border-slate-300 bg-white">
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('employees.table.caption')
            }}
          </caption>
          <thead class="border-b border-slate-300 bg-slate-50">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.name') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.code') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.site') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.department') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.status') }}</th>
              <!-- Estado del PIN, nunca el PIN. El valor solo existe en el
                   dialogo que lo emite (RF-ID-09). -->
              <th scope="col" class="px-3 py-2">{{ t('employees.table.pinStatus') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="employee of rows" :key="employee.uuid" class="border-b border-slate-200">
              <th scope="row" class="px-3 py-2 font-medium">
                <RouterLink
                  :to="{ name: 'employee', params: { uuid: employee.uuid } }"
                  class="underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
                >
                  {{ fullName(employee.first_name, employee.last_name) }}
                </RouterLink>
              </th>
              <td class="px-3 py-2 font-mono">{{ employee.employee_code }}</td>
              <td class="px-3 py-2">{{ siteNames.get(employee.site_id) ?? '—' }}</td>
              <td class="px-3 py-2">
                {{
                  employee.department_id === null
                    ? t('employees.fields.departmentNone')
                    : (departmentNames.get(employee.department_id) ?? '—')
                }}
              </td>
              <td class="px-3 py-2">{{ t(`employees.status.${employee.status}`) }}</td>
              <td class="px-3 py-2">{{ t(`pin.status.${employee.pin_status}`) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <nav
        v-if="meta !== null"
        :aria-label="t('employees.pagination.label')"
        class="mt-4 flex flex-wrap items-center justify-between gap-3"
      >
        <p>
          {{
            t('employees.pagination.summary', {
              from: rangeStart,
              to: rangeEnd,
              total: meta.total,
            })
          }}
          <span v-if="isFetching" class="text-slate-500">{{ t('common.updating') }}</span>
        </p>
        <div class="flex gap-2">
          <button
            type="button"
            class="rounded border border-slate-400 px-3 py-2 disabled:opacity-50"
            :disabled="meta.page <= 1"
            @click="goToPage(meta.page - 1)"
          >
            {{ t('employees.pagination.previous') }}
          </button>
          <p aria-current="page">
            {{ t('employees.pagination.page', { page: meta.page, pages: meta.total_pages }) }}
          </p>
          <button
            type="button"
            class="rounded border border-slate-400 px-3 py-2 disabled:opacity-50"
            :disabled="meta.page >= meta.total_pages"
            @click="goToPage(meta.page + 1)"
          >
            {{ t('employees.pagination.next') }}
          </button>
        </div>
      </nav>
    </template>

    <EmployeeCreateDialog
      v-if="creating"
      :sites="sites?.data ?? []"
      @close="creating = false"
      @created="onCreated"
    />

    <PinRevealDialog
      v-if="provisioned !== null"
      :pin="provisioned.pin"
      :employee-name="fullName(provisioned.employee.first_name, provisioned.employee.last_name)"
      :employee-code="provisioned.employee.employee_code"
      @acknowledged="closePinDialog"
      @delivered="onPinDelivered"
    />
  </section>
</template>
