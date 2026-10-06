import { describe, expect, it } from 'vitest'
import { usePinKeypad } from '@/features/pin/composables/usePinKeypad'

describe('buffer del teclado numerico del PIN', () => {
  it('empieza vacio y no esta completo', () => {
    const pin = usePinKeypad()

    expect(pin.value.value).toBe('')
    expect(pin.canSubmit.value).toBe(false)
  })

  it('junta digitos en orden', () => {
    const pin = usePinKeypad()

    for (const digit of ['4', '8', '3', '9', '2', '0']) pin.pressDigit(digit)

    expect(pin.value.value).toBe('483920')
    expect(pin.canSubmit.value).toBe(true)
  })

  it('no admite un noveno digito', () => {
    const pin = usePinKeypad()
    for (const digit of ['4', '8', '3', '9', '2', '0', '1', '6']) pin.pressDigit(digit)

    pin.pressDigit('7')

    expect(pin.value.value).toBe('48392016')
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
    expect(pin.canSubmit.value).toBe(false)
  })

  it('no se envia solo: canSubmit es falso con 5, verdadero con 6, 7 y 8', () => {
    const pin = usePinKeypad()
    const states: boolean[] = []
    for (const digit of ['4', '8', '3', '9', '2', '0', '1', '6']) {
      pin.pressDigit(digit)
      states.push(pin.canSubmit.value)
    }

    expect(states).toEqual([false, false, false, false, false, true, true, true])
  })

  it('admite una longitud maxima y minima distintas, para pruebas', () => {
    const pin = usePinKeypad(4, 4)
    for (const digit of ['1', '2', '3', '4', '5']) pin.pressDigit(digit)

    expect(pin.value.value).toBe('1234')
    expect(pin.canSubmit.value).toBe(true)
  })
})
