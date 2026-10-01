<script setup lang="ts">
// Casilla de teletrabajo (RF-GP-01), compartida por el alta y la edicion de la
// ficha. El campo es **informativo**: no cambia como ficha la persona ni como se
// calculan sus horas, y el texto de ayuda lo dice con esas palabras para que
// nadie lo marque esperando otra cosa. La ayuda va ligada con `aria-describedby`.
import { useId } from 'vue'
import { useI18n } from 'vue-i18n'

const checked = defineModel<boolean>({ required: true })

const { t } = useI18n()
const inputId = useId()
const hintId = useId()
</script>

<template>
  <div class="flex flex-col gap-1">
    <span class="font-medium text-kq-text">{{ t('employees.fields.teleworking') }}</span>
    <div class="flex items-center gap-2">
      <input
        :id="inputId"
        v-model="checked"
        type="checkbox"
        class="size-5"
        data-test="teleworking-checkbox"
        :aria-describedby="hintId"
      />
      <label :for="inputId">{{ t('employees.fields.teleworkingLabel') }}</label>
    </div>
    <p :id="hintId" class="text-sm text-kq-text-muted">
      {{ t('employees.fields.teleworkingHint') }}
    </p>
  </div>
</template>
