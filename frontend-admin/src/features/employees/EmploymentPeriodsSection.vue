<script setup lang="ts">
// Historia de alta y baja de una persona (RN-27, ADR-062 §1): sus periodos de
// alta, del mas antiguo al mas reciente. Una persona readmitida tiene varios; el
// hueco entre dos no admite tramos ni ausencias, y por eso se enseña entero y no
// solo el ultimo.
//
// Lo escribe el servidor y aqui solo se pinta: la fecha de alta de un periodo no
// cambia nunca, y la de cese de una baja efectiva se corrige con motivo y asiento
// (`TerminationCorrectionDialog`). Nada se edita en la tabla. Corregir es un acto
// de RRHH y se ofrece solo a quien lleva `employees:*` (cortesia: la policy es la
// del servidor, regla dura 18).
import { announce } from '@kronoqr/web-kit/announcer'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EMPLOYEES_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { EmploymentPeriod, EmploymentPeriodCorrection } from '@/shared/api/types'
import TerminationCorrectionDialog from './TerminationCorrectionDialog.vue'
import type { EmploymentProblemKey } from './employmentProblems'

const props = defineProps<{
  employeeUuid: string
  periods: readonly EmploymentPeriod[]
  /** Zona del centro, para acotar la fecha corregida a «hoy». */
  timezone: string | undefined
  /** Una ficha minimizada no se modifica (ADR-060): se lee y nada mas. */
  minimised: boolean
}>()

const emit = defineEmits<{
  /** Se registro una correccion: quien contiene esta seccion vuelve a leer la ficha. */
  corrected: [EmploymentPeriodCorrection]
  /** El servidor dice que el periodo ya no esta como se veia. */
  stale: [EmploymentProblemKey]
}>()

const { t, locale } = useI18n()
const session = useSessionStore()

const mayCorrect = computed(() => session.can(EMPLOYEES_MANAGE) && !props.minimised)

const correcting = ref<EmploymentPeriod | null>(null)
const lastCorrection = ref<EmploymentPeriodCorrection | null>(null)

function nextPeriodOf(period: EmploymentPeriod): EmploymentPeriod | undefined {
  const index = props.periods.findIndex((candidate) => candidate.uuid === period.uuid)

  return index === -1 ? undefined : props.periods[index + 1]
}

function date(value: string | null): string {
  return value === null ? t('employees.periods.noEnd') : formatCivilDate(value, locale.value)
}

function onCorrected(correction: EmploymentPeriodCorrection): void {
  correcting.value = null
  lastCorrection.value = correction
  announce(
    t('employees.correction.announce', {
      from: formatCivilDate(correction.previous_terminated_at, locale.value),
      to: formatCivilDate(correction.terminated_at, locale.value),
    }),
  )
  emit('corrected', correction)
}

const STATE_CLASS: Record<EmploymentPeriod['state'], string> = {
  active: 'bg-kq-success-soft text-kq-success',
  scheduled: 'bg-kq-warning-soft text-kq-warning',
  terminated: 'bg-kq-danger-soft text-kq-danger',
}
</script>

<template>
  <section
    v-if="periods.length > 0"
    class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    data-test="employment-periods"
  >
    <h2 class="text-xl font-semibold">{{ t('employees.periods.heading') }}</h2>
    <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('employees.periods.explanation') }}</p>

    <p
      v-if="lastCorrection !== null"
      role="status"
      data-test="last-correction"
      class="mt-3 rounded-kq-sm border border-kq-border-strong bg-kq-surface-alt p-3 text-kq-text"
    >
      {{
        t('employees.correction.done', {
          from: formatCivilDate(lastCorrection.previous_terminated_at, locale),
          to: formatCivilDate(lastCorrection.terminated_at, locale),
        })
      }}
    </p>

    <div
      tabindex="0"
      role="region"
      :aria-label="t('employees.periods.caption')"
      class="mt-3 overflow-x-auto"
    >
      <table class="w-full border-collapse text-left">
        <caption class="sr-only">
          {{
            t('employees.periods.caption')
          }}
        </caption>
        <thead class="border-b border-kq-border bg-kq-surface-alt">
          <tr>
            <th scope="col" class="px-3 py-2">{{ t('employees.fields.hiredAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('employees.fields.terminatedAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('employees.fields.status') }}</th>
            <th v-if="mayCorrect" scope="col" class="px-3 py-2">
              <span class="sr-only">{{ t('employees.periods.actions') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="period of periods"
            :key="period.uuid"
            class="border-b border-kq-border"
            data-test="employment-period"
          >
            <th scope="row" class="px-3 py-2 font-medium">
              {{ formatCivilDate(period.hired_at, locale) }}
            </th>
            <td class="px-3 py-2">{{ date(period.terminated_at) }}</td>
            <td class="px-3 py-2">
              <span class="rounded-full px-2 py-0.5 text-sm" :class="STATE_CLASS[period.state]">
                {{ t(`employees.periods.state.${period.state}`) }}
              </span>
            </td>
            <td v-if="mayCorrect" class="px-3 py-2">
              <button
                v-if="period.state === 'terminated'"
                type="button"
                data-test="correct-termination"
                class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1 text-kq-text hover:bg-kq-surface-alt"
                @click="correcting = period"
              >
                {{ t('employees.correction.action') }}
                <span class="sr-only">
                  {{ t('employees.correction.actionFor', { date: date(period.hired_at) }) }}
                </span>
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p v-if="periods.length > 1" class="mt-3 max-w-prose text-sm text-kq-text-muted">
      {{ t('employees.periods.gapNote') }}
    </p>

    <TerminationCorrectionDialog
      v-if="correcting !== null"
      :employee-uuid="employeeUuid"
      :period="correcting"
      :next-period="nextPeriodOf(correcting)"
      :timezone="timezone"
      @close="correcting = null"
      @done="onCorrected"
      @stale="(key) => emit('stale', key)"
    />
  </section>
</template>
