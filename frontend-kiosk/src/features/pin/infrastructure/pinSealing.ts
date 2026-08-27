// Sobre cerrado de libsodium para el PIN de respaldo (RF-AT-11, RL-12).
//
// EL PIN NUNCA VIAJA NI SE GUARDA EN CLARO, NI SIQUIERA CON RED. En cuanto el
// empleado teclea el sexto digito, esta funcion lo cierra con la clave publica
// de la instalacion (`pin_sealing_public_key`, de `GET /api/v1/kiosk/roster`)
// y lo que sale es el UNICO rastro que existe del PIN a partir de ese momento:
//
//   pin_sealed = base64( crypto_box_seal(pinUtf8Bytes, pin_sealing_public_key) )
//
// `crypto_box_seal` (sobre cerrado anonimo, curva X25519) genera una clave
// efimera POR LLAMADA: dos fichajes con el mismo PIN producen criptogramas
// distintos, y nadie que mire la cola puede agrupar por PIN ni reconocer una
// repeticion. Solo el servidor, con la clave privada de la instalacion
// (`IDENTITY_PIN_SEALING_SECRET_KEY`), puede abrirlo.
//
// POR QUE CERRADO Y NO EN CLARO SOBRE TLS. El quiosco no puede esperar a tener
// red para aceptar un fichaje (regla dura 19): confirma en local y encola. Con
// la tarjeta eso es facil porque el padron cacheado resuelve el QR sin
// servidor; un PIN solo se puede comprobar contra `pin_hash`, que no sale del
// servidor. Sellarlo al teclearlo es lo unico que evita elegir entre bloquear
// al empleado o dejar el PIN en claro en IndexedDB.
//
// POR QUE SE PRECARGA (`warmUpSealing`). `sodium.ready` inicializa WebAssembly
// la primera vez, y eso SI puede tardar unos milisegundos — nada que ver con la
// red, pero es trabajo que no interesa hacer mientras el empleado ya ha
// pulsado «Confirmar». La pantalla del teclado llama a `warmUpSealing()` al
// montarse, para que cuando llegue el sexto digito el sellado sea, en la
// practica, sincrono.

// Importacion POR DEFECTO, no con espacio de nombres. Verificado contra el
// paquete real (0.8.4): el build ESM solo declara como exportaciones CON
// NOMBRE un puñado de utilidades (`ready`, `from_base64`, `to_base64`...); las
// funciones `crypto_*` -- incluida `crypto_box_seal`, la que aqui importa --
// se anaden en tiempo de ejecucion al mismo objeto que se exporta POR DEFECTO,
// una vez resuelto `sodium.ready`. Un `import * as sodium` compila (la
// declaracion de tipos SI las lista) pero `sodium.crypto_box_seal` es
// `undefined` en marcha: haria que ESTE fichero jamas sellara nada.
import sodium from 'libsodium-wrappers'

let readyPromise: Promise<typeof sodium> | null = null

function whenReady(): Promise<typeof sodium> {
  readyPromise ??= sodium.ready.then(() => sodium)
  return readyPromise
}

/** Se llama al montar la pantalla de PIN, para que `sealPin` no espere a WASM. */
export function warmUpSealing(): void {
  void whenReady()
}

export class PinSealingError extends Error {
  constructor(cause: string) {
    super(`pin_sealing_failed: ${cause}`)
    this.name = 'PinSealingError'
  }
}

/**
 * Sella el PIN. El PIN en claro entra como parametro y no se conserva ninguna
 * referencia a el mas alla de esta llamada: no se asigna a ninguna variable
 * exterior, no se registra y no se guarda.
 *
 * @param pin Seis digitos ASCII, sin relleno ni terminador (doc de contrato).
 * @param publicKeyBase64 `pin_sealing_public_key` del padron, en base64 estandar.
 * @returns El sobre cerrado, en base64 estandar (`pin_sealed`).
 */
export async function sealPin(pin: string, publicKeyBase64: string): Promise<string> {
  const libsodium = await whenReady()

  let publicKey: Uint8Array
  try {
    publicKey = libsodium.from_base64(publicKeyBase64, libsodium.base64_variants.ORIGINAL)
  } catch {
    throw new PinSealingError('invalid_public_key')
  }

  try {
    // El mensaje se pasa como STRING, no como `from_string(pin)`. Verificado
    // contra el entorno de pruebas (jsdom): el `Uint8Array` que devuelve
    // `from_string` no pasa la comprobacion interna de `crypto_box_seal` para
    // el mensaje (aunque su propio `instanceof Uint8Array` de mas alto nivel
    // de digan que si — el fallo real esta dentro del glue asm.js de
    // libsodium). `crypto_box_seal` acepta un `string` directamente y lo
    // codifica el mismo, por eso esta ruta si funciona siempre.
    const sealed = libsodium.crypto_box_seal(pin, publicKey)
    return libsodium.to_base64(sealed, libsodium.base64_variants.ORIGINAL)
  } catch {
    throw new PinSealingError('seal_failed')
  }
}
