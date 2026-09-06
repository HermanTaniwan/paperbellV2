#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Jalankan installer ini dengan sudo: sudo ./tools/install-server-health-timer.sh" >&2
    exit 1
fi

app_dir="${PAPERBELL_APP_DIR:-/var/www/html/paperbell}"
service_file="/etc/systemd/system/paperbell-server-health.service"
timer_file="/etc/systemd/system/paperbell-server-health.timer"

command -v php >/dev/null || {
    echo "PHP CLI tidak ditemukan." >&2
    exit 1
}
[[ -f "${app_dir}/tools/collect-server-health.php" ]] || {
    echo "Collector Server Health tidak ditemukan di ${app_dir}." >&2
    exit 1
}

install -d -o www-data -g www-data -m 0775 "${app_dir}/storage/cache" "${app_dir}/storage/logs"

cat >"${service_file}" <<SERVICE
[Unit]
Description=Collect Paperbell server health metrics

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=${app_dir}
ExecStart=/usr/bin/php ${app_dir}/tools/collect-server-health.php
SERVICE

cat >"${timer_file}" <<TIMER
[Unit]
Description=Collect Paperbell server health metrics every minute

[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
AccuracySec=5s
Persistent=true
Unit=paperbell-server-health.service

[Install]
WantedBy=timers.target
TIMER

systemctl daemon-reload
systemctl enable --now paperbell-server-health.timer
systemctl start paperbell-server-health.service
systemctl --no-pager --full status paperbell-server-health.timer
systemctl --no-pager --full status paperbell-server-health.service
