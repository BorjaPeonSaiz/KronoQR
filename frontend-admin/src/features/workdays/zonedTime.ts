// Convierte la hora que RRHH escribe EN LA ZONA DEL CENTRO al instante UTC
// que exigen las tres operaciones de correccion (RF-PA-04, regla dura 3).
//
// `@kronoqr/web-kit/datetime` ya resuelve la direccion contraria -leer un
// instante UTC en la zona del centro-, pero nunca la necesito: el servidor
// manda cada marca ya resuelta (`*_local`). La que falta es esta, y solo la
// necesita `CorrectionDialog.vue`: convertir lo que alguien acaba de teclear en
// un `<input type="datetime-local">` -pensado en la hora del centro, nunca en
// la del navegador- al `UtcTimestamp` del contrato.
//
// Vanilla `Intl.DateTimeFormat`, sin libreria de zonas: el proyecto no tiene
// ninguna instalada y añadirla es un `npm install`, que en esta maquina pierde
// las plataformas nativas de `@tailwindcss/oxide` (HANDOFF.md, «Trampas del
// entorno»). El algoritmo es el «adivina y corrige» que usa cualquier libreria
// de zonas sin IANA en el motor: se trata la hora escrita como si ya fuera UTC
// para tener una primera aproximacion, se mira que hora da esa aproximacion en
// la zona de verdad, y se corrige por la diferencia. Converge en una pasada
// salvo en dos filos del cambio de horario:
//
//  - **La hora que no existio nunca** (el reloj salta hacia adelante: en
//    `Europe/Madrid`, 2026-03-29 pasa de las 02:00 a las 03:00). Ninguna
//    aproximacion converge -la lectura final nunca coincide con lo tecleado-,
//    y `zonedInputToUtcIso` lo comprueba explicitamente al terminar: `null`
//    en vez de devolver el desplazamiento silencioso a la hora siguiente que
//    daban las dos pasadas sin esta comprobacion (hallazgo de la revision:
//    `02:30` se colaba como `03:30`).
//  - **La hora que se repite** (el reloj retrocede: en `Europe/Madrid`,
//    2026-10-25 vive las 02:00-03:00 dos veces). Aqui SI converge, y lo hace
//    de forma determinista hacia la SEGUNDA ocurrencia -la posterior en el
//    tiempo UTC, la de invierno-: la primera pasada, al tratar la hora
//    tecleada como si ya fuera UTC, cae siempre en el lado de invierno de la
//    transicion (el UTC de esa hora de pared en invierno es mayor que en
//    verano), y desde ahi ya no hay drift que corregir. Se fija como
//    convencion por prueba (`zonedTime.spec.ts`) en vez de dejarla como
//    accidente del algoritmo.

const LOCAL_INPUT_PATTERN = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/

interface WallClock {
  year: number
  month: number
  day: number
  hour: number
  minute: number
}

function parseLocalInput(value: string): WallClock | null {
  const match = LOCAL_INPUT_PATTERN.exec(value)

  if (match === null) {
    return null
  }

  const [, year, month, day, hour, minute] = match

  if (year === undefined || month === undefined || day === undefined) {
    return null
  }

  if (hour === undefined || minute === undefined) {
    return null
  }

  return {
    year: Number(year),
    month: Number(month),
    day: Number(day),
    hour: Number(hour),
    minute: Number(minute),
  }
}

function asUtcMs(wall: WallClock): number {
  return Date.UTC(wall.year, wall.month - 1, wall.day, wall.hour, wall.minute, 0)
}

/** Que hora de pared lee `timeZone` cuando el instante UTC es `ms`. */
function readInZone(ms: number, timeZone: string): WallClock {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).formatToParts(new Date(ms))

  const value = (type: string): number =>
    Number(parts.find((part) => part.type === type)?.value ?? '0')

  return {
    year: value('year'),
    month: value('month'),
    day: value('day'),
    hour: value('hour'),
    minute: value('minute'),
  }
}

/**
 * `YYYY-MM-DDTHH:MM`, tal y como lo escribe una persona pensando en la hora
 * del centro, al `UtcTimestamp` que espera el contrato. `null` si el valor no
 * tiene esa forma -un campo vacio, por ejemplo- o si esa hora de pared no
 * existio nunca en `timeZone` -el hueco del cambio de horario de primavera-,
 * nunca una fecha adivinada.
 */
export function zonedInputToUtcIso(value: string, timeZone: string): string | null {
  const wall = parseLocalInput(value)

  if (wall === null) {
    return null
  }

  const target = asUtcMs(wall)
  let guess = target

  // Dos pasadas: la primera casi siempre converge, la segunda cubre el filo de
  // un cambio de horario de verano en el que la primera aproximacion cae al
  // otro lado de la transicion.
  for (let attempt = 0; attempt < 2; attempt += 1) {
    const reading = asUtcMs(readInZone(guess, timeZone))
    const drift = target - reading

    if (drift === 0) {
      break
    }

    guess += drift
  }

  // La comprobacion que faltaba: si la hora que da `guess` leida de vuelta en
  // `timeZone` no es la que se tecleo, es que esa hora de pared no existio
  // -el hueco del cambio de horario de primavera-, y no hay ningun instante
  // UTC que la represente. Sin esto, las dos pasadas de arriba convergian en
  // silencio a la hora siguiente (02:30 se guardaba como si fuera 03:30).
  if (asUtcMs(readInZone(guess, timeZone)) !== target) {
    return null
  }

  return new Date(guess).toISOString()
}

/**
 * El valor de un `<input type="datetime-local">` a partir de la fecha y la
 * hora de un `LocalTimestamp` ya resuelto por el servidor
 * (`readLocalTimestamp` de `@kronoqr/web-kit/datetime`). No convierte nada:
 * junta dos cadenas que ya estan en la zona del centro.
 */
export function toLocalInputValue(date: string, time: string): string {
  return `${date}T${time}`
}
