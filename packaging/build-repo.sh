#!/bin/sh
#
# Copyright (C) 2026 Pat Ryan
# All rights reserved. BSD 2-Clause License, see LICENSE.
#
# Build a signed pkg repository containing mdns-bridge and os-mdns-bridge,
# laid out for GitHub Pages:
#
#   _site/
#     index.html, install.sh, mdnsbridge.pub
#     repo/<abi>/            e.g. repo/freebsd-15-amd64/
#       meta.conf, packagesite.pkg, data.pkg, All/*.pkg
#
# Run as root on FreeBSD 15.x (what the GitHub workflow does) or on an
# OPNsense 26.7 test machine. The ABI of the build host decides the
# target: OPNsense 26.7 is FreeBSD 15.1, ABI FreeBSD:15:amd64.
#
# Environment:
#   PKG_SIGNING_KEY_FILE  RSA private key (PEM) used to sign the catalogue
#   ALLOW_UNSIGNED=1      build without signing (local testing only)
#   MDNS_BRIDGE_PKG       use this mdns-bridge .pkg instead of the installed
#                         or FreeBSD-repository package
#   PLUGINS_BRANCH        opnsense/plugins branch (default stable/26.7).
#                         Do not use master: it builds "os-mdns-bridge-devel".
#   OUT, WORK             output and scratch directories

set -eu

SRC=$(cd "$(dirname "$0")/.." && pwd)
OUT=${OUT:-${SRC}/_site}
WORK=${WORK:-/tmp/os-mdns-bridge-build}
PLUGINS_BRANCH=${PLUGINS_BRANCH:-stable/26.7}
KEY=${PKG_SIGNING_KEY_FILE:-}
PUBKEY=${SRC}/packaging/mdnsbridge.pub

die() { echo ">>> ERROR: $*" >&2; exit 1; }
say() { echo ">>> $*"; }

[ "$(id -u)" = 0 ] || die "run as root (pkg create/install need it)"
command -v git > /dev/null || die "git is required: pkg install -y git"

ABI=$(pkg config ABI)
ABI_DIR=$(echo "${ABI}" | tr 'A-Z:' 'a-z-')
REPO=${OUT}/repo/${ABI_DIR}
say "Building for ${ABI} into ${REPO}"

# --- Version sanity: a tag vX.Y must match the Makefile -------------------
PLUGIN_VERSION=$(sed -n 's/^PLUGIN_VERSION=[[:space:]]*//p' "${SRC}/Makefile")
PLUGIN_REVISION=$(sed -n 's/^PLUGIN_REVISION=[[:space:]]*//p' "${SRC}/Makefile")
FULL_VERSION=${PLUGIN_VERSION}${PLUGIN_REVISION:+_${PLUGIN_REVISION}}
case "${GITHUB_REF_NAME:-}" in
v*)
	[ "${GITHUB_REF_NAME#v}" = "${FULL_VERSION}" ] || \
	    die "tag ${GITHUB_REF_NAME} does not match Makefile version ${FULL_VERSION}"
	;;
esac

# --- Signing key checks --------------------------------------------------
if [ -n "${KEY}" ]; then
	[ -r "${KEY}" ] || die "cannot read ${KEY}"
	[ -r "${PUBKEY}" ] || die "missing ${PUBKEY}; see README, Building the repository"
	openssl rsa -in "${KEY}" -pubout 2> /dev/null | cmp -s - "${PUBKEY}" || \
	    die "${PUBKEY} does not match the signing key; clients would reject the repository"
elif [ "${ALLOW_UNSIGNED:-0}" != 1 ]; then
	die "set PKG_SIGNING_KEY_FILE (or ALLOW_UNSIGNED=1 for a local test build)"
fi

rm -rf "${REPO}"
mkdir -p "${REPO}/All" "${WORK}"

# --- 1. mdns-bridge (the daemon, from FreeBSD ports) ---------------------
if [ -n "${MDNS_BRIDGE_PKG:-}" ]; then
	say "Using supplied ${MDNS_BRIDGE_PKG}"
	pkg add -f "${MDNS_BRIDGE_PKG}"
elif ! pkg info -e mdns-bridge; then
	say "Installing mdns-bridge from the FreeBSD repository"
	pkg install -y mdns-bridge || die "mdns-bridge is not installable here; set MDNS_BRIDGE_PKG"
fi
# Re-create the package from the installed copy so the repository holds
# exactly the version the plugin package is built against.
pkg create -o "${REPO}/All" mdns-bridge
say "mdns-bridge $(pkg query %v mdns-bridge) packaged"

# --- 2. os-mdns-bridge (the plugin, via the official plugins framework) ---
if [ ! -d "${WORK}/plugins/.git" ]; then
	git clone --quiet --depth 1 --branch "${PLUGINS_BRANCH}" \
	    https://github.com/opnsense/plugins.git "${WORK}/plugins"
else
	git -C "${WORK}/plugins" fetch --quiet --depth 1 origin "${PLUGINS_BRANCH}"
	git -C "${WORK}/plugins" checkout --quiet --force FETCH_HEAD
fi
[ ! -e "${WORK}/plugins/Mk/devel.mk" ] || \
    die "${PLUGINS_BRANCH} is a development branch; packages would be named os-mdns-bridge-devel"

DEST=${WORK}/plugins/net/mdns-bridge
rm -rf "${DEST}"
mkdir -p "${DEST}"
cp -R "${SRC}/Makefile" "${SRC}/pkg-descr" "${SRC}/src" "${DEST}/"
for f in "${SRC}"/+*.pre "${SRC}"/+*.post; do
	[ -e "${f}" ] && cp "${f}" "${DEST}/"
done

# CRLF in a shebang line breaks scripts on the firewall; refuse to ship it.
if grep -rlI "$(printf '\r')" "${DEST}" > /dev/null; then
	grep -rlI "$(printf '\r')" "${DEST}" >&2
	die "files above have CRLF line endings"
fi

# Executable bits are easily lost when committing from Windows; the
# configd actions and the CARP hook run these files directly.
chmod 755 "${DEST}/src/opnsense/scripts/OPNsense/MDNSBridge/mdnsbridge_ctl.php" \
    "${DEST}/src/etc/rc.syshook.d/carp/50-mdnsbridge"

make -C "${DEST}" package PKGDIR="${REPO}/All"
say "os-mdns-bridge ${FULL_VERSION} packaged"

# --- 3. Repository catalogue ------------------------------------------------
if [ -n "${KEY}" ]; then
	pkg repo "${REPO}" "${KEY}"
else
	say "WARNING: building an UNSIGNED repository"
	pkg repo "${REPO}"
fi

# --- 4. Site files ------------------------------------------------------------
cp "${SRC}/packaging/install.sh" "${OUT}/install.sh"
[ -r "${PUBKEY}" ] && cp "${PUBKEY}" "${OUT}/mdnsbridge.pub"
touch "${OUT}/.nojekyll"
cat > "${OUT}/index.html" <<HTML
<!doctype html>
<meta charset="utf-8">
<title>os-mdns-bridge package repository</title>
<p>pkg repository for the os-mdns-bridge OPNsense plugin.
See <a href="https://github.com/PatrickTRyan/os-mdns-bridge">the project page</a>
for installation instructions.</p>
<p>Built $(date -u '+%Y-%m-%d %H:%M UTC') for ${ABI}:</p>
<ul>
$(cd "${REPO}/All" && for p in *.pkg; do echo "<li>${p}</li>"; done)
</ul>
HTML

say "Done. Contents:"
find "${OUT}" -type f | sort
