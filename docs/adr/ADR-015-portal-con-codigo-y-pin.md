# ADR-015 — El portal del empleado usa código y PIN, y es web sencilla

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` con `seguridad-cumplimiento` |
| **Afecta a** | Tareas 0.5, 1.11, 1.12 y 1.13 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §3.3 y §7.5 · **Regla dura 12** de `CLAUDE.md` |
| **Requisitos** | RF-ID-05, RF-ID-06, RF-ID-07, RF-ID-08, RF-ID-09, RF-AT-11, RF-GP-01, RL-05, RS-12 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

RL-05 y RF-ID-05 no dejan margen: la persona trabajadora **puede acceder a su propio registro en cualquier momento y obtener copia**. Es una exigencia legal, no una funcionalidad opcional, y por tanto tiene que funcionar para el 100 % de la plantilla.

La puerta de entrada habitual —correo electrónico y contraseña— choca de frente con ese «100 %» por la misma razón que [ADR-014](ADR-014-la-credencial-es-una-tarjeta-fisica.md): **el producto no puede exigir una dirección de correo a toda la plantilla de un hotel**. El personal de temporada no la tiene, crearla para contratos de dos meses es trabajo administrativo que ningún cliente quiere, y si la invitación no llega —filtro de correo no deseado, dirección mal tecleada—, el empleado se queda sin el acceso que la ley le reconoce.

Además, cada credencial nueva es una credencial que alguien tiene que provisionar, entregar, restablecer y auditar. El PIN de respaldo del quiosco (RF-AT-11) ya existe y ya pasa por todo ese proceso.

## Decisión

**El acceso al portal es con código de empleado y PIN de 6 dígitos, el mismo del respaldo del quiosco. El portal es una web sencilla, no una PWA.**

Cuatro elementos, todos necesarios:

1. **Sin correo electrónico.** `employees.email` es opcional (RF-GP-01) y no participa en la autenticación. Nada del producto depende de él (regla dura 12).
2. **Ámbito `self:read`** (RF-ID-07): la sesión del portal solo lee los datos del propio empleado. Nunca datos de terceros, nunca escritura sobre el registro. Un compromiso del PIN no escala más allá de una persona.
3. **Protección de proceso, porque 10⁶ es un espacio pequeño** (§7.5): bloqueo temporal creciente tras 3, 5 y 10 intentos fallidos por empleado y por origen, limitación de tasa independiente por IP (RS-12), y **acceso restringido a la red interna por defecto** (RF-ID-08). Exponerlo a internet es una decisión explícita del cliente que activa requisitos adicionales.
4. **Web sencilla y no PWA.** No hay credencial que mostrar sin conexión (ADR-014), así que no hace falta service worker, ni caché cifrada, ni instalación. Es una página responsive de consulta.

La provisión del PIN tiene requisito propio (RF-ID-09): se genera al dar de alta, se muestra **una sola vez** para su entrega, se guarda solo como `pin_hash`, RRHH puede restablecerlo, y emisión, entrega y restablecimiento quedan en `audit_log`.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Correo y contraseña con invitación** | Excluye a quien no tiene correo, que en hostelería no es un caso raro. Añade gestión de invitaciones, entregabilidad, rebotes y restablecimiento por correo, y hace depender un derecho legal de que un mensaje llegue a la bandeja de entrada |
| **Reutilizar el usuario de gestión (Sanctum + 2FA)** | El 2FA obligatorio de RS-06 tiene sentido para roles con acceso a toda la plantilla; imponerlo a 500 personas para consultar sus propias horas convierte un derecho en un trámite, y multiplica el soporte por cada TOTP perdido |
| **Un PIN distinto del quiosco** | Dos secretos que provisionar, entregar, restablecer y auditar para la misma persona. La confusión entre ambos generaría bloqueos y llamadas |
| **Enlace mágico por correo o SMS** | Vuelve a depender de un canal externo que el producto no controla, y el SMS añade coste por mensaje y salida a internet en un sistema que debe funcionar aislado |
| **Portal como PWA con credencial offline** | Construye service worker y caché cifrada para mostrar algo que la tarjeta ya lleva encima. Toda una categoría de modos de fallo a cambio de nada |
| **Acceso solo desde el quiosco** | Una cola delante de la tablet para consultar horas, y el quiosco no es sitio para leer un histórico. No cumple RL-05 en la práctica |

## Consecuencias

- **Exige bloqueo por intentos y acceso interno por defecto.** No son mejoras opcionales: son lo que hace aceptable un secreto de 6 dígitos. Sin ellas, este ADR sería una vulnerabilidad.
- **Elimina la gestión de invitaciones, la entregabilidad de correo y el service worker del portal.** Es trabajo que no se hace y superficie que no hay que mantener en cada instalación vendida.
- **El PIN se vuelve infraestructura crítica.** Sostiene el respaldo de fichaje y el acceso legal al registro. Su provisión, entrega y restablecimiento tienen requisito y tarea propios (RF-ID-09, tarea 1.13), porque sin ellos el portal no tendría puerta.
- **El PIN se entrega en documento aparte de la tarjeta.** Imprimirlo en ella anularía el respaldo: quien pierde la tarjeta perdería a la vez las dos vías.
- **Un cliente que quiera exponer el portal a internet asume requisitos adicionales** de robustez y limitación de tasa, y esa decisión queda documentada y auditada.
- **El código de empleado es opaco y aleatorio** (documento 01 §5.5), no secuencial: no permite enumerar la plantilla desde la pantalla de acceso.
- **La documentación de usuario del portal es obligatoria** (tarea 5.11b). Un portal cuyo acceso nadie explica cumple RL-05 solo de forma nominal.

## Verificación

- Prueba de *feature*: un empleado sin correo electrónico accede al portal con código y PIN, consulta su registro y descarga su histórico (RL-05, RF-ID-05).
- Prueba de autorización negativa: una sesión de portal no puede leer datos de otro empleado ni escribir nada, en ningún endpoint (RF-ID-07, RQ-07).
- Prueba de *feature*: tras 3, 5 y 10 intentos fallidos, el bloqueo temporal crece; la limitación por IP actúa de forma independiente (RS-12).
- Prueba de *feature*: el PIN se muestra una sola vez en el alta y después solo puede restablecerse; en base de datos solo existe `pin_hash` (RF-ID-09).
- Prueba de integración: emisión, entrega y restablecimiento del PIN dejan entrada en `audit_log`.
- Comprobación del frontend: `grep -r "vite-plugin-pwa|workbox|serviceWorker" frontend-portal/` sin resultados. El portal no es una PWA.
