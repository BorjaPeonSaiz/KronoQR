<script setup lang="ts">
// «Tus datos son tuyos» (RF-PD-14, RL-20, ADR-019, regla dura 15): la
// exportacion integra de todos los datos de la instalacion, en formato
// abierto, que el cliente puede pedir cuando quiera y sin pedir permiso a
// nadie. Vive dentro de `LicenseView.vue` porque es la pantalla a la que se
// llega cuando se teme perder el producto, y RL-20 es la respuesta a ese
// temor (ficha de la tarea 5.10, decision 10).
//
// NUNCA depende de la licencia (regla dura 15): esta seccion se enseña igual
// con la licencia caducada, ausente o ilegible, y por eso no lee nada de
// `license.store`.
//
// Esta pantalla NUNCA interpreta el contenido del ZIP: lo descarga tal cual
// llega y lo suelta con `downloadDocument`, exactamente igual que el paquete
// de diagnostico de `features/support` (ver el README de esta carpeta).
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstantWithZone, FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite } from '@/shared/api/organisation.api'
import type { DataExport, DataExportCollection } from '@/shared/api/types'
import { SETTINGS_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { downloadDataExport, listDataExports, requestDataExport } from './dataExport.api'

/** Mientras haya una `pending`/`running`, la misma cadencia que la flota de quioscos degradada. */
const POLL_INTERVAL_MS = 5_000

const QUERY_KEY = ['data-exports'] as const

const { t, locale } = useI18n()
const session = useSessionStore()

/**
 * Cortesia, no seguridad (regla dura 18): la ruta la alcanza `admin` con
 * `settings:*`, y la policy del servidor la vuelve a comprobar. Ocultar la
 * seccion aqui solo evita la frustracion de un formulario que el servidor
 * rechazaria con `403`.
 */
const canManage = computed(() => session.can(SETTINGS_MANAGE))

const { data: site } = useQuery({
  queryKey: ['site'] as const,
  queryFn: getSite,
  enabled: canManage,
})
const timezone = computed(() => site.value?.timezone ?? FALLBACK_TIMEZONE)

const queryClient = useQueryClient()

const {
  data: collection,
  error: listError,
  isPending: listPending,
  dataUpdatedAt,
} = useQuery({
  queryKey: QUERY_KEY,
  queryFn: listDataExports,
  enabled: canManage,
  // Sondeo SOLO mientras algo sigue en curso (ficha 5.10, decision 6): una
  // instalacion sin ninguna exportacion pendiente no golpea el servidor cada
  // 5 s para nada.
  refetchInterval: (query) => {
    const rows = query.state.data?.data ?? []
    const active = rows.some((row) => row.status === 'pending' || row.status === 'running')

    return active ? POLL_INTERVAL_MS : false
  },
})

const exportsList = computed<DataExport[]>(() => collection.value?.data ?? [])
const activeExport = computed<DataExport | null>(
  () =>
    exportsList.value.find((row) => row.status === 'pending' || row.status === 'running') ?? null,
)

const lastUpdatedLabel = computed(() =>
  dataUpdatedAt.value === 0
    ? ''
    : t('dataExport.list.lastUpdatedAt', {
        moment: formatInstantWithZone(
          new Date(dataUpdatedAt.value).toISOString(),
          timezone.value,
          locale.value,
        ),
      }),
)

function instantLabel(value: string | null): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, timezone.value, locale.value)
}

function requestedByLabel(row: DataExport): string {
  return row.requested_by?.name ?? t('dataExport.requestedByConsole')
}

/**
 * Los cuatro codigos ESTABLES de `failure_reason` (contrato, revision de
 * cumplimiento): nunca la clase de una excepcion ni un mensaje de base de
 * datos, que podria llevar el valor de una fila (regla dura 21). Un codigo
 * que esta pantalla todavia no conoce se enseña tal cual llega, en vez de
 * escondelo: es mejor un codigo en bruto que un vacio.
 */
