# AgroConecta

Sistema de comercio agrícola para pequeños productores de Puno, Perú: registro, ofertas, lotes, compras, acopio, entregas, cobros, liquidaciones y trazabilidad. Interfaz en español, moneda PEN y fechas operativas de Perú.

**Estado:** fases 1–6 y 9–19 implementadas y validadas localmente. Las fases 7–8 tienen adaptadores WhatsApp/Evolution y SMS/Twilio; falta comprobarlos con cuentas y teléfonos reales. La fase 20 tiene Docker de producción y un ensayo HTTPS local; todavía no hay servidor, dominio ni despliegue público. Las operaciones demo son sintéticas. El sistema registra cobros y desembolsos revisados por personas, pero no transfiere dinero automáticamente.

## Levantar todo con una línea

Con Docker Desktop abierto, desde `E:\VSCODE\Agroconecta`, si esta instalación ya está preparada:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile workers --profile mail --profile messaging up -d
```

Abre **[AgroConecta](http://localhost:8080)**. Inicia web, PostgreSQL, Redis, worker, scheduler, Mailpit y Evolution con sus servicios privados. No instala dependencias ni aplica actualizaciones pendientes. No necesitas el script en cada arranque.

Para **primera instalación o actualizar dependencias, migraciones, configuración y recursos de interfaz**:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\start.ps1
```

Requisito: Docker Desktop con contenedores Linux. PHP, Composer y Node.js/npm corren en Docker; no hace falta instalarlos en Windows. El script genera configuración cuando falta, conserva claves, instala dependencias, aplica migraciones, prepara roles/catálogos/cuentas demo, compila la interfaz y comprueba los servicios. También inicia Evolution y sus servicios privados. La primera preparación tarda más por descargas y compilación; no es el tiempo de navegación.

Para detener conservando datos: `powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\stop.ps1`. No uses `down -v`: elimina volúmenes con datos.

## Accesos y destino por rol

Las contraseñas locales están en **`.local/demo-access.txt`**, fuera de Git. Reiniciar conserva las cuentas y contraseñas existentes.

| Persona | Entrada y funciones |
|---|---|
| Visitante | La portada muestra Productos; puede explorar lotes y crear una cuenta de comprador. |
| Comprador — `comprador@agroconecta.test` | Después de ingresar llega a Productos. Mi cuenta, Compras y Reportes muestran sus propios datos. Comprar requiere aprobación del perfil. |
| Coordinador — `coordinador@agroconecta.test` | Llega a Administración; atiende productores, ofertas, lotes, conversaciones y operación comercial según sus permisos. |
| Administrador — `admin@agroconecta.test` | Llega a Administración; además gestiona usuarios, compradores, configuración de WhatsApp, confirmaciones financieras y auditoría. |
| Productor | El equipo registra su identidad por formulario privado; puede presentar ofertas mediante mensajería habilitada, sin contraseña web. |

