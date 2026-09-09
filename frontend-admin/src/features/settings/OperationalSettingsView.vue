<script setup lang="ts">
// Ajustes operativos de la instalacion: los cuatro umbrales `ATTENDANCE_*` y
// los dos idiomas `LOCALE_*` (RF-PD-01, tarea 5.13, hallazgo B1 del cierre de
// la Fase 5). Hasta ahora estas seis claves solo se podian cambiar por
// `PATCH /api/v1/settings` a mano o por el asistente de puesta en marcha (una
// sola vez, solo tres de las seis): ninguna pantalla del panel las volvia a
// enseñar, y `docs/cliente/configuracion.md` §6.0 y §2.1 prometian «se editan
// desde el panel» sin que hubiera panel.
//
// Dos decisiones que esta pantalla dice en voz alta, en vez de dejarlas
// implicitas:
//
//  - **El rango de cada umbral NO se copia aqui.** Viene de
//    `constraints.minimum`/`constraints.maximum` de `GET /api/v1/settings`
//    (mismo criterio que `ComplianceProfileView`, que tampoco copia los
//    limites del servidor): el `422` de rango es el que manda, y el hint solo
//    interpola el numero que trae la respuesta.
//  - **El idioma por defecto es un desplegable entre TODOS los que trae el
//    producto** (`constraints.allowed` de `LOCALE_DEFAULT`/`LOCALE_AVAILABLE`,
//    nunca un catalogo hardcodeado como el paso de organizacion del asistente:
//    ver la nota que actualiza `packages/web-kit/src/datetime.ts`… no, esa es
//    otra; aqui la nota es que dos pantallas leen el mismo catalogo y no deben
//    divergir). Las casillas de disponibles reutilizan el patron ya probado de
//    `onboarding/steps/OrganisationStep.vue`: no se puede desmarcar el idioma
//    que esta activo por defecto.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  InstallationSetting,
  InstallationSettings,
  UpdateSettingsRequest,
} from '@/shared/api/types'
import { fetchInstallationSettings, updateInstallationSettings } from './settings.api'

const { t } = useI18n()

/** Las cuatro claves `ATTENDANCE_*`, en el orden en que las declara el catalogo. */
const ATTENDANCE_FIELDS = [
  { key: 'ATTENDANCE_MAX_SHIFT_HOURS', testId: 'max-shift-hours', i18n: 'maxShiftHours' },
  { key: 'ATTENDANCE_DEBOUNCE_SECONDS', testId: 'debounce-seconds', i18n: 'debounceSeconds' },
  {
    key: 'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES',
    testId: 'max-clock-skew-minutes',
    i18n: 'maxClockSkewMinutes',
  },
  {
    key: 'ATTENDANCE_MIN_TRANSIT_SECONDS',
    testId: 'min-transit-seconds',
    i18n: 'minTransitSeconds',
  },
] as const

type AttendanceKey = (typeof ATTENDANCE_FIELDS)[number]['key']

const settings = ref<InstallationSettings | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref<unknown>(null)
const saved = ref(false)

const form = ref<Record<AttendanceKey, number | string>>({
  ATTENDANCE_MAX_SHIFT_HOURS: '',
  ATTENDANCE_DEBOUNCE_SECONDS: '',
  ATTENDANCE_MAX_CLOCK_SKEW_MINUTES: '',
  ATTENDANCE_MIN_TRANSIT_SECONDS: '',
})
const localeDefault = ref('')
const localeAvailable = ref<string[]>([])

/** La fila de una clave del catalogo ya cargado, o `undefined` si no llego a resolverse. */
function entryOf(catalog: InstallationSettings, key: string): InstallationSetting | undefined {
  return catalog.data.find((candidate) => candidate.key === key)
}

/** El valor numerico de una clave, como cadena para `v-model`. `'0'` si no es un numero: no debería pasar, el catalogo siempre la declara `integer`. */
function integerValue(catalog: InstallationSettings, key: string): string {
  const value = entryOf(catalog, key)?.value

  return typeof value === 'number' ? String(value) : '0'
}

/** Los idiomas que el PRODUCTO ofrece, del propio catalogo (`constraints.allowed`): nunca un catalogo duplicado en el cliente. */
const shippedLocales = computed<readonly string[]>(() => {
  const catalog = settings.value

  if (catalog === null) {
    return []
  }

  return (
    entryOf(catalog, 'LOCALE_DEFAULT')?.constraints?.allowed ??
    entryOf(catalog, 'LOCALE_AVAILABLE')?.constraints?.allowed ??
    []
  )
})

/** Como se llama un idioma, en el idioma de la interfaz. Sin traduccion propia, el codigo tal cual: no debería pasar con el catalogo de serie (`es`, `en`). */
function localeLabel(code: string): string {
  return code === 'es' || code === 'en' ? t(`common.locales.${code}`) : code
}

