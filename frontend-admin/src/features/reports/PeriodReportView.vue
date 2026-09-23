<script setup lang="ts">
// Consulta de informes de horas por periodo (RF-IN-01, RF-IN-02, RF-IN-03).
//
// PANTALLA MINIMA A PROPOSITO: formulario de periodo, granularidad y agrupacion,
// tabla de resultados y criterios a la vista. El cuadro de impacto y las
// comparaciones visuales son de la tarea 3.13, que tiene agente propio y depende
// de indicadores que esta tarea todavia no calcula (plan de la 2.8, artefactos).
//
// LOS CRITERIOS DE INCLUSION SE ENSEÑAN TAL CUAL LOS DA EL SERVIDOR, sin
// reordenarlos ni resumirlos. Son parte del informe: sin ellos, la tabla es un
// conjunto de numeros que cada persona interpreta a su manera, y esa
// interpretacion acaba discutiendose en una reunion de nomina.
//
// NADA SE CALCULA AQUI (regla dura 7). Los minutos, el `HH:MM`, la desviacion y
// el exceso vienen del servidor, que es el unico que lee la proyeccion.
//
// EL INFORME NO SE PIDE SOLO AL ABRIR LA PANTALLA. Es una consulta cara —cruza
// la plantilla con el calendario— y quien entra todavia no ha elegido periodo:
// generarlo con un rango inventado gastaria la base de datos que atiende el
// fichaje (RNF-P-02) para dar una cifra que nadie ha pedido.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listDepartments } from '@/shared/api/organisation.api'
import type {
  PeriodReport,
  ReportExport,
  ReportExportCollection,
  ReportGranularity,
  ReportGrouping,
} from '@/shared/api/types'
import PeriodReportTable from './PeriodReportTable.vue'
import { generatePeriodReport, type PeriodReportQuery } from './periodReport.api'
import { downloadPeriodReport, type PeriodReportFormat } from './periodReportExport.api'
import ReportExportsPanel from './ReportExportsPanel.vue'
import {
  reportExceedsSynchronousBudget,
  requestReportExport,
  REPORT_EXPORTS_QUERY_KEY,
} from './reportExports.api'

const GRANULARITIES: readonly ReportGranularity[] = ['day', 'week', 'month', 'range']
const GROUPINGS: readonly ReportGrouping[] = ['employee', 'department', 'site']
const FORMATS: readonly PeriodReportFormat[] = ['csv', 'xlsx', 'pdf']

const { t, locale } = useI18n()

// Como se llama cada campo de la consulta EN ESTA PANTALLA, para que un `422`
// diga «Hasta: …» y no «to: …». El servidor ya manda el mensaje en el idioma de
// la persona; el nombre del campo tal y como lo ve es cosa de la vista.
const fieldLabels = computed<Readonly<Record<string, string>>>(() => ({
  from: t('reports.period.filters.from'),
  to: t('reports.period.filters.to'),
  granularity: t('reports.period.filters.granularity'),
  group_by: t('reports.period.filters.groupBy'),
  department_id: t('reports.period.filters.department'),
  include_open_shifts: t('reports.period.filters.openShifts'),
}))

const { data: departments } = useQuery({
  queryKey: ['departments'] as const,
  queryFn: listDepartments,
})
const departmentOptions = computed(() => departments.value?.data ?? [])

const from = ref('')
const to = ref('')
const granularity = ref<ReportGranularity>('month')
const grouping = ref<ReportGrouping>('employee')
const departmentFilter = ref<number | ''>('')
const includeOpenShifts = ref(false)

const report = ref<PeriodReport | null>(null)
const loading = ref(false)
const error = ref<unknown>(null)

/**
 * El instante de generacion, resuelto en la zona del centro (regla dura 3):
 * NUNCA se enseña el ISO/UTC crudo, que es la hora de nadie (`datetime.ts`,
 * mismo patron que `LivePresenceView.vue`).
 */
const generatedAtLabel = computed(() =>
  report.value === null
    ? ''
    : formatInstant(report.value.meta.generated_at, report.value.meta.time_zone, locale.value),
)

/**
 * La consulta **que produjo el informe que hay en pantalla**, no la del
 * formulario.
 *
 * La diferencia importa: si alguien cambia el mes en el formulario y despues
 * pulsa «Descargar», el fichero tiene que ser el del informe que esta viendo, no
 * el de un periodo que todavia no ha consultado. Se congela al generar.
 */
const generatedQuery = ref<PeriodReportQuery | null>(null)

/** Formato que se esta descargando ahora mismo, o `null` si ninguno. */
const downloading = ref<PeriodReportFormat | null>(null)
const downloadError = ref<unknown>(null)

const queryClient = useQueryClient()

