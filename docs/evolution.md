# AgroConecta — WhatsApp mediante Evolution: Meta y QR

Actualización del **19 de septiembre de 2026**: se conserva el adaptador oficial de Meta y se incorpora la vinculación **QR con Evolution/Baileys** solicitada después. El administrador elige la modalidad en `/admin/settings/whatsapp`. El QR se prepara en cola, aparece en la misma web y solo lo puede consultar administración. No se ha vinculado todavía un teléfono ni verificado entrega real. La guía operativa vigente está en [README](../README.md#conectar-whatsapp-y-probar-desde-el-teléfono); el análisis Cloud posterior corresponde específicamente al modo Meta.

## Vinculación QR y contactos permitidos

`WHATSAPP-BAILEYS` es un dispositivo vinculado no oficial, distinto de Cloud API. Se crea una instancia separada con sufijo `-qr`, sin sincronizar todo el historial ni marcar mensajes como leídos. El job consulta `/instance/fetchInstances` sin filtro (el filtro de una instancia inexistente devuelve 404), crea si falta, configura callback y solicita `/instance/connect/{instance}`. El navegador consulta exclusivamente el estado local y el QR temporal; nunca recibe claves ni llama directamente a Evolution.

El callback autentica `X-Evolution-Secret`, instancia y esquema. `qrcode.updated` actualiza una imagen PNG cifrada en caché durante 30 segundos; `connection.update` actualiza conexión; `messages.upsert` admite únicamente texto entrante de teléfono validable y reciente. No usa el número de un LID como identidad. Se ignoran grupos, mensajes propios y contenido no textual. `messages.update` concilia recibos sin rebajar un estado leído. La cuenta canónica es `qr:<instancia>`, separada de los IDs Meta.

Las respuestas empiezan desactivadas. El administrador debe revisar el aviso y activarlas; un productor registrado debe escribir primero. `MESSAGING_REGISTERED_CONTACTS_ONLY=true` descarta desconocidos antes de persistir en AgroConecta; el teléfono y estado se comprueban otra vez al procesar/enviar. No se envía un mensaje al registrar un número. La cola/scheduler siguen siendo necesarios. Meta usa firmas y ventana de 24 horas; QR utiliza el secreto del callback y no acepta las plantillas oficiales.

En local el callback va a `http://nginx/api/v1/webhooks/whatsapp/evolution`, con `Host` coherente con APP_URL. En producción utiliza la URL HTTPS de APP_URL, o `EVOLUTION_CALLBACK_URL` explícita con certificado válido. Evolution permanece sin puertos públicos. Su propia persistencia puede contener contactos/mensajes recibidos antes del filtro de AgroConecta: debe respaldarse y protegerse por separado. El preload `docker/evolution/redact-console.cjs` filtra objetos de mensaje/sesión y QR que la versión fijada imprimía directamente con `console.log`.

Contratos QR comprobados contra [instance.controller.ts 2.3.7](https://github.com/EvolutionAPI/evolution-api/blob/2.3.7/src/api/controllers/instance.controller.ts) y [whatsapp.baileys.service.ts 2.3.7](https://github.com/EvolutionAPI/evolution-api/blob/2.3.7/src/api/integrations/channel/whatsapp/whatsapp.baileys.service.ts). Pruebas: `WhatsAppConnectionTest` y `RegisteredContactMessagingTest`; no equivalen a una prueba con teléfono real.

## Decisión y versiones

Evolution gestiona la instancia Cloud y las solicitudes de envío; Laravel conserva las reglas comerciales, la recepción durable, las autorizaciones y la trazabilidad. El adaptador implementado se llama `EvolutionProvider`, invocado por `MessageDelivery`; no se añadió una interfaz paralela sin uso.

| Referencia comprobada | Situación | Decisión para AgroConecta |
|---|---|---|
| Tag Git `2.3.7`, release titulada `v2.3.7` | Estable publicada el 5/12/2025, marcada Latest al consultar | Base de los contratos auditados aquí; imagen fijada también por digest en Compose |
| Tag `2.4.0-rc2` | Prerelease del 17/05/2026 | No introducir automáticamente una versión candidata en el entorno base |

Se comprobaron releases mediante GitHub y su API pública, no se dedujo la estabilidad del número mayor. La imagen propuesta por el repositorio es `evoapicloud/evolution-api:v2.3.7`; el prefijo `v` del tag Docker no coincide con el tag Git. La imagen descargada se fija con digest `sha256:1bd8afc4a6cf48822e6cf02469aeae7bd35a12a6b616eacd1291926307f4d339`. [Releases oficiales](https://github.com/evolution-foundation/evolution-api/releases) y [configuración Docker del proyecto](https://github.com/evolution-foundation/evolution-api/blob/main/Docker/swarm/evolution_api_v2.yaml).

La rama 2.4 introduce activación operativa y responde `503 LICENSE_REQUIRED` antes de completarla; rc2 agrega `EVOLUTION_OPERATOR_EMAIL` para autoactivación de operadores previamente registrados. No se configura un correo inventado ni se inicia ese registro durante Fase 1. [Cambio 2.4.0-rc1](https://github.com/evolution-foundation/evolution-api/releases/tag/2.4.0-rc1) y [2.4.0-rc2](https://github.com/evolution-foundation/evolution-api/releases/tag/2.4.0-rc2).

El archivo LICENSE de 2.3.7 contiene condiciones adicionales de atribución y conservación de marca. La documentación actual de licenciamiento describe versiones posteriores con Apache 2.0 y activación gratuita; no debe aplicarse retroactivamente esa descripción al tag fijado. AgroConecta identificará Evolution en documentación/configuración administrativa y conservará sus avisos si usa su interfaz. [LICENSE del tag 2.3.7](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/LICENSE) y [licenciamiento actual](https://docs.evolutionfoundation.com.br/licensing/index.md).

## Cloud oficial y alternativa analizada

`WHATSAPP-BUSINESS` utiliza credenciales y activos de Meta; no necesita vincular WhatsApp Web por QR. `WHATSAPP-BAILEYS` conecta mediante WhatsApp Web y es no oficial, expuesta a cambios de protocolo y desconexiones. La elección inicial fue Meta; la petición posterior añade QR como opción explícita en administración. [Integraciones del proyecto](https://github.com/evolution-foundation/evolution-api) y [Cloud API en Evolution](https://docs.evolutionfoundation.com.br/evolution-api/integrations/cloudapi).

Las incidencias reportadas de Baileys/LID no prueban un fallo general de Cloud API. Tampoco la etiqueta estable demuestra por sí sola que la combinación de Graph API, credenciales y callbacks funcione: se requiere prueba real de contrato en Fase 7. La ventana de atención, plantillas aprobadas, consentimiento y captura segura de DNI siguen el [diseño de mensajería](messaging.md).

## Contratos HTTP revisados

Todas las rutas siguientes pertenecen a **Evolution**, no son endpoints Laravel ya implementados. La base interna prevista es el nombre del servicio Docker y puerto 8080; no se expone directamente a Internet. JSON para cuerpo y header `apikey` para las operaciones protegidas.

| Operación | Contrato comprobado | Tratamiento de AgroConecta |
|---|---|---|
| Crear instancia | `POST /instance/create` | `instanceName`, `integration: WHATSAPP-BUSINESS`, `qrcode: false`, `token`, `number`, `businessId` |
| Estado | `GET /instance/connectionState/{instance}` | Respuesta con `instance.state`; indica estado técnico, no entrega de mensajes |
| Enviar texto | `POST /message/sendText/{instance}` | Cuerpo `number` y `text`; opcionales como `linkPreview` se fijan explícitamente |
| Configurar callback | `POST /webhook/set/{instance}` | Objeto `webhook` con `enabled`, `url`, `events`, `headers`, `byEvents`, `base64` |
| Consultar callback | `GET /webhook/find/{instance}` | Verificar configuración sin volcar sus secretos en logs |
| Conectar | `GET /instance/connect/{instance}` | Ruta real analizada; sirve al flujo de vinculación, no se usa para solicitar QR en el modo Cloud elegido |

Referencias: [crear](https://docs.evolutionfoundation.com.br/evolution-api/create-instance), [enviar texto](https://docs.evolutionfoundation.com.br/evolution-api/send-text-message), [router de instancia 2.3.7](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/routes/instance.router.ts), [router de envío](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/routes/sendMessage.router.ts) y [router de callbacks](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/event/webhook/webhook.router.ts).

En la creación Cloud, `number` es el **Phone Number ID de Meta**, no el teléfono del productor; `businessId` identifica la cuenta de WhatsApp Business y `token` es el token de acceso Meta real. En `sendText`, `number` sí es el destinatario internacional. Laravel conserva E.164 y el adaptador produce la representación requerida por el contrato, sin inferir identidad a partir de IDs internos. [Configuración Cloud documentada](https://docs.evolutionfoundation.com.br/evolution-api/integrations/cloudapi).

**Particularidad de autenticación confirmada en código:** 2.3.7 guarda `instanceData.token` como token de instancia; para Cloud es el mismo token Meta. El guard acepta la clave global o ese token de instancia. No existe un segundo `EVOLUTION_INSTANCE_API_KEY` independiente que pueda generarse y funcionar para esa instancia Cloud. AgroConecta usará la clave global Evolution exclusivamente en su red privada; el token Meta permanecerá restringido al aprovisionamiento y almacenamiento del proveedor. [Creación de instancia](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/controllers/instance.controller.ts#L59) y [guard de autenticación](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/guards/auth.guard.ts).

El código Cloud devuelve un objeto con `key.id` para el WAMID al enviar; también puede devolver un error de Graph dentro del cuerpo, conservando el HTTP de la ruta. El adaptador exige ID externo válido y ausencia de error para marcar aceptación. `201` aislado no demuestra aceptación ni entrega. [Servicio Cloud 2.3.7](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/whatsapp.business.service.ts#L1129).

## Dos límites de autenticación distintos

### Meta → ingreso público de AgroConecta

La revisión de `meta.router.ts` en 2.3.7 encontró que GET compara el token de verificación, pero POST entrega `req.body` al controlador **sin validar `X-Hub-Signature-256`**. La ruta se monta sin los guards del API. No se expondrá directamente `/webhook/meta` de esa versión. [Router Meta](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/meta.router.ts) y [montaje de canales](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/channel.router.ts).

Diseño propio para resolverlo:

```text
Meta Cloud
   | HTTPS, firma sobre cuerpo original
   v
Laravel /api/v1/webhooks/whatsapp/meta
   | valida firma, cuenta receptora y esquema; depura datos sensibles
   | persiste entrada canónica + outbox en PostgreSQL
   +----> servicios de dominio: una ejecución por ID Meta
   |
   +----> job reenvía eventos mínimos a Evolution /webhook/meta, red privada
                        |
                        +--> callback interno autenticado a Laravel
                             reconcilia/enriquece; no repite el dominio

Laravel outbox de salida --> Evolution sendText/template --> Meta Cloud
```

El ingreso Laravel valida HMAC-SHA256 con el App Secret sobre bytes originales, comparación en tiempo constante y desafío GET con su token correspondiente. Solo después de esa validación normaliza y depura. No atribuye la firma original de Meta a un JSON reconstruido. Evolution acepta exclusivamente reenvíos desde la red interna controlada. El endpoint público de Meta configurado en el dashboard será Laravel, aunque la documentación genérica de Evolution muestre su propio `/webhook/meta`.

La entrada canónica de Meta es la evidencia durable: el código 2.3.7 usa `changes[0]`, algunos caminos `messages[0]` y procesamiento asíncrono que puede responder antes de terminar. Por ello Laravel recorre todos los eventos originales, ejecuta el dominio una sola vez por ID Meta y reenvía cada unidad de evento por separado para sincronizar Evolution. Los callbacks derivados no crean ofertas adicionales. Un recibo adelantado se conserva aunque Evolution aún no conozca el mensaje enviado. [Controlador Meta](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/meta.controller.ts) y [lectura de contenido Cloud](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/whatsapp.business.service.ts#L126).

Esta capa no crea otro microservicio: es un ingreso HTTP y jobs del Laravel existente. No habrá espera de confirmaciones humanas ni de llamadas externas dentro de la transacción comercial.

### Evolution → callback interno de AgroConecta

El webhook local de instancia admite `headers`. Puede enviar una cabecera secreta estática propia sobre transporte protegido; alternativamente, `headers.jwt_key` genera un JWT HS256 de diez minutos con `app` y `action`. Este JWT no firma el cuerpo del mensaje ni constituye una firma Meta. El webhook global no aplica esos headers locales en el código auditado. [Controlador de webhooks 2.3.7](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/event/webhook/webhook.controller.ts).

Propuesta MVP: callback por instancia, global desactivado, secreto exclusivo en header, ruta interna `POST /api/v1/webhooks/whatsapp/evolution`; validar secreto, instancia esperada, tipos y tamaño. No utilizar el campo `apikey` recibido en el cuerpo como prueba de autenticidad. Si posteriormente se añade firma de cuerpo en un proxy o adaptación propia, se documentará como mecanismo AgroConecta, sin atribuirlo a Evolution.

Configurar `byEvents=false` para una sola URL y `base64=false` para evitar medios incrustados. Suscribir `MESSAGES_UPSERT`, `MESSAGES_UPDATE`, `SEND_MESSAGE` y los eventos operativos necesarios. Los códigos configurados usan mayúsculas con guiones bajos; el evento recibido puede ser `messages.upsert` o `messages.update`. No suscribir historial/grupos/QR por defecto. [Esquema de webhook](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/event/webhook/webhook.schema.ts) y [lista de eventos](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/event/event.controller.ts#L135).

## Identidad, recibos y deduplicación

Para Cloud se toma `messages[].from`/identidad documentada de Meta y el número receptor de `metadata`; se vincula al productor solo después de normalizar y verificar el teléfono. En un callback Evolution el mensaje contiene `data.key.id`, posible `data.key.remoteJid`, texto en `data.message.conversation` o `extendedTextMessage.text`, y marca `fromMe`. El remitente de la envoltura `sender` no se interpreta como productor. Los mensajes propios y eventos no comerciales no inician onboarding.

`@lid` es un identificador de WhatsApp, **no un teléfono**. Si apareciera por una instancia mal configurada o evolución del contrato, se conserva como identificador externo opaco; no se convierte a E.164 quitando el sufijo ni se crea un productor ficticio. Hace falta un mapeo autorizado comprobable; mientras no exista, la entrada queda sin identidad resuelta para atención operativa. La selección Cloud no habilita Baileys silenciosamente.

| Evidencia | Normalización propia |
|---|---|
| Respuesta de envío con WAMID válido | `ACCEPTED` |
| Recibo Meta `sent` / Cloud Evolution `SENT` | `SENT` |
| `delivered` / `DELIVERED` | `DELIVERED` |
| `read` / `READ` | `READ` |
| `failed` / `FAILED` | `FAILED`, conservando error seguro |
| Timeout o información ausente | `UNKNOWN`, sin reenviar ciegamente |

El camino Baileys tiene otra nomenclatura (`SERVER_ACK`, `DELIVERY_ACK`), que no debe confundirse con Cloud. Se conserva el estado original y se prueba el mapeo del modo seleccionado. El servicio Cloud genera actualizaciones con `keyId` y `status`; los recibos canónicos Meta conservan también la fecha original, evitando depender solo de `date_time` de la envoltura. [Recibos Cloud en código](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/whatsapp.business.service.ts#L747) y [estados Baileys](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/utils/renderStatus.ts).

La clave comercial de deduplicación se basa en canal/cuenta Meta e ID externo, con Evolution como ruta de transporte, no como una segunda fuente independiente. Los callbacks de estado se deduplican además por estado y evidencia temporal disponible. No se promete entrega exactamente una vez a través de redes externas.

## Configuración contrastada

Estas son **variables reales de Evolution 2.3.7**. El [Compose opcional](../docker-compose.evolution.yml) inyecta secretos desde el `.env` privado de la raíz; esta tabla no contiene credenciales.

| Variables | Configuración prevista |
|---|---|
| `SERVER_TYPE`, `SERVER_PORT`, `SERVER_URL` | HTTP interno, 8080 y URL interna; HTTPS termina en ingreso público controlado |
| `AUTHENTICATION_API_KEY` | Clave global privada generada para Evolution |
| `AUTHENTICATION_EXPOSE_IN_FETCH_INSTANCES` | `false` explícito; no exponer token de instancia en eventos |
| `DATABASE_PROVIDER`, `DATABASE_CONNECTION_URI` | PostgreSQL con base/usuario propios de Evolution |
| `CACHE_REDIS_ENABLED`, `CACHE_REDIS_URI`, `CACHE_REDIS_PREFIX_KEY` | Redis propio y prefijo `agroconecta-evolution`, aislado de las colas Laravel |
| `WA_BUSINESS_URL`, `WA_BUSINESS_VERSION` | Graph oficial, `v26.0` por defecto; se puede fijar otra versión comprobada con `META_GRAPH_VERSION` en el `.env` raíz |
| `WA_BUSINESS_TOKEN_WEBHOOK` | Token privado del desafío GET interno; no es secreto HMAC de Meta |
| `WEBHOOK_GLOBAL_ENABLED` | `false`; callbacks por instancia |
| `LOG_LEVEL`, `LOG_BAILEYS` | Sin niveles de cuerpos/verbose/webhooks; verificar salida con datos sintéticos |
| `TELEMETRY_ENABLED` | `false` propuesto en 2.3.7; no equivale a desactivar activación de 2.4 |

Las variables se comprobaron en [.env.example del tag](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/.env.example) y su [lector de configuración](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/config/env.config.ts). `DATABASE_ENABLED` no se lee en este tag y se omite; la persistencia Prisma se configura mediante proveedor y URI. `META_APP_SECRET` o un nombre equivalente será configuración **propia de Laravel**, porque el tag auditado no valida la firma POST Cloud mediante una variable Evolution.

El 8/09/2026 se consultó el [endpoint público de Graph v26.0](https://graph.facebook.com/v26.0/me) sin token: respondió `400 OAuthException`, con cabecera `facebook-api-version: v26.0`. Esto confirma que Graph reconoce esa versión sin sustituirla por otra; no prueba permisos, envío de WhatsApp ni compatibilidad integral de Evolution con una cuenta real. Las páginas del [registro oficial de versiones](https://developers.facebook.com/docs/graph-api/changelog/versions) devolvieron 429 durante la consulta. La validación autenticada de Cloud queda en Fase 7.

La base Evolution utiliza su esquema Prisma y contiene secretos de instancia/callback; no comparte tablas ni usuario con AgroConecta. Un callback puede incluir `apikey`: la exposición del token depende de `AUTHENTICATION_EXPOSE_IN_FETCH_INSTANCES`; aun desactivada, se descarta ese campo al ingresar. Para Cloud dicho token puede ser la credencial Meta. [Construcción de eventos y control de exposición](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/services/channel.service.ts#L435).

Minimizar retención de mensajes/contactos en Evolution según lo necesario para su operación y recibos; no desactivar indiscriminadamente su persistencia, porque el camino Cloud consulta mensajes previos. Auditar logs y backups de ambos sistemas. El camino Cloud registra contenido en nivel `LOG`; el camino Baileys incluso contiene una impresión directa de contenido en el tag auditado, razón adicional para no asumir que una única variable resuelve privacidad en todos los modos. [Registro Cloud](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/meta/whatsapp.business.service.ts#L666) y [registro Baileys](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/integrations/channel/whatsapp/whatsapp.baileys.service.ts#L1478).

## Levantar el transporte local opcional

Desde PowerShell en `E:\VSCODE\Agroconecta`, después de inicializar el entorno base:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile messaging config --quiet
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile messaging up -d --wait evolution
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile messaging ps evolution evolution-postgres evolution-redis
```

El perfil crea únicamente tres servicios adicionales. `evolution` queda en `http://evolution:8080`, accesible desde Laravel en la red `application`. No tiene puerto publicado en Windows ni interfaz Manager habilitada. No se abre directamente en el navegador. El usuario sigue probando AgroConecta en la URL del entorno base.

PostgreSQL 18 y Redis 8 usan las mismas imágenes fijadas por digest que el entorno base, pero contenedores, red interna `evolution-data` y volúmenes propios. La base `evolution` y su usuario no comparten tablas ni credenciales PostgreSQL con Laravel. Redis reutiliza la contraseña privada generada para desarrollo, con un proceso y almacenamiento independientes. Al rotar esa contraseña deben recrearse ambos servicios Redis y sus clientes. Las URI requieren contraseñas compatibles con URL; el inicializador genera valores hexadecimales.

Los secretos necesarios para arrancar son `EVOLUTION_API_KEY`, `EVOLUTION_DB_PASSWORD`, `EVOLUTION_WEBHOOK_SECRET` y `REDIS_PASSWORD`, generados por el inicializador del entorno. No se inventan ni requieren credenciales Meta para la prueba técnica o la vinculación QR. `EVOLUTION_WEBHOOK_SECRET` autentica el desafío interno y los callbacks por instancia mediante `X-Evolution-Secret`. Es distinto de `META_APP_SECRET`, que valida la firma original de los eventos de Meta.

El healthcheck exige respuesta HTTP 200 a una consulta autenticada de instancias y descarta el cuerpo: no imprime tokens, números ni contenido. Los servicios de datos tienen comprobaciones propias de autenticación. El endpoint raíz de Evolution no se usa para el healthcheck porque el tag consulta además una versión de WhatsApp Web al construir esa respuesta. [Router auditado](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/api/routes/index.router.ts).

Para detener solo este transporte y conservar sus datos:

```powershell
docker compose -f docker-compose.yml -f docker-compose.evolution.yml --profile messaging stop evolution evolution-postgres evolution-redis
```

Los volúmenes `evolution_postgres_data`, `evolution_redis_data` y `evolution_instances` deben incluirse en la estrategia de copia/restauración antes del piloto. El esquema Evolution contiene secretos reales cuando se aprovisione una cuenta. Un contenedor sano confirma disponibilidad técnica, no conexión con Meta ni mensajería comercial terminada.

### Verificación local realizada

El 8/09/2026 se arrancaron los tres servicios opcionales sin recrear los servicios base. Resultado:

| Comprobación | Resultado observado |
|---|---|
| Compose combinado | Configuración válida |
| PostgreSQL 18 | Migraciones Prisma de la imagen aplicadas correctamente; consulta autenticada disponible |
| Redis 8 desde el proceso Node de Evolution | Cliente del proveedor conecta con contraseña y devuelve `PONG` |
| API autenticada de instancias | HTTP 200; **0 instancias** |
| API de instancias sin clave | HTTP 401 |
| Manager | HTTP 404, deshabilitado |
| Healthcheck Evolution | `healthy` |
| Puertos publicados por Evolution | Ninguno (`PortBindings: {}`) |

La primera configuración de CORS restringida a `http://nginx` devolvió 500 a las solicitudes internas sin cabecera `Origin`: el middleware del tag exige coincidencia incluso para clientes de servidor. El Compose utiliza `CORS_ORIGIN=*`, con credenciales CORS desactivadas; el aislamiento de red y la clave API controlan el acceso. Se verificó nuevamente el acceso autenticado y su rechazo sin clave después de corregirlo. [Middleware del tag](https://github.com/evolution-foundation/evolution-api/blob/2.3.7/src/main.ts).

Esta evidencia acredita arranque y conectividad de la combinación fijada, no pruebas de carga, recuperación, recepción real ni envío Cloud. No se crearon instancias, conectaron cuentas o enviaron mensajes.

## Comprobación prevista en Fase 7

1. Configurar activos Meta reales y versión Graph compatible, sin inventar credenciales ni una aprobación de plantillas.
2. Comprobar firma incorrecta, cuerpo alterado, cuenta ajena, desafío GET y rechazo de acceso público directo a Evolution.
3. Probar eventos múltiples, duplicados y reinicio después de persistir: una entrada original produce una sola transición/oferta.
4. Verificar recepción y envío de texto mediante la instancia Cloud, errores de Graph dentro de HTTP exitoso y recibos atrasados/adelantados.
5. Probar salida dentro/fuera de ventana, plantilla aprobada, baja, expiración y timeout incierto.
6. Inspeccionar con datos sintéticos que secretos/DNI/cuerpos no lleguen a logs públicos ni a exportaciones; comprobar recuperación de la base del proveedor.

La Fase 1 puede completarse con sus servicios locales y conexiones técnicas sin estas credenciales. La integración real se declarará terminada únicamente con la evidencia de Fase 7; este análisis no se presenta como WhatsApp ya operativo.
