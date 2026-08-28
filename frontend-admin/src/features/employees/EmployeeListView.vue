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
//
// Los filtros viven en la query string de la ruta: un enlace copiado, o el
// boton «volver» del navegador desde la ficha de un empleado, reproducen la
// misma busqueda (mismo patron que ya usa el panel de credenciales con
// `site`).
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { listDepartments, listSites } from '@/shared/api/organisation.api'
import type { EmployeeProvisioned, EmploymentStatus, PinStatus } from '@/shared/api/types'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import EmployeeCreateDialog from './EmployeeCreateDialog.vue'
import PinRevealDialog from './PinRevealDialog.vue'
import { EMPLOYEE_LIST_PER_PAGE, listEmployees } from './employees.api'

const PER_PAGE = EMPLOYEE_LIST_PER_PAGE
const SEARCH_DEBOUNCE_MS = 300
const EMPLOYMENT_STATUSES: readonly EmploymentStatus[] = ['active', 'suspended', 'terminated']
const PIN_STATUSES: readonly PinStatus[] = ['pending', 'issued', 'delivered']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

function queryParam(key: string): string {
  const raw = route.query[key]

  return typeof raw === 'string' ? raw : ''
}

function queryInt(key: string): number | '' {
  const parsed = Number.parseInt(queryParam(key), 10)

  return Number.isFinite(parsed) && parsed > 0 ? parsed : ''
}

const initialPage = queryInt('page')
const page = ref(initialPage === '' ? 1 : initialPage)

const initialQ = queryParam('q')
// `searchInput` es lo que teclea la persona; `qFilter` es lo que de verdad se
// manda al servidor, con debounce. Separarlos evita una peticion por tecla.
const searchInput = ref(initialQ)
const qFilter = ref(initialQ)

const initialStatus = queryParam('status')
const statusFilter = ref<EmploymentStatus | ''>(
  (EMPLOYMENT_STATUSES as readonly string[]).includes(initialStatus)
    ? (initialStatus as EmploymentStatus)
    : '',
)

const siteFilter = ref<number | ''>(queryInt('site'))
const departmentFilter = ref<number | ''>(queryInt('department'))

const initialPinStatus = queryParam('pin_status')
const pinStatusFilter = ref<PinStatus | ''>(
  (PIN_STATUSES as readonly string[]).includes(initialPinStatus)
    ? (initialPinStatus as PinStatus)
    : '',
)

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

const allDepartments = computed(() => departments.value?.data ?? [])
const departmentOptions = computed(() =>
  siteFilter.value === ''
    ? allDepartments.value
    : allDepartments.value.filter((department) => department.site_id === siteFilter.value),
)

const query = computed(() => ({
  page: page.value,
  perPage: PER_PAGE,
  ...(qFilter.value === '' ? {} : { q: qFilter.value }),
  ...(statusFilter.value === '' ? {} : { status: statusFilter.value }),
  ...(siteFilter.value === '' ? {} : { siteId: siteFilter.value }),
  ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
  ...(pinStatusFilter.value === '' ? {} : { pinStatus: pinStatusFilter.value }),
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

// Una pagina favorita (`?page=4`) o un filtro que reduce el total pueden dejar
// `page` apuntando mas alla de lo que ya existe. En cuanto el servidor
// contesta con cuantas paginas hay de verdad, se acota aqui: sin esto la
// vista se queda en un vacio que no es tal, sin forma de retroceder.
watch(
  () => meta.value?.total_pages,
  (totalPages) => {
    if (totalPages !== undefined && page.value > totalPages) {
      page.value = Math.max(totalPages, 1)
    }
  },
)

const siteNames = computed(
  () => new Map((sites.value?.data ?? []).map((site) => [site.id, site.name])),
)
const departmentNames = computed(
  () => new Map((departments.value?.data ?? []).map((item) => [item.id, item.name])),
)

const hasFilters = computed(
  () =>
    statusFilter.value !== '' ||
    siteFilter.value !== '' ||
    departmentFilter.value !== '' ||
    pinStatusFilter.value !== '' ||
    qFilter.value !== '',
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
  page.value = next
}

function onSiteChange(): void {
  // Un departamento de otro centro deja de tener sentido en cuanto se elige
  // un centro que no es el suyo.
  if (siteFilter.value !== '' && departmentFilter.value !== '') {
    const chosen = allDepartments.value.find(
      (department) => department.id === departmentFilter.value,
    )

    if (chosen === undefined || chosen.site_id !== siteFilter.value) {
      departmentFilter.value = ''
    }
  }

  resetToFirstPage()
}

let searchDebounce: ReturnType<typeof setTimeout> | undefined

watch(searchInput, (value) => {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }

  searchDebounce = setTimeout(() => {
    qFilter.value = value.trim()
    resetToFirstPage()
  }, SEARCH_DEBOUNCE_MS)
})

onUnmounted(() => {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }
})

function clearSearch(): void {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }

  searchInput.value = ''
  qFilter.value = ''
  resetToFirstPage()
}

