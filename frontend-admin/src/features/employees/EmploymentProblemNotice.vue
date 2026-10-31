<script setup lang="ts">
// El fallo de una accion de baja o readmision, contado con su «que hacer».
//
// Si el `409` es una de las causas que el contrato distingue, se enseña el
// texto propio de esa causa (`employees.problems.<causa>`: que ha pasado y que
// hacer). Cualquier otro fallo —red, 403, 429, un `409` generico— cae en
// `ErrorNotice`, que ya dice las dos cosas. Es una alerta: el lector de pantalla
// la lee solo, sin `announce`.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { employmentProblemKey } from './employmentProblems'

const props = defineProps<{ error: unknown }>()

const { t } = useI18n()

const key = computed(() => employmentProblemKey(props.error))
</script>

<template>
  <div
    v-if="key !== null"
    role="alert"
    data-test="employment-problem"
    :data-problem="key"
    class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
  >
    <p class="font-semibold">{{ t(`employees.problems.${key}.title`) }}</p>
    <p class="mt-1">{{ t(`employees.problems.${key}.advice`) }}</p>
  </div>
  <ErrorNotice v-else :error="error" />
</template>
