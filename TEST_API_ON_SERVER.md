# Тестирование API на сервере

## Проверьте следующие варианты:

```bash
# 1. Проверить через /public/api/deploy
curl -X POST http://localhost/public/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v

# 2. Проверить через домен (если доступен)
curl -X POST https://project.siteaccess.ru/api/deploy -H "Authorization: Bearer bedbae66b3e1288f8d5fb6c40dc03295b13f5838e8d90c2d0952b81555047ad4" -H "Accept: application/json" -v

# 3. Проверить, что public/index.php обрабатывает запросы
curl http://localhost/public/

# 4. Проверить логи Laravel
tail -50 storage/logs/laravel.log
```
