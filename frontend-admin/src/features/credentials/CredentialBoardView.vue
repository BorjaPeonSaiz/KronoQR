<script setup lang="ts">
// Panel de estado de credenciales (RF-QR-04, RF-QR-06, RF-QR-08).
//
// Para que existe, literalmente: para que RRHH vea de un vistazo QUIEN NO PUEDE
// FICHAR TODAVIA. Sin esta pantalla el problema se descubre delante del quiosco
// a las 06:00.
//
// La fila es del empleado y no de la credencial: quien no tiene ninguna emitida
// es precisamente el caso que hay que ver, y un listado de credenciales lo
// dejaria fuera.
//
// Los cinco estados son derivados (`printed_at`, `delivered_at`, `revoked_at`).
// Aqui no se recalculan: los da el servidor y se pintan tal cual.
//
// Lo que esta pantalla NO tiene, a proposito: un boton de reimprimir. No existe
// (ADR-034). Reponer una tarjeta perdida son dos actos separados —revocar y
// volver a emitir— y cada uno deja su asiento en `audit_log`.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { FALLBACK_TIMEZONE, formatInstantWithZone } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { getSite } from '@/shared/api/organisation.api'
import type { CredentialLifecycleStatus } from '@/shared/api/types'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import CredentialRowActions from './CredentialRowActions.vue'
import { STATUS_PILL_CLASS } from './credentialStatusPill'
import { fetchCredentialBoard, printCredentialBatch } from './credentials.api'
import {
  CLIENT_PER_PAGE,
  NO_DEPARTMENT,
  departmentOptionsFrom,
  filterRows,
  paginate,
  reprintProgressOf,
} from './useCredentialRows'

const CREDENTIAL_LIFECYCLE_STATUSES: readonly CredentialLifecycleStatus[] = [
  'no_credential',
  'pending_print',
  'pending_delivery',
  'delivered',
  'revoked',
]

/**
 * Valor del `<select>` de estado que significa «solo quien todavia no tiene
 * la tarjeta en la mano». No es un `CredentialLifecycleStatus` de verdad: es
 * un filtro que va al SERVIDOR (acota tambien el lote de impresion y el
 * resumen), a diferencia de un estado concreto, que se filtra en
 * cliente sobre el lote ya cargado. Antes era un checkbox aparte, mutuamente
 * excluyente con el select mediante `disabled` — inaccesible por teclado,
 * lector de pantalla o tactil (hallazgo de revision). Ahora es una opcion mas
 * del mismo control, sin exclusion que anunciar.
 */
const PENDING_ONLY_OPTION = '__pending_only__'
type StatusFilterValue = CredentialLifecycleStatus | typeof PENDING_ONLY_OPTION | ''

const { t, locale } = useI18n()
const queryClient = useQueryClient()

// Filtros EN CLIENTE (RF-QR-08): el contrato no pagina ni filtra por
// departamento o texto, y eso no se cambia aqui. La opcion «pendiente de
// tarjeta» del select de estado, en cambio, va al servidor porque tambien
// acota el lote de impresion y el resumen; un estado concreto se filtra en
// cliente sobre el lote ya cargado. No hay filtro por centro: hay exactamente
// uno por instalacion (ADR-040).
const searchFilter = ref('')
const departmentFilter = ref('')
const statusFilter = ref<StatusFilterValue>('')
const clientPage = ref(1)

// El centro de la instalacion: su zona horaria es la que da sentido a las
// horas de impresion y entrega (regla dura 3, RN-05).
const { data: site } = useQuery({ queryKey: ['site'], queryFn: getSite })

// Rotacion de la clave de firma (RF-QR-07). Cuando esta activo, el servidor
// devuelve solo a quien sigue fichando con la clave saliente: es la lista que
// hay que vaciar antes de poder retirarla. La rotacion NO se dispara desde
// aqui —no tiene endpoint, y no lo tendra: es un acto operativo con semanas de
// logistica de reimpresion detras—; esta pantalla solo mira el avance.
// Guarda el `key_id`, no un booleano: si guardara «filtrado si/no» tendria que
// leer la clave de la respuesta que el propio filtro provoca, y en el hueco en
// que esa respuesta no ha llegado el filtro se desactivaria solo.
const reprintKeyFilter = ref<string | null>(null)

const boardQuery = computed(() => ({
  ...(statusFilter.value === PENDING_ONLY_OPTION ? { pendingOnly: true } : {}),
  ...(reprintKeyFilter.value === null ? {} : { keyId: reprintKeyFilter.value }),
}))

