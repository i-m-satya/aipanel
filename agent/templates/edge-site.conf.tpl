# Managed by aipanel. Edge routing for one tenant.
# Site: {{domain}}  Tenant: {{user}}
#
# The edge terminates TLS and passes PHP to the tenant's own container over the
# tenants network. The tenant's filesystem is never mounted here: the edge needs
# only the socket, so a compromised edge cannot read a tenant's code, and a
# compromised tenant cannot reach the control plane.

server {
    listen 80;
    server_name {{server_names}};

    # Always served over plain HTTP so a certificate can be issued and renewed
    # even while everything else redirects.
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/acme;
        default_type "text/plain";
    }

    location / {
        {{http_body}}
        proxy_pass http://{{container}}:8080;
        include /etc/nginx/aipanel-proxy.conf;
    }
}

{{tls_server}}
