# ADR-009 — Sin biometría

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `seguridad-cumplimiento` |
| **Afecta a** | Todo el producto · Tareas 3.11 y 5.5 · [documento 01](../01-especificaciones-proyecto.md) §7.4 y §8.1 · **Regla dura 20** de `CLAUDE.md` |
| **Requisitos** | RF-PR-06, RN-16, RL-07, RL-08, RL-13, RS-01 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El reconocimiento facial o la huella son la respuesta comercialmente obvia al préstamo de credencial, y un cliente los pedirá antes o después: *«así no pueden fichar unos por otros»*.

Tres razones lo desaconsejan, y ninguna es técnica:

1. **Son datos de categoría especial** (art. 9 RGPD). Tratarlos exige una base jurídica reforzada; el consentimiento del trabajador no sirve como base válida en una relación laboral por el desequilibrio entre las partes, y la obligación legal del art. 34.9 ET ampara registrar la jornada, no hacerlo con biometría.
2. **El criterio de la autoridad de control ha sido restrictivo** respecto a la proporcionalidad de la biometría en control de presencia: si existe un medio menos invasivo que cumple la misma finalidad, el invasivo no es proporcionado. Y existe: una tarjeta.
3. **Hay alternativa que cubre el 100 % de la plantilla.** La tarjeta física ([ADR-014](ADR-014-la-credencial-es-una-tarjeta-fisica.md)) con PIN de respaldo (RF-AT-11) registra a todo el mundo sin tratar ningún dato especial.

A lo que se añade el argumento de producto: incorporar biometría dispararía la EIPD (RL-13), el conflicto con la representación de los trabajadores y el coste de hardware por punto de fichaje, en un producto cuyo diferencial es instalarse en una tarde.

## Decisión

**El producto no trata datos biométricos de ninguna clase. Ni reconocimiento facial, ni huella, ni patrón de voz, ni ningún otro.**

No es una funcionalidad aplazada ni una opción desactivada por defecto: **no existe en el producto**, y no hay configuración que la habilite. Si una tarea, un cliente o una petición la sugiere, se detiene el trabajo y se pregunta (regla dura 20).

La cámara de la tablet se usa **solo para decodificar el QR**. No se capturan, transmiten ni almacenan imágenes de personas, y `Permissions-Policy: camera=(self)` limita el acceso al origen propio.

**La contrapartida está escrita y tiene tarea asignada.** El préstamo de tarjeta es el único fraude que la firma HMAC no impide (§8.1), y se combate con supervisión presencial y con la **detección de patrones anómalos** (RF-PR-06, RN-16, tarea 3.11): fichajes consecutivos en el mismo quiosco separados por segundos, coincidencias sistemáticas entre dos empleados y secuencias imposibles entre dispositivos. Genera una incidencia para revisión humana y **nunca concluye por sí misma que ha habido fraude**.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Reconocimiento facial en el quiosco** | Dato de categoría especial, desproporcionado existiendo alternativa, y con sesgos demostrados por tono de piel, edad e iluminación. En una entrada de servicio mal iluminada, el falso rechazo recae siempre sobre las mismas personas |
| **Huella dactilar con lector** | Mismos problemas jurídicos, más hardware por punto de fichaje, más higiene —una cocina— y más soporte. La disponibilidad del acto de fichar bajaría, no subiría |
| **Biometría opcional, activable por el cliente** | Convierte una decisión jurídica del fabricante en una casilla del panel. El producto la ofrecería, luego la habría construido, luego habría que mantenerla y defenderla en cada venta. Y quien la active lo hará sin EIPD |
| **Foto del empleado tomada en cada fichaje para revisión posterior** | Es tratamiento biométrico o, como mínimo, videovigilancia continuada del puesto: mismo problema con peor justificación. La foto de perfil del empleado existe en el esquema como campo **opcional y desactivado por defecto** (RL-08), y esa es toda la imagen que el producto maneja |
| **QR rotatorio tipo TOTP en móvil** | No es biometría y sí resolvería el préstamo, pero exige pantalla y excluye a parte de la plantilla (ADR-014, documento 04 §6). Se descarta por cobertura, no por privacidad |

## Consecuencias

- **Se acepta un residuo de riesgo de préstamo de credencial**, y se dice en voz alta en la documentación comercial en lugar de ocultarlo. Está mitigado por su carácter autolimitado —quien presta su tarjeta se queda sin fichar—, por la supervisión y por RF-PR-06.
- **La detección de patrones anómalos deja de ser un extra.** Es la contrapartida explícita de este ADR: sin ella, el descarte de la biometría no tiene compensación alguna. Por eso RN-16 está enunciada con umbral configurable y por eso existe la tarea 3.11 con su bandeja y su runbook.
- **El indicio nunca es una acusación automática.** Un patrón anómalo abre una incidencia para revisión humana; el sistema no sanciona ni anula el fichaje. Un indicio que se dispara por un reloj desviado destruiría la confianza en todos los demás, y por eso RN-16 excluye los escaneos con `clock_skew`.
- **Simplifica el cumplimiento de forma sustancial:** la base jurídica es la obligación legal (RL-07), no hay categoría especial que justificar y la EIPD (RL-13) queda en un tratamiento ordinario de control de presencia.
- **Elimina una objeción recurrente ante la representación de los trabajadores**, que es un argumento de venta real y no solo un ahorro de riesgo.
- **Cierra la puerta al único antifraude fuerte.** Si algún día un cliente presenta un problema demostrado de fichajes fraudulentos, la vía no es reabrir la biometría por la puerta de atrás: es un ADR nuevo que revise este.

## Verificación

- Búsqueda en el árbol y en las dependencias: cero librerías de reconocimiento facial, huella o comparación biométrica; ninguna captura de imagen fuera del decodificador de QR.
- Prueba de arquitectura: el quiosco no envía fotogramas al servidor. El único dato que sale de la cámara es la cadena decodificada del payload.
- Cabecera `Permissions-Policy: camera=(self)` presente, verificada en la prueba de cabeceras de seguridad (RS-09).
- Prueba de la tarea 3.11: un patrón anómalo genera incidencia `anomalous_pattern` y **no** rechaza el fichaje ni marca a nadie como fraudulento (RF-PR-06, RN-16).
- Revisión de cumplimiento (`/revision-cumplimiento`): ningún campo del esquema almacena datos biométricos; `photo_path` sigue siendo opcional y desactivado por defecto (RL-08).
