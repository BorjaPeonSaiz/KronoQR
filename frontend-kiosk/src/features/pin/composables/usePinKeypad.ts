// El buffer de digitos del teclado numerico del PIN (RF-AT-11, doc 01 §6.5).
//
// Solo habilita el envio entre 6 y 8 cifras y NUNCA envia por si mismo (ADR-050).
// Deliberadamente ciego a la red y al sellado: solo junta y borra digitos. Eso
// es lo que permite probarlo sin montar ningun componente ni tocar
// `libsodium-wrappers`.

import type { Ref } from 'vue'
import { computed, ref } from 'vue'
import { PIN_MAX_LENGTH, PIN_MIN_LENGTH } from '../domain/pinCode'

export interface PinKeypad {
  readonly value: Readonly<Ref<string>>
  readonly canSubmit: Readonly<Ref<boolean>>
  pressDigit(digit: string): void
  backspace(): void
  clear(): void
}

const DIGIT = /^[0-9]$/

export function usePinKeypad(
  maxLength: number = PIN_MAX_LENGTH,
  minLength: number = PIN_MIN_LENGTH,
): PinKeypad {
  const value = ref('')

  return {
    value,
    canSubmit: computed(() => value.value.length >= minLength),

    pressDigit(digit) {
      if (!DIGIT.test(digit)) return
      if (value.value.length >= maxLength) return
      value.value += digit
    },

    backspace() {
      value.value = value.value.slice(0, -1)
    },

    clear() {
      value.value = ''
    },
  }
}
