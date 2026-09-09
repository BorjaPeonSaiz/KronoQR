<script setup lang="ts">
// Filas del historico de errores (RF-PD-15).
//
// SIN VIRTUALIZAR A PROPOSITO: el contrato limita `per_page` a 100 (el mismo
// techo que la bandeja de soporte y la de exportaciones), y cada fila puede
// expandirse a una altura variable -contexto, «que hacer»-, que no encaja bien
// con una lista virtualizada de altura estimada fija. Ver `README.md`.
//
// LA SEVERIDAD SE DICE CON PALABRAS, NO SOLO CON COLOR (WCAG 2.2 AA, 1.4.1): el
// texto del nivel va siempre junto al color de fondo.
//
// LA ANTIGUEDAD DE `last_seen_at` SE CALCULA CONTRA EL RELOJ DEL SERVIDOR
// (regla dura 3): la vista pasa `serverNowMs`, nunca se usa `Date.now()` aqui.
//
// EXPANSION ACCESIBLE: el boton de cada fila lleva `aria-expanded` y
// `aria-controls`; el foco se queda en el propio boton al abrir o cerrar (el
// patron de un `disclosure`, WAI-ARIA APG), asi que no hace falta moverlo a
// ningun otro sitio para que quien usa el teclado sepa que ha pasado.
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ErrorEvent, ErrorSource } from '@/shared/api/types'
import { ageSinceLastSeen, levelBadgeClass, whatToDoKey } from './errorPresentation'

const props = defineProps<{
  entries: readonly ErrorEvent[]
  timeZone: string
  /** El «ahora» del servidor, en milisegundos desde la epoca. */
  serverNowMs: number
  /** Oculto/deshabilitado para un actor de soporte (decision 8 de la ficha). */
  canResolve: boolean
}>()

const emit = defineEmits<{ resolve: [entry: ErrorEvent] }>()

const { t, locale } = useI18n()

const SOURCES: readonly ErrorSource[] = [
  'api',
  'worker',
  'scheduler',
  'console',
  'kiosk',
  'admin',
  'portal',
]

function sourceLabel(source: ErrorSource): string {
  return SOURCES.includes(source) ? t(`errorEvents.sources.${source}`) : source
}

const expanded = reactive(new Set<number>())

function isExpanded(id: number): boolean {
  return expanded.has(id)
}

function toggle(id: number): void {
  if (expanded.has(id)) {
    expanded.delete(id)
  } else {
    expanded.add(id)
  }
}

function firstSeenLabel(entry: ErrorEvent): string {
  return formatInstant(entry.first_seen_at, props.timeZone, locale.value)
}

function lastSeenLabel(entry: ErrorEvent): string {
  return formatInstant(entry.last_seen_at, props.timeZone, locale.value)
}

function ageLabel(entry: ErrorEvent): string {
  return t('errorEvents.table.ageValue', {
    duration: t('errorEvents.duration', ageSinceLastSeen(entry.last_seen_at, props.serverNowMs)),
  })
}

const copiedIds = reactive(new Set<number>())

function fallbackCopy(value: string): void {
  const textarea = document.createElement('textarea')

  textarea.value = value
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.opacity = '0'
  document.body.appendChild(textarea)
  textarea.select()
  document.execCommand('copy')
  document.body.removeChild(textarea)
}

async function copyTraceId(entry: ErrorEvent): Promise<void> {
  const value = entry.trace_id

  if (value === null) {
    return
  }

  try {
    if (navigator.clipboard?.writeText !== undefined) {
      await navigator.clipboard.writeText(value)
    } else {
      fallbackCopy(value)
    }
  } catch {
    fallbackCopy(value)
  }

  copiedIds.add(entry.id)
  window.setTimeout(() => copiedIds.delete(entry.id), 2_000)
}

interface ContextLine {
  key: string
  value: string
}

function contextLines(entry: ErrorEvent): ContextLine[] {
  return Object.entries(entry.context).map(([key, value]) => ({ key, value: String(value) }))
}
</script>

