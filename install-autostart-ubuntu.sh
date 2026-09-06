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
drive_service_file="/etc/systemd/system/paperbell-google-drive-mount.service"
drive_user="${PAPERBELL_DRIVE_USER:-herman}"
drive_mount="${PAPERBELL_UBUNTU_DRIVE_MOUNT:-/home/herman/GoogleDrive}"
drive_remote="${PAPERBELL_RCLONE_REMOTE:-gdrive:}"
ubuntu_print_root="${PAPERBELL_UBUNTU_PRINT_ROOT:-${drive_mount}/Paperbell/Print}"
wf_queue="${PAPERBELL_WF_QUEUE:-EPSON_WF_C5390_Series}"
wf_uri="${PAPERBELL_WF_URI:-ipp://192.168.1.6/ipp/print}"

for command_name in php python3 lp lpadmin lpstat cancel cupsenable cupsaccept systemctl; do
    command -v "${command_name}" >/dev/null || {
        echo "Perintah wajib tidak ditemukan: ${command_name}" >&2
        exit 1
    }
done

# Keep Paperbell mappings on a stable queue name while avoiding temporary
# implicitclass:// queues created by cups-browsed discovery.
lpadmin -p "${wf_queue}" -E -v "${wf_uri}" -m everywhere
cupsenable "${wf_queue}"
cupsaccept "${wf_queue}"

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

if command -v rclone >/dev/null && [[ -f "/home/${drive_user}/.config/rclone/rclone.conf" ]]; then
    for command_name in findmnt fusermount3 mountpoint runuser setfacl; do
        command -v "${command_name}" >/dev/null || {
            echo "Perintah mount wajib tidak ditemukan: ${command_name}" >&2
            exit 1
        }
    done
    if ! grep -Eq '^[[:space:]]*user_allow_other([[:space:]]|$)' /etc/fuse.conf 2>/dev/null; then
        printf '\nuser_allow_other\n' >> /etc/fuse.conf
    fi
    cat >"${drive_service_file}" <<SERVICE
[Unit]
Description=Paperbell Google Drive mount
Wants=network-online.target
After=network-online.target

[Service]
Type=simple
User=${drive_user}
Group=${drive_user}
Environment=HOME=/home/${drive_user}
ExecStart=/usr/bin/rclone mount ${drive_remote} ${drive_mount} --config /home/${drive_user}/.config/rclone/rclone.conf --vfs-cache-mode full --allow-other --umask 002
ExecStop=/usr/bin/fusermount3 -u ${drive_mount}
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
SERVICE

    mount_ready=false
    if systemctl is-active --quiet paperbell-google-drive-mount.service && mountpoint -q "${drive_mount}"; then
        current_options="$(findmnt -T "${drive_mount}" -n -o OPTIONS || true)"
        [[ ",${current_options}," == *,allow_other,* ]] && mount_ready=true
    fi
    if [[ "${mount_ready}" != true ]]; then
        systemctl stop paperbell-google-drive-mount.service 2>/dev/null || true
        while read -r mount_pid mount_command; do
            [[ "${mount_command}" == "/usr/bin/rclone mount ${drive_remote} ${drive_mount}"* ]] || continue
            kill "${mount_pid}"
        done < <(ps -u "${drive_user}" -o pid=,args=)
        if mountpoint -q "${drive_mount}"; then
            runuser -u "${drive_user}" -- fusermount3 -uz "${drive_mount}"
        fi
        for _ in {1..90}; do
            mountpoint -q "${drive_mount}" || break
            sleep 1
        done
        mountpoint -q "${drive_mount}" && {
            echo "Mount Google Drive lama tidak dapat dihentikan: ${drive_mount}" >&2
            exit 1
        }
        install -d -o "${drive_user}" -g "${drive_user}" -m 0775 "${drive_mount}"
    fi
    systemctl daemon-reload
    systemctl enable --now paperbell-google-drive-mount.service
    setfacl -m u:www-data:x "/home/${drive_user}"
    for _ in {1..90}; do
        runuser -u www-data -- test -r "${ubuntu_print_root}" && break
        sleep 1
    done
    runuser -u www-data -- test -r "${ubuntu_print_root}" || {
        echo "Google Drive belum dapat dibaca www-data: ${ubuntu_print_root}" >&2
        systemctl --no-pager --full status paperbell-google-drive-mount.service >&2 || true
        exit 1
    }
else
    echo "Peringatan: konfigurasi rclone tidak ditemukan; pastikan www-data dapat membaca ${ubuntu_print_root}." >&2
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
Wants=network-online.target cups.service paperbell-google-drive-mount.service
After=network-online.target cups.service mariadb.service paperbell-google-drive-mount.service

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
systemctl enable paperbell-print-worker.service
systemctl restart paperbell-print-worker.service
sleep 2
systemctl --no-pager --full status paperbell-print-worker.service

echo "Worker Paperbell Ubuntu aktif. Printer CUPS: ${printers[*]}. WF permanen: ${wf_queue} -> ${wf_uri}"
