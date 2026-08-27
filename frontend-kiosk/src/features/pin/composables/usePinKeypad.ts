// El buffer de digitos del teclado numerico del PIN (RF-AT-11, doc 01 §6.5).
//
// Deliberadamente ciego a la red y al sellado: solo junta y borra digitos. Eso
// es lo que permite probarlo sin montar ningun componente ni tocar
// `libsodium-wrappers`.

import type { Ref } from 'vue'
import { computed, ref } from 'vue'
import { PIN_LENGTH } from '../domain/pinCode'

export interface PinKeypad {
  readonly value: Readonly<Ref<string>>
  readonly isComplete: Readonly<Ref<boolean>>
  pressDigit(digit: string): void
  backspace(): void
  clear(): void
}

const DIGIT = /^[0-9]$/

export function usePinKeypad(length: number = PIN_LENGTH): PinKeypad {
  const value = ref('')

  return {
    value,
    isComplete: computed(() => value.value.length === length),

    pressDigit(digit) {
      if (!DIGIT.test(digit)) return
      if (value.value.length >= length) return
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
