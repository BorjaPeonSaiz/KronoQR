<script setup lang="ts">
// Pantalla «Quioscos» (RF-PA-07, RF-PD-06): la flota de tablets de la
// instalacion, vivas y revocadas, con lo que hace falta para saber si estan
// sanas, y el emparejamiento de una nueva por codigo.
//
// SOLO ADMIN, AMBITO `settings:*` (doc 02 §7.3, nota 5): dar de alta un
// quiosco es crear un origen de fichajes, y eso es la misma potestad que
// configurar la instalacion. El router y el marco (`AppShellView`) ya ocultan
// esta seccion a quien no la lleva; la autorizacion de verdad es del servidor
// (regla dura 18).
//
// SIN CANAL EN TIEMPO REAL: el contrato no publica un evento de presencia para
// la flota de quioscos (a diferencia de RF-PA-01/02), asi que esta pantalla no
// inventa uno en el cliente. Lo que SI hace, para no quedarse congelada sin
// avisarlo, es refrescar por sondeo cada 15 s (el mismo respaldo que usa la
// presencia en vivo cuando el canal cae) y enseñar siempre la marca de la
// ultima actualizacion.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstantWithZone, FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite } from '@/shared/api/organisation.api'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { Device } from '@/shared/api/types'
import { fetchDevices, unpairDevice } from './devices.api'
import PairKioskForm from './PairKioskForm.vue'
import { elapsedSinceHeartbeat } from './useDeviceRows'

/** Respaldo cuando no hay nada mejor: el mismo sondeo que la presencia en vivo degradada. */
const POLL_INTERVAL_MS = 15_000
/** Cada cuanto se repinta el tiempo transcurrido desde el ultimo latido. */
const CLOCK_TICK_MS = 30_000

const { t, locale } = useI18n()
const queryClient = useQueryClient()

const { data: site } = useQuery({ queryKey: ['site'] as const, queryFn: getSite })
const timezone = computed(() => site.value?.timezone ?? FALLBACK_TIMEZONE)

const {
  data: list,
  error,
  isPending,
  dataUpdatedAt,
} = useQuery({
  queryKey: ['devices'] as const,
  queryFn: fetchDevices,
  refetchInterval: POLL_INTERVAL_MS,
})

// El SERVIDOR ordena («activos primero, luego por nombre»): la regla no vive
// aqui, para no tener dos sitios que puedan divergir sobre el mismo criterio.
const devices = computed(() => list.value?.devices ?? [])

const now = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  clockTimer = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MS)
})

onUnmounted(() => {
  clearInterval(clockTimer)
})

const lastUpdatedLabel = computed(() =>
  dataUpdatedAt.value === 0
    ? ''
    : t('devices.list.lastUpdatedAt', {
        moment: formatInstantWithZone(
          new Date(dataUpdatedAt.value).toISOString(),
          timezone.value,
          locale.value,
        ),
      }),
)

function heartbeatLabel(device: Device): string {
  const elapsed = elapsedSinceHeartbeat(device.last_seen_at, now.value)

  if (elapsed === null) {
    return t('devices.list.neverSeen')
  }

  return t('devices.list.lastSeenAgo', { hours: elapsed.hours, minutes: elapsed.minutes })
}

function heartbeatInstant(device: Device): string {
  return device.last_seen_at === null
    ? ''
    : formatInstantWithZone(device.last_seen_at, timezone.value, locale.value)
}

function pairedAtLabel(device: Device): string {
  return device.paired_at === null
    ? t('common.empty')
    : formatInstantWithZone(device.paired_at, timezone.value, locale.value)
}

async function refreshList(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['devices'] })
}

// --- Vincular ---------------------------------------------------------------
//
// El dialogo NO se cierra solo al vincular: `PairKioskForm` enseña su propio
// resumen (version de la app, hora en que la tablet pidio el codigo) para que
// la persona lo contraste con la tablet delante, y es ella quien decide
// cuando cerrar («Cerrar» en las acciones) o vincular otro quiosco sin salir
// del dialogo. Aqui solo se refresca la lista en cuanto se confirma, para que
// ya este al dia cuando se cierre.

const pairing = ref(false)

function onPaired(): void {
  void refreshList()
}

// --- Desvincular -------------------------------------------------------------
//
// El propio `PairKioskForm` ya anuncia el resultado de vincular (se reutiliza
// tambien en el asistente, donde nadie mas lo haria); desvincular, en cambio,
// SOLO existe aqui, asi que el anuncio de exito va en este mismo flujo.

const unpairTarget = ref<Device | null>(null)
const unpairBusy = ref(false)
const unpairError = ref<unknown>(null)

function openUnpair(device: Device): void {
  unpairTarget.value = device
  unpairError.value = null
}

function closeUnpair(): void {
  unpairTarget.value = null
  unpairError.value = null
}

const unpairChanges = computed<Change[]>(() => {
  const target = unpairTarget.value

  if (target === null) {
    return []
  }

  return [
    {
      label: t('devices.table.status'),
      from: t(`devices.status.${target.status}`),
      to: t('devices.status.revoked'),
    },
    // La cola pendiente NO cambia por desvincular (regla dura 19: la cola
    // offline nunca se pierde): se enseña aqui para que quien confirma vea el
    // dato antes de decidir, no porque esta accion la modifique.
    {
      label: t('devices.table.pendingQueue'),
      from: String(target.pending_queue_size),
      to: String(target.pending_queue_size),
    },
  ]
})

