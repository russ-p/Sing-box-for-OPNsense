## Sing-box for OPNsense
One-click installation tool for Sing-box, implementing transparent proxy functionality on OPNsense. Tested on OPNsense 26.1.6.

![](images/proxy.png)

## Program Version
[Vincent-Loeng's modified Sing-Box](https://github.com/Vincent-Loeng/sing-box)

## Important Notes
1. Currently supports x86_64 platform only.
2. The script does not provide any node information; please prepare your own outbound configuration file.
3. The script will automatically add TUN interface, firewall rules, and modify the Unbound default port.
4. The script includes configuration templates; you only need to supplement the outbound configuration section to get started.
5. Due to configuration differences between different Sing-box versions, the released configuration files are specific to the current installer version.
6. To reduce the number of logs saved during long-term operation, please change the log type to "error" or "warn" after debugging is complete.

## Installation Command

```bash
sh install.sh
```
![](images/install.png)

## Uninstall Command

```bash
sh uninstall.sh
```

## Configuration Steps
1. Go to VPN > Proxy Suite > Sing-Box, modify the outbound to route section content and save.
2. Click the restart button, then go to Interfaces > Assignments, check if the TUN virtual network interface has been added and enable it.
4. Go to Services > Unbound DNS > General, check if the listening interface has been modified to a port other than 53.
5. Go to Firewall > Rules (Floating), check if a TUN to TUN firewall rule has been added to the TUN interface.
6. Configuration complete. Clients can access ip111.cn to check if traffic splitting is working properly.

## Additional Notes
1. The default configuration file has Clash API functionality enabled. Access http://lan_ip:9090/ui to log into the dashboard and view proxy connection information.
2. Subscription updates can be set up as scheduled tasks for automatic updates. Go to System > Settings > Cron and add the "Renew sing-box subscription" task.