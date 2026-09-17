<script setup lang="ts">
// Icono y texto de pausa (tarea 3.5, ADR-024), compartido por panel y portal
// (ADR-036). Antes cada SPA llevaba su propio SVG y su propia pareja de
// tokens -`kq-primary-soft` en el panel, `kq-accent-soft` en el portal- y ya
// habian divergido: `kq-accent` es decorativo (doc 06 S6.5), nunca debe
// llevar un significado como «esto fue una pausa», que es justo lo que un
// tinte de marca no puede prometer en una instalacion con otra marca.
//
// Texto E icono, nunca solo color (WCAG 1.4.1): el icono va `aria-hidden`,
// asi que quien navega con lector de pantalla depende del `label`, no del
// SVG.
//
// `pill` distingue las DOS formas en que aparece esta pausa en el detalle de
// jornada: `true` (por omision) es la insignia suelta en la salida de un
// tramo cerrado por `break_start`; `false` es el mismo icono y el mismo
// texto, sin el fondo redondeado, dentro de la fila que describe la pausa
// completa («Pausa de HH:MM a HH:MM (N min)»). El par de tokens es el mismo
// en los dos casos.
withDefaults(defineProps<{ label: string; pill?: boolean }>(), { pill: true })
</script>

<template>
  <span
    class="inline-flex items-center gap-1 text-kq-on-primary-soft"
    :class="
      pill
        ? 'rounded-full bg-kq-primary-soft px-2 py-0.5 text-sm font-semibold'
        : 'text-sm font-medium'
    "
  >
    <svg aria-hidden="true" viewBox="0 0 20 20" class="h-4 w-4 flex-none" fill="currentColor">
      <rect x="5" y="4" width="3.5" height="12" rx="1" />
      <rect x="11.5" y="4" width="3.5" height="12" rx="1" />
    </svg>
    {{ label }}
  </span>
</template>
