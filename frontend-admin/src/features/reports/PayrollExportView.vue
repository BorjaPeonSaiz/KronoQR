<script setup lang="ts">
// Salida a nomina (RF-IN-07, tarea 3.9): horas por periodo y por empleado, en
// el formato que configura «Ajustes operativos» (`PAYROLL_EXPORT_*`).
//
// **AQUI NO SE CALCULA NI SE MAPEA NINGUNA COLUMNA.** El fichero lo escribe el
// servidor con la plantilla que ya tiene guardada; esta pantalla solo enseña
// una PREVISUALIZACION de esa plantilla -las columnas y sus etiquetas, leidas
// del mismo catalogo que `OperationalSettingsView`- para que quien va a
// descargar sepa que va a recibir antes de pulsar, con un enlace a donde se
// cambia si no es lo que espera.
//
// **DOS CAMINOS DE SALIDA, LOS DOS DEL MISMO INFORME** (decision 5 de la
// ficha): «Descargar» es sincrono (`GET /reports/payroll-export`, como
// `periodReportExport.api.ts`); «Generar en segundo plano» pide la MISMA
// consulta a `POST /reports/exports` con `kind: payroll` y aparece en el
// bloque de exportaciones de «Informes» (`ReportExportsPanel`, no duplicado
// aqui: las dos pantallas comparten el mismo ciclo de vida de exportacion).
//
// **LICENCIA ACCESORIA (regla dura 15, ADR-019).** `payroll_export` es la
// primera funcionalidad que consume `Feature::PayrollExport` (decision 6 de
// la ficha): un `402` de cualquiera de los dos caminos se explica en la propia
// pantalla, sin necesidad del ambito `license:*` que exige `LicenseNotice` -
// RRHH normalmente no lo lleva (doc 02 §7.3) y aun asi tiene que enterarse de
// por que no puede exportar.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { isApiError } from '@kronoqr/web-kit/http'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { listDepartments } from '@/shared/api/organisation.api'
import type {
  InstallationSettings,
  PayrollExportFormat,
  PayrollExportGranularity,
  ReportExport,
  ReportExportCollection,
} from '@/shared/api/types'
import { fetchInstallationSettings, settingValue } from '@/features/settings/settings.api'
import { downloadPayrollExport, type PayrollExportQuery } from './payrollExport.api'
import {
  reportExceedsSynchronousBudget,
  requestReportExport,
  REPORT_EXPORTS_QUERY_KEY,
} from './reportExports.api'

const GRANULARITIES: readonly PayrollExportGranularity[] = ['range', 'month', 'day']
const FORMATS: readonly PayrollExportFormat[] = ['csv', 'xlsx']

/**
 * El catalogo cerrado de columnas (decision 5 de la ficha): identico al que
 * declara `PAYROLL_EXPORT_COLUMNS` en el backend. Vive aqui SOLO para poner
 * una etiqueta legible por omision a cada `id` en la previsualizacion —el
 * backend es quien de verdad valida que un `id` desconocido es `422`, esta
 * lista no sustituye esa validacion, es la misma idea que
 * `operationalSettings.hints.*` no copia un rango que ya publica el servidor.
 */
const COLUMN_LABEL_KEYS: Readonly<Record<string, string>> = {
  employee_code: 'payrollExport.columns.employee_code',
  employee_uuid: 'payrollExport.columns.employee_uuid',
  last_name: 'payrollExport.columns.last_name',
  first_name: 'payrollExport.columns.first_name',
  full_name: 'payrollExport.columns.full_name',
  department: 'payrollExport.columns.department',
  period_from: 'payrollExport.columns.period_from',
  period_to: 'payrollExport.columns.period_to',
  days_in_period: 'payrollExport.columns.days_in_period',
  days_with_activity: 'payrollExport.columns.days_with_activity',
  shift_count: 'payrollExport.columns.shift_count',
  worked_hours: 'payrollExport.columns.worked_hours',
  contracted_hours: 'payrollExport.columns.contracted_hours',
  deviation_hours: 'payrollExport.columns.deviation_hours',
  overtime_hours: 'payrollExport.columns.overtime_hours',
  absence_days: 'payrollExport.columns.absence_days',
  holiday_days: 'payrollExport.columns.holiday_days',
  unjustified_absence_days: 'payrollExport.columns.unjustified_absence_days',
  days_without_contract: 'payrollExport.columns.days_without_contract',
  time_zone: 'payrollExport.columns.time_zone',
}

