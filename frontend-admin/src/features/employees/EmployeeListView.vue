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
// misma busqueda (mismo patron que el panel de credenciales).
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AuditNotice from '@/shared/ui/AuditNotice.vue'
import { usePersonName } from '@/shared/people/personName'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { getSite, listDepartments } from '@/shared/api/organisation.api'
import type {
  Employee,
  EmployeeProvisioned,
  EmploymentStatus,
  PinStatus,
  RehiredEmployee,
} from '@/shared/api/types'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import EmployeeCreateDialog from './EmployeeCreateDialog.vue'
import PinRevealDialog from './PinRevealDialog.vue'
import RehireBatchDialog from './RehireBatchDialog.vue'
import RehireDialog from './RehireDialog.vue'
import RehireOutcome from './RehireOutcome.vue'
import TeleworkingBadge from './TeleworkingBadge.vue'
import { EMPLOYEE_LIST_PER_PAGE, listEmployees } from './employees.api'

const PER_PAGE = EMPLOYEE_LIST_PER_PAGE
const SEARCH_DEBOUNCE_MS = 300
const EMPLOYMENT_STATUSES: readonly EmploymentStatus[] = ['active', 'suspended', 'terminated']
const PIN_STATUSES: readonly PinStatus[] = ['pending', 'issued', 'delivered']
// El filtro se guarda como el literal que viaja en la URL y en la peticion.
type TeleworkingFilterValue = '' | 'true' | 'false'

const { t } = useI18n()
const { personName } = usePersonName()
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

const departmentFilter = ref<number | ''>(queryInt('department'))

const initialPinStatus = queryParam('pin_status')
const pinStatusFilter = ref<PinStatus | ''>(
  (PIN_STATUSES as readonly string[]).includes(initialPinStatus)
    ? (initialPinStatus as PinStatus)
    : '',
)

const initialTeleworking = queryParam('teleworking')
const teleworkingFilter = ref<TeleworkingFilterValue>(
  initialTeleworking === 'true' || initialTeleworking === 'false' ? initialTeleworking : '',
)

const creating = ref(false)

// El PIN recien emitido vive AQUI y solo mientras el dialogo esta abierto. No
// entra en la tienda, ni en la cache de consultas, ni en `sessionStorage`
// (RF-ID-09).
const provisioned = ref<EmployeeProvisioned | null>(null)

// El centro de la instalacion (ADR-040): solo hace falta su zona horaria, para
// proponer «hoy» como fecha de alta en la zona correcta.
const { data: site } = useQuery({ queryKey: ['site'], queryFn: getSite })
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const departmentOptions = computed(() => departments.value?.data ?? [])

