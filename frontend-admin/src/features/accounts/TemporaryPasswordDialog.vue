<script setup lang="ts">
// Visualizacion UNICA de una contrasena temporal (RF-ID-10), tras un alta o un
// restablecimiento. Mismo patron que `PinRevealDialog` (RF-ID-09):
//
//  - La contrasena llega por `props` desde el estado efimero de la pantalla que
//    la pidio y **no se escribe en ningun sitio**: ni `localStorage`, ni
//    `sessionStorage`, ni la tienda de Pinia, ni la cache de consultas. Al
//    cerrar, el padre pone su `ref` a `null` y el valor desaparece.
//  - No hay boton de copiar, como en el PIN: un secreto en el portapapeles acaba en
//    otras aplicaciones (y pegado en un chat). Se entrega de viva voz o en papel.
//  - No se cierra por descuido: sin Escape ni velo. Solo con la casilla
//    «la he entregado en mano» marcada, y el boton dice que no se podra volver
//    a ver. Si se pierde, se restablece y se emite otra.
//  - La entrega es presencial, nunca por correo (regla dura 12): no hay
//    endpoint de acuse para las cuentas, asi que la casilla solo habilita el
//    cierre; el asiento de auditoria ya lo escribio la emision.
//  - La caducidad se enseña en la zona horaria del centro, con la zona escrita.
import { formatInstantWithZone } from '@kronoqr/web-kit/datetime'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { TemporaryPasswordIssued } from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'

defineProps<{
  password: TemporaryPasswordIssued
  accountName: string
  accountEmail: string
  timezone: string
  /** `created`: acaba de darse de alta; `reset`: se ha restablecido su contrasena. */
  reason: 'created' | 'reset'
}>()

const emit = defineEmits<{ acknowledged: [] }>()

const { t, locale } = useI18n()

const handedOver = ref(false)
</script>

<template>
  <BaseDialog :title="t('accounts.reveal.heading')" :dismissible="false">
    <p class="text-kq-text-muted">
      {{
        reason === 'created'
          ? t('accounts.reveal.forCreated', { name: accountName, email: accountEmail })
          : t('accounts.reveal.forReset', { name: accountName, email: accountEmail })
      }}
    </p>

    <p
      class="mt-4 rounded-kq border-2 border-kq-border-strong bg-kq-surface-alt px-3 py-6 text-center font-mono text-2xl break-all text-kq-text select-all"
      data-test="password-value"
    >
      {{ password.password }}
    </p>

    <p
      role="alert"
      class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
    >
      {{ t('accounts.reveal.onlyOnce') }}
    </p>

    <p class="mt-3 text-sm text-kq-text-muted">
      {{
        t('accounts.reveal.expires', {
          when: formatInstantWithZone(password.expires_at, timezone, locale),
        })
      }}
    </p>
    <p class="mt-1 text-sm text-kq-text-muted">{{ t('accounts.reveal.handDelivery') }}</p>

    <label class="mt-4 flex items-start gap-2">
      <input v-model="handedOver" type="checkbox" class="mt-1" data-test="handed-over" />
      <span>{{ t('accounts.reveal.handedOverLabel', { name: accountName }) }}</span>
    </label>

    <template #actions>
      <button
        type="button"
        :disabled="!handedOver"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        data-test="acknowledge"
        @click="emit('acknowledged')"
      >
        {{ t('accounts.reveal.acknowledge') }}
      </button>
    </template>
  </BaseDialog>
</template>
