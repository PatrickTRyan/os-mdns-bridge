# os-mdns-bridge

OPNsense plugin for [mdns-bridge](https://github.com/dennypage/mdns-bridge) by Denny Page:
multicast DNS (Bonjour / DNS-SD) forwarding between network segments, with
service filtering globally, per interface in both directions, and per
source/destination interface pair.

Target: OPNsense 26.7 (FreeBSD 15.1), mdns-bridge 3.1.0.

> **Beta (1.0.b1).** Installation, upgrade from 0.1, configuration
> generation and service control have been tested on OPNsense 26.7 with LAN
> and VLAN interfaces. Filtering on live traffic, CARP failover on a real HA
> pair and reboot behaviour are still being tested. Please report problems at
> <https://github.com/PatrickTRyan/os-mdns-bridge/issues> with the output of
> `configctl mdnsbridge check` and the last lines of
> **Services › mDNS Bridge › Log File**.

## Install

On the firewall, as root (either from the console menu **Option 8** or SSH to the box):

`sh fetch https://patricktryan.github.io/os-mdns-bridge/install.sh`

**Review the file if desired** `install.sh`

`sh install.sh`

The script adds this project's signed package repository, backs up
`/conf/config.xml` to `/root`, and installs `os-mdns-bridge` together with the
`mdns-bridge` daemon. Then from the GUI open **Services › mDNS Bridge** to configure.

### Updates

After the first install, updates arrive through **System › Firmware** like any
other package (or `pkg upgrade`). Running `install.sh` again is also safe: it
upgrades in place.

Your settings are stored in `/conf/config.xml`. Packages never modify that file;
when a new version changes the settings structure, a model migration converts
them on upgrade (0.1 settings are migrated automatically). The only file
overwritten is the generated `/usr/local/etc/mdns-bridge.conf`, which is
rebuilt from your settings. A hand-written `mdns-bridge.conf` found on first
install is saved to `/root` first.

### Uninstall

```sh
sh install.sh uninstall
```

This removes both packages and the repository. Settings stay in
`config.xml` and are used again if you reinstall.

### Do not combine with mDNS Repeater

Run either mdns-bridge or the `os-mdns-repeater` plugin, not both on the same
interfaces: two forwarders produce duplicate announcements. Disable
**Services › mDNS Repeater** (or remove `os-mdns-repeater`) before enabling
mDNS Bridge.

## How filtering works

A packet received on interface A and forwarded to interface B passes through:

1. the **global inbound** filter (General tab), for every packet;
2. A's **inbound** filter (Interfaces tab): what devices on A may advertise;
3. B's **outbound** filter (Interfaces tab): what devices on B may see.
   A **peer rule** "from A to B" (Peer rules tab) *replaces* B's outbound
   filter for packets from A, and may be the opposite type.

Each filter is `allow` (only matching names pass) or `deny` (matching names are
dropped). Filters are DNS labels: a service (`_ipp`), a host (`Office-Printer`)
or both (`Office-Printer._ipp`). They are case sensitive; `_tcp`, `_udp` and
`local` are unnecessary. Use whole services in allow lists and host names in
deny lists. `<all>` alone in a deny list blocks everything (not allowed
globally).

### Example: IoT sees only printers and casting from LAN

| Tab | Entry | Setting |
|---|---|---|
| Interfaces | LAN | no filters |
| Interfaces | IoT | Inbound: allow `_airplay, _raop, _googlecast, _hap`; Outbound: deny `<all>` |
| Peer rules | From LAN → To IoT | allow `_ipp, _ipps` |

LAN clients discover IoT's AirPlay, Cast and HomeKit devices. IoT devices
discover only LAN's printers and nothing else.

The **Generated configuration** tab shows the exact file given to the daemon.

## Firewall rules

mdns-bridge only forwards *discovery*. Two kinds of rules are still needed:

- **Discovery traffic to the firewall** on each bridged interface:
  UDP to `224.0.0.251` and `ff02::fb` port 5353. The default LAN rule allows
  this; restrictive VLANs (IoT, Guest) usually need an explicit pass rule.
- **The actual connection** from client to device (for example AirPlay or
  IPP ports from LAN to IoT). Discovery succeeding while connecting fails
  is almost always a missing pass rule here.

## High availability (CARP)

Set **CARP dependency** to a CARP VIP on one of the bridged networks. The
daemon runs only on the node that is MASTER for that VIP, and starts or stops
on CARP transitions. On the BACKUP node the service shows as stopped; that is
expected. Include mDNS Bridge and Virtual IPs in HA synchronization.

Without a CARP dependency the daemon runs on every node where it is enabled,
which on an HA pair means both nodes forward mDNS.

## Troubleshooting

- If Apply reports that the service did not start, the page shows why. The
  **Generated configuration** tab also lists, per interface, whether it will
  be bridged and over which address families. From a shell:
  `configctl mdnsbridge check`.
- Every bridged interface needs an address (IPv4, or IPv6 including
  link-local) in a family that at least one other bridged interface also has.
  A newly created VLAN gets its address only after its interface is saved and
  applied under **Interfaces**; a VLAN that tracks WAN for IPv6 also needs a
  free prefix ID, which requires WAN to request a prefix larger than /64.
- **Services › mDNS Bridge › Log File** holds the daemon's messages and the
  control script's CARP and start/stop decisions.
- **Log decode warnings** (General tab, advanced) starts the daemon with `-w`.
- The daemon reads interface addresses only at startup. The plugin restarts it
  when a bridged interface's address changes; after other interface changes,
  press **Apply**.
- To see the daemon's own view of interfaces and filter tables, stop the
  service, then from a shell run
  `mdns-bridge -f -c /usr/local/etc/mdns-bridge.conf` and press Ctrl-C.
  Do not do this while the service is running: both instances would forward.

## Development

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

## License

BSD 2-Clause, see [LICENSE](LICENSE). mdns-bridge is © Denny Page, BSD 2-Clause.
