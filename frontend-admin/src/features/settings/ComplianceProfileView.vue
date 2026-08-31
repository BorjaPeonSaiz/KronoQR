<script setup lang="ts">
// Perfil de cumplimiento: los umbrales LEGALES del centro (RF-PD-07, regla
// dura 14, ADR-017).
//
// Para que existe, literalmente: para que un cliente con otro convenio no
// necesite que nadie despliegue nada. La via de consola —editar la fila de
// `compliance_profiles`— sigue existiendo y es la de respaldo; esta es la del
// dia a dia, y ademas es la unica que deja asiento en `audit_log` con el valor
// anterior y su autor.
//
// Lo que esta pantalla dice en voz alta, en vez de dejar que alguien lo suponga:
//
//  - **Cambiar un umbral cambia que incidencias se abren** a partir de la
//    proxima revision diaria. Es el aviso permanente de arriba.
//  - **No hay retroactividad**: el valor nuevo rige desde el cambio y el
//    historico no se reprocesa; ninguna incidencia se cierra ni se reabre.
//  - **Tres campos se guardan y todavia no los aplica ninguna regla.** Se marcan
//    uno a uno. Prometer un efecto que no existe es peor que no ofrecer el campo.
//  - **`retention_years` es el unico cuyo error se paga con datos que no
//    vuelven**, y lleva su propio aviso al lado.
//
// Los limites de cada campo NO se copian aqui: el `422` del servidor es el que
// manda y `ErrorNotice` lo pinta con el nombre del campo.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ComplianceProfileBody, UpdateComplianceProfileRequest } from '@/shared/api/types'
import { fetchComplianceProfile, updateComplianceProfile } from './complianceProfile.api'

const { t } = useI18n()

const profile = ref<ComplianceProfileBody | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref<unknown>(null)
const saved = ref(false)

/**
 * El formulario.
 *
 * Los campos numericos se declaran `number | string` **porque Vue los devuelve
 * de las dos formas**: `v-model` sobre un `<input type="number">` convierte a
 * numero cuando lo escrito es un numero, y deja la cadena tal cual cuando no lo
 * es —que es justo el caso que hay que poder detectar—. Fingir que siempre es
 * una cadena rompe en cuanto alguien teclea.
 */
const form = ref({
  name: '',
  minRestHours: '' as number | string,
  maxDailyHours: '' as number | string,
  maxWeeklyHours: '' as number | string,
  breakRequiredAfterHours: '' as number | string,
  weekStartsOn: '1' as number | string,
  retentionYears: '' as number | string,
  holidayCalendar: '',
})

/**
 * Los nombres de campo del `422`, en el idioma de la interfaz.
 *
 * Sin esto, el aviso diria «min_rest_hours: …», que es el nombre de la columna y
 * no lo que la persona acaba de escribir en la pantalla.
 */
const fieldLabels = computed<Record<string, string>>(() => ({
  name: t('compliance.fields.name'),
  min_rest_hours: t('compliance.fields.minRestHours'),
  max_daily_hours: t('compliance.fields.maxDailyHours'),
  max_weekly_hours: t('compliance.fields.maxWeeklyHours'),
  break_required_after_hours: t('compliance.fields.breakRequiredAfterHours'),
  week_starts_on: t('compliance.fields.weekStartsOn'),
  holiday_calendar: t('compliance.fields.holidayCalendar'),
  retention_years: t('compliance.fields.retentionYears'),
  compliance_profile: t('compliance.heading'),
}))

const weekDays = [1, 2, 3, 4, 5, 6, 7] as const