const FAILURE_REASON_KEYS: Readonly<Record<string, string>> = {
  write_failed: 'dataExport.list.failureReasons.write_failed',
  database_error: 'dataExport.list.failureReasons.database_error',
  stale: 'dataExport.list.failureReasons.stale',
  unexpected: 'dataExport.list.failureReasons.unexpected',
}

function failureReasonLabel(code: string | null): string {
  if (code === null) {
    return t('common.empty')
  }

  const key = FAILURE_REASON_KEYS[code]

  return key === undefined ? code : t(key)
}

/**
 * Tamaño legible del ZIP, en unidades BINARIAS (divisor 1024): las mismas
 * KiB/MiB/GiB/TiB que `product:export-all` y `doctor` en la consola
 * (`ProductExportAllCommand::humanBytes`, `DiskProbe`), para que el panel y
 * la consola digan lo mismo del mismo fichero. No es un dato del registro
 * legal -es una lista de ficheros, no una nomina-, asi que aqui si conviene
 * redondear a una cifra razonable en vez del numero exacto de bytes.
 */
function sizeLabel(bytes: number | null): string {
  if (bytes === null) {
    return t('common.empty')
  }

  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'] as const
  let value = bytes
  let unitIndex = 0

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024
    unitIndex += 1
  }

  const decimals = unitIndex === 0 ? 0 : 1
  const formatted = new Intl.NumberFormat(locale.value, {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  }).format(value)

  return `${formatted} ${units[unitIndex]}`
}

/** Total de filas de todos los ficheros del ZIP. Vacio hasta que termina (contrato). */
function rowCountLabel(row: DataExport): string {
  const counts = Object.values(row.row_counts)

  if (counts.length === 0) {
    return t('common.empty')
  }

  const total = counts.reduce((sum, count) => sum + count, 0)

  return t('dataExport.list.rows', { count: total })
}

// --- Pedir una exportacion nueva (RF-PD-14, RL-20) ---------------------------

const confirmOpen = ref(false)
const requesting = ref(false)
const requestError = ref<unknown>(null)

function openConfirm(): void {
  requestError.value = null
  confirmOpen.value = true
}

function closeConfirm(): void {
  confirmOpen.value = false
}

async function confirmRequest(): Promise<void> {
  requesting.value = true
  requestError.value = null

  try {
    const resource = await requestDataExport()

    // Se enseña en el acto, sin esperar al primer sondeo: tanto si es la fila
    // recien creada como si es la que ya estaba en curso (409, ficha 5.10,
    // decision 4), es la misma forma y el mismo sitio en la lista.
    queryClient.setQueryData<DataExportCollection>(QUERY_KEY, (previous) => {
      const rest = (previous?.data ?? []).filter((row) => row.uuid !== resource.data.uuid)

      return { data: [resource.data, ...rest].slice(0, 20) }
    })

    announce(t('dataExport.announceRequested'))
    closeConfirm()
  } catch (failure) {
    requestError.value = failure
  } finally {
    requesting.value = false
  }
}

// --- Descargar una exportacion terminada -------------------------------------

const downloadingUuid = ref<string | null>(null)
const downloadError = ref<unknown>(null)

async function download(row: DataExport): Promise<void> {
  downloadingUuid.value = row.uuid
  downloadError.value = null

  try {
    const document_ = await downloadDataExport(row.uuid)

    downloadDocument(document_)
    announce(t('dataExport.announceDownloaded', { filename: document_.filename }))
  } catch (failure) {
    downloadError.value = failure
  } finally {
    downloadingUuid.value = null
  }
}
</script>

