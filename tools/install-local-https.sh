#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Jalankan installer ini dengan sudo: sudo ./tools/install-local-https.sh" >&2
    exit 1
fi

app_dir="${PAPERBELL_APP_DIR:-/var/www/html/paperbell}"
source_site_config="${PAPERBELL_HTTPS_SITE_CONFIG:-${app_dir}/deploy/apache-paperbell-site.conf}"
apache_site_config="/etc/apache2/sites-available/paperbell.conf"
tls_dir="/etc/paperbell/tls"
ca_key="${tls_dir}/paperbell-local-ca.key"
ca_cert="${tls_dir}/paperbell-local-ca.crt"
server_key="${tls_dir}/app.paperbell.id.key"
server_cert="${tls_dir}/app.paperbell.id.crt"
public_ca="${app_dir}/paperbell-local-ca.crt"

for command_name in a2enmod a2ensite apache2ctl install openssl systemctl; do
    command -v "${command_name}" >/dev/null || {
        echo "Perintah wajib tidak ditemukan: ${command_name}" >&2
        exit 1
    }
done

[[ -d "${app_dir}" ]] || {
    echo "Instalasi Paperbell tidak ditemukan: ${app_dir}" >&2
    exit 1
}
[[ -r "${source_site_config}" ]] || {
    echo "Konfigurasi Apache HTTPS tidak ditemukan: ${source_site_config}" >&2
    exit 1
}

install -d -o root -g root -m 0700 "${tls_dir}"

if [[ ! -s "${ca_key}" || ! -s "${ca_cert}" ]]; then
    openssl genrsa -out "${ca_key}" 4096
    chmod 0600 "${ca_key}"
    openssl req -x509 -new -sha256 -days 3650 \
        -key "${ca_key}" \
        -out "${ca_cert}" \
        -subj '/CN=Paperbell Local CA/O=Paperbell' \
        -addext 'basicConstraints=critical,CA:TRUE,pathlen:0' \
        -addext 'keyUsage=critical,keyCertSign,cRLSign' \
        -addext 'subjectKeyIdentifier=hash'
fi

temporary_server_key="$(mktemp)"
temporary_server_csr="$(mktemp)"
temporary_server_cert="$(mktemp)"
extensions_file="$(mktemp)"
trap 'rm -f "${temporary_server_key}" "${temporary_server_csr}" "${temporary_server_cert}" "${extensions_file}"' EXIT

openssl genrsa -out "${temporary_server_key}" 2048
openssl req -new -sha256 \
    -key "${temporary_server_key}" \
    -out "${temporary_server_csr}" \
    -subj '/CN=app.paperbell.id/O=Paperbell'

printf '%s\n' \
    'basicConstraints=critical,CA:FALSE' \
    'keyUsage=critical,digitalSignature,keyEncipherment' \
    'extendedKeyUsage=serverAuth' \
    'subjectAltName=DNS:app.paperbell.id' \
    'authorityKeyIdentifier=keyid,issuer' \
    >"${extensions_file}"

openssl x509 -req -sha256 -days 825 \
    -in "${temporary_server_csr}" \
    -CA "${ca_cert}" \
    -CAkey "${ca_key}" \
    -CAcreateserial \
    -out "${temporary_server_cert}" \
    -extfile "${extensions_file}"
openssl verify -CAfile "${ca_cert}" "${temporary_server_cert}"
install -o root -g root -m 0600 "${temporary_server_key}" "${server_key}"
install -o root -g root -m 0644 "${temporary_server_cert}" "${server_cert}"
chmod 0644 "${ca_cert}"
install -o root -g root -m 0644 "${ca_cert}" "${public_ca}"

if [[ -f "${apache_site_config}" ]] && ! cmp -s "${source_site_config}" "${apache_site_config}"; then
    backup_path="${apache_site_config}.bak.$(date +%Y%m%d%H%M%S)"
    install -o root -g root -m 0644 "${apache_site_config}" "${backup_path}"
    echo "Konfigurasi lama disimpan di ${backup_path}"
fi
install -o root -g root -m 0644 "${source_site_config}" "${apache_site_config}"

a2enmod ssl rewrite
a2ensite paperbell.conf
apache2ctl configtest
systemctl reload apache2

echo "HTTPS Paperbell aktif: https://app.paperbell.id/"
echo "CA untuk perangkat klien: http://app.paperbell.id/paperbell-local-ca.crt"
