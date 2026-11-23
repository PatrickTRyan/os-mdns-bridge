# Updates & Bug Fixes

## Following v1.0.b1 Release

### 22 Nov 2026

net/mdns-bridge: only wheel and wwwonly may stop the service

An issue was noted in the base OPNsense Plugins that would allow any user to stop the service.
configd's socket is open to every local user, so without allowed_groups any of them could stop mdns-bridge. This update matches core's service stop actions. The same fix was merged into the main plugins 19 Nov 2026 and added to mdns-bridge 22-Nov-2026.
