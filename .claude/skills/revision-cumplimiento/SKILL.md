---
name: revision-cumplimiento
description: Checklist de revisión legal y de privacidad de un cambio, contra el registro horario obligatorio (art. 34.9 ET), el RGPD y las decisiones de seguridad del proyecto. Úsalo antes de cerrar cualquier funcionalidad que toque datos personales, fichajes, correcciones, auditoría, retención, exportaciones o credenciales.
---

# Revisión de cumplimiento

Este sistema produce el registro de jornada de personas reales. Su falseamiento o su pérdida tienen consecuencias legales para el cliente. Esta revisión es el filtro previo a integrar.

**Nota:** esto es una lista de verificación técnica derivada del marco normativo, no asesoramiento jurídico. La validación final corresponde a la asesoría laboral del cliente y a su DPO.

## A · Registro de jornada (art. 34.9 ET)

- [ ] **¿Este cambio permite que un registro se modifique sin dejar traza?** Si la respuesta no es un no rotundo, es un bloqueante.
- [ ] Toda corrección crea versión nueva y conserva la anterior con autor, momento, valor previo y motivo del catálogo.
- [ ] Ningún camino del código registra una hora que no ocurrió. Revisa especialmente cierres automáticos, valores por defecto y rellenos de datos.
- [ ] Los datos de `occurred_at` y `recorded_at` se conservan ambos y no se sobrescriben entre sí.
- [ ] La conservación de 4 años se respeta; la purga no puede alcanzar registros aún vigentes.
- [ ] La persona trabajadora sigue pudiendo acceder a su propio registro.
- [ ] La exportación para Inspección sigue siendo completa y coherente tras el cambio, incluidas las correcciones y sus motivos.

## B · Protección de datos

- [ ] **Minimización:** cada campo nuevo es necesario. ¿Se puede lograr lo mismo con un hash, un identificador o menos precisión?
- [ ] La finalidad no se amplía. Si el dato se usa para algo distinto del registro y la gestión de presencia, requiere análisis previo.
- [ ] Todo dato personal nuevo tiene política de retención definida. Un campo sin retención es un incumplimiento diferido.
- [ ] El dato es accesible para la persona interesada si le concierne.
- [ ] No hay datos biométricos. ADR-009 es firme: si el cambio los introduce, se rechaza.
- [ ] Ningún servicio externo nuevo recibe datos personales sin contrato de encargo y sin estar en la UE.
- [ ] Los datos personales están cifrados en tránsito y, donde corresponda (backups, caché del quiosco), en reposo.
- [ ] Si el cambio afecta a lo que se informa a la plantilla, el aviso de privacidad del quiosco se actualiza.

## C · Seguridad

- [ ] La firma HMAC se verifica con `hash_equals` (tiempo constante).
- [ ] Los rechazos de escaneo son genéricos y no distinguen la causa ni por mensaje ni por latencia.
- [ ] Cada endpoint tocado tiene policy **y** comprobación de ámbito de token.
- [ ] Existe prueba negativa por cada rol no autorizado.
- [ ] `audit_log` sigue siendo solo-append; el usuario de aplicación no ha ganado `UPDATE` ni `DELETE`.
- [ ] La cadena de hash se calcula sobre una serialización canónica y determinista.
- [ ] Ningún log técnico contiene nombres de empleados ni datos identificativos en claro.
- [ ] **`error_events` no contiene PII** (RF-PD-15): ni nombres, ni correos, ni DNI, ni horas de fichaje. Solo `employee_uuid`, `device_id` y contexto técnico. Es la tabla que se envía al fabricante en el paquete de diagnóstico, así que un descuido aquí es una comunicación de datos, no un log feo.
- [ ] El token de la credencial se almacena **hasheado**, nunca en claro.
- [ ] El PIN está protegido con bloqueo creciente por intentos, por empleado y por origen (RS-12).
- [ ] El portal del empleado sigue restringido a red interna salvo decisión explícita del cliente (RF-ID-08).
- [ ] Ningún secreto en el repositorio, en las imágenes ni en los logs de CI.
- [ ] Rate limiting en su sitio para el camino de fichaje, para el PIN y para autenticación.

## C bis · Producto licenciado

- [ ] El cambio **no introduce nada específico de un cliente en el código** (ADR-017). Si lo hace, es un defecto de configurabilidad.
- [ ] Ningún umbral legal queda cableado: sale del perfil de cumplimiento (RN-10/11/12, RF-PD-07).
- [ ] El cambio **no puede bloquear el fichaje ni el acceso al registro legal** por caducidad de licencia o límite de plan (ADR-019).
- [ ] Si el cambio añade datos al paquete de diagnóstico, siguen siendo anónimos por defecto (RL-19).
- [ ] Si el cambio toca la configuración con efecto en el cálculo de horas, queda auditado con su valor anterior.
- [ ] El cambio no exige acceso permanente del fabricante a datos del cliente (ADR-020).
- [ ] El cambio no introduce credencial en móvil, invitaciones por correo ni TOTP (ADR-014). Si la tarea lo pide, es un conflicto con un ADR: reportarlo, no implementarlo.

## D · Trazabilidad de auditoría

¿La acción nueva escribe en `audit_log`? Debe hacerlo si:

- Crea, modifica, anula o cierra un fichaje
- Emite, imprime, entrega, revoca o reemite una credencial
- Provisiona, empareja o revoca un dispositivo
- Accede a datos personales de terceros (consulta de un empleado por un responsable)
- Genera una exportación legal
- Cambia roles, permisos o configuración con efecto en el cálculo de horas
- Ejecuta una purga por retención

Ante la duda, la respuesta es sí. El coste de auditar de más es despreciable; el de auditar de menos, no.

## E · Cambios de configuración con efecto retroactivo

Si el cambio modifica un umbral que afecta al cálculo (minutos de gracia, horas máximas, descanso mínimo):

- [ ] ¿Se aplica a datos históricos? **Por defecto, no.**
- [ ] Si se aplica retroactivamente, ¿se ha valorado que puede alterar registros ya entregados a la plantilla o a la Inspección?
- [ ] El cambio de configuración queda auditado con su valor anterior y su fecha de efecto.

## Formato del informe

Para cada incumplimiento:

```
[BLOQUEANTE | REVISAR | OBSERVACIÓN] Título
Sección:     A / B / C / D / E
Ubicación:   ruta/al/fichero:línea
Problema:    qué falla
Consecuencia: qué ocurre si llega a producción — sanción, pérdida de dato, exposición
Corrección:  qué hacer, y qué agente
Requisito:   RL-* / RS-* afectado
```

- **BLOQUEANTE** — no puede integrarse: incumplimiento legal, pérdida de trazabilidad o exposición de datos
- **REVISAR** — requiere decisión humana informada, típicamente de la asesoría o el DPO
- **OBSERVACIÓN** — mejora recomendable

Si todo está conforme, dilo en una línea y termina. No rellenes el informe.
