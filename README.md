При внедрении в проект, не забывайте настроить supervisor исходя из шаблона, лежащего в директории docs.

Пример конфигурации в Symfony:

```php
dv_evil_queue:
    debug: "%kernel.debug%"
    connection: "@doctrine.dbal.xmlrpc_connection"
    logger: "@monolog.logger.console"
    workers: 10
    priority_workers: 5
```
