// Generador del encabezado `traceparent` (W3C Trace Context), compartido por
// las tres SPA (tarea 3.1, decision 7 de la ficha: "la cabecera es
// `traceparent`, en el borde, en la aplicacion y en los tres frontends").
//
// POR QUE NO ES EL SDK WEB DE OPENTELEMETRY. El presupuesto del quiosco es
// 250 KB gzip de JS critico (Anexo A del doc 02) y el SDK completo no cabe en
// el margen que queda. Esto genera el identificador y nada mas: la raiz de la
// traza pasa a ser el `fetch` del navegador -que es lo que pide el doc 02
// §8.1- sin cargar un cliente de trazas en el dispositivo. El muestreo y el
// resto del arbol de spans los decide el backend a partir de esta cabecera
// (`PropagateTraceContext`, `app/Support/Observability`).
//
// FORMATO (https://www.w3.org/TR/trace-context/#traceparent-header):
//   00-<trace-id, 16 bytes hex>-<parent-id, 8 bytes hex>-01
// `00` es la unica version que existe hoy. `01` son las flags (`sampled`):
// se marca siempre a 1 porque aqui no hay decision de muestreo que tomar,
// solo la generacion del identificador.
//
// `crypto.getRandomValues`, nunca `Math.random`: un identificador de
// correlacion no puede depender de un generador previsible. El trace-id y el
// parent-id nunca son todo ceros -la especificacion los declara invalidos
// (`INVALID_TRACE_ID`/`INVALID_SPAN_ID`) y un backend que los reciba a cero
// trataria la peticion como "sin traza"-, asi que se regeneran si eso ocurre
// (probabilidad astronomicamente baja con 16 y 8 bytes, pero la
// especificacion lo prohibe y la prueba lo comprueba).
//
// UNA `traceparent` POR PETICION, no por sesion ni por dispositivo (doc 01
// §9.4). Un reenvio idempotente del mismo `scan_id` -la cola offline, un
// intento tras otro- lleva una `traceparent` nueva en cada intento: es la
// traza DEL INTENTO, no del fichaje. La correlacion entre intentos la da el
// `scan_id`, nunca la traza. Nada de esto se persiste en el dispositivo: se
// genera y se descarta con cada llamada.

function randomHex(byteLength: number): string {
  const bytes = new Uint8Array(byteLength)
  crypto.getRandomValues(bytes)

  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
}

function isAllZero(hex: string): boolean {
  return /^0+$/.test(hex)
}

/** 16 bytes en hexadecimal (32 caracteres): el `trace-id` del W3C Trace Context. */
function randomTraceId(): string {
  let id = randomHex(16)
  while (isAllZero(id)) id = randomHex(16)
  return id
}

/** 8 bytes en hexadecimal (16 caracteres): el `parent-id` del W3C Trace Context. */
function randomParentId(): string {
  let id = randomHex(8)
  while (isAllZero(id)) id = randomHex(8)
  return id
}

/**
 * Un `traceparent` W3C nuevo, listo para la cabecera del mismo nombre.
 *
 * Cada llamada genera un identificador propio: se invoca una vez por
 * peticion, nunca se reutiliza entre llamadas ni se guarda para la
 * siguiente.
 */
export function createTraceparent(): string {
  return `00-${randomTraceId()}-${randomParentId()}-01`
}
