<script setup lang="ts">
// Alta de una ausencia (RF-GP-04): vacaciones, baja medica o permiso.
//
// EL BUSCADOR DE PERSONA REUTILIZA `listEmployees` (la API de plantilla que
// ya existe, `employees.api.ts`) por codigo o por nombre: no hay un segundo
// endpoint para esto. Solo se buscan personas de alta -una ausencia de
// alguien de baja no tiene sentido, y el servidor la rechazaria con `422`
// (decision 2 de la ficha).
//
// `OTHER` EXIGE NOTA (decision 2): es el permiso que no es ninguno de los
// otros tres, y sin una nota la fila no explica nada.
//
// SOLO DIAS COMPLETOS (decision 1): `starts_on`/`ends_on` son fechas civiles
// inclusivas, sin hora. Se puede registrar hacia atras -una baja se conoce
// despues- y hacia delante -las vacaciones-, asi que no hay minimo respecto
// de hoy.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listEmployees } from '@/features/employees/employees.api'
import type { Absence, AbsenceType, CreateAbsenceRequest, Employee } from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import { createAbsence } from './absences.api'

const emit = defineEmits<{ close: []; created: [Absence] }>()

const { t } = useI18n()

const SEARCH_DEBOUNCE_MS = 300
const SEARCH_PER_PAGE = 8
const ABSENCE_TYPES: readonly AbsenceType[] = ['vacation', 'sick_leave', 'leave', 'other']
const NOTE_MAX_LENGTH = 500

const searchInput = ref('')
const searchResults = ref<Employee[]>([])
const searching = ref(false)
const selectedEmployee = ref<Employee | null>(null)

let searchDebounce: ReturnType<typeof setTimeout> | undefined

function fullName(employee: Employee): string {
  return `${employee.first_name} ${employee.last_name}`
}

async function runSearch(term: string): Promise<void> {
  searching.value = true

  try {
    const result = await listEmployees({
      page: 1,
      perPage: SEARCH_PER_PAGE,
      status: 'active',
      q: term,
    })

    searchResults.value = [...result.data]
  } finally {
    searching.value = false
  }
}

function onSearchInput(): void {
  selectedEmployee.value = null

  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }

  const term = searchInput.value.trim()

  if (term.length < 2) {
    searchResults.value = []

    return
  }

  searchDebounce = setTimeout(() => {
    void runSearch(term)
  }, SEARCH_DEBOUNCE_MS)
}

function selectEmployee(employee: Employee): void {
  selectedEmployee.value = employee
  searchResults.value = []
  searchInput.value = `${fullName(employee)} (${employee.employee_code})`
}

onUnmounted(() => {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }
})

const type = ref<AbsenceType | ''>('')
const startsOn = ref('')
const endsOn = ref('')
const note = ref('')

const noteRequired = computed(() => type.value === 'other')
const noteValue = computed(() => note.value.trim())

const datesCoherent = computed(
  () => startsOn.value !== '' && endsOn.value !== '' && endsOn.value >= startsOn.value,
)

const canSubmit = computed(
  () =>
    selectedEmployee.value !== null &&
    type.value !== '' &&
    datesCoherent.value &&
    (!noteRequired.value || noteValue.value !== '') &&
    !submitting.value,
)

const submitting = ref(false)
const error = ref<unknown>(null)

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function submit(): Promise<void> {
  const employee = selectedEmployee.value
  const chosenType = type.value

  if (employee === null || chosenType === '' || !canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null

  const body: CreateAbsenceRequest = {
    employee_uuid: employee.uuid,
    type: chosenType,
    starts_on: startsOn.value,
    ends_on: endsOn.value,
    note: noteValue.value === '' ? null : noteValue.value,
  }

  try {
    emit('created', await createAbsence(body))
  } catch (caught) {
    error.value = caught
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <BaseDialog :title="t('absences.register.heading')" size="wide" @close="emit('close')">
    <form
      id="absence-register-form"
      class="grid gap-4 sm:grid-cols-2"
      novalidate
      @submit.prevent="submit"
    >
      <ErrorNotice v-if="error !== null" :error="error" class="sm:col-span-2" />

      <FormField
        v-slot="field"
        class="sm:col-span-2"
        :label="t('absences.register.personLabel')"
        :hint="t('absences.register.personHint')"
        :errors="fieldErrors('employee_uuid')"
        required
      >
        <input
          :id="field.id"
          v-model="searchInput"
          type="text"
          autocomplete="off"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="register-person-search"
          @input="onSearchInput"
        />
        <p v-if="searching" class="text-sm text-kq-text-muted">
          {{ t('absences.register.searching') }}
        </p>
        <ul
          v-else-if="searchResults.length > 0"
          class="mt-1 flex flex-col gap-1 rounded-kq-sm border border-kq-border bg-kq-surface-raised p-1"
          data-test="register-person-results"
        >
          <li v-for="employee of searchResults" :key="employee.uuid">
            <button
              type="button"
              class="w-full rounded-kq-sm px-2 py-1 text-left hover:bg-kq-surface-alt"
              :data-test="`register-person-option-${employee.uuid}`"
              @click="selectEmployee(employee)"
            >
              {{ fullName(employee) }}
              <span class="text-sm text-kq-text-muted">({{ employee.employee_code }})</span>
            </button>
          </li>
        </ul>
        <p
          v-if="selectedEmployee !== null"
          class="text-sm text-kq-success"
          data-test="register-person-selected"
        >
          {{ t('absences.register.personSelected', { name: fullName(selectedEmployee) }) }}
        </p>
      </FormField>

      <FormField
        v-slot="field"
        :label="t('absences.fields.type')"
        :errors="fieldErrors('type')"
        required
      >
        <select
          :id="field.id"
          v-model="type"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="register-type"
        >
          <option value="" disabled>{{ t('absences.register.typePlaceholder') }}</option>
          <option v-for="option of ABSENCE_TYPES" :key="option" :value="option">
            {{ t(`absences.types.${option}`) }}
          </option>
        </select>
      </FormField>

      <div />

      <FormField
        v-slot="field"
        :label="t('absences.fields.startsOn')"
        :errors="fieldErrors('starts_on')"
        required
      >
        <input
          :id="field.id"
          v-model="startsOn"
          type="date"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="register-starts-on"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('absences.fields.endsOn')"
        :hint="t('absences.register.endsOnHint')"
        :errors="fieldErrors('ends_on')"
        required
      >
        <input
          :id="field.id"
          v-model="endsOn"
          type="date"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="register-ends-on"
        />
      </FormField>

      <FormField
        v-slot="field"
        class="sm:col-span-2"
        :label="t('absences.fields.note')"
        :hint="
          noteRequired ? t('absences.register.noteRequiredHint') : t('absences.register.noteHint')
        "
        :errors="fieldErrors('note')"
        :required="noteRequired"
      >
        <textarea
          :id="field.id"
          v-model="note"
          rows="3"
          :maxlength="NOTE_MAX_LENGTH"
          :required="noteRequired"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="register-note"
        />
      </FormField>
    </form>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('close')"
      >
        {{ t('common.cancel') }}
      </button>
      <button
        type="submit"
        form="absence-register-form"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        data-test="register-submit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
      >
        {{ submitting ? t('common.saving') : t('absences.register.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
