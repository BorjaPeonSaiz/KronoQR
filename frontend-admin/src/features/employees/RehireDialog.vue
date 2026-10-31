<script setup lang="ts">
// Readmision de UNA persona de baja: «Dar de alta de nuevo» (RN-27, ADR-062 §6).
//
// UN SOLO DATO OBLIGATORIO: la fecha de alta, con hoy por defecto. Todo lo demas
// viene relleno de la ficha anterior y se puede cambiar: departamento, idioma,
// contrato y si se emite un PIN nuevo. No vuelve a pedir DNI, nombre ni codigo:
// es la misma persona en la misma ficha.
//
// Antes de confirmar enseña QUE cambia y DESDE que valor: la situacion, las fechas
// y lo que ocurre con la tarjeta (una nueva, pendiente de imprimir; las de antes
// siguen revocadas) y con el PIN (se conserva, o uno nuevo que se vera UNA vez).
//
// Dos avisos que decidio el propietario (31-10-2026), porque el efecto llega a la
// tablet y no se ve desde aqui: con fecha de alta futura, los fichajes anteriores
// a esa fecha se rechazan; y si la baja fue hoy, hay que esperar a que los
// quioscos esten sincronizados. Son avisos, no bloqueos.
import { useQuery } from '@tanstack/vue-query'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate, todayInZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useOfferedLocales } from '@/shared/i18n/useOfferedLocales'
import { listDepartments } from '@/shared/api/organisation.api'
import type {
  Employee,
  RehireContract,
  RehireEmployeeRequest,
  RehiredEmployee,
  ScheduleType,
} from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { listEmploymentContracts } from './contracts.api'
import DateWarnings from './DateWarnings.vue'
import { addCivilDays } from './employmentDates'
import { parsePositiveHours, WEEKLY_HOURS_MAX, ANNUAL_HOURS_MAX } from './employmentLimits'
import EmploymentProblemNotice from './EmploymentProblemNotice.vue'
import { type EmploymentProblemKey, staleProblemKey } from './employmentProblems'
import { rehireEmployee } from './employees.api'
import { rehireDateWarnings } from './rehirePlan'

const props = defineProps<{
  employee: Employee
  /** Zona del centro; sin ella no se adivina «hoy» y no hay avisos de fecha. */
  timezone: string | undefined
}>()

const emit = defineEmits<{
  close: []
  rehired: [RehiredEmployee]
  /** La ficha ya no esta de baja, o esta minimizada: quien contiene este dialogo la relee. */
  stale: [EmploymentProblemKey]
}>()

const { t, locale } = useI18n()
const { locales: offered, multilingual } = useOfferedLocales()

const SCHEDULE_TYPES: readonly ScheduleType[] = ['continua', 'partida', 'turnos']

/** Hoy en el centro, fijado al abrir. Vacio si aun no se conoce la zona. */
const today = props.timezone === undefined ? '' : todayInZone(props.timezone)

const hiredAt = ref(today)
const departmentId = ref<number | null>(props.employee.department_id)
const localeValue = ref(props.employee.locale)
const resetPin = ref(false)
type ContractMode = 'keep' | 'none' | 'custom'
const contractMode = ref<ContractMode>('keep')
const weeklyHours = ref('')
const annualHours = ref('')
const scheduleType = ref<ScheduleType | ''>('')

const busy = ref(false)
const error = ref<unknown>(null)
const dateInput = ref<HTMLInputElement | null>(null)

const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const { data: contracts } = useQuery({
  queryKey: ['employee-contracts', props.employee.uuid] as const,
  queryFn: () => listEmploymentContracts(props.employee.uuid),
})

/** El ultimo contrato de la ficha: del que se copian los terminos si no se cambia nada. */
const lastContract = computed(() => contracts.value?.data.at(-1) ?? null)

function date(value: string | null): string {
  return value === null || value === '' ? t('common.empty') : formatCivilDate(value, locale.value)
}

function departmentName(id: number | null): string {
  if (id === null) {
    return t('employees.fields.departmentNone')
  }

  return (departments.value?.data ?? []).find((item) => item.id === id)?.name ?? '—'
}