const {
  data: board,
  error,
  isPending,
} = useQuery({
  queryKey: computed(() => ['credential-board', boardQuery.value] as const),
  queryFn: () => fetchCredentialBoard(boardQuery.value),
})

const rows = computed(() => board.value?.data ?? [])
const summary = computed(() => board.value?.summary ?? null)

const departmentOptions = computed(() => departmentOptionsFrom(rows.value))

// La opcion «pendiente de tarjeta» ya acota `rows` desde el servidor: no se
// vuelve a filtrar por estado en cliente cuando esta elegida.
const filteredRows = computed(() =>
  filterRows(rows.value, {
    search: searchFilter.value,
    department: departmentFilter.value,
    status: statusFilter.value === PENDING_ONLY_OPTION ? '' : statusFilter.value,
  }),
)

const paged = computed(() => paginate(filteredRows.value, clientPage.value, CLIENT_PER_PAGE))
const pageRows = computed(() => paged.value.data)

const hasFilters = computed(
  () =>
    searchFilter.value.trim() !== '' ||
    departmentFilter.value !== '' ||
    statusFilter.value !== '' ||
    reprintKeyFilter.value !== null,
)

watch([searchFilter, departmentFilter, statusFilter, reprintKeyFilter], () => {
  clientPage.value = 1
})

// Se vigila el RECUENTO, no el array: `filteredRows` es un `computed` que
// produce una referencia nueva en cada recalculo (por ejemplo, cuando
// `refreshBoard()` recarga tras emitir, imprimir, entregar o revocar), y eso
// pisaria en la region viva el anuncio de la propia accion con un anuncio de
// recuento que nadie pidio.
watch(
  () => filteredRows.value.length,
  (count) => {
    announce(t('credentials.announce.results', { count }))
  },
)

function clearFilters(): void {
  searchFilter.value = ''
  departmentFilter.value = ''
  statusFilter.value = ''
  reprintKeyFilter.value = null
}

// El avance de la reimpresion sale del `summary` y no de las filas: las filas
// pueden venir acotadas —por este mismo filtro, sin ir mas lejos— y el avance
// se mide contra la plantilla entera.
const reprintProgress = computed(() => reprintProgressOf(summary.value))

/**
 * Tarjetas vivas firmadas con una clave que el servidor ya no reconoce: esas
 * personas NO PUEDEN FICHAR ahora mismo, y sus filas no lo delatan porque se
 * ven entregadas y correctas. Tiene que ser cero siempre (RF-QR-07).
 *
 * El desglose por clave no llega hasta aqui a proposito: esto es la voz de
 * alarma, y el diagnostico —que clave es y quienes son— esta en
 * `php artisan credentials:status` y en el runbook.
 */
const unknownKeyCards = computed(() => summary.value?.active_unknown_key ?? 0)

/**
 * No anuncia nada por su cuenta: el `watch` del recuento de filas ya lo hace
 * —«Resultados: 12»— y anunciar aqui tambien pisaria ese mensaje o quedaria
 * pisado por el, que es el mismo problema que ya documenta ese `watch`. El
 * estado del filtro lo dice el propio boton con `aria-pressed` y su etiqueta.
 */
function toggleReprintFilter(): void {
  const progress = reprintProgress.value

  reprintKeyFilter.value =
    reprintKeyFilter.value === null && progress !== null ? progress.keyId : null
}

const timezone = computed(() => site.value?.timezone ?? FALLBACK_TIMEZONE)

function instant(value: string | null): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, timezone.value, locale.value)
}

/** Cuantas tarjetas entraran en el proximo lote. */
const pendingPrintInScope = computed(() => summary.value?.pending_print ?? 0)

// --- Acciones ------------------------------------------------------------
//
// Emitir, imprimir, entregar y revocar viven en `CredentialRowActions`
// (botones + dialogos), una fuente unica compartida con la ficha de empleado.
// Aqui solo queda invalidar la consulta cuando una fila avisa de que termino,
// y el lote, que es exclusivo de este tablero.

const batching = ref(false)
const busy = ref(false)
const actionError = ref<unknown>(null)

async function refreshBoard(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['credential-board'] })
}

