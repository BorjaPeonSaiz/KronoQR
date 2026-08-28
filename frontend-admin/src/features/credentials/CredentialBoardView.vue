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
import { useRoute } from 'vue-router'
import { listSites } from '@/shared/api/organisation.api'
import type { CredentialLifecycleStatus, CredentialStatusRow } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import {
  deliverCredential,
  fetchCredentialBoard,
  issueCredential,
  printCredential,
  printCredentialBatch,
  revokeCredential,
} from './credentials.api'
import { NO_DEPARTMENT, departmentOptionsFrom, filterRows, paginate } from './useCredentialRows'

/** Píldora de estado de la fila: refleja el ciclo de vida, no una decisión estética. */
const STATUS_PILL_CLASS: Record<CredentialLifecycleStatus, string> = {
  no_credential: 'bg-kq-surface-alt text-kq-text-muted',
  pending_print: 'bg-kq-warning-soft text-kq-warning',
  pending_delivery: 'bg-kq-warning-soft text-kq-warning',
  delivered: 'bg-kq-success-soft text-kq-success',
  revoked: 'bg-kq-danger-soft text-kq-danger',
}

/** Motivos de revocacion. Un catalogo evita el «se perdio» sin mas contexto. */
const REVOCATION_REASONS = ['lost', 'stolen', 'damaged', 'offboarding', 'printFailed'] as const

const CREDENTIAL_LIFECYCLE_STATUSES: readonly CredentialLifecycleStatus[] = [
  'no_credential',
  'pending_print',
  'pending_delivery',
  'delivered',
  'revoked',
]

const CLIENT_PER_PAGE = 25

/**
 * Valor del `<select>` de estado que significa «solo quien todavia no tiene
 * la tarjeta en la mano». No es un `CredentialLifecycleStatus` de verdad: es
 * un filtro que va al SERVIDOR (acota tambien el lote de impresion y el
 * resumen por centro), a diferencia de un estado concreto, que se filtra en
 * cliente sobre el lote ya cargado. Antes era un checkbox aparte, mutuamente
 * excluyente con el select mediante `disabled` — inaccesible por teclado,
 * lector de pantalla o tactil (hallazgo de revision). Ahora es una opcion mas
 * del mismo control, sin exclusion que anunciar.
 */
const PENDING_ONLY_OPTION = '__pending_only__'
type StatusFilterValue = CredentialLifecycleStatus | typeof PENDING_ONLY_OPTION | ''

type RowActionKind = 'issue' | 'print' | 'deliver' | 'revoke'

const { t, locale } = useI18n()
const route = useRoute()
const queryClient = useQueryClient()

const initialSite = Number.parseInt(String(route.query['site'] ?? ''), 10)
const siteFilter = ref<number | ''>(Number.isFinite(initialSite) ? initialSite : '')

// Filtros EN CLIENTE (RF-QR-08): el contrato no pagina ni filtra por
// departamento o texto, y eso no se cambia aqui. `site` y la opcion
// «pendiente de tarjeta» del select de estado, en cambio, van al servidor
// porque tambien acotan el lote de impresion y el resumen por centro; un
// estado concreto se filtra en cliente sobre el lote ya cargado.
const searchFilter = ref('')
const departmentFilter = ref('')
const statusFilter = ref<StatusFilterValue>('')
const clientPage = ref(1)

const { data: sites } = useQuery({ queryKey: ['sites'], queryFn: listSites })