function hours(value: number | null): string {
  return value === null
    ? t('common.empty')
    : t('contracts.hoursValue', {
        hours: new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value),
      })
}

const warnings = computed(() =>
  rehireDateWarnings({
    hiredAt: hiredAt.value,
    terminatedAt: props.employee.terminated_at,
    today,
  }),
)

// Con la fecha ya rechazada (no es posterior al cese) los avisos de sincronizacion y de
// alta futura no hablan de nada que vaya a ocurrir: manda el error, que es lo que hay que arreglar.
const showFutureWarning = computed(() => warnings.value.futureHire)
const showRecentWarning = computed(
  () => warnings.value.recentTermination && !warnings.value.notAfterTermination,
)

/** El dia siguiente al cese: la primera fecha de alta que admite el servidor. */
const minHiredAt = computed(() =>
  props.employee.terminated_at === null ? undefined : addCivilDays(props.employee.terminated_at, 1),
)

const serverDateErrors = computed<readonly string[]>(() =>
  isApiError(error.value) ? (error.value.fieldErrors['hired_at'] ?? []) : [],
)

const dateErrors = computed<readonly string[]>(() => [
  ...(warnings.value.notAfterTermination
    ? [t('employees.rehire.notAfterTermination', { date: date(props.employee.terminated_at) })]
    : []),
  ...(warnings.value.notAfterTermination ? [] : serverDateErrors.value),
])

const weeklyValue = computed(() => parsePositiveHours(weeklyHours.value, WEEKLY_HOURS_MAX))
const annualValue = computed(() => parsePositiveHours(annualHours.value, ANNUAL_HOURS_MAX))
const weeklyInvalid = computed(() => weeklyHours.value.trim() !== '' && weeklyValue.value === null)
const annualInvalid = computed(() => annualHours.value.trim() !== '' && annualValue.value === null)

const customContractReady = computed(
  () => weeklyValue.value !== null && !annualInvalid.value && scheduleType.value !== '',
)

const confirmDisabled = computed(
  () =>
    hiredAt.value === '' ||
    dateErrors.value.length > 0 ||
    (contractMode.value === 'custom' && !customContractReady.value),
)

const contractText = computed(() => {
  if (contractMode.value === 'none') {
    return t('employees.rehire.contract.noneAfter')
  }

  if (contractMode.value === 'custom') {
    return weeklyValue.value === null
      ? t('common.empty')
      : `${hours(weeklyValue.value)} · ${
          scheduleType.value === '' ? '' : t(`contracts.schedule.${scheduleType.value}`)
        }`
  }

  return lastContract.value === null
    ? t('employees.rehire.contract.keepNone')
    : `${hours(lastContract.value.weekly_hours)} · ${t(`contracts.schedule.${lastContract.value.schedule_type}`)}`
})

const changes = computed<Change[]>(() => {
  const rows: Change[] = [
    {
      label: t('employees.fields.status'),
      from: t('employees.status.terminated'),
      to: t('employees.status.active'),
    },
    {
      label: t('employees.fields.hiredAt'),
      from: date(props.employee.hired_at),
      to: date(hiredAt.value),
    },
    {
      label: t('employees.fields.terminatedAt'),
      from: date(props.employee.terminated_at),
      to: t('common.empty'),
    },
  ]

  if (departmentId.value !== props.employee.department_id) {
    rows.push({
      label: t('employees.fields.department'),
      from: departmentName(props.employee.department_id),
      to: departmentName(departmentId.value),
    })
  }

  if (multilingual.value && localeValue.value !== props.employee.locale) {
    rows.push({
      label: t('employees.fields.locale'),
      from: props.employee.locale,
      to: localeValue.value,
    })
  }

  rows.push(
    {
      label: t('employees.rehire.contract.label'),
      from:
        lastContract.value === null ? t('common.empty') : t('employees.rehire.contract.previous'),
      to: contractText.value,
    },
    {
      label: t('credentials.table.status'),
      from: t('credentials.status.revoked'),
      to: t('credentials.status.pending_print'),
    },
    {
      label: t('pin.field'),
      from: t(`pin.status.${props.employee.pin_status}`),
      to: resetPin.value ? t('employees.rehire.pin.new') : t('employees.rehire.pin.kept'),
    },
  )

  return rows
})

