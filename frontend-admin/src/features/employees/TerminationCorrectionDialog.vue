<script setup lang="ts">
// Correccion auditada de la fecha de cese de un periodo con baja efectiva
// (RN-27, RL-04, ADR-062 §7).
//
// Es el camino cuando RRHH puso un cese anterior al ultimo dia trabajado de
// verdad, o posterior al real. Es un acto serio: el dialogo enseña desde que
// fecha y hacia cual, pide un motivo de 10 a 1 000 caracteres y dice que NO se
// borra nada (la fecha anterior queda guardada con quien, cuando y por que).
//
// Limites que el servidor aplica y esta pantalla anticipa: no posterior a hoy
// (fecha civil del centro), no anterior al alta del periodo y anterior al alta
// del periodo siguiente si lo hay. Como la baja, la correccion no mira el ultimo
// tramo ni las ausencias: adelantar el cese no borra nada, y la pantalla avisa
// de que lo posterior queda fuera del periodo y hay que revisarlo.
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate, todayInZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { EmploymentPeriod, EmploymentPeriodCorrection } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { addCivilDays } from './employmentDates'
import { REASON_MAX_LENGTH, REASON_MIN_LENGTH } from './employmentLimits'
import EmploymentProblemNotice from './EmploymentProblemNotice.vue'
import { type EmploymentProblemKey, staleProblemKey } from './employmentProblems'
import { correctTerminationDate } from './employees.api'

const props = defineProps<{
  employeeUuid: string
  period: EmploymentPeriod
  /** El periodo posterior (una readmision), si lo hay: su alta es el techo de la fecha. */
  nextPeriod: EmploymentPeriod | undefined
  timezone: string | undefined
}>()

const emit = defineEmits<{
  close: []
  done: [EmploymentPeriodCorrection]
  stale: [EmploymentProblemKey]
}>()

const { t, locale } = useI18n()

const previous = props.period.terminated_at ?? ''
const today = props.timezone === undefined ? '' : todayInZone(props.timezone)

/** La fecha mas tardia admitida: hoy, o el dia antes del alta del periodo siguiente. */
const maxDate = computed(() => {
  const beforeNext =
    props.nextPeriod === undefined ? '' : addCivilDays(props.nextPeriod.hired_at, -1)

  if (today === '') {
    return beforeNext
  }

  return beforeNext !== '' && beforeNext < today ? beforeNext : today
})

const terminatedAt = ref(previous)
const reason = ref('')
const busy = ref(false)
const error = ref<unknown>(null)
const dateInput = ref<HTMLInputElement | null>(null)

function date(value: string): string {
  return value === '' ? t('common.empty') : formatCivilDate(value, locale.value)
}

const dateErrors = computed<readonly string[]>(() => {
  const serverErrors = isApiError(error.value)
    ? (error.value.fieldErrors['terminated_at'] ?? [])
    : []

  if (terminatedAt.value === '') {
    return serverErrors
  }

  if (terminatedAt.value < props.period.hired_at) {
    return [t('employees.correction.beforeHire', { date: date(props.period.hired_at) })]
  }

  if (maxDate.value !== '' && terminatedAt.value > maxDate.value) {
    return [
      props.nextPeriod !== undefined && maxDate.value !== today
        ? t('employees.correction.afterNext', { date: date(maxDate.value) })
        : t('employees.correction.afterToday', { date: date(maxDate.value) }),
    ]
  }

  return serverErrors
})

const reasonLength = computed(() => reason.value.trim().length)
const reasonErrors = computed<readonly string[]>(() => {
  const serverErrors = isApiError(error.value) ? (error.value.fieldErrors['reason'] ?? []) : []

  if (reason.value !== '' && reasonLength.value < REASON_MIN_LENGTH) {
    return [t('employees.correction.reasonTooShort', { min: REASON_MIN_LENGTH })]
  }

  if (reasonLength.value > REASON_MAX_LENGTH) {
    return [t('employees.correction.reasonTooLong', { max: REASON_MAX_LENGTH })]
  }

  return serverErrors
})