/**
 * El periodo que acaba de resultar demasiado grande para una respuesta
 * sincrona (RF-IN-06, `422` de `ReportTooLargeForSynchronousDelivery`), tanto
 * al generar la tabla como al descargarla. `null` mientras no haya pasado.
 */
const oversizedQuery = ref<PeriodReportQuery | null>(null)
/**
 * El formato con el que se intento la descarga que resulto demasiado grande.
 * `null` cuando lo que fallo fue la CONSULTA (no hay tabla que ver: el
 * formulario no elige un formato de fichero, asi que hay que preguntarlo antes
 * de pedir la generacion en diferido).
 */
const oversizedFixedFormat = ref<PeriodReportFormat | null>(null)
/** El formato elegido para la generacion en diferido cuando lo fallado fue la consulta, no la descarga. */
const backgroundFormatChoice = ref<PeriodReportFormat>('csv')
const backgroundRequesting = ref(false)
const backgroundError = ref<unknown>(null)
const backgroundRequested = ref<ReportExport | null>(null)

const backgroundFormat = computed<PeriodReportFormat>(
  () => oversizedFixedFormat.value ?? backgroundFormatChoice.value,
)

/** Sin las dos fechas no hay informe: el servidor las exige y el boton tambien. */
const canSubmit = computed(() => from.value !== '' && to.value !== '' && !loading.value)

/**
 * Pide la MISMA consulta que acaba de resultar demasiado grande, pero en
 * segundo plano (RF-IN-06): el servidor la ejecuta sin los presupuestos
 * sincronos y avisa cuando el fichero esta listo (`ReportExportsPanel`, mas
 * abajo en esta misma pantalla).
 */
async function requestBackgroundGeneration(): Promise<void> {
  const query = oversizedQuery.value

  if (query === null || backgroundRequesting.value) {
    return
  }

  backgroundRequesting.value = true
  backgroundError.value = null

  try {
    const resource = await requestReportExport({
      ...query,
      kind: 'period',
      format: backgroundFormat.value,
    })

    backgroundRequested.value = resource.data

    // Se enseña en el bloque de exportaciones EN EL ACTO, sin esperar al
    // primer sondeo de `ReportExportsPanel` (mismo patron que
    // `DataExportPanel.confirmRequest`, tarea 5.10): tanto si es la fila
    // recien creada como si es la que ya estaba en curso (409, decision 2 de
    // la ficha), es la misma forma y el mismo sitio en la lista.
    queryClient.setQueryData<ReportExportCollection>(REPORT_EXPORTS_QUERY_KEY, (previous) => {
      const rest = (previous?.data ?? []).filter((row) => row.uuid !== resource.data.uuid)

      return { data: [resource.data, ...rest].slice(0, 20) }
    })

    announce(t('reports.period.background.requested'))
  } catch (failure) {
    backgroundError.value = failure
  } finally {
    backgroundRequesting.value = false
  }
}

function currentQuery(): PeriodReportQuery {
  return {
    from: from.value,
    to: to.value,
    granularity: granularity.value,
    groupBy: grouping.value,
    ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
    includeOpenShifts: includeOpenShifts.value,
  }
}

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  const query = currentQuery()

  loading.value = true
  error.value = null
  downloadError.value = null
  // Un nuevo intento retira la oferta de generacion en diferido anterior: si
  // este periodo si cabe en el acto, la oferta de la vez pasada ya no pinta
  // nada.
  oversizedQuery.value = null
  oversizedFixedFormat.value = null
  backgroundRequested.value = null
  backgroundError.value = null

  try {
    report.value = await generatePeriodReport(query)
    generatedQuery.value = query

    announce(t('reports.period.announce.results', { count: report.value.meta.row_count }))
  } catch (caught) {
    // El informe anterior se retira: dejarlo en pantalla junto a un error haria
    // creer que las cifras siguen valiendo para el periodo que se acaba de
    // pedir, y no valen para ninguno.
    report.value = null
    generatedQuery.value = null
    error.value = caught

    // RF-IN-06: la consulta no cabe en una respuesta sincrona. La tabla no
    // tiene formato de fichero propio, asi que aqui se pregunta cual (por
    // omision CSV) antes de poder pedir la generacion en diferido.
    if (reportExceedsSynchronousBudget(caught)) {
      oversizedQuery.value = query
      oversizedFixedFormat.value = null
    }
  } finally {
    loading.value = false
  }
}

/**
 * Descarga el informe **que ya esta en pantalla** en el formato pedido.
 *
 * El fichero se pide con `fetch` y el token de la sesion —nunca con un enlace
 * suelto, que iria sin `Authorization`— y se suelta en el mismo momento: es una
 * lista nominal con las horas de la plantilla, y no tiene por que quedarse viva
 * en el navegador.
 */
