# Managed by aipanel. Manual edits are overwritten on the next task run.
# Site: {{domain}}  Tenant: {{user}}

server {
    listen 80;
    listen [::]:80;
    server_name {{server_names}};

    # ACME challenges are always served over plain HTTP from a shared webroot,
    # so a certificate can be issued and renewed even while the rest of this
    # vhost redirects to HTTPS.
    location ^~ /.well-known/acme-challenge/ {
        root {{acme_webroot}};
        default_type "text/plain";
        allow all;
    }

    # current/ is a symlink to an immutable release directory; deploys move the
    # symlink atomically, so a request is always served by one whole release.
    root {{root}};
    index index.php index.html;

    access_log /var/log/nginx/{{domain}}.access.log;
    error_log  /var/log/nginx/{{domain}}.error.log;

    client_max_body_size 64m;

    location ~ /\.(git|env|ssh) { deny all; return 404; }
    location ~ ^/(vendor|storage|node_modules)/ { deny all; return 404; }

    location / {
        # Once a certificate exists this becomes a redirect to HTTPS. It lives
        # inside location / rather than at server level: a server-level return
        # runs in the rewrite phase, before location matching, and would
        # swallow the ACME challenge above — breaking every later renewal.
        {{http_body}}
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:{{fpm_socket}};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_read_timeout 60s;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\. { deny all; }
}

server {
{{tls}}
    server_name {{server_names}};

    root {{root}};
    index index.php index.html;

    access_log /var/log/nginx/{{domain}}.access.log;
    error_log  /var/log/nginx/{{domain}}.error.log;

    client_max_body_size 64m;

    location ~ /\.(git|env|ssh) { deny all; return 404; }
    location ~ ^/(vendor|storage|node_modules)/ { deny all; return 404; }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:{{fpm_socket}};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 60s;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\. { deny all; }
}