/** El rango admitido de una clave entera, para interpolarlo en el hint sin copiarlo. */
function rangeOf(
  catalog: InstallationSettings | null,
  key: string,
): { minimum: number; maximum: number } {
  const constraints = catalog === null ? undefined : entryOf(catalog, key)?.constraints

  return { minimum: constraints?.minimum ?? 0, maximum: constraints?.maximum ?? 0 }
}

function fill(catalog: InstallationSettings): void {
  settings.value = catalog

  for (const field of ATTENDANCE_FIELDS) {
    form.value[field.key] = integerValue(catalog, field.key)
  }

  const storedDefault = entryOf(catalog, 'LOCALE_DEFAULT')?.value
  const storedAvailable = entryOf(catalog, 'LOCALE_AVAILABLE')?.value

  localeDefault.value = typeof storedDefault === 'string' ? storedDefault : ''
  localeAvailable.value = Array.isArray(storedAvailable) ? [...storedAvailable] : []
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    fill(await fetchInstallationSettings())
  } catch (failure) {
    error.value = failure
  } finally {
    loading.value = false
  }
}

onMounted(load)

/** El `422` cuelga el error de `settings.<CLAVE>`. La invariante ENTRE `LOCALE_DEFAULT` y `LOCALE_AVAILABLE` cuelga de `settings` a secas (ver `UpdateSettingsRequest` en el instalador de rutas del backend). */
function serverFieldErrors(key: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[`settings.${key}`] ?? []) : []
}

const fieldLabels = computed<Record<string, string>>(() => ({
  settings: t('operationalSettings.heading'),
  'settings.ATTENDANCE_MAX_SHIFT_HOURS': t('operationalSettings.fields.maxShiftHours'),
  'settings.ATTENDANCE_DEBOUNCE_SECONDS': t('operationalSettings.fields.debounceSeconds'),
  'settings.ATTENDANCE_MAX_CLOCK_SKEW_MINUTES': t('operationalSettings.fields.maxClockSkewMinutes'),
  'settings.ATTENDANCE_MIN_TRANSIT_SECONDS': t('operationalSettings.fields.minTransitSeconds'),
  'settings.LOCALE_DEFAULT': t('operationalSettings.fields.localeDefault'),
  'settings.LOCALE_AVAILABLE': t('operationalSettings.fields.localeAvailable'),
}))

/**
 * Un entero, o `undefined` si lo escrito no lo es: el rango lo decide el
 * servidor (mismo criterio que `ComplianceProfileView::asInteger`).
 */
function asInteger(raw: number | string): number | undefined {
  if (typeof raw === 'number') {
    return Number.isInteger(raw) ? raw : undefined
  }

  const trimmed = raw.trim()

  return /^-?\d+$/.test(trimmed) ? Number.parseInt(trimmed, 10) : undefined
}

function issueOf(key: AttendanceKey): 'required' | 'notAWholeNumber' | null {
  const raw = form.value[key]

  if (raw === '' || raw === null) {
    return 'required'
  }

  return asInteger(raw) === undefined ? 'notAWholeNumber' : null
}

function errorsFor(key: AttendanceKey): readonly string[] {
  const issue = issueOf(key)
  const local = issue === null ? [] : [t(`operationalSettings.errors.${issue}`)]

  return [...local, ...serverFieldErrors(key)]
}

const invalidFields = computed(() =>
  ATTENDANCE_FIELDS.filter((field) => issueOf(field.key) !== null),
)

/** Un idioma no se puede desmarcar si es el que esta activo por defecto (mismo patron que `OrganisationStep`). */
function toggleLocale(code: string): void {
  if (localeAvailable.value.includes(code)) {
    if (code === localeDefault.value) {
      return
    }

    localeAvailable.value = localeAvailable.value.filter((entry) => entry !== code)
  } else {
    localeAvailable.value = [...localeAvailable.value, code]
  }
}

const pendingChanges = computed<UpdateSettingsRequest['settings']>(() => {
  const current = settings.value

  if (current === null) {
    return {}
  }

  const changes: UpdateSettingsRequest['settings'] = {}

  for (const field of ATTENDANCE_FIELDS) {
    const value = asInteger(form.value[field.key])
    const previous = entryOf(current, field.key)?.value

    if (value !== undefined && value !== previous) {
      changes[field.key] = value
    }
  }

  const trimmedDefault = localeDefault.value.trim()
  const previousDefault = entryOf(current, 'LOCALE_DEFAULT')?.value

  if (trimmedDefault !== '' && trimmedDefault !== previousDefault) {
    changes['LOCALE_DEFAULT'] = trimmedDefault
  }

  const previousAvailable = entryOf(current, 'LOCALE_AVAILABLE')?.value
  const previousAvailableList = Array.isArray(previousAvailable) ? previousAvailable : []

  if (
    localeAvailable.value.length > 0 &&
    JSON.stringify([...localeAvailable.value].sort()) !==
      JSON.stringify([...previousAvailableList].sort())
  ) {
    changes['LOCALE_AVAILABLE'] = localeAvailable.value
  }

  return changes
})

