<script setup lang="ts">
// Logotipo o nombre de la instalacion (RF-PD-08), el mismo en las tres SPA.
//
// UN SOLO SIGNIFICADO para quien usa lector de pantalla: si hay logotipo, la
// imagen lleva el nombre en su `alt` y el nombre en texto NO se repite; sin
// logotipo, el nombre se lee como texto. Nunca los dos.
//
// El nombre configurado llega hasta 60 caracteres (contrato `Branding`). En un
// movil de 320-375 px no cabe en una linea sin salirse: se trunca con «…» y el
// nombre completo queda accesible en `title`. `min-w-0` es imprescindible: sin
// el, un elemento flex no se encoge por debajo del ancho de su contenido y
// `truncate` no llega a activarse nunca.
//
// El logotipo tiene tope de ALTO y de ANCHO con `object-contain`: un banner
// apaisado —el formato habitual de cabecera de hotel— escalaria a cientos de
// pixeles y empujaria la navegacion (revision de UI/UX de la tarea 5.8).
import { computed } from 'vue'
import type { Branding } from '../branding'

const props = withDefaults(
  defineProps<{
    branding: Branding
    /** `sm` para cabeceras, `lg` para pantallas de acceso. */
    size?: 'sm' | 'lg'
    /** `kiosk` usa los tokens del fondo oscuro de la tablet. */
    tone?: 'light' | 'kiosk'
  }>(),
  { size: 'sm', tone: 'light' },
)

const color = computed(() =>
  props.tone === 'kiosk' ? 'text-kq-kiosk-primary-strong' : 'text-kq-primary-strong',
)

const textClass = computed(() =>
  [
    'block max-w-full min-w-0 truncate font-heading font-bold',
    props.size === 'lg' ? 'text-3xl' : 'text-lg',
    color.value,
  ].join(' '),
)

const imageClass = computed(() =>
  props.size === 'lg'
    ? 'h-12 w-auto max-w-[16rem] object-contain'
    : 'h-8 w-auto max-w-[10rem] object-contain',
)
</script>

<template>
  <img
    v-if="props.branding.logoUrl !== null"
    :src="props.branding.logoUrl"
    :alt="props.branding.applicationName"
    :class="imageClass"
    data-testid="brand-mark"
  />
  <p v-else :class="textClass" :title="props.branding.applicationName" data-testid="brand-mark">
    {{ props.branding.applicationName }}
  </p>
</template>
