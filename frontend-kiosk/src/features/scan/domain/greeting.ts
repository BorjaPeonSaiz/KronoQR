// Saludo del turno.
//
// Del doc 01 §11: «Buenos dias, Lucia — Entrada 07:02» y «Hasta luego, Lucia —
// Salida 11:02 · Hoy: 6 h 0 min». El saludo solo acompana a una ENTRADA; una
// salida se despide.
//
// Los cortes estan pensados para los turnos de un hotel, no para el reloj:
//
//   06:00–12:59  manana   (el cambio de turno de las 06:00 entra aqui)
//   13:00–20:59  tarde
//   21:00–05:59  noche
//
// Se calcula sobre la hora LOCAL del dispositivo, que es la del centro. Es
// presentacion pura: el instante que viaja al servidor siempre es UTC (regla
// dura 3).

export type GreetingSlot = 'morning' | 'afternoon' | 'night'

export function greetingSlotFor(localInstant: Date): GreetingSlot {
  const hour = localInstant.getHours()
  if (hour >= 6 && hour < 13) return 'morning'
  if (hour >= 13 && hour < 21) return 'afternoon'
  return 'night'
}
