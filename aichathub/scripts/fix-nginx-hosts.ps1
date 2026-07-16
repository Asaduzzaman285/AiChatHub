$configs = Get-ChildItem "c:\Users\IT News\Downloads\aichathub\aichathub\infrastructure\docker\nginx\*.conf"

$old = "        fastcgi_read_timeout 300;
        include fastcgi_params;"

$new = "        fastcgi_param HTTP_HOST `$host;
        fastcgi_param SERVER_NAME `$host;
        fastcgi_param SERVER_PORT `$server_port;
        fastcgi_param HTTPS off;
        fastcgi_read_timeout 300;
        include fastcgi_params;"

foreach ($f in $configs) {
    $content = Get-Content $f.FullName -Raw
    if ($content -notmatch "HTTP_HOST") {
        $content = $content.Replace($old, $new)
        Set-Content $f.FullName -Value $content -NoNewline
        Write-Host "Fixed: $($f.Name)"
    } else {
        Write-Host "Already fixed: $($f.Name)"
    }
}
Write-Host "Done."
