<script setup lang="ts">
// Dialogo modal accesible (WCAG 2.2 AA).
//
// Lo que hace y por que:
//  - `role="dialog"` + `aria-modal` + `aria-labelledby`: un lector de pantalla
//    anuncia de que va la ventana al abrirla.
//  - Atrapa el foco y lo devuelve al elemento que la abrio al cerrarse. Sin esto
//    quien navega con teclado se queda tabulando por detras del velo.
//  - `dismissible: false` para los dialogos que NO se pueden cerrar sin una
//    accion explicita — el del PIN, que solo se enseña una vez.
//
// No usa `<Teleport>` a proposito: el panel no tiene ningun ancestro con
// `transform`, asi que `position: fixed` basta, y mantenerlo en el arbol del
// componente hace que la prueba unitaria vea lo mismo que ve el navegador.
import { onBeforeUnmount, onMounted, ref, useId } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    /** Si se puede cerrar con Escape, con el velo o con el aspa. */
    dismissible?: boolean
    /** Anchura del panel. `wide` para las tablas de confirmacion largas. */
    size?: 'normal' | 'wide'
  }>(),
  { dismissible: true, size: 'normal' },
)

const emit = defineEmits<{ close: [] }>()

const titleId = useId()
const panel = ref<HTMLElement | null>(null)
let previouslyFocused: HTMLElement | null = null

const FOCUSABLE =
  'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusableElements(): HTMLElement[] {
  return Array.from(panel.value?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [])
}

function requestClose(): void {
  if (props.dismissible) {
    emit('close')
  }
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    event.stopPropagation()
    requestClose()

    return
  }

  if (event.key !== 'Tab') {
    return
  }

  const elements = focusableElements()
  const first = elements[0]
  const last = elements[elements.length - 1]

  if (first === undefined || last === undefined) {
    return
  }

  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault()
    last.focus()

    return
  }

  if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault()
    first.focus()
  }
}

onMounted(() => {
  previouslyFocused = document.activeElement instanceof HTMLElement ? document.activeElement : null
  const elements = focusableElements()
  ;(elements[0] ?? panel.value)?.focus()
})

onBeforeUnmount(() => {
  previouslyFocused?.focus()
})
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-950/70" aria-hidden="true" @click="requestClose" />
    <div
      ref="panel"
      role="dialog"
      aria-modal="true"
      :aria-labelledby="titleId"
      tabindex="-1"
      class="relative max-h-full w-full overflow-y-auto rounded-lg bg-white p-6 text-slate-900 shadow-xl"
      :class="size === 'wide' ? 'max-w-3xl' : 'max-w-xl'"
      @keydown="onKeydown"
    >
      <h2 :id="titleId" class="text-xl font-semibold">{{ title }}</h2>
      <div class="mt-4">
        <slot />
      </div>
      <div class="mt-6 flex flex-wrap justify-end gap-3">
        <slot name="actions" />
      </div>
    </div>
  </div>
</template>
