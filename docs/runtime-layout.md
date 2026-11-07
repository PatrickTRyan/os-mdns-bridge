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

