<script setup lang="ts">
// Ausencias: vacaciones, baja medica y permiso (RF-GP-04).
//
// SIN FLUJO DE APROBACION Y SIN BOTON DE APROBAR (doc 05 §8, Fase 4): una
// ausencia registrada es un hecho, nunca un estado «pendiente».
//
// AMBITO DE LECTURA Y NO EL DE ESCRITURA (RF-ID-03): la pantalla se ofrece con
// `employees:read`, que tambien lleva `responsable_departamento` -el alcance
// por departamento lo aplica el servidor en el `WHERE`-, y las acciones de
// registrar, corregir, anular e importar se muestran solo con
// `employees:*` (`EMPLOYEES_MANAGE`). Ocultarlas aqui es cortesia de
// interfaz: quien las fuerce por la red recibe `403` igualmente (regla dura
// 18).
//
// LA NOTA NO SE PINTA SI NO VIENE. El servidor omite el campo entero para
// quien no tiene `employees:*` (decision 5 de la ficha): no es que la nota
// este vacia, es que no viaja. La columna se decide mirando los datos que han
// llegado, nunca el rol de quien mira -asi la pantalla nunca inventa una nota
// que el servidor no mando.
//
// PERIODO POR OMISION DEL SERVIDOR: si no se elige «desde»/«hasta», no se
// manda el parametro y el servidor aplica el suyo (el mes en curso hasta un
// año despues, decision 5). El listado no espera a que se elija un periodo
// para cargar -al contrario que el informe por periodo, que es una consulta
// cara-: leer las ausencias vigentes de la instalacion es barato y es lo que
// se espera ver nada mas entrar, como la plantilla.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/vue-query'
import { EMPLOYEES_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { listDepartments } from '@/shared/api/organisation.api'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import { ABSENCE_LIST_PER_PAGE, getAbsence, listAbsences } from './absences.api'
import AbsenceCorrectDialog from './AbsenceCorrectDialog.vue'
import AbsenceHistoryPanel from './AbsenceHistoryPanel.vue'
import AbsenceImportDialog from './AbsenceImportDialog.vue'
import AbsenceRegisterDialog from './AbsenceRegisterDialog.vue'
import AbsenceVoidDialog from './AbsenceVoidDialog.vue'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { Absence, AbsenceType } from '@/shared/api/types'

const PER_PAGE = ABSENCE_LIST_PER_PAGE
const ABSENCE_TYPES: readonly AbsenceType[] = ['vacation', 'sick_leave', 'leave', 'other']

const { t, locale } = useI18n()
const session = useSessionStore()
const queryClient = useQueryClient()

const canWrite = computed(() => session.can(EMPLOYEES_MANAGE))

const page = ref(1)
const fromFilter = ref('')
const toFilter = ref('')
const departmentFilter = ref<number | ''>('')
const typeFilter = ref<AbsenceType | ''>('')
/** «Incluir corregidas y anuladas» (decision 5: `status=all`, o nada para `active`). */
const includeAllStatuses = ref(false)

const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})
const departmentOptions = computed(() => departments.value?.data ?? [])
const departmentNames = computed(
  () => new Map((departments.value?.data ?? []).map((item) => [item.id, item.name])),
)

const query = computed(() => ({
  page: page.value,
  perPage: PER_PAGE,
  ...(fromFilter.value === '' ? {} : { from: fromFilter.value }),
  ...(toFilter.value === '' ? {} : { to: toFilter.value }),
  ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
  ...(typeFilter.value === '' ? {} : { type: typeFilter.value }),
  ...(includeAllStatuses.value ? { status: 'all' as const } : {}),
}))

const {
  data: absences,
  error,
  isPending,
  isFetching,
} = useQuery({
  queryKey: computed(() => ['absences', query.value] as const),
  queryFn: () => listAbsences(query.value),
  placeholderData: keepPreviousData,
})

const rows = computed(() => absences.value?.data ?? [])
const meta = computed(() => absences.value?.meta ?? null)

/** La nota no se pinta si no viene (regla dura 21, decision 5): dato de salud posible. */
const hasNoteColumn = computed(() => rows.value.some((row) => row.note !== undefined))