const boardQuery = computed(() => ({
  ...(siteFilter.value === '' ? {} : { siteId: siteFilter.value }),
  ...(statusFilter.value === PENDING_ONLY_OPTION ? { pendingOnly: true } : {}),
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
const summary = computed(() => board.value?.summary ?? [])

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
    siteFilter.value !== '' ||
    searchFilter.value.trim() !== '' ||
    departmentFilter.value !== '' ||
    statusFilter.value !== '',
)

watch([searchFilter, departmentFilter, statusFilter, siteFilter], () => {
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
  siteFilter.value = ''
}

const timezones = computed(
  () => new Map((sites.value?.data ?? []).map((site) => [site.id, site.timezone])),
)

function zoneOf(siteId: number): string {
  return timezones.value.get(siteId) ?? FALLBACK_TIMEZONE
}

function instant(value: string | null, siteId: number): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, zoneOf(siteId), locale.value)
}

/** Cuantas tarjetas entraran en el proximo lote del alcance elegido. */
const pendingPrintInScope = computed(() =>
  summary.value.reduce((total, site) => total + site.pending_print, 0),
)

const scopeName = computed(() => {
  if (siteFilter.value === '') {
    return t('credentials.batch.allSites')
  }

  return (sites.value?.data ?? []).find((site) => site.id === siteFilter.value)?.name ?? ''
})

// --- Acciones ----------------------------------------------------------------

const action = ref<{ kind: RowActionKind; row: CredentialStatusRow } | null>(null)
const batching = ref(false)
const busy = ref(false)
const actionError = ref<unknown>(null)
const revocationReasonKey = ref('')
const revocationReasonText = ref('')

const revocationReason = computed(() =>
  revocationReasonKey.value === 'other'
    ? revocationReasonText.value.trim()
    : revocationReasonKey.value === ''
      ? ''
      : t(`credentials.revoke.reasons.${revocationReasonKey.value}`),
)

function openAction(kind: RowActionKind, row: CredentialStatusRow): void {
  actionError.value = null
  revocationReasonKey.value = ''
  revocationReasonText.value = ''
  action.value = { kind, row }
}

function closeAction(): void {
  action.value = null
  actionError.value = null
}

function changesFor(kind: RowActionKind, row: CredentialStatusRow): Change[] {
  const to: Record<RowActionKind, string> = {
    issue: t('credentials.status.pending_print'),
    print: t('credentials.status.pending_delivery'),
    deliver: t('credentials.status.delivered'),
    revoke: t('credentials.status.revoked'),
  }

  return [
    {
      label: t('credentials.table.status'),
      from: t(`credentials.status.${row.status}`),
      to: to[kind],
    },
  ]
}

async function refreshBoard(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['credential-board'] })
}

async function runAction(): Promise<void> {
  const current = action.value

  if (current === null) {
    return
  }

  busy.value = true
  actionError.value = null

  try {
    await perform(current.kind, current.row)
    await refreshBoard()
    closeAction()
  } catch (caught) {
    actionError.value = caught
  } finally {
    busy.value = false
  }
}

async function perform(kind: RowActionKind, row: CredentialStatusRow): Promise<void> {
  if (kind === 'issue') {
    // `reissue: false` siempre: aqui solo se emite a quien NO tiene credencial
    // activa —sin ninguna, o con la ultima revocada—. Una reemision revoca la
    // anterior y exige motivo, y ese camino pasa por «revocar» primero.
    await issueCredential({ employee_uuid: row.employee_uuid, reissue: false })
    announce(t('credentials.announce.issued', { name: row.full_name }))

    return
  }

  const credentialUuid = row.credential?.uuid

  if (credentialUuid === undefined) {
    return
  }

  if (kind === 'print') {
    const document_ = await printCredential(credentialUuid)

    if (document_ !== null) {
      downloadDocument(document_)
    }

    announce(t('credentials.announce.printed', { name: row.full_name }))

    return
  }

  if (kind === 'deliver') {
    await deliverCredential(credentialUuid)
    announce(t('credentials.announce.delivered', { name: row.full_name }))

    return
  }

  await revokeCredential(credentialUuid, { reason: revocationReason.value })
  announce(t('credentials.announce.revoked', { name: row.full_name }))
}

