<script setup lang="ts">
// Detalle de jornada de una persona (RF-PA-03).
//
// Es la primera pantalla desde la que alguien con responsabilidad de gestion ve
// el registro horario de OTRA persona. Eso decide casi todo lo que hay aqui:
//
//  - **La lectura es de todo el mundo con `attendance:read`; corregir, no.**
//    Añadir un tramo, rectificarlo o anularlo (RF-PA-04) exige el ambito
//    `attendance:correct`, que esta pantalla comprueba aparte
//    (`canCorrect`/`CorrectionDialog.vue`) y que un `auditor` con solo
//    `attendance:read` no lleva: sigue pudiendo leer el registro sin que se le
//    ofrezca nada que lo cambie.
//  - **Se dice que el acceso queda auditado**, porque es verdad: el servidor
//    escribe en `audit_log` quien miro, de quien y que rango (RS-05). Quien lo
//    hace tiene derecho a saberlo antes, no a enterarse despues.
//  - **Solo el nombre y el codigo de la persona.** Ni correo, ni estado del PIN,
//    ni fecha de alta: nada de eso hace falta para leer unas horas
//    (minimizacion). El nombre si: corregir la nomina de quien no era empieza
//    por no saber a quien se esta mirando.
//  - **El rango lo resuelve el servidor cuando no se pide.** Calcular aqui «los
//    ultimos 31 dias» usaria el reloj y la zona del navegador, y el dia de hoy
//    de un centro no lo decide el ordenador de quien mira (regla dura 3).
//
// **«Añadir un tramo» vive en la cabecera y no en cada jornada** porque el
// caso que mas importa —un dia entero sin ningun fichaje, ni tramo ni
// correccion previa— no tiene tarjeta que lo represente: el contrato solo
// devuelve «jornadas con actividad registrada» (`EmployeeWorkDays.data`), asi
// que ese dia no aparece en la lista de abajo. La jornada se declara a mano en
// el propio dialogo (RN-05, ADR-024), no se deduce de una tarjeta que no
// existe.
//
// Volumen: el rango acota el resultado —el contrato lo limita a 366 jornadas— y
// el filtro es del servidor, asi que en el DOM hay como mucho un año de dias.
// No hace falta virtualizar; lo que si hace falta es la cache de consultas, que
// evita repetir la peticion al volver de la ficha.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { exceedsMaxRange, isInvertedRange, MAX_RANGE_DAYS } from '@kronoqr/web-kit/dateRange'
import { FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { ATTENDANCE_CORRECT } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { getEmployee } from '@/features/employees/employees.api'
import type { CorrectedShiftEntry, WorkDayShiftEntry } from '@/shared/api/types'
import CorrectionDialog from './CorrectionDialog.vue'
import WorkDayCard from './WorkDayCard.vue'
import { useEmployeeWorkDays, WORKDAYS_QUERY_KEY } from './useEmployeeWorkDays'
import type { WorkDateRange } from './workdays.api'
import { UNBOUNDED_RANGE } from './workdays.api'

const props = defineProps<{ uuid: string }>()

const { t } = useI18n()
const session = useSessionStore()
const queryClient = useQueryClient()

const canCorrect = computed(() => session.can(ATTENDANCE_CORRECT))

/** Lo que hay abierto: nada, o el dialogo de una de las tres operaciones de RF-PA-04. */
type DialogState =
  { mode: 'add' } | { mode: 'correct' | 'void'; entry: WorkDayShiftEntry; workDate: string } | null

const dialog = ref<DialogState>(null)

/** `undefined` en 'add': todavia no hay jornada, la declara quien rellena el formulario. */
const dialogWorkDate = computed<string | undefined>(() => {
  const state = dialog.value

  return state === null || state.mode === 'add' ? undefined : state.workDate
})

/** `undefined` en 'add': todavia no hay ningun tramo sobre el que actuar. */
const dialogEntry = computed<WorkDayShiftEntry | undefined>(() => {
  const state = dialog.value

  return state === null || state.mode === 'add' ? undefined : state.entry
})

function openAdd(): void {
  dialog.value = { mode: 'add' }
}

function openCorrect(entry: WorkDayShiftEntry, workDate: string): void {
  dialog.value = { mode: 'correct', entry, workDate }
}

function openVoid(entry: WorkDayShiftEntry, workDate: string): void {
  dialog.value = { mode: 'void', entry, workDate }
}

function closeDialog(): void {
  dialog.value = null
}

/** Recarga la jornada: tras un exito, y tambien tras un 409, para que la version vigente se vea en cuanto se cierre el aviso. */
async function reloadWorkDays(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: [WORKDAYS_QUERY_KEY, props.uuid] })
}

async function onCorrectionSuccess(result: CorrectedShiftEntry): Promise<void> {
  // Las cuatro claves de `corrections.action.*` son exactamente los cuatro
  // valores de `CorrectionAction` (RF-PA-04): la respuesta ya dice que paso.
  announce(t(`corrections.action.${result.action}`))
  closeDialog()
  await reloadWorkDays()
}

async function onDialogStale(): Promise<void> {
  // Un 409: alguien corrigio o anulo este tramo mientras el dialogo estaba
  // abierto. Se recarga en el acto para que, en cuanto se cierre el aviso, la
  // jornada ya enseñe la version vigente (el dialogo se queda abierto con el
  // aviso hasta que la persona lo cierra).
  await reloadWorkDays()
}