function fill(loaded: ComplianceProfileBody): void {
  profile.value = loaded
  form.value = {
    name: loaded.name,
    minRestHours: String(loaded.min_rest_hours),
    maxDailyHours: String(loaded.max_daily_hours),
    maxWeeklyHours: String(loaded.max_weekly_hours),
    breakRequiredAfterHours: String(loaded.break_required_after_hours),
    weekStartsOn: String(loaded.week_starts_on),
    retentionYears: String(loaded.retention_years),
    // Una fecha por linea: es como se pega un calendario de festivos copiado de
    // un boletin oficial, y como se revisa de un vistazo.
    holidayCalendar: loaded.holiday_calendar.join('\n'),
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    fill((await fetchComplianceProfile()).data)
  } catch (failure) {
    error.value = failure
  } finally {
    loading.value = false
  }
}

onMounted(load)

/**
 * Un entero, o `undefined` si lo escrito no lo es: el servidor decide el resto.
 *
 * Acepta las dos formas que llegan de `v-model` sobre un campo numerico —numero
 * cuando lo tecleado es valido, cadena cuando no— porque las dos ocurren
 * mientras alguien escribe.
 */
function asInteger(raw: number | string): number | undefined {
  if (typeof raw === 'number') {
    return Number.isInteger(raw) ? raw : undefined
  }

  const trimmed = raw.trim()

  return /^-?\d+$/.test(trimmed) ? Number.parseInt(trimmed, 10) : undefined
}

/** Las fechas escritas, una por linea, sin vacias. */
function asCalendar(raw: string): string[] {
  return raw
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '')
}

/** Los campos numéricos del formulario, con su clave en la API. */
const numericFields = [
  ['minRestHours', 'min_rest_hours'],
  ['maxDailyHours', 'max_daily_hours'],
  ['maxWeeklyHours', 'max_weekly_hours'],
  ['breakRequiredAfterHours', 'break_required_after_hours'],
  ['weekStartsOn', 'week_starts_on'],
  ['retentionYears', 'retention_years'],
] as const

type NumericField = (typeof numericFields)[number][0]

/**
 * Lo que hay que corregir en un campo numérico antes de poder guardar.
 *
 * Antes se descartaba en silencio: `asInteger()` devolvía `undefined`, el campo
 * no entraba en el `PATCH` y el botón se deshabilitaba **sin decir por qué**.
 * Los dos casos que llegan aquí de verdad son el campo **vaciado** —que un
 * `<input type="number">` sí permite— y un **decimal**, porque el navegador no
 * deja teclear letras en ese tipo de campo pero sí un `1,5`. En los dos, quien
 * está delante ve un formulario que no reacciona y no sabe qué mira mal.
 *
 * El rango sigue siendo cosa del servidor: aquí solo se comprueba que lo escrito
 * sea un entero, que es lo que hace falta para poder enviarlo.
 */
function issueOf(field: NumericField): 'required' | 'notAWholeNumber' | null {
  const raw = form.value[field]

  if (raw === '' || raw === null) {
    return 'required'
  }

  return asInteger(raw) === undefined ? 'notAWholeNumber' : null
}

const invalidFields = computed<NumericField[]>(() =>
  numericFields.map(([key]) => key).filter((key) => issueOf(key) !== null),
)

function errorsFor(field: NumericField): string[] {
  const issue = issueOf(field)

  return issue === null ? [] : [t(`compliance.errors.${issue}`)]
}

/**
 * Solo lo que ha cambiado.
 *
 * No es una optimizacion: un `PATCH` con los ocho campos dejaria el trail lleno
 * de asientos que dicen «alguien abrio la pantalla». El servidor tambien
 * descarta lo que no cambia, y las dos mitades se refuerzan.
 */