<template>
  <div
    class="overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
    data-test="error-table"
  >
    <table class="w-full border-collapse text-left">
      <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
        {{
          t('errorEvents.table.caption', { zone: timeZone })
        }}
      </caption>
      <thead class="border-b border-kq-border bg-kq-surface-alt text-sm font-semibold">
        <tr>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.level') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.source') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.message') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.occurrences') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.firstSeen') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.lastSeen') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.version') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.traceId') }}</th>
          <th scope="col" class="px-3 py-2">{{ t('errorEvents.table.actions') }}</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="entry of entries" :key="entry.id">
          <tr class="border-b border-kq-border" data-test="error-row" :data-error-id="entry.id">
            <td class="px-3 py-2">
              <span
                class="inline-block rounded-full px-3 py-1 text-sm font-semibold"
                :class="levelBadgeClass(entry.level)"
                :data-test="`level-${entry.id}`"
              >
                {{ t(`errorEvents.levels.${entry.level}`) }}
              </span>
            </td>
            <td class="px-3 py-2">{{ sourceLabel(entry.source) }}</td>
            <td class="max-w-sm truncate px-3 py-2" :title="entry.message">{{ entry.message }}</td>
            <td class="px-3 py-2 tabular-nums" data-test="occurrences">
              {{ t('errorEvents.table.occurrencesValue', { count: entry.occurrences }) }}
            </td>
            <td class="px-3 py-2">{{ firstSeenLabel(entry) }}</td>
            <td class="px-3 py-2">
              <span class="block">{{ lastSeenLabel(entry) }}</span>
              <span class="block text-sm text-kq-text-muted">{{ ageLabel(entry) }}</span>
            </td>
            <td class="px-3 py-2">{{ entry.app_version }}</td>
            <td class="px-3 py-2">
              <button
                v-if="entry.trace_id !== null"
                type="button"
                class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 font-mono text-xs hover:bg-kq-surface-alt"
                :data-test="`copy-trace-${entry.id}`"
                @click="copyTraceId(entry)"
              >
                {{ copiedIds.has(entry.id) ? t('errorEvents.table.copied') : entry.trace_id }}
              </button>
              <span v-else class="text-kq-text-muted">{{ t('errorEvents.table.noTraceId') }}</span>
            </td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm hover:bg-kq-surface-alt"
                  :aria-expanded="isExpanded(entry.id)"
                  :aria-controls="`error-detail-${entry.id}`"
                  :data-test="`toggle-${entry.id}`"
                  @click="toggle(entry.id)"
                >
                  {{
                    isExpanded(entry.id)
                      ? t('errorEvents.table.collapse')
                      : t('errorEvents.table.expand')
                  }}
                </button>
                <button
                  v-if="entry.resolved_at === null && canResolve"
                  type="button"
                  class="rounded-kq-sm bg-kq-primary-strong px-3 py-1 text-sm font-semibold text-kq-on-primary"
                  :data-test="`resolve-${entry.id}`"
                  @click="emit('resolve', entry)"
                >
                  {{ t('errorEvents.resolve.action') }}
                </button>
                <span
                  v-else-if="entry.resolved_at !== null"
                  class="text-sm text-kq-text-muted"
                  :data-test="`resolved-${entry.id}`"
                >
                  {{ t('errorEvents.table.resolved') }}
                </span>
              </div>
            </td>
          </tr>
          <tr
            v-if="isExpanded(entry.id)"
            :id="`error-detail-${entry.id}`"
            class="border-b border-kq-border bg-kq-surface-alt"
          >
            <td colspan="9" class="px-4 py-4">
              <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="font-medium">{{ t('errorEvents.detail.exceptionClass') }}</dt>
                <dd data-test="detail-exception-class">
                  {{ entry.exception_class ?? t('errorEvents.detail.notApplicable') }}
                </dd>
                <dt class="font-medium">{{ t('errorEvents.detail.location') }}</dt>
                <dd data-test="detail-location">
                  {{
                    entry.file !== null && entry.line !== null
                      ? `${entry.file}:${entry.line}`
                      : t('errorEvents.detail.notApplicable')
                  }}
                </dd>
                <dt class="font-medium">{{ t('errorEvents.detail.module') }}</dt>
                <dd>{{ entry.module ?? t('errorEvents.detail.notApplicable') }}</dd>
                <dt class="font-medium">{{ t('errorEvents.detail.code') }}</dt>
                <dd>{{ entry.code ?? t('errorEvents.detail.notApplicable') }}</dd>
                <dt class="font-medium">{{ t('errorEvents.detail.deviceId') }}</dt>
                <dd>{{ entry.device_id ?? t('errorEvents.detail.notApplicable') }}</dd>
                <dt class="font-medium">{{ t('errorEvents.detail.employeeUuid') }}</dt>
                <dd>{{ entry.employee_uuid ?? t('errorEvents.detail.notApplicable') }}</dd>
              </dl>

              <div v-if="contextLines(entry).length > 0" class="mt-4">
                <p class="font-medium">{{ t('errorEvents.detail.context') }}</p>
                <dl class="mt-1 grid grid-cols-2 gap-x-6 gap-y-1 text-sm">
                  <template v-for="line of contextLines(entry)" :key="line.key">
                    <dt class="font-mono">{{ line.key }}</dt>
                    <dd>{{ line.value }}</dd>
                  </template>
                </dl>
              </div>

              <div
                class="mt-4 rounded-kq border border-kq-border bg-kq-surface-raised p-3"
                data-test="what-to-do"
              >
                <p class="font-semibold">{{ t('errorEvents.detail.whatToDoHeading') }}</p>
                <p class="mt-1">{{ t(whatToDoKey(entry.source, entry.level)) }}</p>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