/** Lo que hay escrito en el formulario. */
const draft = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })
/** Lo que se ha pedido de verdad. Cambia al enviar, no al teclear. */
const applied = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })

const inverted = computed(() => isInvertedRange(draft.value))
const tooWide = computed(() => exceedsMaxRange(draft.value))
const canSubmit = computed(() => !inverted.value && !tooWide.value)

/** Lo que le pasa al rango, dicho en el propio campo que hay que arreglar. */
const rangeErrors = computed<string[]>(() => {
  if (inverted.value) {
    return [t('workdays.filters.inverted')]
  }

  return tooWide.value ? [t('workdays.filters.tooWide', { days: MAX_RANGE_DAYS })] : []
})

const { data, error, isPending, isFetching } = useEmployeeWorkDays(() => props.uuid, applied)

/**
 * La ficha, solo para poner un nombre en la cabecera. Es una consulta aparte y
 * puede fallar sin llevarse la pantalla por delante: un rol de solo lectura
 * puede tener acceso al registro horario y no a la ficha, y entonces se enseña
 * el identificador, que es lo que hay.
 */
const { data: employee } = useQuery({
  queryKey: computed(() => ['employee', props.uuid] as const),
  queryFn: () => getEmployee(props.uuid),
  retry: false,
})

const personLabel = computed(() =>
  employee.value === undefined
    ? props.uuid
    : `${employee.value.first_name} ${employee.value.last_name}`,
)

const days = computed(() => data.value?.data ?? [])

function submit(): void {
  if (canSubmit.value) {
    applied.value = { ...draft.value }
  }
}

// Cuando el servidor resuelve el rango por omision, el formulario se rellena con
// el que de verdad se ha consultado. Dejar los campos en blanco enseñando datos
// de un mes concreto seria dejar a quien mira sin saber que periodo esta viendo.
watch(data, (value) => {
  if (value === undefined) {
    return
  }

  if (draft.value.from === '' && draft.value.to === '') {
    draft.value = { from: value.from, to: value.to }
  }

  announce(
    t('workdays.announce.results', {
      count: value.data.length,
      from: value.from,
      to: value.to,
    }),
  )
})
</script>

<template>
  <section>
    <RouterLink
      :to="{ name: 'employee', params: { uuid } }"
      class="text-kq-primary-strong underline"
    >
      {{ t('workdays.backToEmployee') }}
    </RouterLink>

    <header class="mt-4 flex flex-wrap items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('workdays.title') }}</h1>
        <p class="mt-1 text-lg" data-test="person">
          {{ personLabel }}
          <span v-if="employee !== undefined" class="font-mono text-kq-text-muted">
            {{ employee.employee_code }}
          </span>
        </p>
        <p class="mt-2 max-w-prose text-kq-text-muted">{{ t('workdays.subtitle') }}</p>
      </div>

      <button
        v-if="canCorrect && data !== undefined"
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        data-test="add-shift-entry"
        @click="openAdd"
      >
        {{ t('corrections.actions.create') }}
      </button>
    </header>

    <form class="mt-4 flex max-w-3xl flex-wrap items-end gap-4" novalidate @submit.prevent="submit">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('workdays.filters.legend') }}</legend>

        <FormField
          v-slot="field"
          :label="t('workdays.filters.from')"
          :hint="t('workdays.filters.fromHint')"
        >
          <input
            :id="field.id"
            v-model="draft.from"
            type="date"
            :aria-describedby="field.describedBy"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('workdays.filters.to')"
          :hint="t('workdays.filters.toHint')"
          :errors="rangeErrors"
        >
          <input
            :id="field.id"
            v-model="draft.to"
            type="date"
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </FormField>
      </fieldset>

      <button
        type="submit"
        :disabled="!canSubmit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-50"
      >
        {{ t('workdays.filters.apply') }}
      </button>
    </form>

    <p v-if="data !== undefined" class="mt-3 text-kq-text-muted" data-test="resolved-range">
      {{ t('workdays.filters.resolved', { from: data.from, to: data.to, zone: data.time_zone }) }}
      <span v-if="isFetching" class="text-kq-text-muted">{{ t('common.updating') }}</span>
    </p>
    <p class="mt-1 text-sm text-kq-text-muted">{{ t('workdays.zoneNotice') }}</p>
    <p class="mt-1 text-sm text-kq-text-muted">{{ t('workdays.auditNotice') }}</p>

    <LoadingPanel v-if="isPending" :label="t('workdays.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <EmptyState
      v-else-if="days.length === 0"
      class="mt-4"
      :title="t('workdays.empty.title')"
      :description="t('workdays.empty.description')"
    />

    <div v-else class="mt-4 flex flex-col gap-6">
      <WorkDayCard
        v-for="day of days"
        :key="day.work_date"
        :day="day"
        :employee-uuid="uuid"
        @correct="openCorrect"
        @void="openVoid"
      />
    </div>

    <CorrectionDialog
      v-if="dialog !== null"
      :mode="dialog.mode"
      :employee-uuid="uuid"
      :employee-name="personLabel"
      :time-zone="data?.time_zone ?? FALLBACK_TIMEZONE"
      :work-date="dialogWorkDate"
      :entry="dialogEntry"
      @success="onCorrectionSuccess"
      @cancel="closeDialog"
      @stale="onDialogStale"
    />
  </section>
</template>
