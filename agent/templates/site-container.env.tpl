# Managed by aipanel. Container runtime arguments for one tenant.
#
# Every flag here is a boundary. Read before changing any of them:
#
#   --user                  php-fpm runs as the tenant's uid, never root
#   --read-only             the image filesystem is immutable; only the mounts
#                           below are writable
#   --cap-drop=ALL          no capabilities at all (php-fpm needs none here)
#   --security-opt no-new-privileges
#                           setuid binaries inside cannot elevate
#   --pids-limit            a fork bomb hits a wall instead of the host
#   --memory / --cpus       one tenant cannot starve the others
#   --network               a private network per site: no route to another
#                           site's container, and no access to the host's
#                           database socket
#   --tmpfs /tmp            writable scratch that vanishes with the container
#   current/ read-only      tenant code cannot rewrite the release it is serving
