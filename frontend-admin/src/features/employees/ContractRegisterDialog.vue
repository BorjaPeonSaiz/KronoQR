<script setup lang="ts">
// Alta de un contrato (RF-GP-02).
//
// REGISTRAR UN CONTRATO CAMBIA LO QUE SE COMPARA EN EL INFORME: `weekly_hours` es
// la cifra contra la que se mide la jornada de una persona. Por eso el dialogo
// enseña, antes de enviar, QUE se va a registrar y DESDE que valor: las horas del
// contrato vigente y el hecho de que se cerrara el dia anterior al inicio.
//
// NO HAY FECHA DE FIN: la escribe el servidor al registrar el siguiente. Dejarla
// escribir permitiria crear huecos sin contrato que nada avisara.
//
// ANTE UN 409 el estado ya no es el que se veia (otra persona registro un
// contrato): el formulario es correcto, asi que no se reescribe; se avisa al
// padre para que relea la serie y la vista previa se actualiza sola.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  CreateEmploymentContractRequest,
  EmploymentContract,
  ScheduleType,
} from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import { registerEmploymentContract } from './contracts.api'

const props = defineProps<{
  employeeUuid: string
  /** El contrato vigente, si lo hay: el valor «desde». */
  current: EmploymentContract | null
  /** La fecha civil de hoy en la zona del centro, para preseleccionar el inicio. */
  today: string
}>()

const emit = defineEmits<{ close: []; created: [EmploymentContract]; conflict: [] }>()

const { t, locale } = useI18n()

const SCHEDULE_TYPES: readonly ScheduleType[] = ['continua', 'partida', 'turnos']
const WEEKLY_MAX = 168
const ANNUAL_MAX = 99999

const weeklyHours = ref('')
const annualHours = ref('')
const scheduleType = ref<ScheduleType | ''>('')
const validFrom = ref(props.today)

const submitting = ref(false)
const error = ref<unknown>(null)
const conflicted = ref(false)

function parsePositive(raw: string, max: number): number | null {
  const normalised = raw.trim().replace(',', '.')

  if (normalised === '') {
    return null
  }

  const value = Number(normalised)

  return Number.isFinite(value) && value > 0 && value <= max ? value : null
}

const weeklyValue = computed(() => parsePositive(weeklyHours.value, WEEKLY_MAX))
const annualValue = computed(() => parsePositive(annualHours.value, ANNUAL_MAX))
const annualInvalid = computed(() => annualHours.value.trim() !== '' && annualValue.value === null)
const weeklyInvalid = computed(() => weeklyHours.value.trim() !== '' && weeklyValue.value === null)

const canSubmit = computed(
  () =>
    weeklyValue.value !== null &&
    !annualInvalid.value &&
    scheduleType.value !== '' &&
    validFrom.value !== '' &&
    !submitting.value,
)

function formatHours(value: number | null): string {
  return value === null
    ? t('common.empty')
    : t('contracts.hoursValue', {
        hours: new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value),
      })
}

function scheduleLabel(value: ScheduleType | '' | null): string {
  return value === null || value === '' ? t('common.empty') : t(`contracts.schedule.${value}`)
}

/** Lo que cambia: desde el contrato vigente hacia el nuevo, campo a campo. */
const changes = computed<Change[]>(() => [
  {
    label: t('contracts.fields.weeklyHours'),
    from: formatHours(props.current?.weekly_hours ?? null),
    to: formatHours(weeklyValue.value),
  },
  {
    label: t('contracts.fields.annualHours'),
    from: formatHours(props.current?.annual_hours ?? null),
    to: formatHours(annualValue.value),
  },
  {
    label: t('contracts.fields.scheduleType'),
    from: scheduleLabel(props.current?.schedule_type ?? null),
    to: scheduleLabel(scheduleType.value),
  },
  {
    label: t('contracts.fields.validFrom'),
    from:
      props.current === null
        ? t('common.empty')
        : formatCivilDate(props.current.valid_from, locale.value),
    to: validFrom.value === '' ? t('common.empty') : formatCivilDate(validFrom.value, locale.value),
  },
])

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function submit(): Promise<void> {
  const weekly = weeklyValue.value
  const schedule = scheduleType.value

  if (weekly === null || schedule === '' || !canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null
  conflicted.value = false

  const body: CreateEmploymentContractRequest = {
    weekly_hours: weekly,
    annual_hours: annualValue.value,
    schedule_type: schedule,
    valid_from: validFrom.value,
  }

  try {
    emit('created', await registerEmploymentContract(props.employeeUuid, body))
  } catch (caught) {
    if (isApiError(caught) && caught.kind === 'conflict') {
      conflicted.value = true
      emit('conflict')
    } else {
      error.value = caught
    }
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <BaseDialog :title="t('contracts.register.heading')" size="wide" @close="emit('close')">
    <form
      id="contract-register-form"
      class="grid gap-4 sm:grid-cols-2"
      novalidate
      @submit.prevent="submit"
    >
      <p class="max-w-prose text-kq-text-muted sm:col-span-2">
        {{ t('contracts.register.explanation') }}
      </p>

      <p
        v-if="conflicted"
        role="alert"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning sm:col-span-2"
        data-test="contract-conflict"
      >
        {{ t('contracts.register.conflict') }}
      </p>
      <ErrorNotice v-else-if="error !== null" :error="error" class="sm:col-span-2" />

      <FormField
        v-slot="field"
        :label="t('contracts.fields.weeklyHours')"
        :hint="t('contracts.register.weeklyHint')"
        :errors="
          weeklyInvalid ? [t('contracts.register.weeklyInvalid')] : fieldErrors('weekly_hours')
        "
        required
      >
        <input
          :id="field.id"
          v-model="weeklyHours"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="contract-weekly-hours"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('contracts.fields.annualHours')"
        :hint="t('contracts.register.annualHint')"
        :errors="
          annualInvalid ? [t('contracts.register.annualInvalid')] : fieldErrors('annual_hours')
        "
      >
        <input
          :id="field.id"
          v-model="annualHours"
          type="text"
          inputmode="decimal"
          autocomplete="off"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="contract-annual-hours"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('contracts.fields.scheduleType')"
        :hint="t('contracts.register.scheduleHint')"
        :errors="fieldErrors('schedule_type')"
        required
      >
        <select
          :id="field.id"
          v-model="scheduleType"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="contract-schedule-type"
        >
          <option value="" disabled>{{ t('contracts.register.schedulePlaceholder') }}</option>
          <option v-for="option of SCHEDULE_TYPES" :key="option" :value="option">
            {{ t(`contracts.schedule.${option}`) }}
          </option>
        </select>
      </FormField>

      <FormField
        v-slot="field"
        :label="t('contracts.fields.validFrom')"
        :hint="
          current === null
            ? t('contracts.register.validFromHintFirst')
            : t('contracts.register.validFromHint', {
                date: formatCivilDate(current.valid_from, locale),
              })
        "
        :errors="fieldErrors('valid_from')"
        required
      >
        <input
          :id="field.id"
          v-model="validFrom"
          type="date"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          data-test="contract-valid-from"
        />
      </FormField>

      <div class="sm:col-span-2">
        <ChangePreview :changes="changes" :caption="t('contracts.register.previewCaption')" />
        <p v-if="current !== null" class="mt-3 text-sm text-kq-text-muted" data-test="closes-note">
          {{ t('contracts.register.closesPrevious') }}
        </p>
      </div>
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
        form="contract-register-form"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        data-test="contract-submit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
      >
        {{ submitting ? t('common.saving') : t('contracts.register.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