const hasChanges = computed(() => Object.keys(pendingChanges.value).length > 0)

const canSave = computed(
  () =>
    hasChanges.value &&
    invalidFields.value.length === 0 &&
    localeAvailable.value.length > 0 &&
    !saving.value,
)

/**
 * Si el cambio pendiente toca alguna clave que **afecta al calculo de
 * horas** (`affects_worked_hours` de `GET /api/v1/settings`, no una lista
 * copiada aqui: hoy es `ATTENDANCE_DEBOUNCE_SECONDS`, y si el catalogo
 * cambiara mañana el aviso seguiria acertando sin tocar esta pantalla).
 */
const affectsWorkedHoursPending = computed(() => {
  const current = settings.value

  if (current === null) {
    return false
  }

  return Object.keys(pendingChanges.value).some(
    (key) => entryOf(current, key)?.affects_worked_hours === true,
  )
})

async function save(): Promise<void> {
  if (!canSave.value) {
    return
  }

  saving.value = true
  error.value = null
  saved.value = false

  try {
    fill(await updateInstallationSettings(pendingChanges.value))
    saved.value = true
    announce(t('operationalSettings.saved'))
  } catch (failure) {
    error.value = failure
    announce(t('operationalSettings.failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('operationalSettings.heading') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('operationalSettings.intro') }}</p>
    </header>

    <LoadingPanel v-if="loading" :label="t('operationalSettings.loading')" data-test="loading" />

    <ErrorNotice v-if="error !== null" :error="error" :field-labels="fieldLabels" />

    <form
      v-if="settings !== null"
      class="flex max-w-3xl flex-col gap-6"
      novalidate
      @submit.prevent="save"
    >
      <fieldset class="flex flex-col gap-4">
        <legend class="text-lg font-medium text-kq-text">
          {{ t('operationalSettings.attendanceHeading') }}
        </legend>

        <FormField
          v-for="field of ATTENDANCE_FIELDS"
          :key="field.key"
          :label="t(`operationalSettings.fields.${field.i18n}`)"
          :hint="t(`operationalSettings.hints.${field.i18n}`, rangeOf(settings, field.key))"
          :errors="errorsFor(field.key)"
        >
          <template #default="{ id, describedBy, invalid }">
            <input
              :id="id"
              v-model="form[field.key]"
              type="number"
              inputmode="numeric"
              :data-test="field.testId"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-32 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>
      </fieldset>

      <p
        v-if="affectsWorkedHoursPending"
        role="alert"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        data-test="affects-worked-hours-warning"
      >
        {{ t('operationalSettings.affectsWorkedHoursWarning') }}
      </p>

      <fieldset class="flex flex-col gap-4">
        <legend class="text-lg font-medium text-kq-text">
          {{ t('operationalSettings.localesHeading') }}
        </legend>

        <FormField
          :label="t('operationalSettings.fields.localeDefault')"
          :hint="t('operationalSettings.hints.localeDefault')"
          :errors="serverFieldErrors('LOCALE_DEFAULT')"
        >
          <template #default="{ id, describedBy, invalid }">
            <select
              :id="id"
              v-model="localeDefault"
              data-test="locale-default"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-48 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            >
              <option v-for="code of shippedLocales" :key="code" :value="code">
                {{ localeLabel(code) }}
              </option>
            </select>
          </template>
        </FormField>

        <fieldset class="flex flex-col gap-2" data-test="locale-available">
          <legend class="font-medium text-kq-text">
            {{ t('operationalSettings.fields.localeAvailable') }}
          </legend>
          <p class="text-sm text-kq-text-muted">
            {{ t('operationalSettings.hints.localeAvailable') }}
          </p>
          <label v-for="code of shippedLocales" :key="code" class="flex items-center gap-2">
            <input
              type="checkbox"
              :checked="localeAvailable.includes(code)"
              :disabled="code === localeDefault"
              :data-test="`locale-available-${code}`"
              @change="toggleLocale(code)"
            />
            {{ localeLabel(code) }}
          </label>
          <p
            v-if="serverFieldErrors('LOCALE_AVAILABLE').length > 0"
            class="text-sm font-medium text-kq-danger"
            role="alert"
          >
            {{ serverFieldErrors('LOCALE_AVAILABLE').join(' ') }}
          </p>
        </fieldset>
      </fieldset>

      <p class="text-sm text-kq-text-muted" data-test="audited">
        {{ t('operationalSettings.audited') }}
      </p>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="!canSave"
          data-test="save"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-50"
        >
          {{ t('operationalSettings.save') }}
        </button>
        <p v-if="saving" class="text-kq-text-muted">{{ t('operationalSettings.saving') }}</p>
        <p v-else-if="saved && !hasChanges" class="text-kq-success" role="status" data-test="saved">
          {{ t('operationalSettings.saved') }}
        </p>
      </div>
    </form>
  </section>
</template>
