server {
    listen 443 ssl;
    http2 on;
    server_name {{server_names}};

    ssl_certificate     /etc/letsencrypt/live/{{domain}}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/{{domain}}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    add_header Strict-Transport-Security "max-age=31536000" always;

    location / {
        proxy_pass http://{{container}}:8080;
        include /etc/nginx/aipanel-proxy.conf;
    }
}
