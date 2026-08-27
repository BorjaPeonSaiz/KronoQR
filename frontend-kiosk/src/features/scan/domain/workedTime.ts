// Presentacion del acumulado de la jornada.
//
// **Horas y minutos, nunca decimales** (paso 8 de la tarea 1.8). «6,0 h» no
// significa nada para quien acaba de terminar un turno; «6 h 0 min» si. Y un
// decimal en pantalla invita a discutir si 7,75 h son 7 h 45 min o 7 h 75 min,
// que es exactamente la discusion que un registro horario no debe provocar.
//
// El servidor manda minutos enteros (`worked_minutes`), recalculados y nunca
// acumulados (RN-06, ADR-007). Aqui no se calcula nada: solo se parte en dos.

export interface WorkedTime {
  readonly hours: number
  readonly minutes: number
}

export function splitWorkedMinutes(totalMinutes: number): WorkedTime {
  // Un total negativo o no finito es un fallo del servidor, no del empleado: se
  // ensena 0 h 0 min en lugar de «NaN h», que es lo que llega a la pantalla si
  // no se defiende aqui.
  if (!Number.isFinite(totalMinutes) || totalMinutes <= 0) {
    return { hours: 0, minutes: 0 }
  }

  const total = Math.floor(totalMinutes)
  return { hours: Math.floor(total / 60), minutes: total % 60 }
}
