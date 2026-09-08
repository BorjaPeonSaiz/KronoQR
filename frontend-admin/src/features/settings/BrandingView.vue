<script setup lang="ts">
// La marca de la instalacion: nombre, color de acento y logotipo (RF-PD-08,
// tarea 5.8, regla dura 13).
//
// Tres decisiones que esta pantalla dice en voz alta, en vez de dejarlas
// implicitas:
//
//  - **El color se avisa, no se impone.** Un acento que no llega al minimo de
//    contraste WCAG 2.2 AA en alguna pareja del sistema visual (doc 06 §2) se
//    puede guardar igual: la lista de abajo lo dice, pero no bloquea el
//    boton. Es la misma decision que ya tomo `packages/web-kit/src/branding.ts`.
//  - **El logotipo es una RUTA, no una subida.** El fichero vive en el
//    directorio de marca del servidor del cliente (`BRANDING_LOGO_ROOT`); esta
//    pantalla solo guarda la ruta, y el servidor la comprueba contra el disco
//    al recibir el `PATCH` —PNG o SVG por su contenido, dentro de los limites
//    de tamaño— con un `422` bajo `settings.BRANDING_LOGO_PATH` si algo falla.
//  - **La previsualizacion nunca toca `:root`.** Los tonos derivados del color
//    que se esta escribiendo se calculan con `accentOverrides` y se pintan en
//    ESTILOS EN LINEA de la propia previsualizacion: el resto del panel sigue
//    con la marca ya aplicada hasta que se guarda de verdad.
import {
  accentOverrides,
  contrastWarnings,
  PRODUCT_ACCENT_COLOR,
  readThemeTokens,
  type ContrastWarning,
} from '@kronoqr/web-kit/branding'
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { LICENSE_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import type { InstallationSettings, UpdateSettingsRequest } from '@/shared/api/types'
import { useBrandingStore } from '@/shared/branding/branding.store'
import { fetchInstallationSettings, stringValue, updateInstallationSettings } from './settings.api'
import { useLicenseStore } from './license.store'

// El color de acento del PRODUCTO (doc 06, `primary-strong`) vive en
// `@kronoqr/web-kit/branding` (correccion de revision de UI/UX): una sola
// fuente de verdad para el valor que pinta `PRODUCT_BRANDING` y el que
// escribe el boton «volver al color del producto» de mas abajo.

/** La forma exacta que exige el contrato (`SettingConstraints.pattern` de `BRANDING_ACCENT_COLOR`). */
const HEX_COLOR = /^#[0-9a-fA-F]{6}$/

const { t } = useI18n()
const branding = useBrandingStore()
const session = useSessionStore()
const licenseStore = useLicenseStore()

const settings = ref<InstallationSettings | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref<unknown>(null)
const saved = ref(false)

const appName = ref('')
const accentColor = ref(PRODUCT_ACCENT_COLOR)
const logoPath = ref('')

const fieldLabels = computed<Record<string, string>>(() => ({
  'settings.BRANDING_APP_NAME': t('branding.fields.appName'),
  'settings.BRANDING_ACCENT_COLOR': t('branding.fields.accentColor'),
  'settings.BRANDING_LOGO_PATH': t('branding.fields.logoPath'),
}))

/**
 * Si la funcionalidad de marca propia (ADR-023, enum `LicenseFeature`) esta
 * hoy degradada -no contratada o licencia caducada, ausente, no verificable o
 * todavia no vigente-, con el motivo que trae el servidor. `null` mientras no
 * haya datos de licencia (sin ambito para leerla, o la peticion aun en
 * marcha) o si `white_label` esta activa: en los dos casos no hay nada que
 * avisar. Se filtra por `implemented` como hace `LicenseView`: no se anuncia
 * la perdida de algo que esta version todavia no ha visto.
 */
const whiteLabelDegraded = computed(
  () =>
    licenseStore.license?.data.degraded_features.find(
      (entry) => entry.feature === 'white_label' && entry.implemented,
    ) ?? null,
)

/** Solo se pide si el token alcanza `GET /api/v1/license` (regla dura 18: cortesia, no seguridad). */
const canSeeLicense = computed(() => session.can(LICENSE_MANAGE))

function fill(catalog: InstallationSettings): void {
  settings.value = catalog
  appName.value = stringValue(catalog, 'BRANDING_APP_NAME')
  accentColor.value = stringValue(catalog, 'BRANDING_ACCENT_COLOR') || PRODUCT_ACCENT_COLOR
  logoPath.value = stringValue(catalog, 'BRANDING_LOGO_PATH')
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

onMounted(() => {
  void load()

  // La marca propia es una funcionalidad licenciable (ADR-023): sin
  // ambito de licencia no se pide -es cortesia, la autoridad real la aplica
  // el servidor en `GET /api/v1/license` (regla dura 18)-, y `license.store`
  // memoiza por sesion, asi que si `LicenseNotice` ya la cargo esta llamada
  // no repite la peticion.
  if (canSeeLicense.value) {
    void licenseStore.load()
  }
})

/** El `422` cuelga el error de `settings.<CLAVE>`, no de `<clave>` a secas. */
function serverFieldErrors(key: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[`settings.${key}`] ?? []) : []
}

/** Si lo escrito no tiene forma de color, sin llamar al servidor para saberlo. */
const accentColorLocalIssue = computed<'notAHexColor' | null>(() =>
  HEX_COLOR.test(accentColor.value) ? null : 'notAHexColor',
)

const accentColorErrors = computed<readonly string[]>(() =>
  accentColorLocalIssue.value !== null
    ? [t(`branding.errors.${accentColorLocalIssue.value}`)]
    : serverFieldErrors('BRANDING_ACCENT_COLOR'),
)

/** El selector de color exige `#rrggbb` en minusculas; con un valor invalido, enseña el del producto. */
const colorPickerValue = computed(() =>
  HEX_COLOR.test(accentColor.value) ? accentColor.value.toLowerCase() : PRODUCT_ACCENT_COLOR,
)

function onColorPicker(event: Event): void {
  accentColor.value = (event.target as HTMLInputElement).value
}

function resetAccent(): void {
  accentColor.value = PRODUCT_ACCENT_COLOR
}

/**
 * Los tonos derivados del acento que se esta ESCRIBIENDO, para la
 * previsualizacion. `null` mientras lo escrito no sea un color valido, o si
 * los tokens base del documento no se pueden leer (una prueba sin
 * `theme.css` cargado, por ejemplo): en los dos casos la previsualizacion
 * enseña el color del producto por la propia hoja de estilos, sin estilos en
 * linea que la pisen.
 */
function overridesFor(mode: 'light' | 'kiosk'): Readonly<Record<string, string>> | null {
  if (accentColorLocalIssue.value !== null) {
    return null
  }

  try {
    return accentOverrides(accentColor.value, mode, readThemeTokens(document))
  } catch {
    return null
  }
}

const lightPreview = computed(() => overridesFor('light'))
const kioskPreview = computed(() => overridesFor('kiosk'))

/** Las parejas de contraste que este acento deja de cumplir. Avisa, no bloquea. */
const warnings = computed<ContrastWarning[]>(() => {
  if (accentColorLocalIssue.value !== null) {
    return []
  }

  try {
    return contrastWarnings(accentColor.value, readThemeTokens(document))
  } catch {
    return []
  }
})

/**
 * De token de `theme.css` a clave de i18n, para no interpolar nunca el
 * ingles tecnico de `pair.use` (`themePairs.ts`) en la pantalla (correccion
 * de revision de UI/UX). Solo cubre los tokens que `accentOverrides` puede
 * llegar a sobreescribir -los unicos que `contrastWarnings` evalua-; un token
 * que no este aqui usa el respaldo generico de `foregroundLabel`/`backgroundLabel`.
 */
const FOREGROUND_LABEL_KEYS: Readonly<Record<string, string>> = {
  '--kq-color-primary': 'branding.contrastLabels.foreground.primary',
  '--kq-color-primary-strong': 'branding.contrastLabels.foreground.primaryStrong',
  '--kq-color-focus': 'branding.contrastLabels.foreground.focus',
  '--kq-color-on-primary': 'branding.contrastLabels.foreground.onPrimary',
  '--kq-color-on-primary-soft': 'branding.contrastLabels.foreground.onPrimarySoft',
  '--kq-color-kiosk-primary': 'branding.contrastLabels.foreground.kioskPrimary',
  '--kq-color-kiosk-primary-strong': 'branding.contrastLabels.foreground.kioskPrimaryStrong',
  '--kq-color-kiosk-on-primary': 'branding.contrastLabels.foreground.kioskOnPrimary',
}

/** El mismo diccionario, para el lado del fondo. */
const BACKGROUND_LABEL_KEYS: Readonly<Record<string, string>> = {
  '--kq-color-surface': 'branding.contrastLabels.background.surface',
  '--kq-color-surface-raised': 'branding.contrastLabels.background.surfaceRaised',
  '--kq-color-surface-alt': 'branding.contrastLabels.background.surfaceAlt',
  '--kq-color-primary-strong': 'branding.contrastLabels.background.primaryStrong',
  '--kq-color-primary-soft': 'branding.contrastLabels.background.primarySoft',
  '--kq-color-kiosk-surface': 'branding.contrastLabels.background.kioskSurface',
  '--kq-color-kiosk-surface-raised': 'branding.contrastLabels.background.kioskSurfaceRaised',
  '--kq-color-kiosk-primary-strong': 'branding.contrastLabels.background.kioskPrimaryStrong',
}

/** Que color es, en castellano llano. Respaldo generico si el token no esta en el diccionario. */
function foregroundLabel(token: string): string {
  const key = FOREGROUND_LABEL_KEYS[token]

  return key !== undefined ? t(key) : t('branding.contrastLabels.genericForeground')
}

/** Donde se pinta ese color, en castellano llano. Mismo respaldo que `foregroundLabel`. */
function backgroundLabel(token: string): string {
  const key = BACKGROUND_LABEL_KEYS[token]

  return key !== undefined ? t(key) : t('branding.contrastLabels.genericBackground')
}

/** El texto completo de un aviso, sin ni una palabra de `pair.use`. */
function warningMessage(warning: ContrastWarning): string {
  return t('branding.contrastWarning', {
    what: foregroundLabel(warning.pair.foreground),
    where: backgroundLabel(warning.pair.background),
    ratio: warning.ratio.toFixed(2),
    minimum: warning.minimum,
  })
}

/**
 * El acento deja de leerse como enlace o texto de marca sobre la pagina o las
 * tarjetas (`pair.foreground` es el acento fuerte, `pair.background` el
 * fondo de pagina o de tarjeta): es el aviso con mas consecuencia, porque el
 * MISMO tono pinta tambien el boton principal en solido. Se destaca aparte,
 * con mas peso (`role="alert"`) y una miniatura del boton al lado (correccion
 * de revision de UI/UX, punto 2).
 */
function isPrimaryTextInvisible(warning: ContrastWarning): boolean {
  return (
    warning.pair.foreground === '--kq-color-primary-strong' &&
    (warning.pair.background === '--kq-color-surface' ||
      warning.pair.background === '--kq-color-surface-raised')
  )
}

const criticalWarnings = computed(() => warnings.value.filter(isPrimaryTextInvisible))
const otherWarnings = computed(() =>
  warnings.value.filter((entry) => !isPrimaryTextInvisible(entry)),
)

const pendingChanges = computed<UpdateSettingsRequest['settings']>(() => {
  const current = settings.value

  if (current === null) {
    return {}
  }

  const changes: UpdateSettingsRequest['settings'] = {}
  const trimmedName = appName.value.trim()

  if (trimmedName !== stringValue(current, 'BRANDING_APP_NAME')) {
    changes['BRANDING_APP_NAME'] = trimmedName
  }

  const normalizedAccent = accentColor.value.toLowerCase()
  const currentAccent = (
    stringValue(current, 'BRANDING_ACCENT_COLOR') || PRODUCT_ACCENT_COLOR
  ).toLowerCase()

  if (accentColorLocalIssue.value === null && normalizedAccent !== currentAccent) {
    changes['BRANDING_ACCENT_COLOR'] = normalizedAccent
  }

  const trimmedLogoPath = logoPath.value.trim()

  if (trimmedLogoPath !== stringValue(current, 'BRANDING_LOGO_PATH')) {
    changes['BRANDING_LOGO_PATH'] = trimmedLogoPath
  }

  return changes
})

const hasChanges = computed(() => Object.keys(pendingChanges.value).length > 0)

const canSave = computed(
  () =>
    hasChanges.value &&
    appName.value.trim() !== '' &&
    accentColorLocalIssue.value === null &&
    !saving.value,
)

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
    announce(t('branding.saved'))
    // La cabecera y el acceso pintan `branding.store.current`: sin recargar
    // esto, seguirian enseñando la marca anterior hasta la proxima vez que
    // alguien abriera el panel.
    await branding.load()
  } catch (failure) {
    error.value = failure
    announce(t('branding.failed'))
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('branding.heading') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('branding.intro') }}</p>
    </header>

    <!-- La marca propia es licenciable (ADR-023). Persistente mientras la
         funcionalidad este degradada, y NO bloqueante: lo que se guarde aqui
         se conserva y se aplicara solo al renovar o ampliar el plan. -->
    <p
      v-if="whiteLabelDegraded !== null"
      class="max-w-3xl rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
      role="note"
      data-test="license-restriction"
    >
      {{
        t('branding.license.notice', {
          reason: t(`branding.license.reasons.${whiteLabelDegraded.restriction ?? 'unknown'}`),
        })
      }}
      <RouterLink :to="{ name: 'license' }" class="font-medium underline">{{
        t('license.notice.action')
      }}</RouterLink>
    </p>

    <LoadingPanel v-if="loading" :label="t('branding.loading')" data-test="loading" />

    <ErrorNotice v-if="error !== null" :error="error" :field-labels="fieldLabels" />

    <form
      v-if="settings !== null"
      class="flex max-w-3xl flex-col gap-6"
      novalidate
      @submit.prevent="save"
    >
      <FormField
        :label="t('branding.fields.appName')"
        :hint="t('branding.hints.appName')"
        :errors="serverFieldErrors('BRANDING_APP_NAME')"
        required
      >
        <template #default="{ id, describedBy, invalid }">
          <input
            :id="id"
            v-model="appName"
            type="text"
            maxlength="60"
            required
            data-test="app-name"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </template>
      </FormField>

      <FormField
        :label="t('branding.fields.accentColor')"
        :hint="t('branding.hints.accentColor')"
        :errors="accentColorErrors"
      >
        <template #default="{ id, describedBy, invalid }">
          <div class="flex flex-wrap items-center gap-2">
            <input
              type="color"
              :value="colorPickerValue"
              :aria-label="t('branding.fields.accentColorPicker')"
              data-test="accent-color-picker"
              class="h-10 w-10 shrink-0 cursor-pointer rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised"
              @input="onColorPicker"
            />
            <input
              :id="id"
              v-model="accentColor"
              type="text"
              inputmode="text"
              maxlength="7"
              spellcheck="false"
              data-test="accent-color-hex"
              :aria-describedby="describedBy"
              :aria-invalid="invalid"
              class="w-32 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-kq-text"
            />
            <button
              type="button"
              data-test="reset-accent"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-sm text-kq-text hover:bg-kq-surface-alt"
              @click="resetAccent"
            >
              {{ t('branding.resetAccent') }}
            </button>
          </div>
        </template>
      </FormField>

      <!-- Previsualizacion en vivo: SOLO estilos en linea sobre estos
           elementos, nunca `:root`. Mientras no se guarda, el resto del panel
           sigue con la marca ya aplicada. -->
      <div id="branding-preview" class="grid gap-4 sm:grid-cols-2" data-test="preview">
        <div class="rounded-kq border border-kq-border bg-kq-surface-raised p-4">
          <p class="mb-3 text-sm font-medium text-kq-text-muted">
            {{ t('branding.preview.lightHeading') }}
          </p>
          <div class="flex flex-wrap items-center gap-3">
            <!-- `<p>`, no `<button>`: es una muestra visual, no un control.
                 Un elemento interactivo sin accion confundiria a quien
                 navega con lector de pantalla (aparece en la lista de
                 botones de la pagina y no hace nada al activarlo). -->
            <p
              class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
              :style="
                lightPreview
                  ? {
                      backgroundColor: lightPreview['--kq-color-primary-strong'],
                      color: lightPreview['--kq-color-on-primary'],
                    }
                  : undefined
              "
            >
              {{ t('branding.preview.solidButton') }}
            </p>
            <span
              class="font-medium text-kq-primary-strong underline"
              :style="
                lightPreview ? { color: lightPreview['--kq-color-primary-strong'] } : undefined
              "
            >
              {{ t('branding.preview.link') }}
            </span>
            <span
              class="rounded-kq-sm bg-kq-primary-soft px-2 py-1 text-sm text-kq-on-primary-soft"
              :style="
                lightPreview
                  ? {
                      backgroundColor: lightPreview['--kq-color-primary-soft'],
                      color: lightPreview['--kq-color-on-primary-soft'],
                    }
                  : undefined
              "
            >
              {{ t('branding.preview.chip') }}
            </span>
          </div>
        </div>

        <div class="rounded-kq bg-kq-kiosk-surface p-4">
          <p class="mb-3 text-sm font-medium text-kq-kiosk-text-muted">
            {{ t('branding.preview.kioskHeading') }}
          </p>
          <p
            class="inline-block rounded-kq-sm bg-kq-kiosk-primary-strong px-4 py-2 font-semibold text-kq-kiosk-on-primary"
            :style="
              kioskPreview
                ? {
                    backgroundColor: kioskPreview['--kq-color-kiosk-primary-strong'],
                    color: kioskPreview['--kq-color-kiosk-on-primary'],
                  }
                : undefined
            "
          >
            {{ t('branding.preview.kioskButton') }}
          </p>
        </div>
      </div>

      <div
        v-if="warnings.length > 0"
        class="flex max-w-3xl flex-col gap-3"
        data-test="contrast-warnings"
      >
        <div>
          <p class="font-medium text-kq-text">{{ t('branding.contrastWarningsHeading') }}</p>
          <!-- Que significa el numero, antes de enseñar ninguno: sin esto,
               «3,20:1, hace falta 4,5:1» no dice nada a quien no sabe que la
               escala es peor cuanto mas bajo el primer numero. -->
          <p class="text-sm text-kq-text-muted">{{ t('branding.contrastRatioExplainer') }}</p>
        </div>

        <!-- El acento deja de leerse como enlace, texto de marca Y boton
             principal: el mismo tono los pinta a los tres. Mas peso que el
             resto de avisos (`role="alert"`, color de peligro) y una
             miniatura de como quedaria el boton, para verlo sin bajar a
             buscar la previsualizacion (correccion de revision de UI/UX). -->
        <div
          v-if="criticalWarnings.length > 0"
          role="alert"
          class="rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
          data-test="contrast-warnings-critical"
        >
          <div class="flex items-start gap-3">
            <p
              aria-hidden="true"
              class="shrink-0 rounded-kq-sm bg-kq-primary-strong px-3 py-1.5 text-sm font-semibold text-kq-on-primary"
              :style="
                lightPreview
                  ? {
                      backgroundColor: lightPreview['--kq-color-primary-strong'],
                      color: lightPreview['--kq-color-on-primary'],
                    }
                  : undefined
              "
            >
              {{ t('branding.preview.solidButton') }}
            </p>
            <div>
              <p class="font-medium">{{ t('branding.contrastCriticalHeading') }}</p>
              <ul class="mt-2 list-disc pl-5">
                <li v-for="(warning, index) of criticalWarnings" :key="index">
                  {{ warningMessage(warning) }}
                </li>
              </ul>
              <p class="mt-2 text-sm">
                <a href="#branding-preview" class="font-medium underline">{{
                  t('branding.contrastPreviewLink')
                }}</a>
              </p>
            </div>
          </div>
        </div>

        <div
          v-if="otherWarnings.length > 0"
          role="note"
          class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
          data-test="contrast-warnings-other"
        >
          <ul class="list-disc pl-5">
            <li v-for="(warning, index) of otherWarnings" :key="index">
              {{ warningMessage(warning) }}
            </li>
          </ul>
        </div>

        <p class="text-sm text-kq-text-muted">{{ t('branding.contrastWarningsHint') }}</p>
      </div>

      <FormField
        :label="t('branding.fields.logoPath')"
        :hint="t('branding.hints.logoPath')"
        :errors="serverFieldErrors('BRANDING_LOGO_PATH')"
      >
        <template #default="{ id, describedBy, invalid }">
          <input
            :id="id"
            v-model="logoPath"
            type="text"
            autocomplete="off"
            spellcheck="false"
            data-test="logo-path"
            :aria-describedby="describedBy"
            :aria-invalid="invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-kq-text"
          />
        </template>
      </FormField>

      <div class="flex items-center gap-3">
        <button
          type="submit"
          :disabled="!canSave"
          data-test="save"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-50"
        >
          {{ t('branding.save') }}
        </button>
        <p v-if="saving" class="text-kq-text-muted">{{ t('branding.saving') }}</p>
        <p v-else-if="saved && !hasChanges" class="text-kq-success" role="status" data-test="saved">
          {{ t('branding.saved') }}
        </p>
      </div>
    </form>
  </section>
</template>
