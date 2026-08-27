import { describe, expect, it } from 'vitest'
import { usePinKeypad } from '@/features/pin/composables/usePinKeypad'

describe('buffer del teclado numerico del PIN', () => {
  it('empieza vacio y no esta completo', () => {
    const pin = usePinKeypad()

    expect(pin.value.value).toBe('')
    expect(pin.isComplete.value).toBe(false)
  })

  it('junta digitos en orden', () => {
    const pin = usePinKeypad()

    for (const digit of ['4', '8', '3', '9', '2', '0']) pin.pressDigit(digit)

    expect(pin.value.value).toBe('483920')
    expect(pin.isComplete.value).toBe(true)
  })

  it('no admite un septimo digito', () => {
    const pin = usePinKeypad()
    for (const digit of ['4', '8', '3', '9', '2', '0']) pin.pressDigit(digit)

    pin.pressDigit('7')

    expect(pin.value.value).toBe('483920')
  })

  it('ignora lo que no es un digito', () => {
    const pin = usePinKeypad()

    pin.pressDigit('a')
    pin.pressDigit('')
    pin.pressDigit('12')

    expect(pin.value.value).toBe('')
  })

  it('borra el ultimo digito', () => {
    const pin = usePinKeypad()
    pin.pressDigit('4')
    pin.pressDigit('8')

    pin.backspace()

    expect(pin.value.value).toBe('4')
  })

  it('borrar sin nada que borrar no rompe nada', () => {
    const pin = usePinKeypad()

    pin.backspace()

    expect(pin.value.value).toBe('')
  })

  it('limpia todo de una vez', () => {
    const pin = usePinKeypad()
    for (const digit of ['4', '8', '3']) pin.pressDigit(digit)

    pin.clear()

    expect(pin.value.value).toBe('')
    expect(pin.isComplete.value).toBe(false)
  })

  it('admite una longitud distinta de 6, para pruebas o para otros usos', () => {
    const pin = usePinKeypad(4)
    for (const digit of ['1', '2', '3', '4']) pin.pressDigit(digit)

    expect(pin.isComplete.value).toBe(true)
  })
})
