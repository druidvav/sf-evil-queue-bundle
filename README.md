При внедрении в проект, не забывайте настроить supervisor исходя из шаблона, лежащего в директории docs.

Пример конфигурации в Symfony:

```php
dv_evil_queue:
    debug: "%kernel.debug%"
    connection: "@doctrine.dbal.xmlrpc_connection"
    logger: "@monolog.logger.evil"
    workers: 10
    priority_workers: 5
```

Для JSON-RPC можно явно задать суффикс идентификатора запроса:

```php
$request = new Request(Request::PROTOCOL_JSON_RPC, $url, $method, $params);
$request->setIdSuffix('cargo.zestore.pro');
$evil->enqueue($request);
```

`setIdSuffix(?string $suffix): void` принимает суффикс без `@`,
`getIdSuffix(): ?string` возвращает его без изменений. Непустой суффикс
добавляется к уникальному `id` через `@` как при `execute()`, так и при
`enqueue()`. По умолчанию, при `null` или пустой строке формат `id` прежний.
Для HTTP и XML-RPC суффикс не используется.

В очереди суффикс хранится в существующем поле `comment` целиком как
`jsonrpc_id_suffix=...`; формат `request_param` и схема БД не меняются.
`Request::fromArray()` распознаёт только такой полный комментарий для JSON-RPC,
а не маркер внутри произвольного текста. Не дописывайте к нему другой текст
до выполнения запроса. Старые воркеры совместимы с этими строками очереди,
но отправляют запрос без суффикса; для его передачи воркеры нужно перезапустить
после обновления библиотеки.
