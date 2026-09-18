#!/bin/bash
# B2.1 Server Setup Script
# Run this as root or with elevated privileges
set -e

echo "=== Installing php8.3-fpm ==="
apt-get update -qq
apt-get install -y -qq php8.3-fpm php8.3-mysql php8.3-redis php8.3-xml php8.3-mbstring php8.3-curl php8.3-sqlite3

echo "=== PHP-FPM version ==="
php-fpm8.3 -v

echo "=== Configuring FPM pool for soak ==="
cat > /etc/php/8.3/fpm/pool.d/sirosoak.conf <<'EOF'
[sirosoak]
user = sirosoak
group = sirosoak
listen = /run/php/sirosoak-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 1000
request_terminate_timeout = 60
php_admin_value[error_log] = /var/log/php/sirosoak-fpm-error.log
php_admin_flag[log_errors] = on
php_value[opcache.enable] = 1
php_value[opcache.memory_consumption] = 128
php_value[opcache.max_accelerated_files] = 4000
php_value[opcache.validate_timestamps] = 1
php_value[opcache.revalidate_freq] = 2
EOF

echo "=== Creating log directory ==="
mkdir -p /var/log/php
chown sirosoak:sirosoak /var/log/php 2>/dev/null || true

echo "=== Creating socket directory ==="
mkdir -p /run/php
chown www-data:www-data /run/php

echo "=== Restarting PHP-FPM ==="
systemctl restart php8.3-fpm 2>/dev/null || php-fpm8.3 2>/dev/null || true

echo "=== Testing FPM ==="
ls -la /run/php/sirosoak-fpm.sock 2>/dev/null

echo "=== Creating Nginx soak config ==="
cat > /etc/nginx/sites-available/soak <<'EOF'
server {
    listen 8088;
    server_name _;

    root /opt/soak-app/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/sirosoak-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 60;
    }

    access_log /var/log/nginx/soak-access.log;
    error_log /var/log/nginx/soak-error.log;
}
EOF

ln -sf /etc/nginx/sites-available/soak /etc/nginx/sites-enabled/soak

echo "=== Testing Nginx config ==="
nginx -t

echo "=== Reloading Nginx ==="
nginx -s reload 2>/dev/null || systemctl reload nginx

echo "=== Creating soak app directory ==="
mkdir -p /opt/soak-app/public
chown -R sirosoak:sirosoak /opt/soak-app

echo "=== Setup complete ==="
echo "PHP-FPM pool: sirosoak"
echo "Nginx listen: 8088"
echo "Socket: /run/php/sirosoak-fpm.sock"
