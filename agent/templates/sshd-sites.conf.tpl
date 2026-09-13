# Managed by aipanel. Drop into /etc/ssh/sshd_config.d/.
#
# Every tenant lands in a chroot of its own home with a restricted shell, so a
# site's SSH access reaches that site and nothing else on the node.

Match Group sites
    ChrootDirectory /srv/sites/%u
    AuthorizedKeysFile /srv/sites/%u/.ssh/authorized_keys
    ForceCommand /usr/local/bin/aipanel-shell
    PermitTTY yes
    AllowTcpForwarding no
    AllowStreamLocalForwarding no
    GatewayPorts no
    X11Forwarding no
    PermitTunnel no
    AllowAgentForwarding no
    PasswordAuthentication no
    PermitEmptyPasswords no
    MaxSessions 4
    ClientAliveInterval 300
