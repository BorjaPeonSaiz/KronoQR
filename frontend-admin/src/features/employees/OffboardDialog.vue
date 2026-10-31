<script setup lang="ts">
// Baja de una persona, en el acto o programada (RF-GP-03, RN-14, ADR-062 §3).
//
// La fecha de cese decide que pasa, y el dialogo lo dice ANTES de confirmar:
//  - cese anterior a hoy: la baja es efectiva al confirmarla;
//  - cese de hoy o posterior: queda PROGRAMADA, la persona ficha hasta su ultimo
//    dia y una tarea aplica la baja el dia siguiente; se puede anular o cambiar
//    de fecha mientras el cese no haya pasado;
//  - «Aplicar ya», solo con la fecha de hoy: despido con efecto inmediato, o la
//    contratacion que no llego a empezar si el alta es futura.
// «Hoy» es la fecha civil del CENTRO (ADR-040), fijada al abrir. Es una ayuda: la
// barrera es el servidor.
//
// Con una baja ya programada, el mismo dialogo la CAMBIA (otra fecha) o la aplica
// ya. Anular es otro acto, con su propio dialogo (`CancelScheduledOffboardingDialog`).
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate, todayInZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Employee } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmploymentProblemNotice from './EmploymentProblemNotice.vue'
import { type EmploymentProblemKey, staleProblemKey } from './employmentProblems'
import { offboardEmployee } from './employees.api'
import { applyNowApplies, planOffboard } from './offboardPlan'

const props = defineProps<{
  employee: Employee
  /** Zona del centro; sin ella no se adivina «hoy» y el servidor resuelve. */
  timezone: string | undefined
}>()

const emit = defineEmits<{
  close: []
  /** La baja se registro (en el acto o programada). */
  done: [Employee]
  /** El servidor dice que la ficha ya no esta como se veia (p. ej. otra persona la dio de baja). */
  stale: [EmploymentProblemKey]
}>()

const { t, locale } = useI18n()

const OFFBOARD_REASONS = [
  'endOfContract',
  'resignation',
  'dismissal',
  'retirement',
  'other',
] as const

/** Hoy en el centro, fijado al abrir el dialogo. Vacio mientras no se conoce la zona. */
const today = props.timezone === undefined ? '' : todayInZone(props.timezone)

const rescheduling = props.employee.offboarding?.state === 'scheduled'
const hireIsFuture = today !== '' && props.employee.hired_at > today

const terminatedAt = ref(
  rescheduling
    ? (props.employee.terminated_at ?? today)
    : hireIsFuture
      ? props.employee.hired_at
      : today,
)
const applyNow = ref(false)
const reasonKey = ref('')
const reasonText = ref('')

const busy = ref(false)
const error = ref<unknown>(null)
/** Errores de `terminated_at` que escribio el servidor (422), ya en el idioma de la persona. */
const serverDateErrors = ref<readonly string[]>([])
const dateInput = ref<HTMLInputElement | null>(null)

const applyNowAvailable = computed(() =>
  applyNowApplies({ terminatedAt: terminatedAt.value, hiredAt: props.employee.hired_at, today }),
)

/** «Aplicar ya» solo cuenta si la fecha lo admite; si se cambia la fecha, no se arrastra. */
const effectiveApplyNow = computed(() => applyNowAvailable.value && applyNow.value)

const outcome = computed(() =>
  planOffboard({
    terminatedAt: terminatedAt.value,
    hiredAt: props.employee.hired_at,
    today,
    applyNow: effectiveApplyNow.value,
  }),
)

function date(value: string): string {
  return value === '' ? t('common.empty') : formatCivilDate(value, locale.value)
}

const dateErrors = computed<readonly string[]>(() => [
  ...(outcome.value === 'beforeHire'
    ? [t('employees.offboard.beforeHire', { date: date(props.employee.hired_at) })]
    : []),
  ...serverDateErrors.value,
])

const reason = computed(() =>
  reasonKey.value === 'other'
    ? reasonText.value.trim()
    : reasonKey.value === ''
      ? ''
      : t(`employees.offboard.reasons.${reasonKey.value}`),
)

/** Cambiar una baja programada a la misma fecha, sin «Aplicar ya», no hace nada: no se ofrece. */
const unchanged = computed(
  () =>
    rescheduling && terminatedAt.value === props.employee.terminated_at && !effectiveApplyNow.value,
)

const confirmDisabled = computed(
  () =>
    terminatedAt.value === '' ||
    dateErrors.value.length > 0 ||
    reason.value === '' ||
    unchanged.value,
)

const confirmLabel = computed(() =>
  outcome.value === 'scheduled'
    ? t(
        rescheduling
          ? 'employees.offboard.confirmReschedule'
          : 'employees.offboard.confirmSchedule',
      )
    : t('employees.offboard.confirmAction'),
)

const title = computed(() =>
  t(rescheduling ? 'employees.offboard.rescheduleHeading' : 'employees.offboard.heading'),
)

const statusAfter = computed(() =>
  outcome.value === 'scheduled'
    ? t('employees.status.scheduled')
    : t('employees.status.terminated'),
)

const changes = computed<Change[]>(() => [
  {
    label: t('employees.fields.status'),
    from: rescheduling
      ? t('employees.status.scheduled')
      : t(`employees.status.${props.employee.status}`),
    to: statusAfter.value,
  },
  {
    label: t('employees.fields.terminatedAt'),
    from: rescheduling ? date(props.employee.terminated_at ?? '') : t('common.empty'),
    to: date(terminatedAt.value),
  },
  {
    label: t('employees.offboard.reasonLabel'),
    from: t('common.empty'),
    to: reason.value === '' ? t('common.empty') : reason.value,
  },
])

