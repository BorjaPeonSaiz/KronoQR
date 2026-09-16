<script setup lang="ts">
// Pantalla «Quioscos» (RF-PA-07, RF-PD-06): la flota de tablets de la
// instalacion, vivas y revocadas, con su SALUD -veredicto, razon, bateria,
// cola pendiente- y el emparejamiento de una nueva por codigo.
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
//
// EL VEREDICTO DE SALUD LO CALCULA EL SERVIDOR (tarea 3.3): `health.verdict` y
// `health.reason` vienen ya resueltos por `KioskHealthRow`, la misma regla que
// `php artisan kiosk:health` y que la alerta «Quiosco sin latido» de
// Prometheus (§9.3), asi que panel, consola y alerta cuentan siempre lo mismo.
// La antiguedad de cada latido se mide contra `meta.generated_at` -el reloj
// del SERVIDOR, extrapolado con el tiempo transcurrido desde que llego la
// respuesta, igual que `incidents.store.ts`/`errors.store.ts`- y nunca contra
// `Date.now()` del navegador (regla dura 3).
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstantWithZone, FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { Device } from '@/shared/api/types'
import { fetchDevices, unpairDevice } from './devices.api'
import PairKioskForm from './PairKioskForm.vue'
import { elapsedSinceHeartbeat, elapsedSinceOldestPending } from './useDeviceRows'
import {
  batteryChargingKey,
  batteryPercentLabel,
  hasBatteryWarning,
  reasonKey,
  rowToneClass,
  showsWhatToDo,
  thresholdLabel,
  verdictBadgeClass,
  verdictGlyph,
  verdictKey,
  whatToDoKey,
} from './devicePresentation'

/** Respaldo cuando no hay nada mejor: el mismo sondeo que la presencia en vivo degradada. */
const POLL_INTERVAL_MS = 15_000
/** Cada cuanto se repinta el tiempo transcurrido desde el ultimo latido. */
const CLOCK_TICK_MS = 30_000

const { t, locale } = useI18n()
const queryClient = useQueryClient()

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

// La zona del centro viaja en `meta.timezone` desde la tarea 3.3 (antes se
// pedia aparte con `GET /site`): una sola respuesta, un solo «ahora».
const timezone = computed(() => list.value?.meta.timezone ?? FALLBACK_TIMEZONE)

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

/**
 * El «ahora» del SERVIDOR, extrapolado desde `meta.generated_at` con el
 * tiempo transcurrido desde que `useQuery` recibio la respuesta
 * (`dataUpdatedAt`, el equivalente de `receivedAt` en los almacenes de
 * presencia/incidencias/errores). Nunca hacia atras.
 */
