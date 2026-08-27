// Hora de pared para la pantalla de confirmacion.
//
// SIEMPRE en formato de 24 horas, en los dos idiomas. El doc 01 §11 fija el
// ejemplo —«Entrada 07:02»— y un registro horario no puede permitirse un «07:02»
// que en ingles se convierta en «7:02 AM» y en la conversacion de un turno de
// noche acabe siendo «las siete» sin saber de cual. `h23` lo garantiza sin
// depender de las preferencias regionales del aparato.
//
// La conversion de UTC a la hora local ocurre AQUI, en presentacion, y solo
// aqui (regla dura 3). La zona es la del dispositivo, que es la del centro.

export function formatClockTime(instant: Date, locale: string): string {
  if (Number.isNaN(instant.getTime())) return '--:--'

  return new Intl.DateTimeFormat(locale, {
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(instant)
}
