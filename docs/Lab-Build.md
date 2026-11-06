OPNsense Hyper-V Development Lab Checklist
Objective
•	Create a safe OPNsense development environment for building and testing the os-mdns-bridge plugin.
•	Keep all experimentation isolated from the production OPNsense firewall.
•	Allow development and administration from the primary workstation.
________________________________________
Hardware
Lab Host
•	Lenovo T470s
•	24 GB RAM
•	Hyper-V installed
•	Connected to production network via Ethernet
Production Network
•	Production OPNsense LAN: 172.25.1.1/24
•	T470 connected to the same switch as the production firewall
________________________________________
Hyper-V Configuration
Create WAN Virtual Switch
Create a new Virtual Switch:
•	Type: External
•	Name: WAN-External
•	Bound to physical adapter: 
o	Intel(R) Ethernet Connection I219-LM
•	Check: 
o	Allow management operating system to share this network adapter
________________________________________
Create LAN Virtual Switch
Create a second Virtual Switch:
•	Type: Internal
•	Name: OPNsense-LAN
Purpose:
•	Private management network between Windows host and OPNsense VM
________________________________________
Create OPNsense VM
VM Settings
•	Name: OPNsense-Dev
•	Generation: Generation 2
•	Memory: 4096 MB (or larger)
•	Virtual Disk: 40 GB VHDX
________________________________________
Attach Installation Media
•	Mount OPNsense 26.7 ISO
________________________________________
Configure Network Adapters
Adapter 1
•	Connect to: WAN-External
Purpose:
•	WAN interface
Adapter 2
•	Connect to: OPNsense-LAN
Purpose:
•	LAN interface
________________________________________
Security Settings
•	Disable Secure Boot
________________________________________
Advanced Features
WAN Adapter:
•	Enable MAC Address Spoofing
________________________________________
Install OPNsense
•	Boot VM from ISO
•	Install OPNsense to virtual disk
•	Complete installation
•	Remove ISO from DVD drive
•	Reboot VM
•	Verify normal boot from installed disk
________________________________________
Interface Assignment
Detected interfaces:
•	hn0
•	hn1
Assignments:
•	WAN = hn0
•	LAN = hn1
________________________________________
IP Configuration
WAN
Obtained via DHCP from production OPNsense:
•	WAN IP: 172.25.1.110/24
•	Gateway: 172.25.1.1
________________________________________
LAN
Configured as:
•	LAN IP: 192.168.50.1/24
________________________________________
Configure Hyper-V Host Management Interface
Configure:
vEthernet (OPNsense-LAN)
Settings:
•	IP Address: 192.168.50.10
•	Subnet Mask: 255.255.255.0
•	Default Gateway: Blank
________________________________________
Verify Local Connectivity
Connectivity Tests
From T470 Host:
•	Ping 192.168.50.1
•	Open https://192.168.50.1
•	SSH to 192.168.50.1
Example:
PowerShell
Test-NetConnection 192.168.50.1 -Port 22
 
Expected:
TcpTestSucceeded : True
________________________________________
Verify Internet Connectivity
From OPNsense:
•	Open Shell
•	Run:
Shell
pkg update
Expected:
•	Successful repository update
________________________________________
Enable SSH
GUI:
System
→ Settings
→ Administration
•	Enable Secure Shell
Verify:
Shell
sockstat -4 -6 -l | egrep '(:22|:443)'
Expected:
•	SSH listening on port 22
•	GUI listening on port 443
________________________________________
WAN Management Access Troubleshooting
Initial Problem
Observed:
•	Ping to 172.25.1.110 worked
•	ARP worked
•	Traceroute worked
•	SSH timed out
•	HTTPS timed out
________________________________________
Troubleshooting Performed
•	Verified SSH daemon running
•	Verified HTTPS service running
•	Created WAN allow rules
•	Disabled "Block Private Networks"
•	Disabled Windows Firewall temporarily
•	Disabled NIC offload features temporarily
•	Confirmed TCP SYN reached OPNsense
•	Confirmed TCP SYN-ACK left OPNsense
Packet capture showed:
Client -> Firewall SYN
Firewall -> Client SYN-ACK
but connection never completed.
________________________________________
Root Cause
Navigate to:
Firewall
→ Settings
→ Advanced
Enable:
•	Disable reply-to on WAN rules
________________________________________
Resolution
After enabling:
Disable reply-to on WAN rules
Verified:
•	SSH to 172.25.1.110
•	HTTPS to 172.25.1.110
•	WinSCP connectivity
________________________________________
Development Preparation
Install Git
•	Run:
Shell
pkg install git
________________________________________
Clone OPNsense Plugin Repository
•	Run:
Shell
cd /usr/local
git clone https://github.com/opnsense/plugins.git
________________________________________
Plugin Research
Located reference plugin:
•	net/mdns-repeater
Planned project:
•	os-mdns-bridge
________________________________________
Checkpoints
Hyper-V Snapshot
Create:
•	Clean OPNsense Install
________________________________________
Create:
•	Working Development Environment
________________________________________
Final Network Diagram
Production Network
172.25.1.0/24
|
+-----------------------+
| Production OPNsense |
| 172.25.1.1 |
+-----------------------+
|
Switch
|
+--------------------------------+
| Lenovo T470s Hyper-V Host |
| |
| WAN-External |
| 172.25.1.101 |
| |
| OPNsense-LAN |
| 192.168.50.10 |
+--------------------------------+
| |
| Hyper-V |
| |
v
+---------------------------+
| OPNsense Dev VM |
| |
| WAN (hn0) |
| 172.25.1.110 |
| Gateway 172.25.1.1 |
| |
| LAN (hn1) |
| 192.168.50.1 |
+---------------------------+
Management Paths
Preferred Internal Management
T470 Host
↓
192.168.50.10
↓
192.168.50.1
Services:
•	SSH
•	HTTPS
•	WinSCP
Remote Management From Main PC
Main PC
↓
172.25.1.110
Services:
•	SSH
•	HTTPS
•	WinSCP
(Requires Disable reply-to on WAN rules to be enabled.)

