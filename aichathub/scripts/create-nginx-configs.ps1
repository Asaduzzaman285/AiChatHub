$services = @{
    "subscription" = "subscription-service"
    "wallet"       = "wallet-service"
    "payment"      = "payment-service"
    "ai"           = "ai-gateway-service"
    "chat"         = "chat-service"
    "billing"      = "billing-service"
    "notification" = "notification-service"
}

$template = @'
server {
    listen 80;
    server_name _;
    root /var/www/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    client_max_body_size 25M;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        fastcgi_pass SERVICE_NAME:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 300;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* { deny all; }

    location ~ ^/api/v1/chat {
        try_files $uri $uri/ /index.php?$query_string;
        fastcgi_pass SERVICE_NAME:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffering off;
        proxy_buffering off;
        proxy_cache off;
    }
}
'@

$base = "c:\Users\IT News\Downloads\aichathub\aichathub\infrastructure\docker\nginx"

foreach ($key in $services.Keys) {
    $svcName = $services[$key]
    $conf = $template -replace "SERVICE_NAME", $svcName
    $outFile = "$base\$key.conf"
    Set-Content -Path $outFile -Value $conf
    Write-Host "Created: $key.conf -> $svcName"
}
Write-Host "Done."
