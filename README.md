# Mongodb Status and Backup


This is a simple script that you can monitor a mongodb cluster. It expects to connect to 1 or more mongos routers.

It also comes with a backup script for your cronning delight.

### Installation

Fairly straight forward, copy config.ini.example to config.ini and change the settings. There are no weird dependencies here, even mustache is embedded.

Dev'd and tested on Ubuntu 16.04 with php7.

Pro tip: Make your webserver not serve config.ini unless you like your passwords in the open. Use something like this in an .htaccess file:

```
<filesMatch "\.(ini)$">
	Order Allow, Deny
	Deny from all
</filesMatch>
```

If you are looking for what to do with mongodb permissions stuff, there's an example command in the config.ini.example file. When in doubt, run it
on every primary node. Worst that can happen is you get an error.


###Usage
Run the backup.php as a cronjob to mongodump your database.

monitor.php may take a while to complete since PHP is so serial. But you can just drop in the whole folder into apache and smoke it.

Normally, there aren't any errors and you're chilling happily at OK everywhere. If you get a 503, there's an error. The status page will show one of two new sections,
an Error log section, and a Backup log section. If any host was unreachable or there were replica set problems (quite unlikely), you should see them there.

Individual mongodb nodes can fail and the cluster health will not drop. Seeing a replicaSetError is truly a big deal. Probably best to restore from backups and skip the 
crazy fixing.