function buildContract(): RehireContract | null | undefined {
  if (contractMode.value === 'keep') {
    // Sin el campo: el servidor registra un contrato con los terminos del ultimo.
    return undefined
  }

  if (contractMode.value === 'none') {
    return null
  }

  const weekly = weeklyValue.value
  const schedule = scheduleType.value

  if (weekly === null || schedule === '') {
    return undefined
  }

  return { weekly_hours: weekly, annual_hours: annualValue.value, schedule_type: schedule }
}

async function confirm(): Promise<void> {
  busy.value = true
  error.value = null

  const contract = buildContract()
  const body: RehireEmployeeRequest = {
    hired_at: hiredAt.value,
    reset_pin: resetPin.value,
    // Solo se manda lo que cambia: lo demas lo toma el servidor de la ficha.
    ...(departmentId.value !== props.employee.department_id
      ? { department_id: departmentId.value }
      : {}),
    ...(multilingual.value && localeValue.value !== props.employee.locale
      ? { locale: localeValue.value }
      : {}),
    ...(contract === undefined ? {} : { contract }),
  }

  try {
    emit('rehired', await rehireEmployee(props.employee.uuid, body))
  } catch (caught) {
    error.value = caught

    if (isApiError(caught) && caught.status === 422 && serverDateErrors.value.length > 0) {
      dateInput.value?.focus()
    }

    const stale = staleProblemKey(caught)

    if (stale !== null) {
      emit('stale', stale)
    }
  } finally {
    busy.value = false
  }
}

