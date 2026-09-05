#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Jalankan installer ini dengan sudo: sudo ./install-autostart-ubuntu.sh" >&2
    exit 1
fi

app_dir="${PAPERBELL_APP_DIR:-/var/www/html/paperbell}"
apache_config="${PAPERBELL_APACHE_CONFIG:-/etc/apache2/conf-enabled/paperbell.conf}"
environment_file="/etc/paperbell-print-worker.env"
service_file="/etc/systemd/system/paperbell-print-worker.service"

for command_name in php python3 lp lpstat cancel systemctl; do
    command -v "${command_name}" >/dev/null || {
        echo "Perintah wajib tidak ditemukan: ${command_name}" >&2
        exit 1
    }
done

if ! python3 -c 'import ensurepip' >/dev/null 2>&1; then
    command -v apt-get >/dev/null || {
        echo "Modul venv Python belum tersedia dan apt-get tidak ditemukan." >&2
        exit 1
    }
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y python3-venv
fi

[[ -f "${app_dir}/worker/print-worker.php" ]] || {
    echo "Worker Paperbell tidak ditemukan di ${app_dir}." >&2
    exit 1
}
[[ -f "${app_dir}/requirements-ubuntu.txt" ]] || {
    echo "requirements-ubuntu.txt tidak ditemukan di ${app_dir}." >&2
    exit 1
}
[[ -r "${apache_config}" ]] || {
    echo "Konfigurasi Apache Paperbell tidak dapat dibaca: ${apache_config}" >&2
    exit 1
}

mapfile -t printers < <(lpstat -e)
if (( ${#printers[@]} == 0 )); then
    echo "CUPS belum memiliki printer. Tambahkan printer sebelum memasang worker." >&2
    exit 1
fi

python3 -m venv --clear "${app_dir}/.venv"
"${app_dir}/.venv/bin/python" -m pip install --disable-pip-version-check -r "${app_dir}/requirements-ubuntu.txt"

python3 - "${apache_config}" "${environment_file}" "${app_dir}/.venv/bin/python" <<'PYTHON'
import os
import shlex
import sys

source_path, destination_path, python_path = sys.argv[1:]
values = {}
with open(source_path, encoding="utf-8") as source:
    for raw_line in source:
        parts = shlex.split(raw_line, comments=True)
        if len(parts) >= 3 and parts[0].lower() == "setenv" and parts[1].startswith("PAPERBELL_"):
            values[parts[1]] = parts[2]

required = {"PAPERBELL_DB_HOST", "PAPERBELL_DB_PORT", "PAPERBELL_DB_NAME", "PAPERBELL_DB_USER", "PAPERBELL_DB_PASSWORD"}
missing = sorted(required - values.keys())
if missing:
    raise SystemExit("Environment database belum lengkap: " + ", ".join(missing))
values["PAPERBELL_PYTHON_PATH"] = python_path

def systemd_quote(value: str) -> str:
    if "\n" in value or "\r" in value:
        raise SystemExit("Nilai environment tidak boleh mengandung baris baru.")
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'

temporary_path = destination_path + ".tmp"
with open(temporary_path, "w", encoding="utf-8") as destination:
    for key in sorted(values):
        destination.write(f"{key}={systemd_quote(values[key])}\n")
os.chmod(temporary_path, 0o600)
os.replace(temporary_path, destination_path)
PYTHON

cat >"${service_file}" <<SERVICE
[Unit]
Description=Paperbell print worker
Wants=network-online.target cups.service
After=network-online.target cups.service mariadb.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${app_dir}
EnvironmentFile=${environment_file}
ExecStart=/usr/bin/php ${app_dir}/worker/print-worker.php
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
SERVICE

install -d -o www-data -g www-data -m 0775 "${app_dir}/storage/print-labels/prepared"
touch "${app_dir}/storage/print-worker.log"
chown www-data:www-data "${app_dir}/storage/print-worker.log"
chmod 0664 "${app_dir}/storage/print-worker.log"
systemctl daemon-reload
systemctl enable --now paperbell-print-worker.service
sleep 2
systemctl --no-pager --full status paperbell-print-worker.service

echo "Worker Paperbell Ubuntu aktif. Printer CUPS: ${printers[*]}"