/** Si la tablet tiene fichajes sin sincronizar, desvincularla ahora arriesga el registro (art. 34.9 ET). */
const unpairHasPendingQueue = computed(() => (unpairTarget.value?.pending_queue_size ?? 0) > 0)

async function confirmUnpair(): Promise<void> {
  const target = unpairTarget.value

  if (target === null) {
    return
  }

  unpairBusy.value = true
  unpairError.value = null

  try {
    await unpairDevice(target.uuid)
    await refreshList()
    announce(t('devices.unpair.announce', { name: target.name }))
    closeUnpair()
  } catch (caught) {
    unpairError.value = caught
  } finally {
    unpairBusy.value = false
  }
}

const buttonClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('devices.title') }}</h1>
        <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('devices.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        @click="pairing = true"
      >
        {{ t('devices.pair.action') }}
      </button>
    </div>

    <!-- INFORMATIVA, no una region viva: cambia cada 15 s con el sondeo y
         cada minuto con el reloj, y un `role="status"` aqui la anunciaria sin
         parar. La region viva unica del panel (`AppShellView`) ya anuncia lo
         que de verdad es un suceso: vincular, desvincular. -->
    <p v-if="lastUpdatedLabel !== ''" class="mt-2 text-sm text-kq-text-muted">
      {{ lastUpdatedLabel }}
    </p>

    <LoadingPanel v-if="isPending" :label="t('devices.loading')" class="mt-4" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />
    <EmptyState
      v-else-if="devices.length === 0"
      class="mt-4"
      :title="t('devices.empty.title')"
      :description="t('devices.empty.description')"
    />

    <div
      v-else
      class="mt-4 overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    >
      <table class="w-full border-collapse text-left">
        <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
          {{
            t('devices.table.caption')
          }}
        </caption>
        <thead class="border-b border-kq-border bg-kq-surface-alt">
          <tr>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.name') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.status') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.appVersion') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.lastSeen') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.pendingQueue') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.pairedAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="device of devices" :key="device.uuid" class="border-b border-kq-border">
            <th scope="row" class="px-3 py-2 font-medium">{{ device.name }}</th>
            <td class="px-3 py-2">
              <span
                class="rounded-full px-2 py-0.5 text-sm"
                :class="
                  device.status === 'active'
                    ? 'bg-kq-success-soft text-kq-success'
                    : 'bg-kq-surface-alt text-kq-text-muted'
                "
              >
                {{ t(`devices.status.${device.status}`) }}
              </span>
            </td>
            <td class="px-3 py-2">{{ device.app_version ?? t('common.empty') }}</td>
            <td class="px-3 py-2">
              <span>{{ heartbeatLabel(device) }}</span>
              <span v-if="heartbeatInstant(device) !== ''" class="block text-sm text-kq-text-muted">
                {{ heartbeatInstant(device) }}
              </span>
            </td>
            <td class="px-3 py-2 tabular-nums">{{ device.pending_queue_size }}</td>
            <td class="px-3 py-2">{{ pairedAtLabel(device) }}</td>
            <td class="px-3 py-2">
              <button
                v-if="device.status === 'active'"
                type="button"
                :class="buttonClass"
                @click="openUnpair(device)"
              >
                {{ t('devices.unpair.action') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Vincular un quiosco nuevo. NO se cierra sola al confirmar (ver el
         comentario de `onPaired`): «Cerrar» es la unica forma de salir. -->
    <BaseDialog v-if="pairing" :title="t('devices.pair.heading')" @close="pairing = false">
      <p class="mb-4 max-w-prose text-kq-text-muted">{{ t('devices.pair.explanation') }}</p>
      <PairKioskForm :existing-devices="devices" @paired="onPaired" />
      <template #actions>
        <button type="button" :class="buttonClass" @click="pairing = false">
          {{ t('common.close') }}
        </button>
      </template>
    </BaseDialog>

    <!-- Desvincular -->
    <ConfirmDialog
      v-if="unpairTarget !== null"
      :title="t('devices.unpair.heading')"
      :confirm-label="t('devices.unpair.action')"
      tone="danger"
      :busy="unpairBusy"
      :error="unpairError"
      @cancel="closeUnpair"
      @confirm="confirmUnpair"
    >
      <p class="mb-4">{{ t('devices.unpair.explanation', { name: unpairTarget.name }) }}</p>
      <ChangePreview :changes="unpairChanges" :caption="t('devices.unpair.heading')" />

      <!-- Destacado: sin esto, los fichajes de la cola solo llegan al
           registro legal si se vuelve a emparejar la MISMA tablet (art. 34.9
           ET; runbook de alta de un nuevo quiosco §5.1). -->
      <p
        v-if="unpairHasPendingQueue"
        role="alert"
        class="mt-4 rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
      >
        {{
          t('devices.unpair.pendingQueueWarning', {
            count: unpairTarget.pending_queue_size,
          })
        }}
      </p>

      <p class="mt-4 text-sm text-kq-text-muted">{{ t('devices.unpair.notice') }}</p>
    </ConfirmDialog>
  </section>
</template>
