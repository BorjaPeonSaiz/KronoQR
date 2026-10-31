<script setup lang="ts">
// Anulacion de una baja programada (RN-14, RF-GP-03, ADR-062 §3).
//
// Solo antes de que pase la fecha de cese. La persona nunca dejo de poder fichar:
// su tarjeta, su PIN y su portal no han cambiado, asi que no hay nada que
// reactivar. Queda un asiento con la fecha anulada. Si el servidor dice que la
// fecha ya paso (`offboarding-due`), la baja rige aunque la tarea no la haya
// aplicado, y la salida es otra: readmitir o corregir el cese.
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Employee } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import EmploymentProblemNotice from './EmploymentProblemNotice.vue'
import { type EmploymentProblemKey, staleProblemKey } from './employmentProblems'
import { cancelScheduledOffboarding } from './employees.api'

const props = defineProps<{ employee: Employee }>()

const emit = defineEmits<{
  close: []
  done: [Employee]
  stale: [EmploymentProblemKey]
}>()

const { t, locale } = useI18n()

const busy = ref(false)
const error = ref<unknown>(null)

const scheduledFor = computed(() =>
  props.employee.terminated_at === null
    ? t('common.empty')
    : formatCivilDate(props.employee.terminated_at, locale.value),
)

const changes = computed<Change[]>(() => [
  {
    label: t('employees.fields.status'),
    from: t('employees.status.scheduled'),
    to: t('employees.status.active'),
  },
  {
    label: t('employees.fields.terminatedAt'),
    from: scheduledFor.value,
    to: t('common.empty'),
  },
])

async function confirm(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    emit('done', await cancelScheduledOffboarding(props.employee.uuid))
  } catch (caught) {
    error.value = caught

    const stale = staleProblemKey(caught)

    if (stale !== null) {
      emit('stale', stale)
    }
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <ConfirmDialog
    :title="t('employees.offboard.cancelHeading')"
    :confirm-label="t('employees.offboard.cancelAction')"
    :busy="busy"
    :error="error"
    @cancel="emit('close')"
    @confirm="confirm"
  >
    <template #error="{ error: failure }">
      <EmploymentProblemNotice :error="failure" />
    </template>

    <p class="mb-4">{{ t('employees.offboard.cancelExplanation', { date: scheduledFor }) }}</p>
    <ChangePreview :changes="changes" :caption="t('employees.offboard.cancelHeading')" />
    <p class="mt-4 text-kq-text-muted">{{ t('employees.offboard.cancelNothingChanged') }}</p>
  </ConfirmDialog>
</template>