/**
 * El separador entre el `id` y la etiqueta de una entrada de
 * `PAYROLL_EXPORT_COLUMNS` (`id=Etiqueta`). Se declara UNA sola vez y no en
 * cada `indexOf`/`slice`: es el mismo caracter que fija
 * `PayrollLayout::LABEL_SEPARATOR` en el backend, y las dos partes tienen que
 * seguir de acuerdo si algun dia cambia.
 */
const LABEL_SEPARATOR = '='

const { t } = useI18n()
const queryClient = useQueryClient()

const { data: departments } = useQuery({
  queryKey: ['departments'] as const,
  queryFn: listDepartments,
})
const departmentOptions = computed(() => departments.value?.data ?? [])

const {
  data: settings,
  isPending: settingsLoading,
  error: settingsError,
} = useQuery({
  queryKey: ['installation-settings'] as const,
  queryFn: fetchInstallationSettings,
  // `staleTime: 0` A PROPOSITO, por encima de los 30 s de serie
  // (`queryClient.ts`): esta previsualizacion existe para que nadie descargue
  // a ciegas, y «Ajustes operativos» no invalida esta clave al guardar (son
  // dos pantallas independientes). Sin esto, volver aqui tras cambiar el
  // formato en la otra pestaña seguiria enseñando la plantilla vieja hasta
  // que expirara la cache, que es exactamente el caso que esta pantalla
  // existe para evitar.
  staleTime: 0,
})

/** Una entrada `id` o `id=Etiqueta` de `PAYROLL_EXPORT_COLUMNS`, resuelta a `{ id, label }`. */
interface PreviewColumn {
  id: string
  label: string
}

function parseColumnEntry(entry: string): PreviewColumn {
  const separatorIndex = entry.indexOf(LABEL_SEPARATOR)

  if (separatorIndex === -1) {
    const key = COLUMN_LABEL_KEYS[entry]

    return { id: entry, label: key === undefined ? entry : t(key) }
  }

  return { id: entry.slice(0, separatorIndex), label: entry.slice(separatorIndex + 1) }
}

/**
 * Las columnas TAL CUAL las publica `GET /api/v1/settings` en
 * `PAYROLL_EXPORT_COLUMNS.value`: el catalogo siempre trae una fila para esta
 * clave -con `source: product_default` y la plantilla de serie de la
 * decision 5 de la ficha cuando la instalacion no ha guardado la suya-, asi
 * que no hace falta duplicar aqui esos diez valores de fabrica. Antes de que
 * la consulta resuelva (`catalog === undefined`) no hay nada que previsualizar
 * todavia; `settingsLoading`/`LoadingPanel` ya cubren ese hueco en la plantilla.
 */
function columnsOf(catalog: InstallationSettings | undefined): readonly string[] {
  if (catalog === undefined) {
    return []
  }

  const value = settingValue(catalog, 'PAYROLL_EXPORT_COLUMNS')

  return Array.isArray(value) ? value : []
}

const previewColumns = computed<PreviewColumn[]>(() =>
  columnsOf(settings.value).map(parseColumnEntry),
)

const delimiterLabel = computed(() => {
  const catalog = settings.value
  const value = catalog === undefined ? null : settingValue(catalog, 'PAYROLL_EXPORT_DELIMITER')
  const code = typeof value === 'string' ? value : 'semicolon'

  return t(`payrollExport.delimiter.${code}`)
})

const hoursFormatLabel = computed(() => {
  const catalog = settings.value
  const value = catalog === undefined ? null : settingValue(catalog, 'PAYROLL_EXPORT_HOURS_FORMAT')
  const code = typeof value === 'string' ? value : 'hhmm'

  return t(`payrollExport.hoursFormat.${code}`)
})

// --- El formulario ------------------------------------------------------------

const from = ref('')
const to = ref('')
const granularity = ref<PayrollExportGranularity>('range')
const departmentFilter = ref<number | ''>('')
const employeeUuid = ref('')
const format = ref<PayrollExportFormat>('csv')

const canSubmit = computed(() => from.value !== '' && to.value !== '')

function currentQuery(): PayrollExportQuery {
  return {
    from: from.value,
    to: to.value,
    granularity: granularity.value,
    ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
    ...(employeeUuid.value.trim() === '' ? {} : { employeeUuid: employeeUuid.value.trim() }),
  }
}

/** `true` si el fallo es un `402`: `payroll_export` no esta en el plan contratado (regla dura 15, ADR-019). */
function isLicenseRequired(error: unknown): boolean {
  return isApiError(error) && error.status === 402
}