Si se solicitó una página protegida antes de ingresar, se conserva ese destino. Los enlaces administrativos se ocultan al comprador y cada ruta comprueba permisos. La recuperación local de contraseña llega a [Mailpit](http://localhost:8025). [Estado del sistema](http://localhost:8080/dev/status) es una herramienta separada de la portada, disponible solo en desarrollo.

## Conectar WhatsApp y probar desde el teléfono

Como administrador abre **[WhatsApp](http://localhost:8080/admin/settings/whatsapp)** desde el menú o Administración.

| Modalidad | Requisitos y funcionamiento |
|---|---|
| API oficial de Meta mediante Evolution | Activos de WhatsApp Business, token Meta, Phone Number ID, WABA ID, App Secret y callback HTTPS. No utiliza el QR de dispositivo vinculado. |
| Evolution / Baileys mediante QR | Un número en WhatsApp y Evolution funcionando. Escanea el QR dentro de AgroConecta. Es una integración no oficial y depende del protocolo de WhatsApp Web. |

La preferencia anterior era Meta oficial; la petición posterior añadió QR. En esta instalación se creó **agroconecta-qr** y se comprobó el QR dentro de la web. **En la comprobación del 20/09/2026, Evolution devolvió `open` y AgroConecta mostró «WhatsApp conectado». Las respuestas se activaron desde la web para la prueba solicitada con un productor registrado; falta confirmar la entrega real.** La pantalla conserva la modalidad elegida y las claves permanecen en el servidor. En una instalación nueva las respuestas empiezan desactivadas.

### Pasos para QR

1. Levanta todos los servicios con la línea de arranque completo.
2. Selecciona **Vincular con QR mediante Evolution / Baileys** y pulsa **Preparar conexión**. Se prepara en segundo plano y el estado se actualiza en la misma página.
3. En el teléfono receptor: **WhatsApp → Dispositivos vinculados → Vincular un dispositivo**. Escanea el QR mostrado. Si caduca, pulsa **Revisar conexión / renovar QR**.
4. Espera **WhatsApp conectado**. Revisa el aviso de uso de datos y activa las respuestas. Conectar por sí solo no activa mensajes.
5. En **Productores**, registra el número remitente de prueba. Usa el teléfono correcto; un celular peruano de nueve dígitos se normaliza con `+51`. El equipo verifica la identidad antes de permitir ofertas.
6. Desde ese teléfono registrado envía **HOLA** al receptor; revisa el aviso y responde **ACEPTO**. Remitente y receptor deben ser números distintos: los mensajes propios se ignoran. En **Mensajería** puedes observar el recorrido y probar **VENDER**, **ESTADO**, **AYUDA** y **BAJA**.

**Solo contactos registrados:** `MESSAGING_REGISTERED_CONTACTS_ONLY=true` es el valor predeterminado. Un desconocido no crea conversación, mensaje ni respuesta en AgroConecta. Se admiten productores pendientes o verificados; suspendidos, rechazados y archivados se ignoran. Registrar un número no envía un saludo. El cambio de teléfono y la suspensión se comprueban también antes de procesar o enviar respuestas pendientes.

El QR es exclusivo de administradores, se entrega sin caché HTTP y su copia temporal se cifra y caduca. Se ignoran grupos, mensajes propios, historial antiguo, contenido no textual e identificadores LID sin teléfono validable. BAJA revoca el canal. Desactivar respuestas impide nuevos envíos, pero no retira uno en curso o ya enviado.

Al reconectar se recuperan las respuestas bloqueadas por desconexión únicamente cuando consta que no se enviaron; los resultados inciertos requieren conciliación. Un callback QR atrasado no sustituye una conexión ya establecida. Si se pausa antes de entregar el primer aviso, al reactivar se presenta de nuevo el aviso completo antes de aceptar consentimiento.

El outbox conserva las tareas en PostgreSQL y el scheduler las despacha cada minuto. Una respuesta puede requerir dos ciclos, procesamiento y envío; es independiente del tiempo de carga web. Redis y worker deben estar activos. QR necesita Internet en el equipo, pero el teléfono puede escanear localhost sin publicar la web.

### Opción oficial de Meta

Configura en `application/.env`: `META_ACCESS_TOKEN`, `META_PHONE_NUMBER_ID`, `META_BUSINESS_ACCOUNT_ID`, `META_APP_SECRET`, `META_VERIFY_TOKEN` y `WHATSAPP_PUBLIC_WEBHOOK_URL`. El callback es `https://tu-dominio/api/v1/webhooks/whatsapp/meta`: requiere HTTPS público y suscripción de la cuenta al campo `messages`.

Actualiza configuración/procesos con `start.ps1` y prepara **API oficial de Meta** desde WhatsApp. «Meta configurado» confirma el aprovisionamiento, no la entrega. Revisa el aviso y activa las respuestas. La configuración exclusivamente por variables, sin conexión creada en la web, usa además `WHATSAPP_ENABLED`, `MESSAGING_LIVE_ENABLED` y `MESSAGING_NOTICE_APPROVED`, falsos por defecto.

Laravel valida la firma original y deduplica cada evento. Los callbacks derivados de Evolution no repiten el procesamiento. Fuera de la ventana de 24 horas, el modo oficial exige una plantilla aprobada configurada en `WHATSAPP_APPROVED_TEMPLATES`. QR no utiliza esas plantillas. Contratos y pasos detallados: [Evolution](docs/evolution.md) y [Mensajería](docs/messaging.md).

### SMS y simulador

Twilio está preparado con firmas, recibos, consentimiento y límites de segmentos; permanece desactivado hasta verificar cuenta, número y recepción bidireccional. No se afirma que cualquier número Twilio reciba SMS desde Perú.

El [simulador](http://localhost:8080/dev/message-simulator) usa el mismo motor y nunca envía mensajes reales. Registra primero el productor/teléfono, y simula HOLA → ACEPTO → VENDER. El antiguo prerregistro abierto sigue disponible cambiando conscientemente `MESSAGING_REGISTERED_CONTACTS_ONLY=false`; no es la configuración actual recomendada.

## Funciones y recorrido completo

| Módulo | Funcionalidad |
|---|---|
| Usuarios | Registro, sesiones, recuperación, roles, activación y aprobación auditada de compradores. |
| Catálogos | Productos, variedades, calidades, unidades, geografía, equivalencias, costos y métodos de pago. Un saco necesita factor aprobado y vigente; no se inventa su peso. |
| Productores | DNI/teléfono cifrados, duplicados por HMAC, verificación manual, cambios de contacto/ubicación con historial, consentimiento por finalidad, suspensión y archivo. |
| Ofertas | Cantidad, clasificación, ubicación, disponibilidad, fotos privadas y precio solicitado. Revisión, propuesta económica, aceptación de la versión vigente y publicación. |
| Lotes | Porciones compatibles, agrupación manual o sugerida, control de saldo/vigencias/acuerdos, publicación, pausa y cancelación. |
| Productos públicos | Filtros, orden, paginación y disponibilidad actual; estimación sin reserva. No expone identidades ni acuerdos privados. |
| Cotizaciones y compras | Aportaciones exactas, precio ponderado, cargos desglosados, vencimiento, compra parcial/completa, reservas y ajustes aceptados. Bloqueos e idempotencia evitan duplicados. |
| Acopio | Puntos, capacidad, horario, planificación, reprogramación y avisos individuales. GPS consentido y opcional; distancia aproximada, no ruta vial. |
| Logística | Pesajes, aceptado/rechazado, fotos privadas, incidencias, lotes físicos, despachos, entregas, devoluciones y pérdidas. No repone stock automáticamente. |
| Finanzas | Registro manual de Yape, Plin, transferencia y presencial; evidencias, confirmación de ingresos, devoluciones y resultados inciertos. Liquidaciones por peso aceptado y acuerdo congelado; cierre conciliado. |
| Trazabilidad | Recorrido privado desde oferta hasta entrega; ficha pública con token revocable y QR local. No publica DNI, teléfono, dirección privada ni acuerdos económicos. |
| Reportes | Ventas, productores, ofertas, lotes, pagos, liquidaciones, logística, precios y auditoría; filtros y exportación privada a XLSX, CSV y PDF mediante cola. |

Los reportes muestran el estado actual de órdenes confirmadas en el período, no un libro de caja por fecha de movimiento. Las exportaciones caducan en 24 horas y comprueban permisos actuales. Límite: 366 días y 2.000 filas.

`COMMERCE_LIVE_ENABLED=false` mantiene cerrado el comercio real. Los cargos, tributos y receptores demo no deben utilizarse en ventas reales. Verificar productores no garantiza ventas ni sustituye pesaje y entrega. Adjuntar evidencia de pago no confirma dinero.

## Demostración disponible

La orden **[O-7HHTJSCGBM](http://localhost:8080/commerce/orders/1)** conserva el recorrido de **25,125 kg sintéticos** de quinua hasta cierre, liquidación y QR. Total comprador S/ 128,14; neto productor S/ 113,07. Cobros y desembolsos simulados.

El lote **L-CNNSF4LO** conserva 34,875 kg disponibles hasta el **20/09/2026**. Después se oculta de Productos; sus órdenes e historial siguen consultables. Para nuevas pruebas debes crear ofertas/lotes con fechas vigentes. El arranque no crea compras nuevas ni renueva disponibilidades.

`CommerceWalkthroughSeeder` reproduce esa demostración si existen sus cuentas y lote previo; reconoce la orden existente sin duplicarla. El QR de trazabilidad apunta a localhost y es distinto del QR de vinculación WhatsApp. Abrir la ficha desde otro teléfono exige una dirección alcanzable y `APP_URL` coherente; no se ha expuesto la red automáticamente.

## Rendimiento y actualización

Se trasladaron dependencias y cachés PHP desde los montajes Windows a volúmenes Linux; localhost escucha en IPv4 e IPv6 para eliminar la espera de reconexión. HTTP utiliza configuración/rutas compiladas y vistas precompiladas. Nginx comprime respuestas y conserva assets versionados. El menú consulta permisos en conjunto, el catálogo evita consultas repetidas y los reportes agrupan datos por orden antes del cálculo.

No se cachea HTML privado ni stock compartido: disponibilidad, permisos y saldos se comprueban nuevamente. Las exportaciones y la preparación de Evolution corren en segundo plano.

La referencia inicial del catálogo fue aproximadamente **3–7 segundos**. La medición posterior en el navegador integrado, con sesión administrativa, cachés calientes y tres recargas por pantalla, obtuvo:

| Pantalla | Carga completa mínima–máxima |
|---|---|
| Productos | 0,174–0,234 s |
| Administración | 0,201–0,232 s |
| Reportes | 0,229–0,346 s |
| Compras | 0,133–0,155 s |
| Detalle de orden cerrada | 0,216–0,328 s |
| Productores | 0,132–0,174 s |
| Ofertas | 0,139–0,142 s |
| Lotes | 0,133–0,153 s |
| Mensajería | 0,121–0,164 s |
| Catálogos | 0,129–0,147 s |
| Usuarios | 0,175–0,181 s |
| WhatsApp | 0,134–0,170 s |

Muestra pequeña sobre datos demo, no ensayo de carga ni garantía para otros equipos. **0,25 segundos es el objetivo; todavía no se cumple de forma uniforme**, especialmente en reportes y detalle de orden. La autenticación, inicios en frío, exportaciones y condiciones de red requieren mediciones separadas. TTFB mide el inicio de respuesta; los valores de la tabla son `load`, carga completa del documento y sus recursos, y no deben confundirse con TTFB.

En local, `data-page-timing` del documento contiene Navigation Timing y `Server-Timing` separa aplicación/SQL. No se envía telemetría. Comprueba Network/Performance después de una primera carga y con los tests detenidos.

Después de cambiar rutas o `.env`, regenera las cachés HTTP; `start.ps1` ya lo hace. Para cambios pequeños:

```powershell
docker compose exec -T app env APP_CONFIG_CACHE=/var/www/html/bootstrap/cache/http-config.php php artisan config:cache
docker compose exec -T app env APP_ROUTES_CACHE=/var/www/html/bootstrap/cache/http-routes.php php artisan route:cache
docker compose --profile workers restart app queue scheduler
```

No ejecutes `config:cache` sin indicar el archivo HTTP en desarrollo: pruebas y restauraciones necesitan su propia configuración. Las cachés Linux `php_vendor`, `bootstrap_cache` y `framework_cache` son distintas de los volúmenes persistentes de PostgreSQL, Redis y Evolution. No borres datos para resolver un problema de caché.

## Configuración, privacidad y respaldos

`.env` raíz configura Compose; `application/.env` configura Laravel. Las plantillas son `.env.example`; `initialize-environment.ps1` genera secretos locales sin mostrarlos. Conserva **APP_KEY y PRODUCER_LOOKUP_KEY originales**: cambiarlas sin migración rompe el descifrado o búsqueda de identidades. No publiques claves, QR de vinculación, accesos, `.env` ni backups.

Evolution dispone de PostgreSQL/Redis separados, sin puertos públicos y con clave interna. Recibe los mensajes de la cuenta antes del filtro de AgroConecta: su almacenamiento también es privado. Filtrar desconocidos en AgroConecta no significa que WhatsApp/Evolution nunca reciban sus mensajes. Un filtro de consola suprime cargas sensibles que Evolution 2.3.7 imprimía fuera de su nivel de log, conservando diagnósticos técnicos.

La web usa sesiones cifradas, CSRF, permisos, validación del Host, CSP y límites de solicitudes. Producción exige HTTPS/cookies seguras y oculta herramientas de desarrollo. La verificación de DNI es manual; no se integró una consulta oficial de identidad.

Respaldo con restauración de comprobación en una base temporal:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\backup-local.ps1
```

Pone brevemente la web en mantenimiento y verifica tablas, campos cifrados y archivos privados. Guarda resultados en `.local/backups/`. Conserva claves por separado y copia externa cifrada. No incluye sesiones/bases de Evolution ni Redis; requieren respaldo propio. No hace falta ejecutarlo para iniciar.

Última restauración comprobada: `.local/backups/20260920-063520-bb29d16fb64b4e6b8f6d29e2473c58e4/result.json`, resultado correcto, 202 campos descifrados y un archivo privado verificado. La base temporal de comprobación se elimina al terminar.

## Pruebas y diagnóstico

```powershell
docker compose exec -T app php artisan test --compact
docker compose exec -T app php artisan agroconecta:health --wait=75
docker compose exec -T app php artisan messaging:check
docker compose exec -T app php artisan agroconecta:security-check
docker compose exec -T app php artisan migrate:status
```

Los tests usan **agroconecta_testing**, separada de la demo, e interceptan HTTP de proveedores: no envían mensajes. Cubren permisos, cifrado, contactos registrados, QR/webhooks, consentimiento, disponibilidad, concurrencia PostgreSQL, compras, finanzas, trazabilidad y reportes. El caso integral de 220 kg vive exclusivamente en pruebas.

Suite completa del 20/09/2026: **373 pruebas / 2.206 aserciones correctas**, en `.local/full-performance-whatsapp-tests.log`. Incluye navegación, contacto registrado, QR, reconexión y consentimiento. Pint, compilación Vite en Docker y arranque con diagnóstico de PostgreSQL, Redis, worker, scheduler y Evolution también pasaron. Pruebas locales no equivalen a validar entrega real, SMS o carga de producción. Evidencia histórica: `.local/phases-17-19-full-tests.log` (347 pruebas / 2.032 aserciones).

Corrección posterior de Activar respuestas: **42 pruebas / 276 aserciones correctas** en `.local/whatsapp-activation-regression.log`, más comprobación en navegador. La consulta automática de estado ya no cambia la página anterior de la sesión; los errores de validación regresan explícitamente al formulario, conservan el aviso y muestran el motivo en español. Abrir `/admin/settings/whatsapp/status` como página lleva a la configuración; la consulta AJAX sigue recibiendo JSON. La suite completa anterior no incluye estas seis pruebas adicionales.

Si no aparece QR: revisa Evolution/queue, espera preparación y renueva. Si no responde: revisa conexión, automatización, teléfono habilitado, consentimiento, worker y scheduler; consulta Mensajería. «Aceptado» no significa «entregado». Un envío incierto se concilia antes de repetirlo.

## Producción y pendientes

`docker-compose.production.yml` y `docker/production/` construyen imágenes sin montajes de código Windows, con usuario restringido y datos privados separados. Ensayo local:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\production-rehearsal.ps1
```

Usa proyecto/bases propios, puertos 8088/8443 y certificado local de siete días. No altera la demo de 8080 ni instala el certificado en Windows. El ensayo anterior fue verificado; sus servicios se detuvieron para liberar recursos durante las mediciones. Reconstruye para incluir cambios posteriores.

Falta validar mensajes reales de WhatsApp con el número ya vinculado, validar Twilio, definir operación comercial real y sus condiciones/receptores, contratar servidor/dominio, preparar HTTPS público, backups externos, monitorización y pruebas de carga. El workflow de GitHub Actions está preparado, pero no se ejecutó en remoto porque no hay repositorio remoto configurado. No existe despliegue/CD público.

## Código y documentación

Laravel 13, PHP 8.4, PostgreSQL 18, Redis 8, Vite/Tailwind y Evolution 2.3.7 fijado por digest. Dependencias bloqueadas en `composer.lock` y `package-lock.json`; no se añadió una dependencia para estas mejoras.

| Ruta | Contenido |
|---|---|
| `application/app/` | Modelos, permisos, controladores, dominio, jobs y comandos. |
| `application/routes/` | Web, API y scheduler. |
| `application/resources/` | Blade, CSS y JavaScript. |
| `application/database/`, `application/tests/` | Migraciones, factories, seeders y pruebas. |
| `docker/`, `scripts/` | Infraestructura, inicio, respaldo, CI y ensayo de producción. |
| [Arquitectura](docs/architecture.md) / [Base de datos](docs/database.md) | Actores, módulos, estados y modelo. |
| [Mensajería](docs/messaging.md) / [Evolution](docs/evolution.md) | Flujos, contratos y conexión. |
| [Pruebas locales](docs/local-testing.md) | Recorridos y operación detallada. |
| [Despliegue](docs/deployment.md) / [Fases](docs/roadmap.md) | Producción y criterios de salida. |
