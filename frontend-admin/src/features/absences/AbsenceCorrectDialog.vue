<script setup lang="ts">
// Corregir una ausencia (RF-GP-04, RN-13).
//
// UNA CORRECCION NO SOBRESCRIBE: crea la version siguiente y la anterior se
// conserva, con autor -implicito en la sesion-, momento y motivo (regla dura
// 5). Por eso este dialogo, antes de dejar confirmar, enseña QUE va a cambiar,
// DESDE que valor y HACIA cual (doc 03 §4.3): el mismo `ChangePreview` que usa
// `CorrectionDialog.vue` del registro horario.
//
// EL MOTIVO ES TEXTO LIBRE DE 3 A 500 CARACTERES (decision 4 de la ficha): a
// diferencia de las correcciones del registro horario, aqui no hay un
// catalogo de nueve causas — no hay tantas razones tipificadas que defender
// ante una inspeccion para corregir unas fechas de vacaciones.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import { correctAbsence } from './absences.api'
import type { Absence, AbsenceType, CorrectAbsenceRequest } from '@/shared/api/types'

const REASON_MIN_LENGTH = 3
const REASON_MAX_LENGTH = 500
const NOTE_MAX_LENGTH = 500
const ABSENCE_TYPES: readonly AbsenceType[] = ['vacation', 'sick_leave', 'leave', 'other']

const props = defineProps<{ absence: Absence }>()
const emit = defineEmits<{ success: [Absence]; cancel: [] }>()

const { t, locale } = useI18n()

const type = ref<AbsenceType>(props.absence.type)
const startsOn = ref(props.absence.starts_on)
const endsOn = ref(props.absence.ends_on)
const note = ref(props.absence.note ?? '')
const reason = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
const conflict = ref(false)

const datesCoherent = computed(() => startsOn.value !== '' && endsOn.value >= startsOn.value)
const reasonValue = computed(() => reason.value.trim())
const reasonValid = computed(
  () =>
    reasonValue.value.length >= REASON_MIN_LENGTH && reasonValue.value.length <= REASON_MAX_LENGTH,
)

interface PendingCorrection {
  body: CorrectAbsenceRequest
  changes: Change[]
}

const originalNote = props.absence.note ?? ''

const pending = computed<PendingCorrection>(() => {
  const body: CorrectAbsenceRequest = { reason: reasonValue.value }
  const changes: Change[] = []

  if (type.value !== props.absence.type) {
    body.type = type.value
    changes.push({
      label: t('absences.fields.type'),
      from: t(`absences.types.${props.absence.type}`),
      to: t(`absences.types.${type.value}`),
    })
  }

  if (startsOn.value !== props.absence.starts_on) {
    body.starts_on = startsOn.value
    changes.push({
      label: t('absences.fields.startsOn'),
      from: formatCivilDate(props.absence.starts_on, locale.value),
      to: startsOn.value === '' ? '' : formatCivilDate(startsOn.value, locale.value),
    })
  }

  if (endsOn.value !== props.absence.ends_on) {
    body.ends_on = endsOn.value
    changes.push({
      label: t('absences.fields.endsOn'),
      from: formatCivilDate(props.absence.ends_on, locale.value),
      to: endsOn.value === '' ? '' : formatCivilDate(endsOn.value, locale.value),
    })
  }

  if (note.value.trim() !== originalNote) {
    body.note = note.value.trim() === '' ? null : note.value.trim()
    changes.push({
      label: t('absences.fields.note'),
      from: originalNote === '' ? t('common.empty') : originalNote,
      to: note.value.trim() === '' ? t('common.empty') : note.value.trim(),
    })
  }

  return { body, changes }
})

const canSubmit = computed(
  () =>
    pending.value.changes.length > 0 &&
    datesCoherent.value &&
    reasonValid.value &&
    !submitting.value,
)

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null
  conflict.value = false

  try {
    emit('success', await correctAbsence(props.absence.uuid, pending.value.body))
  } catch (caught) {
    if (isApiError(caught) && caught.status === 409) {
      conflict.value = true
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
  <BaseDialog :title="t('absences.correct.heading')" size="wide" @close="emit('cancel')">
    <p class="text-kq-text-muted" data-test="dialog-context">
      {{
        t('absences.dialog.contextEmployee', {
          name: absence.employee_name,
          code: absence.employee_code,
        })
      }}
    </p>

    <div
      v-if="conflict"
      role="alert"
      class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4"
      data-test="dialog-conflict"
    >
      <p class="font-semibold text-kq-warning">{{ t('absences.correct.conflict') }}</p>
    </div>

    <form
      id="absence-correct-form"
      class="mt-4 flex flex-col gap-4"
      novalidate
      @submit.prevent="submit"
    >
      <ErrorNotice v-if="error !== null" :error="error" data-test="dialog-error" />

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField v-slot="field" :label="t('absences.fields.type')" required>
          <select :id="field.id" v-model="type" :class="inputClass" data-test="correct-type">
            <option v-for="option of ABSENCE_TYPES" :key="option" :value="option">
              {{ t(`absences.types.${option}`) }}
            </option>
          </select>
        </FormField>

        <div />

        <FormField v-slot="field" :label="t('absences.fields.startsOn')" required>
          <input
            :id="field.id"
            v-model="startsOn"
            type="date"
            required
            :class="inputClass"
            data-test="correct-starts-on"
          />
        </FormField>

        <FormField v-slot="field" :label="t('absences.fields.endsOn')" required>
          <input
            :id="field.id"
            v-model="endsOn"
            type="date"
            required
            :class="inputClass"
            data-test="correct-ends-on"
          />
        </FormField>
      </div>

      <FormField v-slot="field" :label="t('absences.fields.note')">
        <textarea
          :id="field.id"
          v-model="note"
          rows="3"
          :maxlength="NOTE_MAX_LENGTH"
          :class="inputClass"
          data-test="correct-note"
        />
      </FormField>

      <ChangePreview
        v-if="pending.changes.length > 0"
        :changes="pending.changes"
        :caption="t('absences.correct.previewCaption')"
        :from-label="t('workdays.history.before')"
        :to-label="t('workdays.history.after')"
        data-test="dialog-preview"
      />
      <p v-else class="text-sm text-kq-text-muted" data-test="dialog-no-change">
        {{ t('absences.correct.noChange') }}
      </p>

      <FormField
        v-slot="field"
        :label="t('absences.correct.reasonLabel')"
        :hint="t('absences.correct.reasonHint')"
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
          data-test="correct-reason"
        />
      </FormField>
    </form>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('cancel')"
      >
        {{ t('common.cancel') }}
      </button>
      <button
        type="submit"
        form="absence-correct-form"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        data-test="dialog-submit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
      >
        {{ submitting ? t('common.saving') : t('absences.correct.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
