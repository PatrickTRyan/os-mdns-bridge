# os-mdns-bridge plugin review

Review of [PatrickTRyan/os-mdns-bridge](https://github.com/PatrickTRyan/os-mdns-bridge) against the OPNsense plugin conventions, the official os-mdns-repeater plugin, and upstream mdns-bridge source. Check items off as you fix them; your progress stays in this browser.

**0 of 0 fixed**

[Findings](#findings) [Packaging path](#packaging)

## What’s already right

- The directory layout, controllers, ACL, menu and configd actions match the OPNsense MVC pattern and the mdns-repeater reference plugin.
- The service name `mdns_bridge` and pidfile `/var/run/mdns_bridge.pid` match the FreeBSD port’s rc script.
- I rendered your config template with sample settings. It produces a valid `[global]` section with the keys mdns-bridge expects.

## Filter by file

Must fix: breaks at runtime Should fix before submitting Polish or later

1. ### Move the plugin into a fork of opnsense/plugins

   Your `Makefile` includes `../../Mk/plugins.mk`, which only exists inside the official plugins tree. In your repo that path resolves to nothing, so the plugin can’t be built as a package where it is now. Fork `opnsense/plugins` and place the contents of `net-mdns-bridge/` at `net/mdns-bridge/`. Your `PLUGIN_NAME=mdns-bridge` will produce a package named `os-mdns-bridge`.
2. ### Build and install it on your lab firewall

   Clone your fork onto the OPNsense VM and run `make package` from `net/mdns-bridge`, then install the resulting package. The plugins tree also has lint and style targets; run those before opening a pull request. Confirm the exact commands against the development section of docs.opnsense.org, since build targets change between releases.
3. ### Get the mdns-bridge package into OPNsense’s repository

   `PLUGIN_DEPENDS=mdns-bridge` is the correct way to pull in the daemon. When someone installs your plugin, `pkg` installs mdns-bridge alongside it.

   **The dependency isn’t available yet.** The port is in `opnsense/ports` (version 3.1.0), but `net/mdns-bridge` is not listed in `opnsense/tools` `config/26.7/ports.conf`, which controls which packages OPNsense actually builds. `net/mdns-repeater` is listed there. On a stock OPNsense install, `os-mdns-bridge` would fail to install because `pkg` can’t find its dependency.

   To fix this, ask the OPNsense maintainers to add `net/mdns-bridge` to the ports list. You can raise it in your plugin pull request or open an issue in `opnsense/tools`. Until then, keep testing with the daemon installed manually, as you’ve been doing.

   Checked against the opnsense/tools and opnsense/ports master branches on 9 Nov 2026.
4. ### Open a pull request to opnsense/plugins

   Before you submit, clear the must-fix items and add BSD-2-Clause headers. Mention that this plugin depends on the ports.conf addition from step 3. Expect review comments; mdns-repeater is the closest precedent, so pointing to how your plugin differs (service filtering, IPv4/IPv6 control) will help the maintainers.
5. ### After it’s merged

   The plugin ships in a later OPNsense plugin release and shows up under System › Firmware › Plugins. Keep the `Plugin Changelog` in `pkg-descr` updated, and bump `PLUGIN_VERSION` with each change.