async function runBatch(): Promise<void> {
  busy.value = true
  actionError.value = null

  try {
    const document_ = await printCredentialBatch({})

    if (document_ === null) {
      // 204: no habia nada pendiente. Es la idempotencia del lote, no un fallo.
      announce(t('credentials.announce.batchEmpty'))
    } else {
      downloadDocument(document_)
      announce(
        t('credentials.announce.batchPrinted', {
          count: document_.printedCount ?? pendingPrintInScope.value,
        }),
      )
    }

    await refreshBoard()
    batching.value = false
  } catch (caught) {
    actionError.value = caught
  } finally {
    busy.value = false
  }
}

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('credentials.title') }}</h1>
        <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('credentials.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        @click="
          () => {
            actionError = null
            batching = true
          }
        "
      >
        {{ t('credentials.batch.action') }}
      </button>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent>
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('credentials.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="credentials-search-filter" class="font-medium">
            {{ t('credentials.filters.search') }}
          </label>
          <div class="flex items-center gap-2">
            <input
              id="credentials-search-filter"
              v-model="searchFilter"
              type="search"
              :placeholder="t('credentials.filters.searchPlaceholder')"
              :class="selectClass"
              @keydown.esc="searchFilter = ''"
            />
            <button
              v-if="searchFilter !== ''"
              type="button"
              :class="selectClass"
              class="px-2 py-2 text-sm"
              @click="searchFilter = ''"
            >
              <span aria-hidden="true">&times;</span>
              <span class="sr-only">{{ t('common.filters.clearSearch') }}</span>
            </button>
          </div>
        </div>

        <div class="flex flex-col gap-1">
          <label for="credentials-department-filter" class="font-medium">
            {{ t('credentials.filters.department') }}
          </label>
          <select
            id="credentials-department-filter"
            v-model="departmentFilter"
            :class="selectClass"
          >
            <option value="">{{ t('credentials.filters.departmentAll') }}</option>
            <option v-for="department of departmentOptions" :key="department" :value="department">
              {{
                department === NO_DEPARTMENT ? t('credentials.filters.departmentNone') : department
              }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="credentials-status-filter" class="font-medium">
            {{ t('credentials.filters.status') }}
          </label>
          <select id="credentials-status-filter" v-model="statusFilter" :class="selectClass">
            <option value="">{{ t('credentials.filters.statusAll') }}</option>
            <option :value="PENDING_ONLY_OPTION">{{ t('credentials.filters.pendingOnly') }}</option>
            <option v-for="status of CREDENTIAL_LIFECYCLE_STATUSES" :key="status" :value="status">
              {{ t(`credentials.status.${status}`) }}
            </option>
          </select>
        </div>
      </fieldset>

      <button
        v-if="hasFilters"
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="clearFilters"
      >
        {{ t('common.filters.clear') }}
      </button>
    </form>

    <!-- Recuento de la plantilla. `summary` no se filtra: dice «faltan 3 de 60»,
         no «faltan 3 de 3» (contrato, CredentialCoverage). -->
    <div
      v-if="summary !== null"
      class="mt-4 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    >
      <p class="font-semibold">{{ site?.name ?? t('credentials.summary.heading') }}</p>
      <p class="mt-1">
        {{
          t('credentials.summary.withoutDelivered', {
            missing: summary.without_delivered_credential,
            total: summary.employees,
          })
        }}
      </p>
      <p class="text-kq-text-muted">
        {{ t('credentials.summary.pendingPrint', { count: summary.pending_print }) }}
      </p>
    </div>

    <!-- La averia que el resto del panel no delata (RF-QR-07): tarjetas vivas
         firmadas con una clave que el servidor ya no reconoce. Va ANTES del
         avance de la reimpresion porque, si aparece, es lo unico que importa
         de esta pantalla: hay gente que no puede fichar. -->
    <div
      v-if="unknownKeyCards > 0"
      role="alert"
      class="mt-4 rounded-kq border border-kq-danger bg-kq-surface-raised p-4 shadow-kq-soft"
    >
      <p class="font-semibold text-kq-danger">{{ t('credentials.unknownKey.heading') }}</p>
      <p class="mt-1 max-w-prose">
        {{ t('credentials.unknownKey.explanation', { count: unknownKeyCards }) }}
      </p>
      <p class="mt-1 text-kq-text-muted">{{ t('credentials.unknownKey.action') }}</p>
    </div>

    <!-- Avance de la reimpresion durante una rotacion de clave (RF-QR-07).
         Solo aparece cuando hay una rotacion abierta, que es lo excepcional.
         Aqui NO se rota nada: la rotacion se ejecuta en el servidor con
         `php artisan credentials:rotate-key` y el panel solo mira el avance. -->
    <section
      v-if="reprintProgress !== null"
      class="mt-4 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      :aria-label="t('credentials.rotation.heading')"
    >
      <h2 class="font-semibold">{{ t('credentials.rotation.heading') }}</h2>
      <p class="mt-1 max-w-prose text-kq-text-muted">
        {{ t('credentials.rotation.explanation', { keyId: reprintProgress.keyId }) }}
      </p>

      <p class="mt-2">
        {{
          t('credentials.rotation.progress', {
            done: reprintProgress.done,
            total: reprintProgress.total,
            percent: reprintProgress.percent,
          })
        }}
      </p>

      <div
        class="mt-2 h-2 w-full overflow-hidden rounded-full bg-kq-surface-alt"
        role="progressbar"
        :aria-valuenow="reprintProgress.percent"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-label="t('credentials.rotation.heading')"
      >
        <div
          class="h-full bg-kq-primary-strong"
          :style="{ width: `${reprintProgress.percent}%` }"
        />
      </div>

      <p v-if="reprintProgress.pending === 0" class="mt-2 text-kq-text-muted">
        {{ t('credentials.rotation.complete', { keyId: reprintProgress.keyId }) }}
      </p>

      <button
        v-else
        type="button"
        class="mt-3 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
        :aria-pressed="reprintKeyFilter !== null"
        @click="toggleReprintFilter"
      >
        {{
          reprintKeyFilter === null
            ? t('credentials.rotation.showPending', { count: reprintProgress.pending })
            : t('credentials.rotation.showAll')
        }}
      </button>
    </section>

    <LoadingPanel v-if="isPending" :label="t('credentials.loading')" class="mt-4" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />
    <EmptyState
      v-else-if="rows.length === 0"
      class="mt-4"
      :title="t('credentials.empty.title')"
      :description="
        statusFilter === PENDING_ONLY_OPTION
          ? t('credentials.empty.allDelivered')
          : t('credentials.empty.description')
      "
    />
    <EmptyState
      v-else-if="filteredRows.length === 0"
      class="mt-4"
      :title="t('credentials.empty.title')"
      :description="t('credentials.empty.filtered')"
    />

    <template v-else>
      <div
        class="mt-4 overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
            {{
              t('credentials.table.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.employee') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.department') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.status') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.printedAt') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.deliveredAt') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row of pageRows" :key="row.employee_uuid" class="border-b border-kq-border">
              <th scope="row" class="px-3 py-2 font-medium">
                {{ row.full_name }}
                <span class="block font-mono text-sm font-normal text-kq-text-muted">
                  {{ row.employee_code }}
                </span>
              </th>
              <td class="px-3 py-2">
                {{ row.department_name ?? t('credentials.table.noDepartment') }}
              </td>
              <td class="px-3 py-2">
                <span
                  class="rounded-full px-2 py-0.5 text-sm"
                  :class="STATUS_PILL_CLASS[row.status]"
                >
                  {{ t(`credentials.status.${row.status}`) }}
                </span>
              </td>
              <td class="px-3 py-2">
                {{ instant(row.credential?.printed_at ?? null) }}
              </td>
              <td class="px-3 py-2">
                {{ instant(row.credential?.delivered_at ?? null) }}
              </td>
              <td class="px-3 py-2">
                <CredentialRowActions :row="row" :on-changed="refreshBoard" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar
        :page="paged.page"
        :per-page="paged.perPage"
        :total="paged.total"
        :total-pages="paged.totalPages"
        :label="t('credentials.pagination.label')"
        @update:page="(next) => (clientPage = next)"
      />
    </template>

    <!-- Confirmaciones -->
    <ConfirmDialog
      v-if="batching"
      :title="t('credentials.batch.heading')"
      :confirm-label="t('credentials.batch.confirmAction')"
      tone="danger"
      :busy="busy"
      :error="actionError"
      @cancel="batching = false"
      @confirm="runBatch"
    >
      <p>
        {{ t('credentials.batch.explanation', { count: pendingPrintInScope }) }}
      </p>
      <p class="mt-3 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning">
        {{ t('credentials.batch.warning') }}
      </p>
      <p class="mt-3 text-sm text-kq-text-muted">{{ t('credentials.batch.bearerNotice') }}</p>
    </ConfirmDialog>
  </section>
</template>
