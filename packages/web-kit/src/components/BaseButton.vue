<script setup lang="ts">
// Boton comun de las tres SPA (V7-UX-03). Una sola receta para el primario, el secundario y el
// destructivo, con tres tamaños; el que antes eran cadenas de utilidades repetidas que divergian
// (`font-semibold` en unos y no en otros, `min-h-12` solo en el portal).
//
// Estado en vuelo. `busy` (envio en curso) y `disabled` NO usan el atributo `disabled`: lo
// sustituye `aria-disabled` con la directiva `v-aria-disabled`, para que el boton conserve el
// foco mientras se envia y el clic, el Enter, el Espacio y el envio implicito del formulario se
// bloqueen por codigo. `busy` ademas marca `aria-busy`. Foco visible: el `:focus-visible` global
// de `base.css`, que ningun estilo de aqui quita.
//
// Solo tokens `--kq-*`: ningun color propio. El estado deshabilitado baja la opacidad; los
// tonos de hover/activo/deshabilitado propios los define el sistema visual (16.3).
import { computed } from 'vue'
import { vAriaDisabled } from '../vAriaDisabled'

export type ButtonVariant = 'primary' | 'secondary' | 'danger'
export type ButtonSize = 'sm' | 'md' | 'lg'

const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant
    size?: ButtonSize
    type?: 'button' | 'submit' | 'reset'
    disabled?: boolean
    /** Envio en curso: bloquea como `disabled` y marca `aria-busy`. */
    busy?: boolean
  }>(),
  { variant: 'primary', size: 'md', type: 'button', disabled: false, busy: false },
)

const VARIANTS: Readonly<Record<ButtonVariant, string>> = {
  primary: 'bg-kq-primary-strong text-kq-on-primary',
  secondary:
    'border border-kq-border-strong bg-kq-surface-raised text-kq-text hover:bg-kq-surface-alt',
  danger: 'bg-kq-danger text-kq-on-danger',
}

// `lg` es el objetivo tactil de 48 px del portal (WCAG 2.5.8 con holgura) y del quiosco.
const SIZES: Readonly<Record<ButtonSize, string>> = {
  sm: 'px-3 py-1.5 text-sm',
  md: 'px-4 py-2',
  lg: 'min-h-12 px-4 py-2 text-lg',
}

const blocked = computed(() => props.disabled || props.busy)
const classes = computed(
  () =>
    `max-w-full break-words rounded-kq-sm font-semibold aria-disabled:cursor-not-allowed aria-disabled:opacity-50 ${VARIANTS[props.variant]} ${SIZES[props.size]}`,
)
</script>

<template>
  <button
    v-aria-disabled="blocked"
    :type="type"
    :aria-busy="busy ? 'true' : undefined"
    :class="classes"
  >
    <slot />
  </button>
</template>