const pendingChanges = computed<UpdateComplianceProfileRequest>(() => {
  const current = profile.value

  if (current === null) {
    return {}
  }

  const changes: UpdateComplianceProfileRequest = {}
  const name = form.value.name.trim()

  if (name !== current.name) {
    changes.name = name
  }

  const integers = [
    ['min_rest_hours', form.value.minRestHours, current.min_rest_hours],
    ['max_daily_hours', form.value.maxDailyHours, current.max_daily_hours],
    ['max_weekly_hours', form.value.maxWeeklyHours, current.max_weekly_hours],
    [
      'break_required_after_hours',
      form.value.breakRequiredAfterHours,
      current.break_required_after_hours,
    ],
    ['week_starts_on', form.value.weekStartsOn, current.week_starts_on],
    ['retention_years', form.value.retentionYears, current.retention_years],
  ] as const

  for (const [field, raw, previous] of integers) {
    const value = asInteger(raw)

    if (value !== undefined && value !== previous) {
      changes[field] = value
    }
  }

  const calendar = asCalendar(form.value.holidayCalendar)

  if (calendar.join('\n') !== current.holiday_calendar.join('\n')) {
    changes.holiday_calendar = calendar
  }

  return changes
})

const hasChanges = computed(() => Object.keys(pendingChanges.value).length > 0)

/** No se envía nada mientras haya un campo que no es un número: el aviso ya está a la vista. */
const canSave = computed(
  () => hasChanges.value && invalidFields.value.length === 0 && !saving.value,
)

/** No se envía nada mientras haya un campo que no es un número: el aviso ya está a la vista. */
/**
 * Si el cambio pendiente toca alguno de los umbrales que **hoy** mueven la
 * revisión diaria.
 *
 * `break_required_after_hours` NO está en la lista, y no es un olvido: RN-12 se
 * evalúa pero su apertura de incidencia está suspendida hasta que el quiosco
 * registre la pausa declarada, así que cambiar ese umbral no altera ni una
 * incidencia. Prometer lo contrario sería mentir en la pantalla, igual que lo
 * era en el asiento de auditoría. El campo lleva su propio aviso, que dice lo
 * que de verdad pasa.
 *
 * **La autoridad es el servidor**, que decide lo mismo derivándolo de una única
 * lista de reglas suspendidas: esto solo elige qué aviso enseñar.
 */
const changesDetection = computed(() =>
  ['min_rest_hours', 'max_daily_hours'].some((field) => field in pendingChanges.value),
)

/** Si el cambio pendiente toca el plazo de conservacion, que es el irreversible. */
const changesRetention = computed(() => 'retention_years' in pendingChanges.value)

