# ADR-004 — UTC en almacenamiento, zona del centro en presentación

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 0.2, 1.1, 1.3 y 2.8 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §3.2 y Anexo B · Reglas duras 2 y 3 de `CLAUDE.md` |
| **Requisitos** | RN-04, RN-05, RN-09, RQ-02, RL-01 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

Un hotel ficha las 24 horas. Eso convierte dos días al año en un problema real y no teórico:

- **El domingo de octubre**, las 02:30 locales ocurren dos veces. Un `TIMESTAMP` sin zona no puede distinguir cuál de las dos, y un turno de noche que empieza a las 22:00 y termina a las 06:00 mide **nueve** horas, no ocho.
- **El domingo de marzo**, las 02:30 locales no existen. El mismo turno mide siete.

Si esa hora se guarda en local, la diferencia no se puede recuperar después: el dato ya perdió la información. Y lo que se pierde no es un detalle de informe, es **una hora de trabajo en la nómina de alguien**, en un registro que la ley obliga a mantener veraz (RL-01).

A eso se suma que la zona horaria no es del sistema, sino **del centro de trabajo**: `sites.timezone` es una columna (documento 01 §5.5), porque una cadena hotelera puede tener centros en zonas distintas y el cálculo de la jornada de RN-05 se resuelve en la zona del centro donde se ficha, no en la del servidor.

## Decisión

**Todo instante se almacena en UTC, en columnas `TIMESTAMPTZ`. La conversión a la zona del centro ocurre únicamente en la capa de presentación.**

Cuatro reglas operativas derivadas, todas verificables:

1. **`APP_TIMEZONE=UTC` siempre**, en desarrollo y en la instalación del cliente. Cambiarlo invalida el cálculo de jornada.
2. **La aritmética de duraciones se hace sobre instantes UTC** (RN-09), por lo que es inmune al cambio de hora por construcción, no por corrección posterior.
3. **La fecha civil de la jornada (`work_date`) se calcula convirtiendo el instante de apertura a la zona del centro** (RN-05). Es la única conversión que ocurre dentro del cálculo, y entra por el puerto `SiteCalendar` ([ADR-025](ADR-025-frontera-de-dependencias-del-nucleo.md)) — el dominio no consulta la configuración.
4. **El dominio no llama a `now()`.** Recibe los instantes ya resueltos; el puerto `Clock` vive en `Shared` ([ADR-021](ADR-021-clock-en-shared.md)) y lo usa el caso de uso. Es la regla dura 2, y es lo que permite fijar el reloj en la prueba del cambio de hora.

**Un día natural puede tener 23 o 25 horas y el informe debe decirlo.** No se normaliza a 24: se refleja.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Almacenar en la zona local del centro** | Los instantes del cambio de hora dejan de ser únicos y la información se pierde en la escritura. Ninguna corrección posterior la recupera. Además, un centro que cambia de zona horaria —o una cadena con varios— haría el histórico ambiguo |
| **`TIMESTAMP` sin zona, asumiendo UTC por convenio** | Funciona hasta que alguien conecta un cliente SQL con otra zona de sesión, o una librería aplica una conversión implícita. El tipo debe hacer explícita la semántica; `TIMESTAMPTZ` la lleva escrita |
| **Guardar además la hora local en una columna paralela** | Dos fuentes de verdad sobre el mismo hecho. La que se desincronice —al corregir un tramo, al cambiar la zona del centro— producirá dos respuestas distintas a la misma pregunta ante una inspección |
| **Zona horaria de proceso igual a la del cliente** | Ata el cálculo a una variable de entorno del servidor y rompe el caso de la cadena con centros en zonas distintas. La zona es un atributo del centro, no del despliegue |
| **Guardar segundos desde época sin zona** | Correcto en aritmética y pésimo en operación: ilegible en cualquier consulta manual y sin ayuda del motor para rangos, que es justo lo que RN-10 necesita |

## Consecuencias

- **Toda conversión pasa por un único punto.** La presentación —panel, portal, quiosco, PDF, exportaciones— formatea a la zona del centro; nada más lo hace. Si aparece una conversión dispersa, se ha abierto la puerta al fallo que este ADR cierra.
- **Las exportaciones legales deben decir en qué zona están expresadas.** Un registro para Inspección con horas ambiguas no cumple RL-01 aunque el dato subyacente sea correcto.
- **La prueba del cambio de hora es obligatoria y determinista**, en los dos sentidos y con reloj inyectado (RQ-02). Sin la regla dura 2 no sería posible escribirla.
- **`occurred_at` viaja del quiosco en UTC con `Z`** y el servidor añade `recorded_at` (regla dura 9, [ADR-008](ADR-008-offline-first-con-idempotencia-por-scan-id.md)). El contrato OpenAPI lo exige en `date-time` UTC.
- **El día del cambio de hora aparece en los informes con 23 o 25 horas**, y hay que explicárselo a RRHH antes de que lo descubra. Es correcto, no es un fallo.
- **Cambiar `APP_TIMEZONE` en una instalación es un incidente**, no una preferencia. El `.env.example` lo advierte y `doctor.sh` lo comprueba.

## Verificación

- Prueba unitaria de dominio, con reloj fijo y sin base de datos: turno 22:00 → 06:00 en las dos noches de cambio de hora de `Europe/Madrid`. Duración correcta en ambos sentidos (RN-09).
- Prueba basada en propiedades (RQ-02): para cualquier par de instantes, la duración calculada coincide con la diferencia en UTC, con independencia de la zona del centro.
- Prueba unitaria: `work_date` de un turno que abre a las 23:30 hora del centro es el día de apertura, también cuando en UTC ya es el día siguiente (RN-05).
- Prueba de arquitectura: ninguna clase de `Domain/` invoca `now()`, `time()`, `Carbon::now()` ni `new DateTime()`.
- Prueba de integración: todas las columnas de instante del esquema son `TIMESTAMPTZ`. Ninguna es `TIMESTAMP` sin zona.
- Comprobación de configuración: `APP_TIMEZONE=UTC` en el arranque; `doctor.sh` lo verifica en la instalación del cliente.
