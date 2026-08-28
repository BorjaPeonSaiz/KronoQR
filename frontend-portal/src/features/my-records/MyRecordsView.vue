<script setup lang="ts">
// Mi registro de jornada (RF-ID-05, RF-ID-06, RF-ID-07, RL-05, art. 34.9 ET).
//
// La razon de ser de todo el portal esta en esta pantalla: un resumen grande y
// legible arriba -las horas de esta semana, o del periodo que se pida- y el
// detalle debajo. Nadie que entra aqui quiere un panel de control: quiere saber
// cuantas horas lleva.
//
//  - **Solo lee.** No hay ninguna accion en esta pantalla que cambie el
//    registro: corregir un tramo es cosa de RRHH, con otro token y otro ambito.
//  - **El rango lo resuelve el servidor cuando no se pide.** Calcular aqui
//    «los ultimos 31 dias» usaria el reloj y la zona del navegador, y el dia de
//    hoy en el centro no lo decide el telefono de quien mira (regla dura 3).
//  - **No queda auditado.** A diferencia de cuando alguien de gestion mira el
//    registro de un tercero (RS-05), consultar el propio no genera ningun
//    asiento: es un derecho, no un acceso que vigilar.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { exceedsMaxRange, isInvertedRange, MAX_RANGE_DAYS } from '@kronoqr/web-kit/dateRange'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '../login/session.store'
import { useMyWorkDays } from './useMyWorkDays'
import WorkDayCard from './WorkDayCard.vue'
import type { WorkDateRange } from './workdays.api'
import { UNBOUNDED_RANGE } from './workdays.api'

const { t } = useI18n()
const session = useSessionStore()

/** Lo que hay escrito en el formulario. */
const draft = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })
/** Lo que se ha pedido de verdad. Cambia al enviar, no al teclear. */
const applied = ref<WorkDateRange>({ ...UNBOUNDED_RANGE })

const inverted = computed(() => isInvertedRange(draft.value))
const tooWide = computed(() => exceedsMaxRange(draft.value))
const canSubmit = computed(() => !inverted.value && !tooWide.value)

const rangeErrors = computed<string[]>(() => {
  if (inverted.value) {
    return [t('myRecords.filters.inverted')]
  }

  return tooWide.value ? [t('myRecords.filters.tooWide', { days: MAX_RANGE_DAYS })] : []
})

const { data, error, isPending, isFetching } = useMyWorkDays(applied)

const days = computed(() => data.value?.data ?? [])

function submit(): void {
  if (canSubmit.value) {
    applied.value = { ...draft.value }
  }
}

// Cuando el servidor resuelve el rango por omision, el formulario se rellena
// con el que de verdad se ha consultado, para que quien mira sepa que periodo
// esta viendo sin tener que adivinarlo.
watch(data, (value) => {
  if (value === undefined) {
    return
  }

  if (draft.value.from === '' && draft.value.to === '') {
    draft.value = { from: value.from, to: value.to }
  }

  announce(
    t('myRecords.announce.results', {
      count: value.data.length,
      from: value.from,
      to: value.to,
    }),
  )
})
</script>

<template>
  <section>
    <header>
      <h1 class="font-heading text-2xl font-bold text-kq-text">{{ t('myRecords.title') }}</h1>
      <div v-if="session.employee !== null" class="mt-1">
        <p class="text-lg">{{ session.employee.display_name }}</p>
        <p class="text-sm font-bold text-kq-text-muted">{{ session.employee.employee_code }}</p>
      </div>
      <p class="mt-2 max-w-prose text-kq-text-muted">{{ t('myRecords.subtitle') }}</p>
    </header>

    <form
      class="mt-4 flex max-w-3xl flex-wrap items-start gap-4"
      novalidate
      @submit.prevent="submit"
    >
      <fieldset class="flex flex-wrap items-start gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('myRecords.filters.legend') }}</legend>

        <FormField
          v-slot="field"
          :label="t('myRecords.filters.from')"
          :hint="t('myRecords.filters.fromHint')"
          label-class="text-lg font-medium text-kq-text"
          class="w-full sm:w-96"
        >
          <input
            :id="field.id"
            v-model="draft.from"
            type="date"
            :aria-describedby="field.describedBy"
            class="min-h-12 w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('myRecords.filters.to')"
          :hint="t('myRecords.filters.toHint')"
          :errors="rangeErrors"
          label-class="text-lg font-medium text-kq-text"
          class="w-full sm:w-96"
        >
          <input
            :id="field.id"
            v-model="draft.to"
            type="date"
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="min-h-12 w-full rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-lg"
          />
        </FormField>
      </fieldset>

      <!--
        El boton se alinea con la parte de arriba de los dos `<input>`, no con
        las etiquetas: el espaciador oculto reproduce, en `sm` y superior, la
        altura de una etiqueta `text-lg` (line-height 1.75rem); el `gap-1` que
        `FormField` deja entre la etiqueta y el campo lo aporta el `gap-1` de
        este mismo contenedor, asi que el boton arranca a la altura del campo. En movil, cuando el
        boton salta a su propia linea por el `flex-wrap` del formulario, el
        espaciador desaparece para que no quede un hueco vacio antes del boton.
      -->
      <div class="flex flex-col gap-1">
        <span class="hidden h-7 sm:block" aria-hidden="true"></span>
        <button
          type="submit"
          :disabled="!canSubmit"
          class="min-h-12 rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-lg font-semibold text-kq-on-primary disabled:opacity-50"
        >
          {{ t('myRecords.filters.apply') }}
        </button>
      </div>
    </form>

    <p v-if="data !== undefined" class="mt-3 text-kq-text-muted" data-test="resolved-range">
      {{ t('myRecords.filters.resolved', { from: data.from, to: data.to }) }}
      <span v-if="isFetching" class="text-kq-text-muted">{{ t('common.updating') }}</span>
    </p>

    <LoadingPanel v-if="isPending" :label="t('myRecords.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <EmptyState
      v-else-if="days.length === 0"
      class="mt-4"
      :title="t('myRecords.empty.title')"
      :description="t('myRecords.empty.description')"
    />

    <!--
      Se queda en una sola columna a proposito, incluso con el ancho del
      contenedor ampliado en `lg`. Una rejilla de dos columnas partiria la
      lectura cronologica -dia 1 arriba a la izquierda, dia 2 arriba a la
      derecha, dia 3 abajo a la izquierda- justo para quien menos puede
      permitirse confundir el orden de las jornadas al comprobarlas contra una
      nomina. El ancho ganado en pantallas grandes lo aprovecha la tabla de
      tramos de cada tarjeta (`w-full`, sin `max-w` heredado), no una segunda
      columna de tarjetas.
    -->
    <div v-else class="mt-4 flex flex-col gap-6">
      <WorkDayCard v-for="day of days" :key="day.work_date" :day="day" />
    </div>
  </section>
</template>
