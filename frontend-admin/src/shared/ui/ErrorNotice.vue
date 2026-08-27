<script setup lang="ts">
// Un error de red o de la API, contado como se cuenta a una persona: QUE ha
// pasado y QUE hacer ahora. Nunca se enseña `problem.detail`, que el contrato
// define como explicacion para quien depura, ni el codigo HTTP a secas.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { ApiError, isApiError } from '@/shared/api/http'

const props = defineProps<{ error: unknown }>()

const { t } = useI18n()

const apiError = computed<ApiError>(() =>
  isApiError(props.error) ? props.error : new ApiError({ kind: 'unexpected', status: 0 }),
)

const fields = computed(() => Object.entries(apiError.value.fieldErrors))
</script>

<template>
  <div role="alert" class="rounded-md border border-red-300 bg-red-50 p-4 text-red-900">
    <p class="font-semibold">{{ t(apiError.titleKey) }}</p>
    <p class="mt-1">{{ t(apiError.adviceKey) }}</p>
    <p v-if="apiError.retryAfterSeconds !== null" class="mt-1">
      {{ t('errors.retryAfter', { seconds: apiError.retryAfterSeconds }) }}
    </p>
    <ul v-if="fields.length > 0" class="mt-2 list-disc pl-5">
      <li v-for="[field, messages] of fields" :key="field">
        {{ field }}: {{ messages.join(' ') }}
      </li>
    </ul>
  </div>
</template>