<template>
  <section v-if="canManage" class="flex max-w-3xl flex-col gap-4" data-test="data-export">
    <div>
      <h2 class="text-lg font-semibold">{{ t('dataExport.heading') }}</h2>
      <p class="mt-1 text-kq-text-muted">{{ t('dataExport.intro') }}</p>
    </div>

    <div>
      <button
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        :disabled="activeExport !== null"
        data-test="open-generate"
        @click="openConfirm"
      >
        {{ t('dataExport.generate') }}
      </button>
      <p
        v-if="activeExport !== null"
        class="mt-2 text-sm text-kq-text-muted"
        data-test="generate-disabled-hint"
      >
        {{ t('dataExport.generateDisabledHint') }}
      </p>
    </div>

    <p
      v-if="activeExport !== null"
      role="status"
      aria-live="polite"
      class="rounded-kq border border-kq-border bg-kq-surface-alt p-4 text-kq-text"
      data-test="active-notice"
    >
      {{ t('dataExport.activeNotice', { requestedAt: instantLabel(activeExport.requested_at) }) }}
      <span v-if="lastUpdatedLabel !== ''"> {{ lastUpdatedLabel }}</span>
    </p>

    <ErrorNotice v-if="downloadError !== null" :error="downloadError" data-test="download-error" />

    <LoadingPanel v-if="listPending" :label="t('dataExport.list.loading')" />
    <ErrorNotice v-else-if="listError !== null" :error="listError" data-test="list-error" />
    <EmptyState
      v-else-if="exportsList.length === 0"
      :title="t('dataExport.list.empty.title')"
      :description="t('dataExport.list.empty.description')"
    />

    <div
      v-else
      class="overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    >
      <table class="w-full border-collapse text-left">
        <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
          {{
            t('dataExport.list.caption')
          }}
        </caption>
        <thead class="border-b border-kq-border bg-kq-surface-alt">
          <tr>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.status') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.requestedBy') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.requestedAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.size') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.rows') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.expiresAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('dataExport.list.columns.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row of exportsList" :key="row.uuid" class="border-b border-kq-border">
            <td class="px-3 py-2">
              <span
                class="rounded-full px-2 py-0.5 text-sm"
                :class="{
                  'bg-kq-success-soft text-kq-success': row.status === 'completed',
                  'bg-kq-danger-soft text-kq-danger': row.status === 'failed',
                  'bg-kq-surface-alt text-kq-text-muted':
                    row.status !== 'completed' && row.status !== 'failed',
                }"
                :data-test="`status-${row.uuid}`"
              >
                {{ t(`dataExport.status.${row.status}`) }}
              </span>
              <p v-if="row.status === 'failed'" class="mt-1 text-sm text-kq-text-muted">
                {{
                  t('dataExport.list.failedReason', {
                    reason: failureReasonLabel(row.failure_reason),
                  })
                }}
              </p>
              <p v-if="row.status === 'purged'" class="mt-1 text-sm text-kq-text-muted">
                {{ t('dataExport.list.purgedNotice') }}
              </p>
            </td>
            <td class="px-3 py-2">{{ requestedByLabel(row) }}</td>
            <td class="px-3 py-2">{{ instantLabel(row.requested_at) }}</td>
            <td class="px-3 py-2">{{ sizeLabel(row.size_bytes) }}</td>
            <td class="px-3 py-2">{{ rowCountLabel(row) }}</td>
            <td class="px-3 py-2">{{ instantLabel(row.expires_at) }}</td>
            <td class="px-3 py-2">
              <button
                v-if="row.status === 'completed'"
                type="button"
                class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
                :disabled="downloadingUuid === row.uuid"
                :aria-busy="downloadingUuid === row.uuid"
                :data-test="`download-${row.uuid}`"
                @click="download(row)"
              >
                {{
                  downloadingUuid === row.uuid
                    ? t('dataExport.list.downloading')
                    : t('dataExport.list.download')
                }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <ConfirmDialog
      v-if="confirmOpen"
      :title="t('dataExport.confirm.heading')"
      :confirm-label="t('dataExport.confirm.action')"
      :busy="requesting"
      :error="requestError"
      @cancel="closeConfirm"
      @confirm="confirmRequest"
    >
      <p role="alert" data-test="data-export-warning">{{ t('dataExport.confirm.warning') }}</p>
    </ConfirmDialog>
  </section>
</template>
