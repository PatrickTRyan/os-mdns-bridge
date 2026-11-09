# Phase 3 Load Test

- [x] Build os-mdns-bridge package
- [x] Install os-mdns-bridge package
- [x] Resolve mdns-bridge package dependency
- [x] Migrate model to version 0.1.0
- [x] Display Services > mDNS Bridge
- [x] Save GUI configuration
- [x] Generate /usr/local/etc/mdns-bridge.conf
- [x] Generate /etc/rc.conf.d/mdns_bridge
- [x] Start service from GUI
- [x] Stop service from GUI
- [x] Restart service through Apply
- [x] Resolve OPNsense interface names to physical names
- [x] Run alongside mdns-repeater

## Observed transient issue

An initial manual start reported:

    IPv4 bind on hn1 failed: Address already in use

After stopping and restarting both services, mdns-bridge and
mdns-repeater ran simultaneously. Socket inspection showed
mdns-bridge using wildcard UDP 5353 bindings while mdns-repeater
used interface-address-specific bindings.
