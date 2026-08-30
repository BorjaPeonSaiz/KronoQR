<script setup lang="ts">
// Detalle de jornada de una persona (RF-PA-03).
//
// Es la primera pantalla desde la que alguien con responsabilidad de gestion ve
// el registro horario de OTRA persona. Eso decide casi todo lo que hay aqui:
//
//  - **Solo lee.** Ninguna accion de esta pantalla cambia el registro. Corregir
//    un tramo es otro endpoint y otro ambito de token.
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
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useQuery } from '@tanstack/vue-query'
import { getEmployee } from '@/features/employees/employees.api'
import WorkDayCard from './WorkDayCard.vue'
import { useEmployeeWorkDays } from './useEmployeeWorkDays'
import type { WorkDateRange } from './workdays.api'
import { UNBOUNDED_RANGE } from './workdays.api'

const props = defineProps<{ uuid: string }>()

const { t } = useI18n()

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

    <header class="mt-4">
      <h1 class="text-2xl font-bold">{{ t('workdays.title') }}</h1>
      <p class="mt-1 text-lg" data-test="person">
        {{ personLabel }}
        <span v-if="employee !== undefined" class="font-mono text-kq-text-muted">
          {{ employee.employee_code }}
        </span>
      </p>
      <p class="mt-2 max-w-prose text-kq-text-muted">{{ t('workdays.subtitle') }}</p>
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
      <WorkDayCard v-for="day of days" :key="day.work_date" :day="day" :employee-uuid="uuid" />
    </div>
  </section>
</template>
