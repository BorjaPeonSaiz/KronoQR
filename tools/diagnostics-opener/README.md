# Abridor de paquetes de diagnóstico de KronoQR

Herramienta **del fabricante**. Abre el paquete de diagnóstico **con datos
personales** que un cliente envía a soporte (RL-19,
[ADR-020](../../docs/adr/ADR-020-soporte-con-paquete-de-diagnostico.md),
enmienda del 01-11-2026).

> **Esto no se entrega a ningún cliente y no viaja en ninguna imagen.**
> `.dockerignore` excluye `tools/`. En el servidor del cliente solo vive la
> **clave pública** de soporte, compilada en `backend/config/product.php`, y el
> producto solo cifra: nada en él abre un sobre.

## Qué es el sobre

El paquete anonimizado, el habitual, sale en claro y se lee con `less`. El que
lleva datos personales sale como `kronoqr-diagnostics-<versión>-<UTC>.sealed.json`:

1. El paquete entero se cifra con XChaCha20-Poly1305-IETF bajo una clave de un
   solo uso.
2. Esa clave va en un sobre sellado X25519 (`crypto_box_seal`) para la clave
   pública de soporte.
3. La **cabecera**, en claro, es todo el fichero menos `sealed_key`, `nonce` y
   `ciphertext`: qué secciones lleva, cuántas filas y qué columnas tiene cada
   colección de `personal_data`, y la huella del texto en claro
   (`manifest.sha256`). Su forma canónica son los **datos asociados** del
   cifrado: si alguien la cambia, el sobre no abre.

El cliente no puede abrir el contenido; lo que sí revisa es esa cabecera, y su
huella coincide con el asiento `diagnostics.bundle_generated` de su auditoría.

## La clave de soporte no es la de licencias

Son dos pares distintos. Con la privada de **licencias**
(`tools/license-issuer/`) se emiten licencias; con la de **soporte** se abren
paquetes. Separarlas evita que el equipo de soporte pueda emitir licencias, y
que el compromiso de una arrastre a la otra. Rotan con ciclos distintos.

## Puesta en marcha, una sola vez

```bash
php tools/diagnostics-opener/generate-keypair.php --keyring=~/.kronoqr/diagnostics > publica-soporte.hex
```

- La **privada** queda en `~/.kronoqr/diagnostics/<key_id>.key` (directorio
  `0700`, fichero `0600`, nunca se sobrescribe). Copia ese llavero al gestor de
  secretos de soporte: perderla es no poder abrir ningún paquete cifrado con
  esa clave. **Nunca entra en el repositorio**, ni en una prueba: las pruebas
  generan su propio par en cada ejecución.
- La **pública** (64 caracteres hexadecimales) se pega en
  `backend/config/product.php`, sustituyendo la cadena vacía del segundo
  argumento de `env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', '')`. Se compila con la
  imagen de release. `make release-gate` falla mientras esté vacía.

Sin `--keyring`, la privada sale por la salida de error y la pública por la
estándar, para que una tubería no acabe con la privada en un fichero.

Mientras la pública esté vacía, el producto **no genera** el paquete con datos
personales (`503` en el panel, código `1` en la consola) y `product:doctor`
avisa (`diagnostics.recipient_key`). Nunca sale en claro. El anonimizado, el
fichaje y el registro no dependen de esta clave.

## Abrir un paquete

```bash
php tools/diagnostics-opener/open.php kronoqr-diagnostics-2.3.0-20261101T101500Z.sealed.json \
    --keyring=~/.kronoqr/diagnostics
```

Elige la clave por el `recipient_key_id` del sobre, la abre, descifra con la
cabecera como datos asociados y comprueba que el manifiesto descifrado es el de
la cabecera, que la huella sale íntegra y que `contents` describe lo que lleva.
Escribe el paquete en claro junto al sobre (`….json`) o en `--output=RUTA`, con
permisos `0600` y **sin sobrescribir nada**.

| Código | Significado |
| --- | --- |
| `0` | Abierto, comprobado y escrito |
| `1` | No se puede abrir: falta la clave en el llavero, no es la suya, el sobre está alterado o cortado, lo descifrado no coincide con la cabecera, o la salida ya existe |
| `2` | Formato no válido: argumentos, fichero ilegible o un fichero que no es un sobre de esta versión |

**Lo que sale es una copia de la plantilla de un cliente.** Bórrala en cuanto
la incidencia no la necesite (`shred -u fichero.json`), y antes confirma con el
cliente que la huella coincide con su asiento.

**Lo que el sobre no hace:** no autentica a quien lo genera. Cualquiera con la
clave pública puede fabricar uno. La referencia de que salió de esa instalación
es el asiento `diagnostics.bundle_generated` del cliente, con la misma huella
(`sha256`) y el `sealed_sha256` del fichero entero.

## Rotar el par (RS-08)

1. Genera el par nuevo en el mismo llavero:
   `php tools/diagnostics-opener/generate-keypair.php --keyring=~/.kronoqr/diagnostics`.
   **No borres la privada anterior**: abre los paquetes que los clientes sigan
   enviando con la clave antigua hasta que actualicen.
2. Pega la pública nueva en `backend/config/product.php` y publica una versión.
   Cada cliente pasa a la clave nueva al actualizar; `product:doctor` enseña el
   identificador de la que usa.
3. Cuando ningún cliente soportado tenga una versión con la clave anterior,
   retira su `.key` del llavero.

**Rotación de urgencia** (la privada se ha comprometido): publica cuanto antes
una versión con la pública nueva. Mientras tanto, un cliente puede adelantarse
poniendo `DIAGNOSTICS_RECIPIENT_PUBLIC_KEY=<pública nueva>` en su `.env` y
recreando los contenedores, solo si soporte se lo pide. Los paquetes que ya
salieron con la clave comprometida se tratan como expuestos.