async function runBatch(): Promise<void> {
  busy.value = true
  actionError.value = null

  try {
    const document_ = await printCredentialBatch(
      siteFilter.value === '' ? {} : { site_id: siteFilter.value },
    )

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
const rowButtonClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt'
const rowDangerButtonClass =
  'rounded-kq-sm bg-kq-danger px-2 py-1 text-sm font-semibold text-kq-on-danger'
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
          <label for="credentials-site-filter" class="font-medium">
            {{ t('credentials.filters.site') }}
          </label>
          <select id="credentials-site-filter" v-model="siteFilter" :class="selectClass">
            <option value="">{{ t('credentials.filters.siteAll') }}</option>
            <option v-for="site of sites?.data ?? []" :key="site.id" :value="site.id">
              {{ site.name }}
            </option>
          </select>
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

    <!-- Recuento por centro. `summary` no se filtra: dice «faltan 3 de 60», no
         «faltan 3 de 3» (contrato, SiteCredentialCoverage). -->
    <ul v-if="summary.length > 0" class="mt-4 grid gap-3 sm:grid-cols-2">
      <li
        v-for="coverage of summary"
        :key="coverage.site_id"
        class="rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <p class="font-semibold">{{ coverage.site_name }}</p>
        <p class="mt-1">
          {{
            t('credentials.summary.withoutDelivered', {
              missing: coverage.without_delivered_credential,
              total: coverage.employees,
            })
          }}
        </p>
        <p class="text-kq-text-muted">
          {{ t('credentials.summary.pendingPrint', { count: coverage.pending_print }) }}
        </p>
      </li>
    </ul>

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
              <th scope="col" class="px-3 py-2">{{ t('credentials.table.site') }}</th>
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
              <td class="px-3 py-2">{{ row.site_name }}</td>
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
                {{ instant(row.credential?.printed_at ?? null, row.site_id) }}
              </td>
              <td class="px-3 py-2">
                {{ instant(row.credential?.delivered_at ?? null, row.site_id) }}
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap gap-2">
                  <button
                    v-if="row.status === 'no_credential' || row.status === 'revoked'"
                    type="button"
                    :class="rowButtonClass"
                    @click="openAction('issue', row)"
                  >
                    {{ t('credentials.actions.issue') }}
                  </button>
                  <button
                    v-if="row.status === 'pending_print'"
                    type="button"
                    :class="rowButtonClass"
                    @click="openAction('print', row)"
                  >
                    {{ t('credentials.actions.print') }}
                  </button>
                  <button
                    v-if="row.status === 'pending_delivery'"
                    type="button"
                    :class="rowButtonClass"
                    @click="openAction('deliver', row)"
                  >
                    {{ t('credentials.actions.deliver') }}
                  </button>
                  <button
                    v-if="
                      row.credential !== null &&
                      row.status !== 'revoked' &&
                      row.status !== 'no_credential'
                    "
                    type="button"
                    :class="rowDangerButtonClass"
                    @click="openAction('revoke', row)"
                  >
                    {{ t('credentials.actions.revoke') }}
                  </button>
                </div>
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
      v-if="action !== null"
      :title="t(`credentials.confirm.${action.kind}.heading`)"
      :confirm-label="t(`credentials.confirm.${action.kind}.action`)"
      :tone="action.kind === 'issue' ? 'normal' : 'danger'"
      :busy="busy"
      :error="actionError"
      size="wide"
      :confirm-disabled="action.kind === 'revoke' && revocationReason === ''"
      @cancel="closeAction"
      @confirm="runAction"
    >
      <p class="mb-4">
        {{ t(`credentials.confirm.${action.kind}.explanation`, { name: action.row.full_name }) }}
      </p>

      <div v-if="action.kind === 'revoke'" class="mb-4 grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-1">
          <label for="revocation-reason" class="font-medium">
            {{ t('credentials.revoke.reasonLabel') }}
          </label>
          <select id="revocation-reason" v-model="revocationReasonKey" :class="selectClass">
            <option value="">{{ t('credentials.revoke.reasonPlaceholder') }}</option>
            <option v-for="key of REVOCATION_REASONS" :key="key" :value="key">
              {{ t(`credentials.revoke.reasons.${key}`) }}
            </option>
            <option value="other">{{ t('credentials.revoke.reasons.other') }}</option>
          </select>
        </div>
        <div v-if="revocationReasonKey === 'other'" class="flex flex-col gap-1">
          <label for="revocation-reason-text" class="font-medium">
            {{ t('credentials.revoke.reasonOtherLabel') }}
          </label>
          <input
            id="revocation-reason-text"
            v-model="revocationReasonText"
            type="text"
            maxlength="190"
            :class="selectClass"
          />
        </div>
      </div>

      <ChangePreview
        :changes="changesFor(action.kind, action.row)"
        :caption="t(`credentials.confirm.${action.kind}.heading`)"
      />

      <p class="mt-4 text-sm text-kq-text-muted">
        {{ t(`credentials.confirm.${action.kind}.notice`) }}
      </p>
    </ConfirmDialog>

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
        {{
          t('credentials.batch.explanation', {
            count: pendingPrintInScope,
            scope: scopeName,
          })
        }}
      </p>
      <p class="mt-3 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning">
        {{ t('credentials.batch.warning') }}
      </p>
      <p class="mt-3 text-sm text-kq-text-muted">{{ t('credentials.batch.bearerNotice') }}</p>
    </ConfirmDialog>
  </section>
</template>
