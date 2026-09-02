<script setup lang="ts">
// Paso 2: datos de la organizacion (RF-PD-03, RF-PD-01).
//
// Escribe en el mismo catalogo de `PATCH /settings` que gobernara la marca de
// la tarea 5.8: el nombre visible del hotel (`BRANDING_APP_NAME`) y los
// idiomas del panel (`LOCALE_DEFAULT`, `LOCALE_AVAILABLE`). Las tres se
// guardan y se auditan desde ya, aunque las tres aplicaciones cliente sigan
// leyendo la marca del entorno del servidor hasta la 5.8 (ver el contrato,
// esquema `SettingKey`).
//
// A diferencia de la licencia o el quiosco, este paso NO es omitible: el
// contrato lo marca `skippable: false`. La interfaz exige un nombre no vacio
// antes de continuar para que el paso tenga sentido —de lo contrario el hotel
// se quedaria con «KronoQR» a secas en sus informes.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  fetchInstallationSettings,
  updateInstallationSettings,
} from '@/features/settings/settings.api'
import type { InstallationSettings, UpdateSettingsRequest } from '@/shared/api/types'
import { SUPPORTED_LOCALES } from '@/shared/i18n'
import type { AppLocale } from '@/shared/i18n'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const loading = ref(true)
const submitting = ref(false)
const error = ref<unknown>(null)
const settings = ref<InstallationSettings | null>(null)

const appName = ref('')
const defaultLocale = ref<AppLocale>('es')
const availableLocales = ref<AppLocale[]>(['es', 'en'])

function stringValue(catalog: InstallationSettings, key: string): string {
  const found = catalog.data.find((entry) => entry.key === key)

  return typeof found?.value === 'string' ? found.value : ''
}

function listValue(catalog: InstallationSettings, key: string): string[] {
  const found = catalog.data.find((entry) => entry.key === key)

  return Array.isArray(found?.value) ? found.value : []
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    const catalog = await fetchInstallationSettings()

    settings.value = catalog
    appName.value = stringValue(catalog, 'BRANDING_APP_NAME')

    const storedDefault = stringValue(catalog, 'LOCALE_DEFAULT')
    const storedAvailable = listValue(catalog, 'LOCALE_AVAILABLE').filter(
      (locale): locale is AppLocale => (SUPPORTED_LOCALES as readonly string[]).includes(locale),
    )

    defaultLocale.value = (SUPPORTED_LOCALES as readonly string[]).includes(storedDefault)
      ? (storedDefault as AppLocale)
      : 'es'
    availableLocales.value = storedAvailable.length > 0 ? storedAvailable : ['es', 'en']
  } catch (caught) {
    error.value = caught
  } finally {
    loading.value = false
  }
}

onMounted(load)

/**
 * El `422` de `PATCH /settings` cuelga el error de `settings.<CLAVE>`, no de
 * la clave a secas (contrato, `UpdateSettingsRequest`): asi que el nombre de
 * campo tambien lleva el prefijo aqui, tanto para leer el error como para
 * traducirlo en `ErrorNotice`.
 */
function fieldErrors(key: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[`settings.${key}`] ?? []) : []
}

/** Un idioma no se puede desmarcar si es el que esta activo por defecto. */
function toggleLocale(locale: AppLocale): void {
  if (availableLocales.value.includes(locale)) {
    if (locale === defaultLocale.value) {
      return
    }

    availableLocales.value = availableLocales.value.filter((entry) => entry !== locale)
  } else {
    availableLocales.value = [...availableLocales.value, locale]
  }
}

const canContinue = computed(() => appName.value.trim() !== '' && availableLocales.value.length > 0)

async function submit(): Promise<void> {
  if (!canContinue.value || settings.value === null) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    const changes: UpdateSettingsRequest['settings'] = {}
    const trimmedName = appName.value.trim()

    if (trimmedName !== stringValue(settings.value, 'BRANDING_APP_NAME')) {
      changes['BRANDING_APP_NAME'] = trimmedName
    }

    if (defaultLocale.value !== stringValue(settings.value, 'LOCALE_DEFAULT')) {
      changes['LOCALE_DEFAULT'] = defaultLocale.value
    }

    const storedAvailable = listValue(settings.value, 'LOCALE_AVAILABLE')

    if (
      JSON.stringify([...availableLocales.value].sort()) !==
      JSON.stringify([...storedAvailable].sort())
    ) {
      changes['LOCALE_AVAILABLE'] = availableLocales.value
    }

    if (Object.keys(changes).length > 0) {
      await updateInstallationSettings(changes)
    }

    await setup.recordStep('organisation', 'completed')
  } catch (caught) {
    error.value = caught
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.organisation.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.organisation.intro') }}</p>

    <p v-if="loading" class="text-kq-text-muted" role="status">
      {{ t('onboarding.steps.organisation.loading') }}
    </p>

    <ErrorNotice
      v-if="error !== null"
      :error="error"
      :field-labels="{
        'settings.BRANDING_APP_NAME': t('onboarding.steps.organisation.fields.appName'),
      }"
    />

    <form v-if="!loading" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField
        v-slot="field"
        :label="t('onboarding.steps.organisation.fields.appName')"
        :hint="t('onboarding.steps.organisation.hints.appName')"
        :errors="fieldErrors('BRANDING_APP_NAME')"
        required
      >
        <input
          :id="field.id"
          v-model="appName"
          type="text"
          maxlength="60"
          required
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
        />
      </FormField>

      <FormField
        :label="t('onboarding.steps.organisation.fields.defaultLocale')"
        :hint="t('onboarding.steps.organisation.hints.defaultLocale')"
      >
        <template #default="{ id, describedBy }">
          <select
            :id="id"
            v-model="defaultLocale"
            :aria-describedby="describedBy"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          >
            <option v-for="locale of SUPPORTED_LOCALES" :key="locale" :value="locale">
              {{ t(`onboarding.locales.${locale}`) }}
            </option>
          </select>
        </template>
      </FormField>

      <fieldset class="flex flex-col gap-2">
        <legend class="font-medium text-kq-text">
          {{ t('onboarding.steps.organisation.fields.availableLocales') }}
        </legend>
        <p class="text-sm text-kq-text-muted">
          {{ t('onboarding.steps.organisation.hints.availableLocales') }}
        </p>
        <label v-for="locale of SUPPORTED_LOCALES" :key="locale" class="flex items-center gap-2">
          <input
            type="checkbox"
            :checked="availableLocales.includes(locale)"
            :disabled="locale === defaultLocale"
            @change="toggleLocale(locale)"
          />
          {{ t(`onboarding.locales.${locale}`) }}
        </label>
      </fieldset>

      <div>
        <button
          type="submit"
          :disabled="!canContinue || submitting"
          :aria-busy="submitting"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        >
          {{ submitting ? t('onboarding.actions.saving') : t('onboarding.actions.continue') }}
        </button>
      </div>
    </form>
  </div>
</template>