/** Un 422 con mensaje en `hired_at` se pinta junto al campo; el resto, en el aviso del dialogo. */
const visibleError = computed<unknown>(() =>
  isApiError(error.value) &&
  error.value.status === 422 &&
  error.value.fieldErrors['hired_at'] !== undefined
    ? null
    : error.value,
)

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <ConfirmDialog
    :title="t('employees.rehire.heading')"
    :confirm-label="t('employees.rehire.confirmAction')"
    size="wide"
    :busy="busy"
    :error="visibleError"
    :confirm-disabled="confirmDisabled"
    @cancel="emit('close')"
    @confirm="confirm"
  >
    <template #error="{ error: failure }">
      <EmploymentProblemNotice :error="failure" />
    </template>

    <p class="mb-4 max-w-prose">{{ t('employees.rehire.explanation') }}</p>

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField
        v-slot="field"
        class="sm:col-span-2"
        :label="t('employees.rehire.hiredAtLabel')"
        :hint="
          t('employees.rehire.hiredAtHint', {
            date: date(employee.terminated_at),
          })
        "
        :errors="dateErrors"
        required
      >
        <input
          :id="field.id"
          ref="dateInput"
          v-model="hiredAt"
          type="date"
          required
          :min="minHiredAt"
          :class="inputClass"
          :aria-invalid="field.invalid ? 'true' : undefined"
          :aria-describedby="field.describedBy"
        />
      </FormField>

      <!-- Los dos avisos del propietario (31-10-2026), en una sola caja. -->
      <DateWarnings class="sm:col-span-2" :visible="showFutureWarning || showRecentWarning">
        <p v-if="showFutureWarning" data-test="rehire-future-warning">
          {{ t('employees.rehire.futureHire', { date: date(hiredAt) }) }}
        </p>
        <p v-if="showRecentWarning" data-test="rehire-recent-warning">
          {{ t('employees.rehire.recentTermination') }}
        </p>
      </DateWarnings>

      <FormField v-slot="field" :label="t('employees.fields.department')">
        <select :id="field.id" v-model="departmentId" :class="inputClass">
          <option :value="null">{{ t('employees.fields.departmentNone') }}</option>
          <option v-for="item of departments?.data ?? []" :key="item.id" :value="item.id">
            {{ item.name }}
          </option>
        </select>
      </FormField>

      <FormField v-if="multilingual" v-slot="field" :label="t('employees.fields.locale')">
        <select :id="field.id" v-model="localeValue" :class="inputClass">
          <option v-for="option in offered" :key="option" :value="option">
            {{ t(`common.locales.${option}`) }}
          </option>
        </select>
      </FormField>

      <fieldset class="sm:col-span-2">
        <legend class="font-medium">{{ t('employees.rehire.contract.label') }}</legend>
        <div class="mt-2 flex flex-col gap-2">
          <label class="flex items-start gap-2">
            <input v-model="contractMode" type="radio" value="keep" class="mt-1" />
            <span>
              {{ t('employees.rehire.contract.keep') }}
              <span class="block text-sm text-kq-text-muted" data-test="rehire-contract-keep">
                {{
                  lastContract === null
                    ? t('employees.rehire.contract.keepNone')
                    : t('employees.rehire.contract.keepTerms', {
                        terms: `${hours(lastContract.weekly_hours)} · ${t(`contracts.schedule.${lastContract.schedule_type}`)}`,
                      })
                }}
              </span>
            </span>
          </label>
          <label class="flex items-start gap-2">
            <input v-model="contractMode" type="radio" value="none" class="mt-1" />
            <span>{{ t('employees.rehire.contract.none') }}</span>
          </label>
          <label class="flex items-start gap-2">
            <input v-model="contractMode" type="radio" value="custom" class="mt-1" />
            <span>{{ t('employees.rehire.contract.custom') }}</span>
          </label>
        </div>

        <div v-if="contractMode === 'custom'" class="mt-3 grid gap-4 sm:grid-cols-3">
          <FormField
            v-slot="field"
            :label="t('contracts.fields.weeklyHours')"
            :hint="t('contracts.register.weeklyHint')"
            :errors="weeklyInvalid ? [t('contracts.register.weeklyInvalid')] : []"
            required
          >
            <input
              :id="field.id"
              v-model="weeklyHours"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              :class="inputClass"
              :aria-invalid="field.invalid ? 'true' : undefined"
              :aria-describedby="field.describedBy"
            />
          </FormField>
          <FormField
            v-slot="field"
            :label="t('contracts.fields.annualHours')"
            :hint="t('contracts.register.annualHint')"
            :errors="annualInvalid ? [t('contracts.register.annualInvalid')] : []"
          >
            <input
              :id="field.id"
              v-model="annualHours"
              type="text"
              inputmode="decimal"
              autocomplete="off"
              :class="inputClass"
              :aria-invalid="field.invalid ? 'true' : undefined"
              :aria-describedby="field.describedBy"
            />
          </FormField>
          <FormField v-slot="field" :label="t('contracts.fields.scheduleType')" required>
            <select :id="field.id" v-model="scheduleType" :class="inputClass">
              <option value="">{{ t('contracts.register.schedulePlaceholder') }}</option>
              <option v-for="option of SCHEDULE_TYPES" :key="option" :value="option">
                {{ t(`contracts.schedule.${option}`) }}
              </option>
            </select>
          </FormField>
        </div>
      </fieldset>

      <div class="sm:col-span-2">
        <label class="flex items-start gap-2">
          <input v-model="resetPin" type="checkbox" class="mt-1" data-test="rehire-reset-pin" />
          <span>
            <span class="font-medium">{{ t('employees.rehire.pin.resetLabel') }}</span>
            <span class="block text-sm text-kq-text-muted">
              {{ t('employees.rehire.pin.resetHint') }}
            </span>
          </span>
        </label>
      </div>
    </div>

    <ChangePreview class="mt-4" :changes="changes" :caption="t('employees.rehire.heading')" />

    <ul class="mt-4 list-disc pl-5 text-kq-text-muted">
      <li>{{ t('employees.rehire.consequenceCard') }}</li>
      <li>{{ t('employees.rehire.consequencePortal') }}</li>
      <li>{{ t('employees.rehire.consequenceHistory') }}</li>
    </ul>
  </ConfirmDialog>
</template>