function clearFilters(): void {
  clearSearch()
  statusFilter.value = ''
  siteFilter.value = ''
  departmentFilter.value = ''
  pinStatusFilter.value = ''
}

// La query string refleja el estado, para que un enlace copiado o el «volver»
// del navegador reproduzcan la misma vista.
watch(
  [qFilter, statusFilter, siteFilter, departmentFilter, pinStatusFilter, page],
  ([q, status, site, department, pinStatus, currentPage]) => {
    const nextQuery: Record<string, string> = {}

    if (q !== '') {
      nextQuery['q'] = q
    }

    if (status !== '') {
      nextQuery['status'] = status
    }

    if (site !== '') {
      nextQuery['site'] = String(site)
    }

    if (department !== '') {
      nextQuery['department'] = String(department)
    }

    if (pinStatus !== '') {
      nextQuery['pin_status'] = pinStatus
    }

    if (currentPage !== 1) {
      nextQuery['page'] = String(currentPage)
    }

    void router.replace({ query: nextQuery })
  },
)

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
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('employees.title') }}</h1>
        <p class="mt-1 text-kq-text-muted">{{ t('employees.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        @click="creating = true"
      >
        {{ t('employees.actions.create') }}
      </button>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent>
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('employees.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="employees-search-filter" class="font-medium">
            {{ t('employees.filters.search') }}
          </label>
          <div class="flex items-center gap-2">
            <input
              id="employees-search-filter"
              v-model="searchInput"
              type="search"
              maxlength="100"
              :placeholder="t('employees.filters.searchPlaceholder')"
              :class="selectClass"
              @keydown.esc="clearSearch"
            />
            <button
              v-if="searchInput !== ''"
              type="button"
              :class="selectClass"
              class="px-2 py-2 text-sm"
              @click="clearSearch"
            >
              <span aria-hidden="true">&times;</span>
              <span class="sr-only">{{ t('common.filters.clearSearch') }}</span>
            </button>
          </div>
        </div>

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
            <option v-for="status of EMPLOYMENT_STATUSES" :key="status" :value="status">
              {{ t(`employees.status.${status}`) }}
            </option>
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
            @change="onSiteChange"
          >
            <option value="">{{ t('employees.filters.siteAll') }}</option>
            <option v-for="site of sites?.data ?? []" :key="site.id" :value="site.id">
              {{ site.name }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="employees-department-filter" class="font-medium">
            {{ t('employees.filters.department') }}
          </label>
          <select
            id="employees-department-filter"
            v-model="departmentFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('employees.filters.departmentAll') }}</option>
            <option
              v-for="department of departmentOptions"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="employees-pin-status-filter" class="font-medium">
            {{ t('employees.filters.pinStatus') }}
          </label>
          <select
            id="employees-pin-status-filter"
            v-model="pinStatusFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('employees.filters.pinStatusAll') }}</option>
            <option v-for="status of PIN_STATUSES" :key="status" :value="status">
              {{ t(`pin.status.${status}`) }}
            </option>
          </select>
        </div>
      </fieldset>

      <button
        v-if="hasFilters"
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="clearFilters"
      >
        {{ t('common.filters.clear') }}
      </button>
    </form>

    <LoadingPanel v-if="isPending" :label="t('employees.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <template v-else>
      <EmptyState
        v-if="rows.length === 0"
        class="mt-4"
        :title="t('employees.empty.title')"
        :description="hasFilters ? t('employees.empty.filtered') : t('employees.empty.description')"
      />

      <div
        v-else
        class="mt-4 overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('employees.table.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
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
            <tr v-for="employee of rows" :key="employee.uuid" class="border-b border-kq-border">
              <th scope="row" class="px-3 py-2 font-medium">
                <RouterLink
                  :to="{ name: 'employee', params: { uuid: employee.uuid } }"
                  class="text-kq-primary-strong underline"
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

      <!-- Se muestra siempre que hay respuesta del servidor, incluso con la
           pagina visible vacia: sin la barra no habria forma de volver a la
           pagina que si tiene filas. -->
      <PaginationBar
        v-if="meta !== null"
        :page="meta.page"
        :per-page="meta.per_page"
        :total="meta.total"
        :total-pages="meta.total_pages"
        :fetching="isFetching"
        :label="t('employees.pagination.label')"
        @update:page="goToPage"
      />
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
