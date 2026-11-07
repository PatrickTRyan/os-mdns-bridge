mDNS-Bridge Installation on OPNsense
Purpose

Install Denny Page's mDNS-Bridge daemon on OPNsense for plugin development and testing.

Assumptions
OPNsense 26.7 installed
Internet connectivity verified
SSH access enabled
Root shell access available
Verify Build Environment

Update package repositories:

Shell
pkg update

Install development tools:

Shell
pkg install git gcc gmake

Additional dependencies may be required depending on the current mdns-bridge source tree.

Clone mdns-bridge Source

Create a workspace:

Shell
mkdir -p /root/src
cd /root/src

Clone the source repository:

Shell
git clone <mdns-bridge repository URL>

Record the exact repository URL once confirmed.

Build

Example:

Shell
cd mdns-bridge
gmake

or

Shell
make

depending on the build instructions.

Document all build dependencies discovered during testing.

Install Binary

Example:

Shell
gmake install

or manually:

Shell
cp mdns-bridge /usr/local/sbin/
chmod 755 /usr/local/sbin/mdns-bridge

Document the actual method used.

Install Service Script

Determine whether the project provides:

Plain Text
/etc/rc.d/mdns-bridge

or

Plain Text
/usr/local/etc/rc.d/mdns-bridge

If not, create one.

Create Configuration

Determine actual configuration location.

Possibilities:

Plain Text
/usr/local/etc/mdns-bridge.conf

or

Plain Text
/usr/local/etc/mdns-bridge/mdns-bridge.conf

Record the confirmed path.

Enable Startup
Shell
sysrc mdns_bridge_enable=YES

or equivalent rc.conf setting.

Document exact service variable names.

Verify Service
Shell
service mdns-bridge start
service mdns-bridge status

Verify process:

Shell
pgrep -laf mdns
Verify Function

Confirm service discovery between test VLANs.

Examples:

AirPlay
Sonos
Chromecast
Home Assistant

Milestone 1
-----------
Initial plugin architecture drafted