const serverNowMs = computed(() => {
  const meta = list.value?.meta

  if (meta === undefined || dataUpdatedAt.value === 0) {
    return now.value
  }

  return Date.parse(meta.generated_at) + Math.max(now.value - dataUpdatedAt.value, 0)
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
  const elapsed = elapsedSinceHeartbeat(device.last_seen_at, serverNowMs.value)

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

/** «El mas antiguo hace X»: cadena vacia con la cola vacia o sin `oldest_pending_at`. */
function oldestPendingLabel(device: Device): string {
  const elapsed = elapsedSinceOldestPending(device.oldest_pending_at, serverNowMs.value)

  return elapsed === null
    ? ''
    : t('devices.list.oldestPendingAgo', { hours: elapsed.hours, minutes: elapsed.minutes })
}

function batteryPercent(device: Device): string {
  return batteryPercentLabel(device.battery_level) ?? t('common.empty')
}

function pairedAtLabel(device: Device): string {
  return device.paired_at === null
    ? t('common.empty')
    : formatInstantWithZone(device.paired_at, timezone.value, locale.value)
}

/**
 * La leyenda de umbrales, con los valores REALES de la instalacion
 * (`meta.thresholds`, tarea 3.3): un aviso cuyo criterio no se ve es un aviso
 * que nadie puede defender ante quien pregunta por que un quiosco esta en
 * fallo.
 */
const legend = computed(() => {
  const thresholds = list.value?.meta.thresholds

  if (thresholds === undefined) {
    return ''
  }

  return t('devices.legend', {
    fresh: thresholdLabel(thresholds.fresh_within_seconds),
    silent: thresholdLabel(thresholds.silent_after_seconds),
    batteryPercent: thresholds.battery_low_percent,
  })
})

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
         cada 30 s con el reloj (`CLOCK_TICK_MS`), y un `role="status"` aqui
         la anunciaria sin parar. La region viva unica del panel
         (`AppShellView`) ya anuncia lo que de verdad es un suceso: vincular,
         desvincular. -->
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
            t('devices.table.caption', { zone: timezone })
          }}
        </caption>
        <thead class="border-b border-kq-border bg-kq-surface-alt">
          <tr>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.name') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.health') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.lastSeen') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.appVersion') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.pendingQueue') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.battery') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.pairedAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('devices.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="device of devices" :key="device.uuid">
            <tr
              class="border-b border-kq-border"
              :class="rowToneClass(device.health.verdict)"
              data-test="device-row"
              :data-verdict="device.health.verdict"
            >
              <th scope="row" class="px-3 py-2 font-medium">{{ device.name }}</th>
              <td class="px-3 py-2">
                <span
                  class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-sm font-semibold"
                  :class="verdictBadgeClass(device.health.verdict)"
                  data-test="device-verdict"
                >
                  <span aria-hidden="true">{{ verdictGlyph(device.health.verdict) }}</span>
                  {{ t(verdictKey(device.health.verdict)) }}
                </span>
                <span class="block text-sm text-kq-text-muted">
                  {{ t(reasonKey(device.health.reason)) }}
                </span>
              </td>
              <td class="px-3 py-2">
                <span>{{ heartbeatLabel(device) }}</span>
                <span
                  v-if="heartbeatInstant(device) !== ''"
                  class="block text-sm text-kq-text-muted"
                >
                  {{ heartbeatInstant(device) }}
                </span>
              </td>
              <td class="px-3 py-2">{{ device.app_version ?? t('common.empty') }}</td>
              <td class="px-3 py-2 tabular-nums">
                <span>{{ device.pending_queue_size }}</span>
                <span
                  v-if="oldestPendingLabel(device) !== ''"
                  class="block text-sm text-kq-text-muted"
                  data-test="oldest-pending"
                >
                  {{ oldestPendingLabel(device) }}
                </span>
              </td>
              <td class="px-3 py-2" data-test="battery">
                <span>{{ batteryPercent(device) }}</span>
                <span class="block text-sm text-kq-text-muted">
                  {{ t(batteryChargingKey(device.battery_charging)) }}
                </span>
                <span
                  v-if="hasBatteryWarning(device)"
                  class="mt-1 inline-flex items-center gap-1 text-sm font-medium text-kq-warning"
                  data-test="battery-warning"
                >
                  <span aria-hidden="true">⚠</span>
                  {{ t('devices.battery.lowWarning') }}
                </span>
              </td>
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
            <!-- «Que hacer», SIEMPRE visible mientras haya algo que decir y no
                 un desplegable (a diferencia de `ErrorTable`): con unos pocos
                 quioscos por instalacion (ADR-040, decision 11 de la ficha
                 3.3), esconder la guia operativa detras de un clic no ahorra
                 espacio de verdad y cuesta un paso a quien ya esta mirando un
                 quiosco en fallo. El runbook NO viaja al navegador (regla
                 dura 16): solo se nombra.

                 OMITIDO para `ok` (correccion de `ui-ux`, segunda vuelta): un
                 quiosco que late con normalidad no tiene ninguna accion que
                 ofrecer, y repetir «no hace falta ninguna accion» en cada
                 fila sana es ruido que entierra el aviso de las filas que si
                 lo necesitan. -->
            <tr
              v-if="showsWhatToDo(device.health.verdict)"
              class="border-b border-kq-border bg-kq-surface-alt"
            >
              <td colspan="8" class="px-4 py-3 text-sm">
                <p class="font-semibold">{{ t('devices.health.whatToDoHeading') }}</p>
                <p data-test="what-to-do">{{ t(whatToDoKey(device.health.reason)) }}</p>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- Leyenda con los umbrales REALES de la instalacion (`meta.thresholds`,
         tarea 3.3): un aviso cuyo criterio no se ve es un aviso que nadie
         puede defender. -->
    <p v-if="legend !== ''" class="mt-2 text-sm text-kq-text-muted" data-test="thresholds-legend">
      {{ legend }}
    </p>

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