const hasFilters = computed(
  () =>
    fromFilter.value !== '' ||
    toFilter.value !== '' ||
    departmentFilter.value !== '' ||
    typeFilter.value !== '' ||
    includeAllStatuses.value,
)

watch(
  () => meta.value?.total,
  (total) => {
    if (total !== undefined) {
      announce(t('absences.announce.results', { count: total }))
    }
  },
)

watch(
  () => meta.value?.total_pages,
  (totalPages) => {
    if (totalPages !== undefined && page.value > totalPages) {
      page.value = Math.max(totalPages, 1)
    }
  },
)

function resetToFirstPage(): void {
  page.value = 1
}

function clearFilters(): void {
  fromFilter.value = ''
  toFilter.value = ''
  departmentFilter.value = ''
  typeFilter.value = ''
  includeAllStatuses.value = false
  resetToFirstPage()
}

function departmentName(absence: Absence): string {
  if (absence.department_name !== null) {
    return absence.department_name
  }

  if (absence.department_id === null) {
    return t('absences.fields.departmentNone')
  }

  return departmentNames.value.get(absence.department_id) ?? '—'
}

async function invalidate(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['absences'] })
}

// --- Alta ----------------------------------------------------------------

const registering = ref(false)

function onRegistered(): void {
  registering.value = false
  announce(t('absences.announce.registered'))
  void invalidate()
}

// --- Importacion -----------------------------------------------------------

const importing = ref(false)

function onImported(): void {
  void invalidate()
}

// --- Correccion y anulacion ------------------------------------------------

const correcting = ref<Absence | null>(null)
const voiding = ref<Absence | null>(null)

function onCorrected(): void {
  correcting.value = null
  announce(t('absences.announce.corrected'))
  void invalidate()
}

function onVoided(): void {
  voiding.value = null
  announce(t('absences.announce.voided'))
  void invalidate()
}

// --- Historial ---------------------------------------------------------------

const historyUuid = ref<string | null>(null)

const {
  data: historyDetail,
  error: historyError,
  isPending: historyPending,
} = useQuery({
  queryKey: computed(() => ['absence', historyUuid.value] as const),
  queryFn: () => getAbsence(historyUuid.value as string),
  enabled: computed(() => historyUuid.value !== null),
})

