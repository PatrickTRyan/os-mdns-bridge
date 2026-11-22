# Development

Edit on a test VM and install the working tree with the official framework:

```sh
pkg install -y git
git clone -b stable/26.7 https://github.com/opnsense/plugins /usr/plugins
cp -R Makefile pkg-descr src +*.pre +*.post /usr/plugins/net/mdns-bridge/
make -C /usr/plugins/net/mdns-bridge upgrade
```

Use `stable/26.7`, not `master`: master builds a package named
`os-mdns-bridge-devel`.

Text files must have LF line endings (`.gitattributes` enforces this); a CRLF
after a `#!` line stops a script from running on the firewall.

## Building the repository

Packages are built by `.github/workflows/build-repo.yml` in a FreeBSD 15.1 VM
and published to GitHub Pages.

One-time setup:

```sh
openssl genrsa -out mdnsbridge.key 4096
openssl rsa -in mdnsbridge.key -pubout -out packaging/mdnsbridge.pub
```

1. Commit `packaging/mdnsbridge.pub`. Keep `mdnsbridge.key` offline and backed
   up; `*.key` is git-ignored.
2. Repository **Settings › Secrets and variables › Actions**: add secret
   `PKG_SIGNING_KEY` with the contents of `mdnsbridge.key`.
3. **Settings › Pages › Source**: GitHub Actions.

To release: set `PLUGIN_VERSION` (or `PLUGIN_REVISION`) in `Makefile`, add a
`pkg-descr` changelog entry, commit, then tag `v<version>` (for example
`v1.0.b1` for a beta, `v1.0`, or `v1.0_1` for a revision) and push the tag.
The workflow refuses a tag that does not match the Makefile. pkg sorts
`1.0.b1` before `1.0`, so beta installs upgrade to the final release
automatically.

`packaging/build-repo.sh` can also run by hand on FreeBSD 15 or an OPNsense
26.7 test VM (`ALLOW_UNSIGNED=1` for a local test).

If the signing key ever changes, existing installs refuse the new key until
`/usr/local/etc/pkg/keys/mdnsbridge.pub` is removed and `install.sh` rerun.
Say so in the release notes.
