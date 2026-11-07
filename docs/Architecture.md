# os-mdns-bridge Architecture

## Runtime Components

Binary:
    /usr/local/bin/mdns-bridge

Config:
    /usr/local/etc/mdns-bridge.conf

Service:
    mdns_bridge

## MVC Components

Model:
    MDNSBridge.xml

Controllers:
    IndexController
    SettingsController
    ServiceController

View:
    index.volt

Form:
    general.xml

## Templates

+TARGETS
mdnsbridge

## Actions

actions_mdnsbridge.conf