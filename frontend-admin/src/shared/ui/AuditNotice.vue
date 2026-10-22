<script setup lang="ts">
// Aviso de que la consulta queda anotada (R7-PA-02; RS-05, ADR-037).
//
// Quien abre una pantalla con datos de terceros tiene derecho a saber que su
// consulta deja un asiento de lectura en `audit_log`: quien mira, cuando y con
// que alcance —NUNCA lo que se muestra—. Lo llevan todas las pantallas cuya
// lectura deja asiento (`auditedReads.ts` lista cuales y una prueba lo cruza con
// los conjuntos de datos que escribe el backend); las que no lo dejan (la ficha,
// departamentos, el cuadro de adopcion) no lo llevan: avisar de un asiento que no
// existe seria mentir.
//
// Es un texto, no una alerta: ni `role="status"` ni `aria-live`, para que no
// suene en cada carga. Se lee al recorrer la pagina.
import { useI18n } from 'vue-i18n'

withDefaults(defineProps<{ messageKey?: string }>(), { messageKey: 'audit.readNotice' })

const { t } = useI18n()
</script>

<template>
  <p class="mt-1 max-w-prose text-sm text-kq-text-muted" data-test="audit-notice">
    {{ t(messageKey) }}
  </p>
</template>