async function save(): Promise<void> {
  if (!canSave.value) {
    return
  }

  saving.value = true
  error.value = null
  saved.value = false

  try {
    fill((await updateComplianceProfile(pendingChanges.value)).data)
    saved.value = true
    announce(t('compliance.saved'))
  } catch (failure) {
    error.value = failure
    announce(t('compliance.failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('compliance.heading') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('compliance.intro') }}</p>
    </header>

    <p
      class="max-w-3xl rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
      role="note"
      data-test="detection-warning"
    >
      {{ t('compliance.detectionWarning') }}
    </p>

    <p v-if="loading" class="text-kq-text-muted" data-test="loading">
      {{ t('compliance.loading') }}
    </p>

    <ErrorNotice v-if="error !== null" :error="error" :field-labels="fieldLabels" />

    <form
      v-if="profile !== null"
      class="flex max-w-3xl flex-col gap-4"
      novalidate
      @submit.prevent="save"
    >
      <FormField :label="t('compliance.fields.name')" :hint="t('compliance.hints.name')">
        <template #default="{ id, describedBy }">
          <input
            :id="id"
            v-model="form.name"
            type="text"
            autocomplete="off"
            data-test="name"
            :aria-describedby="describedBy"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </template>
      </FormField>

      <div class="grid gap-4 sm:grid-cols-2">
        <FormField
          :label="t('compliance.fields.minRestHours')"
          :hint="t('compliance.hints.minRestHours')"
          :errors="errorsFor('minRestHours')"
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="form.minRestHours"
              type="number"
              inputmode="numeric"
              data-test="min-rest-hours"
              :aria-describedby="describedBy"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>

        <FormField
          :label="t('compliance.fields.maxDailyHours')"
          :hint="t('compliance.hints.maxDailyHours')"
          :errors="errorsFor('maxDailyHours')"
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="form.maxDailyHours"
              type="number"
              inputmode="numeric"
              data-test="max-daily-hours"
              :aria-describedby="describedBy"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>

        <FormField
          :label="t('compliance.fields.breakRequiredAfterHours')"
          :hint="t('compliance.hints.breakRequiredAfterHours')"
          :errors="errorsFor('breakRequiredAfterHours')"
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="form.breakRequiredAfterHours"
              type="number"
              inputmode="numeric"
              data-test="break-required-after-hours"
              :aria-describedby="describedBy"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>

        <p class="text-sm text-kq-text-muted sm:col-span-2" data-test="break-suspended">
          {{ t('compliance.breakSuspended') }}
        </p>

        <FormField
          :label="t('compliance.fields.maxWeeklyHours')"
          :hint="t('compliance.hints.maxWeeklyHours')"
          :errors="errorsFor('maxWeeklyHours')"
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="form.maxWeeklyHours"
              type="number"
              inputmode="numeric"
              data-test="max-weekly-hours"
              :aria-describedby="describedBy"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>
      </div>

      <p class="text-sm text-kq-text-muted" data-test="not-applied-yet">
        {{ t('compliance.notAppliedYet') }}
      </p>

      <FormField
        :label="t('compliance.fields.weekStartsOn')"
        :hint="t('compliance.hints.weekStartsOn')"
      >
        <template #default="{ id, describedBy }">
          <select
            :id="id"
            v-model="form.weekStartsOn"
            data-test="week-starts-on"
            :aria-describedby="describedBy"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          >
            <option v-for="day of weekDays" :key="day" :value="String(day)">
              {{ t(`compliance.weekDays.${day}`) }}
            </option>
          </select>
        </template>
      </FormField>

      <FormField
        :label="t('compliance.fields.holidayCalendar')"
        :hint="t('compliance.hints.holidayCalendar')"
      >
        <template #default="{ id, describedBy }">
          <textarea
            :id="id"
            v-model="form.holidayCalendar"
            rows="6"
            spellcheck="false"
            data-test="holiday-calendar"
            :aria-describedby="describedBy"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-kq-text"
          ></textarea>
        </template>
      </FormField>

      <FormField
        :label="t('compliance.fields.retentionYears')"
        :hint="t('compliance.hints.retentionYears')"
        :errors="errorsFor('retentionYears')"
      >
        <template #default="{ id, describedBy }">
          <input
            :id="id"
            v-model="form.retentionYears"
            type="number"
            inputmode="numeric"
            data-test="retention-years"
            :aria-describedby="describedBy"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </template>
      </FormField>

      <p
        v-if="changesRetention"
        class="rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
        role="alert"
        data-test="retention-warning"
      >
        {{ t('compliance.retentionWarning') }}
      </p>

      <p
        v-if="changesDetection"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        role="alert"
        data-test="pending-detection-warning"
      >
        {{ t('compliance.pendingDetectionWarning') }}
      </p>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="!canSave"
          data-test="save"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-50"
        >
          {{ t('compliance.save') }}
        </button>
        <p v-if="saving" class="text-kq-text-muted">{{ t('compliance.saving') }}</p>
        <p v-else-if="saved && !hasChanges" class="text-kq-success" role="status" data-test="saved">
          {{ t('compliance.saved') }}
        </p>
      </div>

      <p class="text-sm text-kq-text-muted" data-test="source">
        {{
          t(
            profile.source === 'site' ? 'compliance.source.site' : 'compliance.source.installation',
            { name: profile.name, jurisdiction: profile.jurisdiction },
          )
        }}
      </p>

      <p class="text-sm text-kq-text-muted">{{ t('compliance.audited') }}</p>
    </form>
  </section>
</template>
