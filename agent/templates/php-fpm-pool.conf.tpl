; Managed by aipanel. Manual edits are overwritten on the next task run.
; Tenant: {{user}}  PHP: {{php_version}}

[{{user}}]
user = {{user}}
group = {{user}}

listen = /run/php/{{user}}.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = 8
pm.process_idle_timeout = 30s
pm.max_requests = 500

; Confinement: this pool can only see its own tenant's home.
php_admin_value[open_basedir] = {{home}}/current:{{home}}/shared:{{home}}/releases:/tmp:/usr/share/php
php_admin_value[upload_tmp_dir] = {{home}}/shared/tmp
php_admin_value[sys_temp_dir] = {{home}}/shared/tmp
php_admin_value[session.save_path] = {{home}}/shared/sessions
php_admin_value[error_log] = {{home}}/shared/logs/php-error.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off
php_admin_flag[expose_php] = off

; No shelling out from tenant code: the AI owns changes, the repo owns code.
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,proc_nice,pcntl_exec,dl
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60

; Nothing from the control plane leaks into tenant processes.
clear_env = yes
