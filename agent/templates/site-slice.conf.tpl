# Managed by aipanel. Resource limits for one tenant, so no single site can
# starve the node.
[Unit]
Description=aipanel resource limits for {{user}}

[Slice]
CPUAccounting=yes
CPUQuota=50%
MemoryAccounting=yes
MemoryMax=512M
MemorySwapMax=0
TasksAccounting=yes
TasksMax=96
IOAccounting=yes
IOWeight=100
