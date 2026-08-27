// Ampliacion de `lib.dom` para las capacidades de camara que el quiosco SI usa
// y TypeScript todavia no declara.
//
// `focusMode` y `torch` estan en el Media Capture Extensions del W3C e
// implementados en Chromium sobre Android, que es el navegador de la tablet.
// Sin estas declaraciones habria que escribir `as any` para pedirlas, y el
// documento 02 §3.5 no admite `any`. Declarar la ampliacion es la forma correcta
// de decir «esto existe en mi plataforma objetivo».
//
// Todo lo que se pide a traves de estos campos va envuelto en `try/catch` y
// comprobado contra `getCapabilities()`: una tablet que no los exponga tiene que
// seguir escaneando (regla dura 19).

export {}

declare global {
  interface MediaTrackConstraintSet {
    focusMode?: ConstrainDOMString
    torch?: ConstrainBoolean
    zoom?: ConstrainDouble
  }

  interface MediaTrackCapabilities {
    focusMode?: string[]
    torch?: boolean
    zoom?: DoubleRange
  }

  interface MediaTrackSettings {
    focusMode?: string
    torch?: boolean
    zoom?: number
  }

  interface MediaTrackSupportedConstraints {
    focusMode?: boolean
    torch?: boolean
  }
}
