<script setup lang="ts">
// Anular una ausencia (RF-GP-04, RN-13).
//
// ANULAR ES UN HECHO Y NO UNA VERSION (decision 4 de la ficha): la ausencia
// pasa a `voided` con autor, momento y motivo, y no hay «version posterior» de
// un hecho que no ocurrio -al contrario que corregir, que si crea una
// version nueva. Por eso este dialogo no es un formulario: es un resumen de
// LO QUE se va a anular y el motivo, antes de confirmar.
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Absence } from '@/shared/api/types'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { voidAbsence } from './absences.api'

const REASON_MIN_LENGTH = 3
const REASON_MAX_LENGTH = 500

const props = defineProps<{ absence: Absence }>()
const emit = defineEmits<{ success: [Absence]; cancel: [] }>()

const { t, locale } = useI18n()

const reason = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)

const reasonValue = computed(() => reason.value.trim())
const reasonValid = computed(
  () =>
    reasonValue.value.length >= REASON_MIN_LENGTH && reasonValue.value.length <= REASON_MAX_LENGTH,
)

async function confirm(): Promise<void> {
  if (!reasonValid.value) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    emit('success', await voidAbsence(props.absence.uuid, { reason: reasonValue.value }))
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
  <ConfirmDialog
    :title="t('absences.void.heading')"
    :confirm-label="t('absences.void.action')"
    tone="danger"
    size="wide"
    :busy="submitting"
    :error="error"
    :confirm-disabled="!reasonValid"
    @cancel="emit('cancel')"
    @confirm="confirm"
  >
    <p class="mb-4">
      {{
        t('absences.void.explanation', {
          name: absence.employee_name,
          type: t(`absences.types.${absence.type}`),
          from: formatCivilDate(absence.starts_on, locale),
          to: formatCivilDate(absence.ends_on, locale),
        })
      }}
    </p>

    <dl
      class="mb-4 grid gap-2 rounded-kq border border-kq-border bg-kq-surface-alt p-3 sm:grid-cols-2"
    >
      <div>
        <dt class="font-medium text-kq-text-muted">{{ t('absences.table.person') }}</dt>
        <dd>{{ absence.employee_name }} ({{ absence.employee_code }})</dd>
      </div>
      <div>
        <dt class="font-medium text-kq-text-muted">{{ t('absences.table.type') }}</dt>
        <dd>{{ t(`absences.types.${absence.type}`) }}</dd>
      </div>
      <div>
        <dt class="font-medium text-kq-text-muted">{{ t('absences.table.from') }}</dt>
        <dd>{{ formatCivilDate(absence.starts_on, locale) }}</dd>
      </div>
      <div>
        <dt class="font-medium text-kq-text-muted">{{ t('absences.table.to') }}</dt>
        <dd>{{ formatCivilDate(absence.ends_on, locale) }}</dd>
      </div>
    </dl>

    <FormField
      v-slot="field"
      :label="t('absences.void.reasonLabel')"
      :hint="t('absences.void.reasonHint')"
      required
    >
      <textarea
        :id="field.id"
        v-model="reason"
        rows="3"
        minlength="3"
        maxlength="500"
        required
        :class="inputClass"
        :aria-describedby="field.describedBy"
        data-test="void-reason"
      />
    </FormField>

    <p class="mt-4 text-sm text-kq-text-muted">{{ t('absences.void.notice') }}</p>
  </ConfirmDialog>
</template>
