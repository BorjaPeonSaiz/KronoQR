<script setup lang="ts">
// Un error de red o de la API, contado como se cuenta a una persona: QUE ha
// pasado y QUE hacer ahora. Nunca se enseña `problem.detail`, que el contrato
// define como explicacion para quien depura, ni el codigo HTTP a secas.
// Componente compartido por las SPA del panel y del portal (ADR-036).
//
// Las claves de i18n (`errors.<kind>.title`, `errors.<kind>.advice`,
// `errors.retryAfter`) las resuelve la aplicacion que lo consume: cada SPA
// declara su propio `locales/{es,en}.json` con el mismo catalogo de causas que
// exporta `ApiErrorKind`.
//
// **«Reintentar».** Una lista que no carga y que dice «vuelve a intentarlo» sin un
// boton obliga a recargar el navegador (V3-UX-01). Si quien lo usa pasa `@retry`,
// aparece el boton y el evento vuelve a pedir lo que fallo. Se declara como prop
// `onRetry` y no como evento para que su mera presencia decida si se pinta.
//
// Los errores por campo de un `422` llegan ya en el idioma de la persona (el
// servidor los escribe en el que pide `Accept-Language`); lo que este
// componente no puede saber es como se llama cada campo EN ESTA PANTALLA. Por
// eso `fieldLabels`: la vista que conoce su formulario pasa las etiquetas y la
// lista dice «Hasta: …» en vez de «to: …». Sin etiqueta se enseña la clave, que
// es mejor que esconder el error.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError, isApiError } from '../http'
import BaseButton from './BaseButton.vue'

const props = defineProps<{
  error: unknown
  /** Etiqueta visible de cada campo del formulario, por su nombre en la API. */
  fieldLabels?: Readonly<Record<string, string>>
  /** Vuelve a pedir lo que fallo. Si falta, no hay boton. */
  onRetry?: () => void
}>()

const { t } = useI18n()

const apiError = computed<ApiError>(() =>
  isApiError(props.error) ? props.error : new ApiError({ kind: 'unexpected', status: 0 }),
)

const fields = computed(() =>
  Object.entries(apiError.value.fieldErrors).map(([field, messages]) => ({
    field,
    label: props.fieldLabels?.[field] ?? field,
    messages,
  })),
)
</script>

<template>
  <div role="alert" class="rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger">
    <p class="font-semibold">{{ t(apiError.titleKey) }}</p>
    <p class="mt-1">{{ t(apiError.adviceKey) }}</p>
    <p v-if="apiError.retryAfterSeconds !== null" class="mt-1">
      {{ t('errors.retryAfter', { seconds: apiError.retryAfterSeconds }) }}
    </p>
    <BaseButton
      v-if="onRetry !== undefined"
      variant="secondary"
      class="mt-3"
      data-test="error-retry"
      @click="onRetry()"
    >
      {{ t('errors.retry') }}
    </BaseButton>
    <ul v-if="fields.length > 0" class="mt-2 list-disc pl-5">
      <li v-for="{ field, label, messages } of fields" :key="field">
        {{ label }}: {{ messages.join(' ') }}
      </li>
    </ul>
  </div>
</template>
