# Guion del vídeo

Duración objetivo: 10–12 minutos. Captura de pantalla obligatoria; cámara opcional.

| Minuto | Pantalla | Qué contar |
| --- | --- | --- |
| 0:00 | Portada de la presentación | Quién soy, qué es KronoQR en una frase y por qué lo elegí: un producto real, con valor legal, que un hotel instala en su servidor. |
| 0:45 | Slides 2–3 (problema y respuesta) | El registro horario es obligatorio desde 2019 y en hostelería los sistemas genéricos fallan: turnos de noche, jornada partida, gente sin móvil de empresa, wifi que cae. |
| 2:00 | Panel · Presencia | Entrar con la cuenta de demostración. Quién está dentro ahora, en tiempo real. |
| 2:45 | Panel · Plantilla → «Dar de alta» | Alta de una persona; el código y el PIN se muestran una sola vez. Sin correo. |
| 3:30 | Panel · Credenciales → «Emitir credencial» → «Imprimir la tarjeta» | La tarjeta en PDF. Comentar que el QR es opaco y va firmado: sin nombre, sin número de empleado. |
| 4:15 | Quiosco en otra pestaña · Panel · Quioscos → «Vincular quiosco» | La tablet muestra el código de 6 dígitos; el panel lo teclea. Explicar que la tablet solo puede fichar y sincronizar. |
| 5:00 | Quiosco | Fichar mostrando el QR a la cámara (o con código y PIN). Confirmación visual y sonora. Cerrar el turno: total del día. |
| 6:00 | Quiosco con red cortada | Modo sin conexión: encola, confirma, sincroniza al volver y conserva la hora real. Doble marca de tiempo. |
| 7:00 | Panel · Plantilla → ficha → jornada → Corregir | Nada se borra: la corrección crea una versión nueva con autor, momento y motivo. Enseñar el tramo anterior conservado. |
| 7:45 | Panel · Incidencias · Cumplimiento · Informes | La incidencia del fichaje por PIN. La vista de cumplimiento con los umbrales del perfil. Exportación sellada y exportación para la Inspección. |
| 8:45 | Portal | La persona consulta y descarga su propio registro con código y PIN. |
| 9:15 | Repositorio · `backend/app/Modules` · `docs/adr` · `.claude/agents` | Arquitectura hexagonal modular, 57 ADR, y cómo se construyó con Claude Code: `CLAUDE.md`, 11 agentes, 6 skills, plan por tareas, `HANDOFF.md`. |
| 10:15 | GitHub Actions · la ejecución de `ci.yml` de la etiqueta `v2.2.0` · `docs/verificacion/` | Las ocho etapas: calidad, arquitectura, mutación, trazabilidad requisito → prueba, seguridad, E2E con cámara simulada e instalación limpia y actualización desde el paquete. Después, el ciclo de la versión: verificación de la 2.1.0 en NO-GO → plan de correcciones en 25 bloques → re-verificación → verificación final con 0 críticos y 0 altos de seguridad → 2.2.0 publicada el 9 de octubre. |
| 11:00 | Slides 12–13 (resultados y lo aprendido) · slide de cierre | Qué aprendí: la IA acelera lo mecánico; el criterio sobre el negocio, la ley y la seguridad sigue siendo humano. La verificación encontró lo que las herramientas no miden. Lo que queda, dicho claro: tablet real, validación jurídica y revisión externa. |

## Preparación antes de grabar

- Tener abiertas tres pestañas: panel (ya autenticado), quiosco (ya emparejado o con el código listo) y portal.
- Tener a mano el PDF de una tarjeta ya emitida, y el código y el PIN de esa persona.
- Comprobar en `/api/v1/health` que está en marcha la 2.2.0, y enseñar al minuto 7:45 la sección *Cuentas* y, al 8:45, la descarga del portal en PDF sellado.
- Datos ficticios en pantalla; ningún nombre real.
- Cerrar notificaciones del sistema y poner el navegador a pantalla completa.