async function download(format: PeriodReportFormat): Promise<void> {
  const query = generatedQuery.value

  if (query === null || downloading.value !== null) {
    return
  }

  downloading.value = format
  downloadError.value = null
  oversizedQuery.value = null
  oversizedFixedFormat.value = null
  backgroundRequested.value = null
  backgroundError.value = null

  try {
    const downloaded = await downloadPeriodReport(query, format)

    downloadDocument(downloaded.document)
    announce(t('reports.period.export.done', { format: format.toUpperCase() }))
  } catch (caught) {
    downloadError.value = caught
    announce(t('reports.period.export.failed'))

    // RF-IN-06: aqui SI hay un formato -es el que se acaba de pulsar-, asi
    // que la oferta de generacion en diferido no pregunta nada: reusa el
    // mismo.
    if (reportExceedsSynchronousBudget(caught)) {
      oversizedQuery.value = query
      oversizedFixedFormat.value = format
    }
  } finally {
    downloading.value = null
  }
}

const fieldClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div>
      <h1 class="text-2xl font-bold">{{ t('reports.period.title') }}</h1>
      <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('reports.period.subtitle') }}</p>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" @submit.prevent="submit">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('reports.period.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="report-from" class="font-medium">{{
            t('reports.period.filters.from')
          }}</label>
          <input id="report-from" v-model="from" type="date" required :class="fieldClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="report-to" class="font-medium">{{ t('reports.period.filters.to') }}</label>
          <input id="report-to" v-model="to" type="date" required :class="fieldClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="report-granularity" class="font-medium">
            {{ t('reports.period.filters.granularity') }}
          </label>
          <select id="report-granularity" v-model="granularity" :class="fieldClass">
            <option v-for="value of GRANULARITIES" :key="value" :value="value">
              {{ t(`reports.period.granularity.${value}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="report-group-by" class="font-medium">
            {{ t('reports.period.filters.groupBy') }}
          </label>
          <select id="report-group-by" v-model="grouping" :class="fieldClass">
            <option v-for="value of GROUPINGS" :key="value" :value="value">
              {{ t(`reports.period.grouping.${value}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="report-department" class="font-medium">
            {{ t('reports.period.filters.department') }}
          </label>
          <select id="report-department" v-model="departmentFilter" :class="fieldClass">
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

        <div class="flex items-center gap-2">
          <input
            id="report-open-shifts"
            v-model="includeOpenShifts"
            type="checkbox"
            class="size-4 rounded-kq-sm border border-kq-border-strong"
          />
          <label for="report-open-shifts">{{ t('reports.period.filters.openShifts') }}</label>
        </div>
      </fieldset>

      <button
        type="submit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
        :disabled="!canSubmit"
        data-test="generate-report"
      >
        {{ t('reports.period.generate') }}
      </button>
    </form>

    <LoadingPanel v-if="loading" :label="t('reports.period.loading')" class="mt-4" />

    <template v-else-if="error !== null">
      <ErrorNotice :error="error" :field-labels="fieldLabels" class="mt-4" />

      <!-- RF-IN-06: el periodo pedido no cabe en una respuesta sincrona. La
           tabla no tiene formato de fichero propio, asi que aqui se pregunta
           cual antes de poder pedir la generacion en diferido. -->
      <section
        v-if="oversizedQuery !== null"
        class="mt-3 flex flex-col gap-3 rounded-kq border border-kq-border bg-kq-surface-alt p-4"
        data-test="oversized-offer"
      >
        <p>{{ t('reports.period.background.offer') }}</p>

        <div class="flex flex-wrap items-end gap-3">
          <div class="flex flex-col gap-1">
            <label for="background-format" class="font-medium">
              {{ t('reports.period.export.label') }}
            </label>
            <select
              id="background-format"
              v-model="backgroundFormatChoice"
              :class="fieldClass"
              data-test="background-format"
            >
              <option v-for="format of FORMATS" :key="format" :value="format">
                {{ t(`reports.period.export.format.${format}`) }}
              </option>
            </select>
          </div>

          <button
            type="button"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
            :disabled="backgroundRequesting || backgroundRequested !== null"
            data-test="request-background"
            @click="requestBackgroundGeneration"
          >
            {{
              backgroundRequesting
                ? t('reports.period.background.requesting')
                : t('reports.period.background.generate')
            }}
          </button>
        </div>

        <ErrorNotice v-if="backgroundError !== null" :error="backgroundError" />

        <p v-if="backgroundRequested !== null" role="status" data-test="background-requested">
          {{ t('reports.period.background.requested') }}
          <a href="#report-exports" class="font-medium underline">
            {{ t('reports.period.background.seeExports') }}
          </a>
        </p>
      </section>
    </template>

    <template v-else-if="report !== null">
      <!-- El aviso de cobertura va ANTES de la tabla y no en una nota al pie:
           un informe comparado contra un contrato que no existe sale con una
           desviacion enorme y con aspecto de dato bueno. -->
      <p
        v-if="!report.meta.contract_coverage.complete"
        class="mt-4 rounded-kq border border-kq-border bg-kq-surface-alt p-3"
        data-test="contract-coverage-warning"
      >
        {{
          t('reports.period.contractCoverage', {
            days: report.meta.contract_coverage.days_without_contract,
            employees: report.meta.contract_coverage.employees_without_contract,
          })
        }}
      </p>

      <!-- La descarga va SOBRE EL INFORME YA CONSULTADO y con su misma consulta
           (RF-IN-04): no se ofrece antes de generarlo, porque no habria nada
           que descargar, y no vuelve a leer el formulario, porque el fichero
           tiene que ser el del informe que se esta viendo. -->
      <section class="mt-4 flex flex-wrap items-center gap-3" data-test="report-export">
        <span class="font-medium">{{ t('reports.period.export.label') }}</span>
        <button
          v-for="format of FORMATS"
          :key="format"
          type="button"
          class="rounded-kq-sm border border-kq-border-strong px-3 py-1.5 text-kq-text hover:bg-kq-surface-alt disabled:opacity-50"
          :disabled="downloading !== null"
          :data-test="`export-${format}`"
          @click="download(format)"
        >
          {{ t(`reports.period.export.format.${format}`) }}
        </button>
        <p v-if="downloading !== null" class="text-kq-text-muted" data-test="export-running">
          {{ t('reports.period.export.running', { format: downloading.toUpperCase() }) }}
        </p>
      </section>

      <ErrorNotice
        v-if="downloadError !== null"
        :error="downloadError"
        :field-labels="fieldLabels"
        class="mt-3"
      />

      <!-- RF-IN-06: la descarga que se acaba de pedir no cabe en el acto.
           Aqui SI hay un formato -es el que se acaba de pulsar-, asi que la
           oferta reusa el mismo sin preguntar de nuevo. -->
      <section
        v-if="oversizedQuery !== null && oversizedFixedFormat !== null"
        class="mt-3 flex flex-col gap-3 rounded-kq border border-kq-border bg-kq-surface-alt p-4"
        data-test="oversized-offer"
      >
        <p>{{ t('reports.period.background.offer') }}</p>

        <div>
          <button
            type="button"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
            :disabled="backgroundRequesting || backgroundRequested !== null"
            data-test="request-background"
            @click="requestBackgroundGeneration"
          >
            {{
              backgroundRequesting
                ? t('reports.period.background.requesting')
                : t('reports.period.background.generate')
            }}
          </button>
        </div>

        <ErrorNotice v-if="backgroundError !== null" :error="backgroundError" />

        <p v-if="backgroundRequested !== null" role="status" data-test="background-requested">
          {{ t('reports.period.background.requested') }}
          <a href="#report-exports" class="font-medium underline">
            {{ t('reports.period.background.seeExports') }}
          </a>
        </p>
      </section>

      <EmptyState
        v-if="report.data.length === 0"
        class="mt-4"
        :title="t('reports.period.empty.title')"
        :description="t('reports.period.empty.description')"
      />

      <div v-else class="mt-4 overflow-x-auto">
        <PeriodReportTable :rows="report.data" :time-zone="report.meta.time_zone" />
      </div>

      <!-- Los criterios, tal cual los da el servidor. No se reordenan ni se
           resumen: son parte del informe (`/informe-nuevo`, paso 1). -->
      <section class="mt-6 rounded-kq border border-kq-border bg-kq-surface-alt p-4">
        <h2 class="font-heading text-lg font-bold">{{ t('reports.period.criteria.title') }}</h2>
        <p class="mt-1 text-sm text-kq-text-muted">
          {{
            t('reports.period.criteria.generated', {
              timeZone: report.meta.time_zone,
              at: generatedAtLabel,
            })
          }}
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5" data-test="report-criteria">
          <li v-for="(criterion, index) of report.meta.criteria" :key="index">{{ criterion }}</li>
        </ul>
      </section>
    </template>

    <!-- Bloque «Exportaciones en segundo plano» (RF-IN-06, RF-IN-07): SIEMPRE
         visible en esta pantalla, no solo tras un `422` -tambien enseña las
         exportaciones de nomina que se pidieron desde `PayrollExportView`,
         porque comparten el mismo ciclo de vida (`report_exports`, decision 1
         de la ficha). -->
    <ReportExportsPanel class="mt-8" />
  </section>
</template>
