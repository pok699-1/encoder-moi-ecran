# Мой-Экран.рф

Сервис трансляции видео-потока на устройства имеющие подключение к сети Wi-Fi и Веб-браузер.

-   PHP - v8.2
-   Node.js - v22.13 (npm - v10.9)
-   Laravel - v11.9
-   Vite - 6.2

## VITE

**Vite** - обрабатывает и оптимизирует файлы front-end<br>
<code>resources/css</code><br>
<code>resources/js</code>

В Blade шаблонах подключается следующим образом:<br>
`@vite(['resources/css/app.css', 'resources/js/app.js'])`

# Установка и запуск

## Установка зависимостей

```bash
npm install
```

```bash
composer install
```

### Запуск миграций

```bash
php artisan migrate
```

Создаст нужные таблицы в БД из `/database/migrations/xxx_table.php`<br> их модели в `/app/Models/Xxx.php`

## Запуск локально

### Запуск дев среды с hot reload

```bash
npm run dev
```

или

### Запуск прод сборки

```bash
npm run build
```

### Запуск очереди(queue)

```bash
php artisan queue:work

```

### Запуск reverb (ws/wss)

```bash
php artisan reverb:start

```

### Запуск сервера

```bash
php artisan serve

```

## Запуск на сервере(nginx)

## Конфигурация Supervisor

### laravel-queue

```ini
[program:laravel-queue]
process_name=%(program_name)s
command=php /var/www/moy_ekran_rf/data/www/xn----8sbzfhkct9i.xn--p1ai/artisan queue:work --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
user=root
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/laravel-queue.log
stopasgroup=true
killasgroup=true
```

### laravel-reverb

```ini
[program:laravel-reverb]
process_name=%(program_name)s
command=/usr/bin/php /var/www/moy_ekran_rf/data/www/xn----8sbzfhkct9i.xn--p1ai/artisan reverb:start
directory=/var/www/moy_ekran_rf/data/www/xn----8sbzfhkct9i.xn--p1ai
autostart=true
autorestart=true
user=root
redirect_stderr=true
stdout_logfile=/var/log/laravel-reverb.log
stopasgroup=true
killasgroup=true
```

### Перезапуск

```
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart laravel-queue
sudo supervisorctl restart laravel-reverb
```

## Конфигурация nginx

```nginx
server {
    server_name xn----8sbzfhkct9i.xn--p1ai  ;
    listen 90.156.229.222:80;

    listen 90.156.229.222:443 ssl;

    ssl_certificate "/var/www/httpd-cert/xn----8sbzfhkct9i.xn--p1ai_2025-07-23-17-03_31.crt";
    ssl_certificate_key "/var/www/httpd-cert/xn----8sbzfhkct9i.xn--p1ai_2025-07-23-17-03_31.key";

    charset utf-8;

    gzip on;
    gzip_proxied expired no-cache no-store private auth;
    gzip_types text/css text/xml application/javascript text/plain application/json image/svg+xml image/x-icon;
    gzip_comp_level 1;
    set $root_path /var/www/moy_ekran_rf/data/www/xn----8sbzfhkct9i.xn--p1ai/public;

    root $root_path;
    disable_symlinks if_not_owner from=$root_path;

    # Прокси для WebSocket
    location /reverb/ {
        proxy_pass         http://127.0.0.1:8080/;
        proxy_http_version 1.1;
        proxy_set_header   Upgrade $http_upgrade;
        proxy_set_header   Connection "Upgrade";
        proxy_set_header   Host $host;
        proxy_set_header   Origin $http_origin;
        proxy_set_header   X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto https;
        proxy_read_timeout 60;
    }

    location / {
        index index.php index.html;
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include /etc/nginx/fastcgi_params;
        fastcgi_pass unix:/var/run/xn----8sbzfhkct9i.xn--p1ai.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
     }

    location ~* ^.+\.(jpg|jpeg|gif|png|svg|js|css|mp3|ogg|mpeg|avi|zip|gz|bz2|rar|swf|ico|7z|doc|docx|map|ogg|otf|pdf|tff|tif|txt|wav|webp|woff|woff2|xls|xlsx|xml)$ {
        try_files $uri $uri/ /index.php?$args;
    }

    location @fallback {
        fastcgi_pass unix:/var/run/xn----8sbzfhkct9i.xn--p1ai.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include /etc/nginx/fastcgi_params;
    }

    include "/etc/nginx/fastpanel2-sites/moy_ekran_rf/xn----8sbzfhkct9i.xn--p1ai.includes";
    include /etc/nginx/fastpanel2-includes/*.conf;

    error_log /var/www/moy_ekran_rf/data/logs/xn----8sbzfhkct9i.xn--p1ai-frontend.error.log;
    access_log /var/www/moy_ekran_rf/data/logs/xn----8sbzfhkct9i.xn--p1ai-frontend.access.log;
}
```

### Перезапуск nginx

```
sudo systemctl reload nginx
```

### При обновлении _.env_

```
php artisan config:cache
php artisan route:cache
php artisan view:cache

npm run build

sudo supervisorctl restart laravel-reverb
sudo supervisorctl restart laravel-queue

sudo systemctl reload nginx

```