const direction = computed<'earlier' | 'later' | 'same'>(() =>
  terminatedAt.value === previous ? 'same' : terminatedAt.value < previous ? 'earlier' : 'later',
)

/** Un 422 con mensajes por campo se pinta junto a su campo; el resto, en el aviso del dialogo. */
const visibleError = computed<unknown>(() =>
  isApiError(error.value) &&
  error.value.status === 422 &&
  (error.value.fieldErrors['terminated_at'] !== undefined ||
    error.value.fieldErrors['reason'] !== undefined)
    ? null
    : error.value,
)

const confirmDisabled = computed(
  () =>
    terminatedAt.value === '' ||
    direction.value === 'same' ||
    dateErrors.value.length > 0 ||
    reasonLength.value < REASON_MIN_LENGTH ||
    reasonLength.value > REASON_MAX_LENGTH,
)

const changes = computed<Change[]>(() => [
  {
    label: t('employees.fields.terminatedAt'),
    from: date(previous),
    to: date(terminatedAt.value),
  },
  {
    label: t('employees.correction.reasonLabel'),
    from: t('common.empty'),
    to: reasonLength.value === 0 ? t('common.empty') : reason.value.trim(),
  },
])

async function confirm(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    emit(
      'done',
      await correctTerminationDate(props.employeeUuid, props.period.uuid, {
        terminated_at: terminatedAt.value,
        reason: reason.value.trim(),
      }),
    )
  } catch (caught) {
    error.value = caught

    if (isApiError(caught) && caught.status === 422) {
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

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <ConfirmDialog
    :title="t('employees.correction.heading')"
    :confirm-label="t('employees.correction.confirmAction')"
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

    <p class="mb-4 max-w-prose">{{ t('employees.correction.explanation') }}</p>

    <div class="grid gap-4">
      <FormField
        v-slot="field"
        :label="t('employees.fields.terminatedAt')"
        :hint="
          t('employees.correction.dateHint', {
            from: date(period.hired_at),
            to: maxDate === '' ? t('employees.correction.today') : date(maxDate),
          })
        "
        :errors="dateErrors"
        required
      >
        <input
          :id="field.id"
          ref="dateInput"
          v-model="terminatedAt"
          type="date"
          required
          :min="period.hired_at"
          :max="maxDate === '' ? undefined : maxDate"
          :class="inputClass"
          :aria-invalid="field.invalid ? 'true' : undefined"
          :aria-describedby="field.describedBy"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.correction.reasonLabel')"
        :hint="
          t('employees.correction.reasonHint', { min: REASON_MIN_LENGTH, max: REASON_MAX_LENGTH })
        "
        :errors="reasonErrors"
        required
      >
        <textarea
          :id="field.id"
          v-model="reason"
          rows="3"
          :class="inputClass"
          :aria-invalid="field.invalid ? 'true' : undefined"
          :aria-describedby="field.describedBy"
        />
        <p class="text-sm text-kq-text-muted" data-test="reason-counter">
          {{
            t('employees.correction.reasonCounter', { chars: reasonLength, max: REASON_MAX_LENGTH })
          }}
        </p>
      </FormField>
    </div>

    <ChangePreview class="mt-4" :changes="changes" :caption="t('employees.correction.heading')" />

    <p
      v-if="direction === 'earlier'"
      role="status"
      data-test="correction-earlier"
      class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      {{ t('employees.correction.earlierNotice') }}
    </p>
    <p
      v-else-if="direction === 'later'"
      role="status"
      data-test="correction-later"
      class="mt-4 rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt p-3 text-kq-text"
    >
      {{ t('employees.correction.laterNotice') }}
    </p>

    <ul class="mt-4 list-disc pl-5 text-kq-text-muted">
      <li>{{ t('employees.correction.consequenceKept') }}</li>
      <li>{{ t('employees.correction.consequenceCards') }}</li>
      <li>{{ t('employees.correction.consequenceRetention') }}</li>
      <li v-if="nextPeriod !== undefined" data-test="correction-contract-note">
        {{ t('employees.correction.consequenceContract') }}
      </li>
    </ul>
  </ConfirmDialog>
</template>
