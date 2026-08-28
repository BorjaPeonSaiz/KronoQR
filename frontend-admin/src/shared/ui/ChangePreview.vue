<script setup lang="ts">
// «Que se va a cambiar, desde que valor y hacia cual», antes de confirmar.
//
// Es una tabla y no una frase porque en una lista de cuatro cambios la frase no
// se lee. Cada fila lleva su encabezado de fila asociado (`th scope="row"`), que
// es lo que permite que un lector de pantalla diga «Departamento: de Cocina a
// Recepcion» en lugar de leer seis celdas sueltas.
//
// Los encabezados de las dos columnas de valor se pueden cambiar porque no
// siempre miran al futuro: confirmando un cambio son «valor actual» y «valor
// nuevo», pero en el historico de correcciones de una jornada son «antes» y
// «despues». Llamar «valor actual» a lo que se rectifico hace tres semanas
// seria decir que sigue vigente.
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Change } from './change'

const props = withDefaults(
  defineProps<{
    changes: readonly Change[]
    caption: string
    fromLabel?: string
    toLabel?: string
  }>(),
  { fromLabel: '', toLabel: '' },
)

const { t } = useI18n()

const fromHeading = computed(() =>
  props.fromLabel === '' ? t('common.change.from') : props.fromLabel,
)
const toHeading = computed(() => (props.toLabel === '' ? t('common.change.to') : props.toLabel))
</script>

<template>
  <table class="w-full border-collapse text-left">
    <caption class="sr-only">
      {{
        caption
      }}
    </caption>
    <thead>
      <tr class="border-b border-kq-border">
        <th scope="col" class="py-2 pr-4 font-semibold">{{ t('common.change.field') }}</th>
        <th scope="col" class="py-2 pr-4 font-semibold">{{ fromHeading }}</th>
        <th scope="col" class="py-2 font-semibold">{{ toHeading }}</th>
      </tr>
    </thead>
    <tbody>
      <tr v-for="change of changes" :key="change.label" class="border-b border-kq-border">
        <th scope="row" class="py-2 pr-4 font-medium">{{ change.label }}</th>
        <td class="py-2 pr-4 text-kq-text-muted">{{ change.from }}</td>
        <td class="py-2 font-semibold">{{ change.to }}</td>
      </tr>
    </tbody>
  </table>
</template>