// --- Descarga sincrona ---------------------------------------------------------

const downloading = ref(false)
const downloadError = ref<unknown>(null)
const oversizedQuery = ref<PayrollExportQuery | null>(null)
/** Los criterios de la ULTIMA descarga (contrato, `X-Kronoqr-Export-Criteria`), tal cual llegan: no se reordenan ni se resumen. */
const lastDownloadCriteria = ref<readonly string[]>([])

async function download(): Promise<void> {
  if (!canSubmit.value || downloading.value) {
    return
  }

  const query = currentQuery()

  downloading.value = true
  downloadError.value = null
  oversizedQuery.value = null
  lastDownloadCriteria.value = []

  try {
    const downloaded = await downloadPayrollExport(query, format.value)

    downloadDocument(downloaded.document)
    lastDownloadCriteria.value = downloaded.criteria
    announce(t('payrollExport.downloaded'))
  } catch (caught) {
    downloadError.value = caught
    announce(t('payrollExport.downloadFailed'))

    if (reportExceedsSynchronousBudget(caught)) {
      oversizedQuery.value = query
    }
  } finally {
    downloading.value = false
  }
}

// --- Generacion en segundo plano (RF-IN-06 aplicado a la nomina) -------------

const backgroundRequesting = ref(false)
const backgroundError = ref<unknown>(null)
const backgroundRequested = ref<ReportExport | null>(null)

async function requestBackgroundGeneration(): Promise<void> {
  const query = oversizedQuery.value ?? (canSubmit.value ? currentQuery() : null)

  if (query === null || backgroundRequesting.value) {
    return
  }

  backgroundRequesting.value = true
  backgroundError.value = null

  try {
    const resource = await requestReportExport({
      kind: 'payroll',
      format: format.value,
      from: query.from,
      to: query.to,
      // `PayrollExportGranularity` ('range'|'month'|'day') es un subconjunto
      // de `ReportGranularity`: siempre cabe, y `?? 'range'` cubre el caso en
      // que `query` venga de `oversizedQuery` sin granularidad explicita.
      granularity: query.granularity ?? 'range',
      ...(query.departmentId === undefined ? {} : { departmentId: query.departmentId }),
      ...(query.employeeUuid === undefined ? {} : { employeeUuid: query.employeeUuid }),
    })

    backgroundRequested.value = resource.data

    queryClient.setQueryData<ReportExportCollection>(REPORT_EXPORTS_QUERY_KEY, (previous) => {
      const rest = (previous?.data ?? []).filter((row) => row.uuid !== resource.data.uuid)

      return { data: [resource.data, ...rest].slice(0, 20) }
    })

    announce(t('payrollExport.backgroundRequested'))
  } catch (failure) {
    backgroundError.value = failure
  } finally {
    backgroundRequesting.value = false
  }
}

const fieldClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('payrollExport.title') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('payrollExport.subtitle') }}</p>
    </header>

    <p
      v-if="isLicenseRequired(downloadError)"
      class="max-w-3xl rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
      data-test="license-required-notice"
    >
      {{ t('payrollExport.licenseRequired') }}
      <RouterLink :to="{ name: 'license' }" class="font-medium underline">
        {{ t('license.notice.action') }}
      </RouterLink>
    </p>

    <form class="flex max-w-3xl flex-wrap items-end gap-4" novalidate @submit.prevent="download">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('payrollExport.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="payroll-from" class="font-medium">{{
            t('payrollExport.filters.from')
          }}</label>
          <input id="payroll-from" v-model="from" type="date" required :class="fieldClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="payroll-to" class="font-medium">{{ t('payrollExport.filters.to') }}</label>
          <input id="payroll-to" v-model="to" type="date" required :class="fieldClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="payroll-granularity" class="font-medium">
            {{ t('payrollExport.filters.granularity') }}
          </label>
          <select id="payroll-granularity" v-model="granularity" :class="fieldClass">
            <option v-for="value of GRANULARITIES" :key="value" :value="value">
              {{ t(`payrollExport.granularity.${value}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="payroll-department" class="font-medium">
            {{ t('reports.period.filters.department') }}
          </label>
          <select id="payroll-department" v-model="departmentFilter" :class="fieldClass">
            <option value="">{{ t('reports.period.filters.departmentAll') }}</option>
            <option
              v-for="department of departmentOptions"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="payroll-employee" class="font-medium">{{
            t('payrollExport.filters.employee')
          }}</label>
          <input
            id="payroll-employee"
            v-model="employeeUuid"
            type="text"
            inputmode="text"
            autocomplete="off"
            class="w-64 font-mono"
            :class="fieldClass"
          />
        </div>

        <div class="flex flex-col gap-1">
          <label for="payroll-format" class="font-medium">{{
            t('reports.period.export.label')
          }}</label>
          <select id="payroll-format" v-model="format" :class="fieldClass">
            <option v-for="value of FORMATS" :key="value" :value="value">
              {{ t(`reports.period.export.format.${value}`) }}
            </option>
          </select>
        </div>
      </fieldset>

      <div class="flex flex-wrap gap-3">
        <button
          type="submit"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
          :disabled="!canSubmit || downloading"
          data-test="payroll-download"
        >
          {{ downloading ? t('payrollExport.downloading') : t('payrollExport.download') }}
        </button>

        <button
          type="button"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
          :disabled="!canSubmit || backgroundRequesting || backgroundRequested !== null"
          data-test="payroll-background"
          @click="requestBackgroundGeneration"
        >
          {{
            backgroundRequesting
              ? t('reports.period.background.requesting')
              : t('reports.period.background.generate')
          }}
        </button>
      </div>
    </form>

    <ErrorNotice
      v-if="downloadError !== null && !isLicenseRequired(downloadError)"
      :error="downloadError"
      data-test="download-error"
    />

    <!-- Los criterios de la ultima descarga (contrato,
         `X-Kronoqr-Export-Criteria`): no viajan dentro del fichero -una fila
         de comentario rompe la importacion de la nomina-, asi que se enseñan
         aqui, tal cual llegan. -->
    <section
      v-if="lastDownloadCriteria.length > 0"
      class="max-w-3xl rounded-kq border border-kq-border bg-kq-surface-alt p-4"
      data-test="payroll-criteria"
    >
      <h2 class="font-heading text-lg font-bold">{{ t('reports.period.criteria.title') }}</h2>
      <ul class="mt-2 list-disc space-y-1 pl-5">
        <li v-for="(criterion, index) of lastDownloadCriteria" :key="index">{{ criterion }}</li>
      </ul>
    </section>

    <section
      v-if="oversizedQuery !== null"
      class="max-w-3xl rounded-kq border border-kq-border bg-kq-surface-alt p-4"
      data-test="oversized-offer"
    >
      {{ t('reports.period.background.offer') }}
    </section>

    <ErrorNotice
      v-if="backgroundError !== null && !isLicenseRequired(backgroundError)"
      :error="backgroundError"
      data-test="background-error"
    />

    <p
      v-if="isLicenseRequired(backgroundError)"
      class="max-w-3xl rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
      data-test="license-required-background"
    >
      {{ t('payrollExport.licenseRequired') }}
      <RouterLink :to="{ name: 'license' }" class="font-medium underline">
        {{ t('license.notice.action') }}
      </RouterLink>
    </p>

    <p v-if="backgroundRequested !== null" role="status" data-test="background-requested">
      {{ t('reports.period.background.requested') }}
      <RouterLink :to="{ name: 'reports', hash: '#report-exports' }" class="font-medium underline">
        {{ t('reports.period.background.seeExports') }}
      </RouterLink>
    </p>

    <section
      class="max-w-3xl rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    >
      <h2 class="font-heading text-lg font-bold">{{ t('payrollExport.preview.heading') }}</h2>
      <p class="mt-1 text-sm text-kq-text-muted">
        {{
          t('payrollExport.preview.intro', { delimiter: delimiterLabel, hours: hoursFormatLabel })
        }}
      </p>

      <LoadingPanel
        v-if="settingsLoading"
        :label="t('payrollExport.preview.loading')"
        class="mt-3"
      />
      <ErrorNotice v-else-if="settingsError !== null" :error="settingsError" class="mt-3" />

      <ol v-else class="mt-3 flex flex-wrap gap-2" data-test="payroll-columns-preview">
        <li
          v-for="column of previewColumns"
          :key="column.id"
          class="rounded-full border border-kq-border-strong px-3 py-1 text-sm text-kq-text"
        >
          {{ column.label }}
        </li>
      </ol>

      <RouterLink
        :to="{ name: 'operational-settings' }"
        class="mt-3 inline-block text-sm font-medium underline"
      >
        {{ t('payrollExport.preview.editLink') }}
      </RouterLink>
    </section>
  </section>
</template>
