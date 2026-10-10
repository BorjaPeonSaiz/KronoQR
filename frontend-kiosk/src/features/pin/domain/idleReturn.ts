// Inactividad de la pantalla de PIN (RF-AT-11, RF-KI-07, regla dura 19).
//
// Quien teclea su codigo o su PIN y se va sin confirmar dejaba la pantalla
// bloqueada para la siguiente persona y, con la guarda de interaccion de la
// puerta de actualizacion, la actualizacion cerrada para siempre. Tras este
// tiempo sin pulsar nada se borra lo tecleado y se vuelve a inicio. Cualquier
// pulsacion lo reinicia; no actua durante una verificacion ni una
// confirmacion visible (esas ya tienen su propio retorno).

/** 60 s: de sobra para buscar el PIN en la tarjeta, poco para dejar la tablet bloqueada. */
export const PIN_IDLE_RETURN_MS = 60_000
