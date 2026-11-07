# mdns-repeater Architecture

## Model

<path>

Purpose:
Stores plugin configuration

## Settings Controller

<path>

Purpose:
GUI configuration handling

## Service Controller

<path>

Purpose:
start/stop/restart/status

## Backend Actions

<path>

Purpose:
Maps configctl commands to shell commands

## Templates

<path>

Purpose:
Generates runtime configuration files

# mdns-repeater Architecture

## Model

File:
MDNSRepeater.xml

Settings:
- enabled
- enablecarp
- interfaces
- blocklist

Interesting Notes:
- Uses InterfaceField
- Supports multi-select interfaces
- Stores config under OPNsense/MDNSRepeater

Potential Reuse for os-mdns-bridge:
- enabled
- interfaces
- enablecarp (optional)


## Service Actions

File:
actions_mdnsrepeater.conf

Purpose:
Maps configctl commands to FreeBSD service commands.

Examples:

configctl mdnsrepeater start
  -> service mdns-repeater start

configctl mdnsrepeater stop
  -> service mdns-repeater stop

configctl mdnsrepeater restart
  -> service mdns-repeater restart

configctl mdnsrepeater status
  -> service mdns-repeater status

Reuse Potential:
Very High

Expected future file:
actions_mdnsbridge.conf

