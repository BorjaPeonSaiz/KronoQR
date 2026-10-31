<script setup lang="ts">
// Historia de alta y baja de una persona (RN-27, ADR-062 §1): sus periodos de
// alta, del mas antiguo al mas reciente. Una persona readmitida tiene varios; el
// hueco entre dos no admite tramos ni ausencias, y por eso se enseña entero y no
// solo el ultimo.
//
// Cada periodo lleva debajo el historico de correcciones de su fecha de cese (RN-13, RL-04),
// de la mas antigua a la mas reciente: fecha anterior -> nueva, quien, cuando (en la zona del
// centro) y el motivo. Viene en la propia ficha, asi que se ve tambien tras recargar: nada se
// sobrescribe sin rastro.
//
// Lo escribe el servidor y aqui solo se pinta: la fecha de alta de un periodo no
// cambia nunca, y la de cese de una baja efectiva se corrige con motivo y asiento
// (`TerminationCorrectionDialog`). Nada se edita en la tabla. Corregir es un acto
// de RRHH y se ofrece solo a quien lleva `employees:*` (cortesia: la policy es la
// del servidor, regla dura 18).
import { announce } from '@kronoqr/web-kit/announcer'
import {
  FALLBACK_TIMEZONE,
  formatCivilDate,
  formatInstantWithZone,
} from '@kronoqr/web-kit/datetime'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EMPLOYEES_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type {
  EmploymentPeriodCorrection,
  EmploymentPeriodRecord,
  TerminationDateCorrectionRecord,
} from '@/shared/api/types'
import TerminationCorrectionDialog from './TerminationCorrectionDialog.vue'
import type { EmploymentProblemKey } from './employmentProblems'

const props = defineProps<{
  employeeUuid: string
  periods: readonly EmploymentPeriodRecord[]
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

const correcting = ref<EmploymentPeriodRecord | null>(null)

function nextPeriodOf(period: EmploymentPeriodRecord): EmploymentPeriodRecord | undefined {
  const index = props.periods.findIndex((candidate) => candidate.uuid === period.uuid)

  return index === -1 ? undefined : props.periods[index + 1]
}

function date(value: string | null): string {
  return value === null ? t('employees.periods.noEnd') : formatCivilDate(value, locale.value)
}

function onCorrected(correction: EmploymentPeriodCorrection): void {
  correcting.value = null
  announce(
    t('employees.correction.announce', {
      from: formatCivilDate(correction.previous_terminated_at, locale.value),
      to: formatCivilDate(correction.terminated_at, locale.value),
    }),
  )
  emit('corrected', correction)
}

// Un solo periodo sin baja efectiva (el caso de casi todas las fichas) repite lo que ya dicen
// «Datos» y «Baja»: una tabla de una fila con la fecha de alta y «En alta». La historia se
// enseña cuando aporta algo: hay mas de un periodo, o hay una baja efectiva que corregir.
const worthShowing = computed(
  () => props.periods.length > 1 || props.periods.some((period) => period.state === 'terminated'),
)

function moment(correction: TerminationDateCorrectionRecord): string {
  return formatInstantWithZone(
    correction.corrected_at,
    props.timezone ?? FALLBACK_TIMEZONE,
    locale.value,
  )
}

const STATE_CLASS: Record<EmploymentPeriodRecord['state'], string> = {
  active: 'bg-kq-success-soft text-kq-success',
  scheduled: 'bg-kq-warning-soft text-kq-warning',
  terminated: 'bg-kq-danger-soft text-kq-danger',
}
</script>

<template>
  <section
    v-if="worthShowing"
    class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    data-test="employment-periods"
  >
    <h2 class="text-xl font-semibold">{{ t('employees.periods.heading') }}</h2>
    <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('employees.periods.explanation') }}</p>

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
          <template v-for="period of periods" :key="period.uuid">
            <tr
              :class="period.corrections.length === 0 ? 'border-b border-kq-border' : ''"
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
            <!-- El historico de correcciones del cese de este periodo, sin borrar ninguna. -->
            <tr
              v-if="period.corrections.length > 0"
              class="border-b border-kq-border"
              data-test="termination-corrections"
            >
              <td :colspan="mayCorrect ? 4 : 3" class="px-3 pb-3">
                <p class="text-sm font-medium text-kq-text-muted">
                  {{ t('employees.periods.correctionsHeading') }}
                </p>
                <ol class="mt-1 flex flex-col gap-2">
                  <li
                    v-for="correction of period.corrections"
                    :key="correction.id"
                    data-test="termination-correction"
                  >
                    <span class="font-medium">
                      {{
                        t('employees.periods.correctionDates', {
                          from: formatCivilDate(correction.previous_terminated_at, locale),
                          to: formatCivilDate(correction.terminated_at, locale),
                        })
                      }}
                    </span>
                    <span class="text-kq-text-muted">
                      ·
                      {{
                        t('employees.periods.correctionBy', {
                          author: correction.corrected_by.name,
                          moment: moment(correction),
                        })
                      }}
                    </span>
                    <span class="block max-w-prose whitespace-pre-line break-words">
                      {{ t('employees.periods.correctionReason', { reason: correction.reason }) }}
                    </span>
                  </li>
                </ol>
              </td>
            </tr>
          </template>
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
