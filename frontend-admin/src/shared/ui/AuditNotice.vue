<script setup lang="ts">
// Aviso de que la consulta queda anotada (R7-PA-02; RS-05, ADR-037).
//
// Quien abre una pantalla con datos de terceros tiene derecho a saber que su
// consulta deja un asiento de lectura en `audit_log`: quien mira, cuando y con
// que alcance —NUNCA lo que se muestra—. Es una banda breve y visible (frase corta
// con icono) y el detalle en un desplegable nativo, accesible con teclado.
//
// Lo llevan todas las pantallas cuya lectura deja asiento, y `tests/unit/auditNotice.spec.ts`
// cruza esa lista con los conjuntos de datos que escribe el backend (ADR-037): un
// conjunto nuevo sin pantalla decidida hace fallar la prueba. Las que no dejan
// asiento (la ficha, departamentos, el cuadro de adopcion) no lo llevan: avisar de
// un asiento que no existe seria mentir.
//
// Es un texto, no una alerta: `role="note"`, sin `aria-live`, para que no suene en
// cada carga. Colores: `border-kq-border-strong` sobre `bg-kq-surface-alt` (doc 06).
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{ messageKey?: string }>(), { messageKey: 'audit.readNotice' })

const { t } = useI18n()
</script>

<template>
  <div
    role="note"
    class="mt-3 flex max-w-prose items-start gap-3 border-l-4 border-kq-border-strong bg-kq-surface-alt p-3 text-sm text-kq-text"
    data-test="audit-notice"
  >
    <svg
      class="mt-0.5 size-5 shrink-0"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
      aria-hidden="true"
    >
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
      <circle cx="12" cy="12" r="3" />
    </svg>
    <div>
      <p class="font-semibold">{{ t('audit.noticeShort') }}</p>
      <details class="mt-1">
        <summary class="cursor-pointer underline">{{ t('audit.noticeMore') }}</summary>
        <p class="mt-1 text-kq-text-muted" data-test="audit-notice-detail">{{ t(messageKey) }}</p>
      </details>
    </div>
  </div>
</template>
