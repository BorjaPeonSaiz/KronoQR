<script setup lang="ts">
// Los PIN nuevos de una readmision en lote, mostrados UNA sola vez (RF-ID-09, RL-05).
//
// Misma regla que `PinRevealDialog`, para varias personas: los PIN llegan por
// `props` desde el estado efimero de la pantalla, no se escriben en ningun sitio
// (ni almacenamiento, ni tienda, ni cache de consultas) y desaparecen al cerrar.
// No se cierra por descuido: sin Escape ni velo, y la unica salida dice
// literalmente que no se podran volver a consultar. No hay boton de copiar.
// La entrega en mano se registra despues, persona a persona, desde su ficha.
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { RehirePinRow } from './rehirePlan'

defineProps<{ rows: readonly RehirePinRow[] }>()

const emit = defineEmits<{ acknowledged: [] }>()

const { t } = useI18n()

const noted = ref(false)
</script>

<template>
  <BaseDialog :title="t('employees.rehire.pins.heading')" size="wide" :dismissible="false">
    <p class="max-w-prose text-kq-text-muted">{{ t('employees.rehire.pins.explanation') }}</p>

    <div tabindex="0" role="region" :aria-label="t('employees.rehire.pins.caption')" class="mt-4">
      <table class="w-full border-collapse text-left">
        <caption class="sr-only">
          {{
            t('employees.rehire.pins.caption')
          }}
        </caption>
        <thead class="border-b border-kq-border bg-kq-surface-alt">
          <tr>
            <th scope="col" class="px-3 py-2">{{ t('employees.table.name') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('employees.table.code') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('pin.field') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row of rows" :key="row.employeeUuid" class="border-b border-kq-border">
            <th scope="row" class="px-3 py-2 font-medium">{{ row.name }}</th>
            <td class="px-3 py-2 font-mono">{{ row.code }}</td>
            <td class="px-3 py-2 font-mono text-xl tracking-[0.25em]" data-test="batch-pin-value">
              {{ row.pin }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <p
      role="alert"
      class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      {{ t('pin.reveal.onlyOnce') }}
    </p>
    <p class="mt-3 text-sm text-kq-text-muted">{{ t('pin.reveal.handDelivery') }}</p>
    <p class="mt-1 text-sm text-kq-text-muted">{{ t('employees.rehire.pins.deliveryLater') }}</p>

    <label class="mt-4 flex items-start gap-2">
      <input v-model="noted" type="checkbox" class="mt-1" data-test="batch-pins-noted" />
      <span>{{ t('employees.rehire.pins.notedLabel') }}</span>
    </label>

    <template #actions>
      <button
        v-aria-disabled.reason="!noted"
        type="button"
        data-test="batch-pins-continue"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary aria-disabled:opacity-60"
        @click="emit('acknowledged')"
      >
        {{ t('employees.rehire.pins.continue') }}
      </button>
    </template>
  </BaseDialog>
</template>