function closeHistory(): void {
  historyUuid.value = null
}

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('absences.title') }}</h1>
        <p class="mt-1 text-kq-text-muted">{{ t('absences.subtitle') }}</p>
      </div>
      <div v-if="canWrite" class="flex flex-wrap gap-3">
        <button
          type="button"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
          data-test="absences-import"
          @click="importing = true"
        >
          {{ t('absences.actions.import') }}
        </button>
        <button
          type="button"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
          data-test="absences-register"
          @click="registering = true"
        >
          {{ t('absences.actions.register') }}
        </button>
      </div>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent>
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('absences.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="absences-from-filter" class="font-medium">{{
            t('absences.filters.from')
          }}</label>
          <input
            id="absences-from-filter"
            v-model="fromFilter"
            type="date"
            :class="selectClass"
            @change="resetToFirstPage"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="absences-to-filter" class="font-medium">{{ t('absences.filters.to') }}</label>
          <input
            id="absences-to-filter"
            v-model="toFilter"
            type="date"
            :class="selectClass"
            @change="resetToFirstPage"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="absences-department-filter" class="font-medium">
            {{ t('absences.filters.department') }}
          </label>
          <select
            id="absences-department-filter"
            v-model="departmentFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('absences.filters.departmentAll') }}</option>
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
          <label for="absences-type-filter" class="font-medium">{{
            t('absences.filters.type')
          }}</label>
          <select
            id="absences-type-filter"
            v-model="typeFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('absences.filters.typeAll') }}</option>
            <option v-for="type of ABSENCE_TYPES" :key="type" :value="type">
              {{ t(`absences.types.${type}`) }}
            </option>
          </select>
        </div>

        <div class="flex items-center gap-2">
          <input
            id="absences-include-all-statuses"
            v-model="includeAllStatuses"
            type="checkbox"
            class="size-4 rounded-kq-sm border border-kq-border-strong"
            @change="resetToFirstPage"
          />
          <label for="absences-include-all-statuses">{{
            t('absences.filters.includeAllStatuses')
          }}</label>
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

    <LoadingPanel v-if="isPending" :label="t('absences.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <template v-else>
      <EmptyState
        v-if="rows.length === 0"
        class="mt-4"
        :title="t('absences.empty.title')"
        :description="hasFilters ? t('absences.empty.filtered') : t('absences.empty.description')"
      />

      <div
        v-else
        class="mt-4 overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('absences.table.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.person') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.code') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.department') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.type') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.from') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.to') }}</th>
              <th scope="col" class="px-3 py-2 text-right">{{ t('absences.table.days') }}</th>
              <th v-if="hasNoteColumn" scope="col" class="px-3 py-2">
                {{ t('absences.table.note') }}
              </th>
              <th scope="col" class="px-3 py-2">{{ t('absences.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="absence of rows" :key="absence.uuid" class="border-b border-kq-border">
              <th scope="row" class="px-3 py-2 font-medium">{{ absence.employee_name }}</th>
              <td class="px-3 py-2 font-mono">{{ absence.employee_code }}</td>
              <td class="px-3 py-2">{{ departmentName(absence) }}</td>
              <td class="px-3 py-2">
                {{ t(`absences.types.${absence.type}`) }}
                <span
                  v-if="absence.status !== 'active'"
                  class="ml-1 rounded-full px-2 py-0.5 text-sm"
                  :class="
                    absence.status === 'voided'
                      ? 'bg-kq-danger-soft text-kq-danger'
                      : 'bg-kq-surface-alt text-kq-text-muted'
                  "
                >
                  {{ t(`absences.status.${absence.status}`) }}
                </span>
              </td>
              <td class="px-3 py-2 tabular-nums">
                {{ formatCivilDate(absence.starts_on, locale) }}
              </td>
              <td class="px-3 py-2 tabular-nums">{{ formatCivilDate(absence.ends_on, locale) }}</td>
              <td class="px-3 py-2 text-right tabular-nums">{{ absence.days }}</td>
              <td v-if="hasNoteColumn" class="px-3 py-2">
                {{ absence.note === undefined || absence.note === null ? '' : absence.note }}
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-2">
                  <button
                    type="button"
                    class="text-kq-primary-strong underline"
                    :data-test="`absence-history-${absence.uuid}`"
                    @click="historyUuid = absence.uuid"
                  >
                    {{ t('absences.actions.history') }}
                  </button>
                  <template v-if="canWrite && absence.status === 'active'">
                    <button
                      type="button"
                      class="text-kq-primary-strong underline"
                      :data-test="`absence-correct-${absence.uuid}`"
                      @click="correcting = absence"
                    >
                      {{ t('absences.actions.correct') }}
                    </button>
                    <button
                      type="button"
                      class="text-kq-danger underline"
                      :data-test="`absence-void-${absence.uuid}`"
                      @click="voiding = absence"
                    >
                      {{ t('absences.actions.void') }}
                    </button>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar
        v-if="meta !== null"
        :page="meta.page"
        :per-page="meta.per_page"
        :total="meta.total"
        :total-pages="meta.total_pages"
        :fetching="isFetching"
        :label="t('absences.pagination.label')"
        @update:page="(next) => (page = next)"
      />
    </template>

    <AbsenceRegisterDialog
      v-if="registering"
      @close="registering = false"
      @created="onRegistered"
    />

    <AbsenceImportDialog v-if="importing" @close="importing = false" @applied="onImported" />

    <AbsenceCorrectDialog
      v-if="correcting !== null"
      :absence="correcting"
      @cancel="correcting = null"
      @success="onCorrected"
    />

    <AbsenceVoidDialog
      v-if="voiding !== null"
      :absence="voiding"
      @cancel="voiding = null"
      @success="onVoided"
    />

    <BaseDialog
      v-if="historyUuid !== null"
      :title="t('absences.history.dialogHeading')"
      size="wide"
      @close="closeHistory"
    >
      <LoadingPanel v-if="historyPending" :label="t('absences.history.loading')" />
      <ErrorNotice v-else-if="historyError !== null" :error="historyError" />
      <AbsenceHistoryPanel v-else-if="historyDetail !== undefined" :detail="historyDetail" />

      <template #actions>
        <button
          type="button"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
          @click="closeHistory"
        >
          {{ t('common.close') }}
        </button>
      </template>
    </BaseDialog>
  </section>
</template>
