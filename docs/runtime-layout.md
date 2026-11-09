# mdns-bridge Runtime Layout

Executable:
    /usr/local/bin/mdns-bridge

Configuration:
    /usr/local/etc/mdns-bridge.conf

Service Script:
    /usr/local/etc/rc.d/mdns_bridge

Service Name:
    mdns_bridge

Enable Variable:
    mdns_bridge_enable

Notes:

A compatibility symlink was manually created:

    /usr/local/etc/rc.d/mdns-bridge
        ->
    /usr/local/etc/rc.d/mdns_bridge

The plugin should use the native service name
(mdns_bridge) and should not depend on the symlink.

## Coexistence with mdns-repeater

During initial testing, mdns-bridge reported:

    IPv4 bind on hn1 failed: Address already in use

After stopping both mdns-repeater and mdns-bridge, both services
were started successfully and remained running simultaneously.

Conclusion:

- mdns-repeater and mdns-bridge can coexist.
- The initial bind failure was transient or caused by stale process/socket state.
- os-mdns-bridge should not block or disable mdns-repeater.
- If a bind failure occurs, the operational guidance should be to verify
  running processes and UDP 5353 socket ownership.

  