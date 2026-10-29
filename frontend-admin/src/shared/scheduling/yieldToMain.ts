// Ceder el hilo principal entre dos trozos de trabajo (RNF-P-04).
//
// Una tarea de mas de 50 ms es una tarea larga: mientras dura, el navegador no
// atiende teclas ni clics. Con 500 empleados y meses de historico, pintar de una
// vez las tarjetas, la tabla y sus filas la superaba en el portatil de recepcion
// (CPU ×4). Partir el trabajo en trozos y ceder el hilo entre ellos mantiene la
// pantalla receptiva y deja pintar a mitad.
//
// `scheduler.yield()` conserva la prioridad de la continuacion y es lo que usan
// los navegadores que lo tienen; sin el, un `setTimeout(0)` cede igual el hilo.

interface SchedulerWithYield {
  yield?: () => Promise<void>
}

export function yieldToMain(): Promise<void> {
  const scheduler = (globalThis as { scheduler?: SchedulerWithYield }).scheduler

  if (typeof scheduler?.yield === 'function') {
    return scheduler.yield()
  }

  return new Promise((resolve) => {
    setTimeout(resolve, 0)
  })
}