const query = computed(() => ({
  page: page.value,
  perPage: PER_PAGE,
  ...(qFilter.value === '' ? {} : { q: qFilter.value }),
  ...(statusFilter.value === '' ? {} : { status: statusFilter.value }),
  ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
  ...(pinStatusFilter.value === '' ? {} : { pinStatus: pinStatusFilter.value }),
  ...(teleworkingFilter.value === '' ? {} : { teleworking: teleworkingFilter.value === 'true' }),
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

const departmentNames = computed(
  () => new Map((departments.value?.data ?? []).map((item) => [item.id, item.name])),
)

const hasFilters = computed(
  () =>
    statusFilter.value !== '' ||
    departmentFilter.value !== '' ||
    pinStatusFilter.value !== '' ||
    teleworkingFilter.value !== '' ||
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
  departmentFilter.value = ''
  pinStatusFilter.value = ''
  teleworkingFilter.value = ''
}

// La query string refleja el estado, para que un enlace copiado o el «volver»
// del navegador reproduzcan la misma vista.
watch(
  [qFilter, statusFilter, departmentFilter, pinStatusFilter, teleworkingFilter, page],
  ([q, status, department, pinStatus, teleworking, currentPage]) => {
    const nextQuery: Record<string, string> = {}

    if (q !== '') {
      nextQuery['q'] = q
    }

    if (status !== '') {
      nextQuery['status'] = status
    }

    if (department !== '') {
      nextQuery['department'] = String(department)
    }

    if (pinStatus !== '') {
      nextQuery['pin_status'] = pinStatus
    }

    if (teleworking !== '') {
      nextQuery['teleworking'] = teleworking
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
      name: fullName(
        result.employee.first_name,
        result.employee.last_name,
        result.employee.anonymized_at,
      ),
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

function fullName(first: string, last: string, anonymizedAt: string | null): string {
  return personName(first, last, anonymizedAt)
}

// --- Dar de alta de nuevo (RN-27, ADR-062 §6) ---------------------------------
//
// Con el filtro «De baja» cada fila ofrece «Dar de alta de nuevo», y se pueden
// marcar varias para readmitirlas con una fecha comun (la vuelta de la temporada).
// La seleccion vive aqui, se conserva al cambiar de pagina y guarda la FICHA de cada
// persona (el dialogo necesita su cese y su departamento), no solo el uuid. Una
// ficha minimizada no se puede readmitir (409) y no se ofrece.

/** Tope de personas por peticion: el del contrato (`RehireEmployeesRequest.employee_uuids`). */
const REHIRE_MAX_SELECTION = 500

const terminatedView = computed(() => statusFilter.value === 'terminated')

function canRehire(employee: Employee): boolean {
  return employee.status === 'terminated' && employee.anonymized_at === null
}

/** uuid -> ficha, en el orden en que se fueron marcando (el orden de la peticion). */
const selection = ref(new Map<string, Employee>())
const selectionLimitReached = ref(false)
const selectAllBox = ref<HTMLInputElement | null>(null)

const selectedPeople = computed(() => [...selection.value.values()])
const rehirableRows = computed(() => rows.value.filter(canRehire))
const allRowsSelected = computed(
  () =>
    rehirableRows.value.length > 0 &&
    rehirableRows.value.every((employee) => selection.value.has(employee.uuid)),
)
const someRowsSelected = computed(
  () =>
    !allRowsSelected.value &&
    rehirableRows.value.some((employee) => selection.value.has(employee.uuid)),
)

/**
 * El aviso del tope se queda mientras la seleccion este llena; en cuanto cabe alguien mas
 * (porque se desmarco a una persona) deja de ser verdad y se quita.
 */
function refreshLimitNotice(size: number): void {
  if (size < REHIRE_MAX_SELECTION) {
    selectionLimitReached.value = false
  }
}

function setSelected(employee: Employee, box: HTMLInputElement): void {
  const next = new Map(selection.value)

  if (!box.checked) {
    next.delete(employee.uuid)
    refreshLimitNotice(next.size)
  } else if (next.size < REHIRE_MAX_SELECTION) {
    next.set(employee.uuid, employee)
  } else {
    selectionLimitReached.value = true
    // La casilla no se re-pinta sola (su `:checked` era falso antes y despues): se desmarca a mano,
    // o quedaria marcada —y un lector de pantalla diria «marcada»— sin que esa persona entre.
    box.checked = false
  }

  selection.value = next
  announce(t('employees.announce.selected', next.size))
}

function toggleAllRows(box: HTMLInputElement): void {
  const next = new Map(selection.value)

  for (const employee of rehirableRows.value) {
    if (!box.checked) {
      next.delete(employee.uuid)
    } else if (next.size < REHIRE_MAX_SELECTION) {
      next.set(employee.uuid, employee)
    } else {
      selectionLimitReached.value = true
    }
  }

  if (!box.checked) {
    refreshLimitNotice(next.size)
  }

  selection.value = next
  // Con el tope alcanzado a medias, la cabecera no esta del todo marcada: que lo diga la casilla.
  box.checked = allRowsSelected.value
  announce(t('employees.announce.selected', next.size))
}

function clearSelection(): void {
  selection.value = new Map()
  selectionLimitReached.value = false
}

/** «Quitar la selección» desaparece al pulsarlo: el foco pasa a la casilla de cabecera, no a `<body>`. */
async function onClearSelection(): Promise<void> {
  clearSelection()
  announce(t('employees.announce.selected', 0))
  await nextTick()
  selectAllBox.value?.focus()
}

// Salir del filtro «De baja» descarta la seleccion: ya no se ve lo que se habia marcado.
watch(terminatedView, (active) => {
  if (!active) {
    clearSelection()
  }
})

/** La persona de la fila cuyo boton «Dar de alta de nuevo» se ha pulsado. */
const rehiring = ref<Employee | null>(null)
const rehiringBatch = ref(false)

/** El resultado de la readmision, con el PIN en claro si se pidio. Solo vive mientras se ensena. */
const rehireResults = ref<RehiredEmployee[] | null>(null)

function onRehired(results: RehiredEmployee[]): void {
  rehiring.value = null
  rehiringBatch.value = false
  rehireResults.value = results
  // Se quita de la seleccion a quien se acaba de dar de alta, y solo a esa gente: una readmision
  // individual no tira la seleccion que se estaba preparando para el lote.
  const next = new Map(selection.value)

  for (const result of results) {
    next.delete(result.employee.uuid)
  }

  selection.value = next
  refreshLimitNotice(next.size)
  announce(
    results.length === 1
      ? t('employees.announce.rehired')
      : t('employees.announce.rehiredMany', results.length),
  )
  void refetch()
}

function closeRehireResults(): void {
  // Al soltar la referencia, los PIN en claro dejan de existir en el navegador.
  rehireResults.value = null
}

/** Aviso de que la persona ya no estaba de baja: sin el, la fila desaparece sin explicacion. */
const rehireStale = ref(false)

// Abrir una readmision nueva descarta el aviso de la anterior: ya no habla de lo que se hace.
watch([rehiring, rehiringBatch], ([single, batch]) => {
  if (single !== null || batch) {
    rehireStale.value = false
  }
})

/** El servidor dice que la ficha ya no esta de baja (o esta minimizada): se relee la lista y se dice por que. */
function onRehireStale(): void {
  rehiring.value = null
  rehireStale.value = true
  void refetch()
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
        <AuditNotice />
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
        <div class="flex flex-col gap-1">
          <label for="employees-teleworking-filter" class="font-medium">
            {{ t('employees.filters.teleworking') }}
          </label>
          <select
            id="employees-teleworking-filter"
            v-model="teleworkingFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('employees.filters.teleworkingAll') }}</option>
            <option value="true">{{ t('employees.filters.teleworkingYes') }}</option>
            <option value="false">{{ t('employees.filters.teleworkingNo') }}</option>
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

    <!-- Alguien se adelanto: la fila ya no esta de baja. Alerta, el lector de pantalla la lee solo. -->
    <div
      v-if="rehireStale"
      role="alert"
      data-test="rehire-stale"
      class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
    >
      <p class="font-semibold">{{ t('employees.rehire.stale.title') }}</p>
      <p class="mt-1">{{ t('employees.rehire.stale.advice') }}</p>
    </div>

    <LoadingPanel v-if="isPending" :label="t('employees.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" @retry="refetch()" />

    <template v-else>
      <EmptyState
        v-if="rows.length === 0"
        class="mt-4"
        :title="t('employees.empty.title')"
        :description="hasFilters ? t('employees.empty.filtered') : t('employees.empty.description')"
      />

      <!-- Dar de alta de nuevo a varias personas: solo con el filtro «De baja» (RN-27). Con
           alguien marcado la barra se queda arriba al bajar por la tabla: el boton no se pierde. -->
      <div
        v-if="rows.length > 0 && terminatedView"
        class="mt-4 flex flex-wrap items-center gap-3"
        :class="
          selection.size > 0 ? 'sticky top-0 z-10 border-b border-kq-border bg-kq-surface py-2' : ''
        "
        data-test="rehire-toolbar"
      >
        <p class="max-w-prose text-kq-text-muted">
          {{ t('employees.selection.hint', { max: REHIRE_MAX_SELECTION }) }}
        </p>
        <p v-if="selection.size > 0" class="font-medium" data-test="selection-summary">
          {{ t('employees.selection.summary', selection.size) }}
        </p>
        <!-- Sin nadie marcado no hay nada que hacer: el boton aparece con la primera casilla. -->
        <button
          v-if="selection.size > 0"
          type="button"
          data-test="rehire-selected"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
          @click="rehiringBatch = true"
        >
          {{ t('employees.actions.rehireSelected', { count: selection.size }) }}
        </button>
        <button
          v-if="selection.size > 0"
          type="button"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
          data-test="selection-clear"
          @click="onClearSelection"
        >
          {{ t('employees.selection.clear') }}
        </button>
        <p
          v-if="selectionLimitReached"
          role="status"
          class="w-full rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
        >
          {{ t('employees.selection.tooMany', { max: REHIRE_MAX_SELECTION }) }}
        </p>
      </div>

      <div
        v-if="rows.length > 0"
        tabindex="0"
        role="region"
        :aria-label="t('employees.table.caption')"
        class="mt-4 relative overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('employees.table.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th v-if="terminatedView" scope="col" class="px-3 py-2">
                <input
                  ref="selectAllBox"
                  type="checkbox"
                  data-test="select-all"
                  :checked="allRowsSelected"
                  :indeterminate="someRowsSelected"
                  :disabled="rehirableRows.length === 0"
                  :aria-label="t('employees.table.selectAll')"
                  @change="toggleAllRows($event.target as HTMLInputElement)"
                />
              </th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.name') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.code') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.department') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('employees.table.status') }}</th>
              <!-- Estado del PIN, nunca el PIN. El valor solo existe en el
                   dialogo que lo emite (RF-ID-09). -->
              <th scope="col" class="px-3 py-2">{{ t('employees.table.pinStatus') }}</th>
              <!-- Informativo: no altera el fichaje ni el calculo de horas. -->
              <th scope="col" class="px-3 py-2">{{ t('employees.table.teleworking') }}</th>
              <th v-if="terminatedView" scope="col" class="px-3 py-2">
                <span class="sr-only">{{ t('employees.table.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="employee of rows"
              :key="employee.uuid"
              class="border-b border-kq-border"
              data-test="employee-row"
            >
              <td v-if="terminatedView" class="px-3 py-2">
                <input
                  v-if="canRehire(employee)"
                  type="checkbox"
                  data-test="select-row"
                  :checked="selection.has(employee.uuid)"
                  :aria-label="
                    t('employees.table.selectRow', {
                      name: fullName(
                        employee.first_name,
                        employee.last_name,
                        employee.anonymized_at,
                      ),
                    })
                  "
                  @change="setSelected(employee, $event.target as HTMLInputElement)"
                />
              </td>
              <th scope="row" class="px-3 py-2 font-medium">
                <RouterLink
                  :to="{ name: 'employee', params: { uuid: employee.uuid } }"
                  class="text-kq-primary-strong underline"
                >
                  {{ fullName(employee.first_name, employee.last_name, employee.anonymized_at) }}
                </RouterLink>
              </th>
              <td class="px-3 py-2 font-mono">{{ employee.employee_code }}</td>
              <td class="px-3 py-2">
                {{
                  employee.department_id === null
                    ? t('employees.fields.departmentNone')
                    : (departmentNames.get(employee.department_id) ?? '—')
                }}
              </td>
              <td class="px-3 py-2">
                {{
                  employee.offboarding?.state === 'scheduled'
                    ? t('employees.status.scheduled')
                    : t(`employees.status.${employee.status}`)
                }}
              </td>
              <td class="px-3 py-2">{{ t(`pin.status.${employee.pin_status}`) }}</td>
              <td class="px-3 py-2"><TeleworkingBadge :teleworking="employee.teleworking" /></td>
              <td v-if="terminatedView" class="px-3 py-2">
                <button
                  v-if="canRehire(employee)"
                  type="button"
                  data-test="rehire-row"
                  class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1 text-kq-text hover:bg-kq-surface-alt"
                  @click="rehiring = employee"
                >
                  {{ t('employees.actions.rehire') }}
                  <span class="sr-only">
                    {{
                      t('employees.actions.rehireFor', {
                        name: fullName(
                          employee.first_name,
                          employee.last_name,
                          employee.anonymized_at,
                        ),
                      })
                    }}
                  </span>
                </button>
              </td>
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
      :timezone="site?.timezone ?? FALLBACK_TIMEZONE"
      @close="creating = false"
      @created="onCreated"
    />

    <RehireDialog
      v-if="rehiring !== null"
      :employee="rehiring"
      :timezone="site?.timezone"
      @close="rehiring = null"
      @rehired="(result) => onRehired([result])"
      @stale="onRehireStale"
    />

    <RehireBatchDialog
      v-if="rehiringBatch"
      :people="selectedPeople"
      :timezone="site?.timezone"
      @close="rehiringBatch = false"
      @rehired="onRehired"
    />

    <RehireOutcome
      v-if="rehireResults !== null"
      :results="rehireResults"
      @done="closeRehireResults"
      @pin-delivered="refetch()"
    />

    <PinRevealDialog
      v-if="provisioned !== null"
      :pin="provisioned.pin"
      :employee-name="
        fullName(
          provisioned.employee.first_name,
          provisioned.employee.last_name,
          provisioned.employee.anonymized_at,
        )
      "
      :employee-code="provisioned.employee.employee_code"
      @acknowledged="closePinDialog"
      @delivered="onPinDelivered"
    />
  </section>
</template>