async function confirm(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const updated = await offboardEmployee(props.employee.uuid, {
      terminated_at: terminatedAt.value,
      reason: reason.value,
      // «Aplicar ya» solo viaja cuando el usuario lo ha pedido Y la fecha lo admite:
      // el servidor responde 422 a `apply_now` con un cese posterior a hoy.
      ...(effectiveApplyNow.value ? { apply_now: true } : {}),
    })

    emit('done', updated)
  } catch (caught) {
    const fieldErrors = isApiError(caught) ? (caught.fieldErrors['terminated_at'] ?? []) : []

    if (isApiError(caught) && caught.status === 422 && fieldErrors.length > 0) {
      // El mensaje ya viene traducido por el servidor y se pinta en su campo.
      serverDateErrors.value = fieldErrors
      dateInput.value?.focus()
    } else {
      error.value = caught

      const stale = staleProblemKey(caught)

      if (stale !== null) {
        emit('stale', stale)
      }
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
    :title="title"
    :confirm-label="confirmLabel"
    tone="danger"
    size="wide"
    :busy="busy"
    :error="error"
    :confirm-disabled="confirmDisabled"
    @cancel="emit('close')"
    @confirm="confirm"
  >
    <template #error="{ error: failure }">
      <EmploymentProblemNotice :error="failure" />
    </template>

    <p v-if="rescheduling" class="mb-4" data-test="offboard-rescheduling">
      {{ t('employees.offboard.reschedulingIntro', { date: date(employee.terminated_at ?? '') }) }}
    </p>
    <p v-else-if="hireIsFuture" class="mb-4" data-test="offboard-future-hire">
      {{ t('employees.offboard.futureHire') }}
    </p>

    <div class="grid gap-4 sm:grid-cols-2">
      <FormField
        v-slot="field"
        :label="t('employees.fields.terminatedAt')"
        :hint="t('employees.offboard.dateHint')"
        :errors="dateErrors"
        required
      >
        <input
          :id="field.id"
          ref="dateInput"
          v-model="terminatedAt"
          type="date"
          required
          :min="employee.hired_at"
          :class="inputClass"
          :aria-invalid="field.invalid ? 'true' : undefined"
          :aria-describedby="field.describedBy"
          @input="serverDateErrors = []"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.offboard.reasonLabel')"
        :hint="t('employees.offboard.reasonHint')"
        required
      >
        <select
          :id="field.id"
          v-model="reasonKey"
          :class="inputClass"
          :aria-describedby="field.describedBy"
        >
          <option value="">{{ t('employees.offboard.reasonPlaceholder') }}</option>
          <option v-for="key of OFFBOARD_REASONS" :key="key" :value="key">
            {{ t(`employees.offboard.reasons.${key}`) }}
          </option>
        </select>
      </FormField>

      <FormField
        v-if="reasonKey === 'other'"
        v-slot="field"
        class="sm:col-span-2"
        :label="t('employees.offboard.reasonOtherLabel')"
        required
      >
        <input
          :id="field.id"
          v-model="reasonText"
          type="text"
          maxlength="200"
          :class="inputClass"
        />
      </FormField>

      <!-- «Aplicar ya» solo se ofrece cuando tiene efecto (cese de hoy o alta por llegar). -->
      <div v-if="applyNowAvailable" class="sm:col-span-2" data-test="apply-now">
        <label class="flex items-start gap-2">
          <input v-model="applyNow" type="checkbox" class="mt-1" />
          <span>
            <span class="font-medium">{{ t('employees.offboard.applyNowLabel') }}</span>
            <span class="block text-sm text-kq-text-muted">
              {{
                hireIsFuture
                  ? t('employees.offboard.applyNowHintFutureHire')
                  : t('employees.offboard.applyNowHint')
              }}
            </span>
          </span>
        </label>
      </div>
    </div>

    <p
      v-if="outcome === 'scheduled'"
      role="status"
      data-test="offboard-outcome-scheduled"
      class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      {{ t('employees.offboard.outcomeScheduled', { date: date(terminatedAt) }) }}
    </p>
    <p
      v-else-if="outcome === 'immediate'"
      role="status"
      data-test="offboard-outcome-immediate"
      class="mt-4 rounded-kq-sm border border-kq-danger bg-kq-danger-soft p-3 text-kq-danger"
    >
      {{ t('employees.offboard.outcomeImmediate') }}
    </p>
    <p
      v-else-if="outcome === 'noEffect'"
      role="status"
      data-test="offboard-outcome-no-effect"
      class="mt-4 rounded-kq-sm border border-kq-danger bg-kq-danger-soft p-3 text-kq-danger"
    >
      {{ t('employees.offboard.outcomeNoEffect') }}
    </p>

    <ChangePreview class="mt-4" :changes="changes" :caption="title" />

    <ul v-if="outcome === 'scheduled'" class="mt-4 list-disc pl-5 text-kq-text-muted">
      <li>{{ t('employees.offboard.scheduledKeepsClocking', { date: date(terminatedAt) }) }}</li>
      <li>{{ t('employees.offboard.scheduledApplies') }}</li>
      <li>{{ t('employees.offboard.scheduledCancel') }}</li>
      <li>{{ t('employees.offboard.consequenceHistory') }}</li>
    </ul>
    <ul v-else class="mt-4 list-disc pl-5 text-kq-text-muted">
      <li>{{ t('employees.offboard.consequenceCredential') }}</li>
      <li>{{ t('employees.offboard.consequenceScan') }}</li>
      <li>{{ t('employees.offboard.consequenceOpenShift') }}</li>
      <li>{{ t('employees.offboard.consequenceHistory') }}</li>
    </ul>
  </ConfirmDialog>
</template>
