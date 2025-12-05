At the time of writing, the Grandstream GWN7710r (formware 1.0.7.9) does not export its Input Voltage when querying the device's SNMP agent.  As a workaround, this php script logs into the device via curl / HTTP and scrapes the voltage value.  It then exports the voltage as per AEN2000 guidelines.
https://nagios-plugins.org/doc/guidelines.html#AEN200

Install Nagios Plugins as per LibreNMS documentation - currently at https://docs.librenms.org/Extensions/Services/
You'll also need to install php on your LibreNMS server.

You can use service templates but this will limit you to having the same username and password for each device as well as voltage warning and critical levels.
![Alt text](/libreNMS_service_template.jpg?raw=true "libreNMS_service_template.jpg")



The GWN7710r does not accept simultaneous logins nor frequent logins (I have not fully tested but less than around 30s between logins will lead to a failure.) so attempts to login to a GWN7710r via a web browser will fail if attempted within a few seconds of LibreNMS polling via this plugin.  Similarly, the plugin will return "GWN_VOLTAGE CRITICAL: Login failed. Check IP/connectivity or authentication credentials." if a successful login via a web browser has just been performed.

