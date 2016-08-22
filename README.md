# xloger-php
The xloger-php library provides and API for communicating with [Xloger](https://github.com/idollo/xloger)


## Requirement
This is a client side library for [Xloger](https://github.com/idollo/xloger), make sure you had installed and started the server side app.

This API required `sockets` module for high-performence communication. make sure you had installed this moudule and enabled in php.ini.

**Linux:**
```conf
extension=sockets.so
```

**Windows:**
```conf
extension=sockets.dll
```

## Install xloger for php
copy xloger.php to your work dir, require "xloger.php" in your index.php
```php
require "[path to xloger]/xloger.php";
```

config the host as XLogerServer doesn't running at this server.
```php
// the xloger server address, default to 127.0.0.1
define("XLOGER_SERVER_HOST", "192.168.1.2");

require "[path to xloger]/xloger.php";
```

## Usage
```php
use \XLoger;

$params = array_merge($_GET, $_POST);
$user = User::db()->get_by_id($params['uid']);

// var_dump($params);  // forget that, out put this will destroy your data structure like html, json.
XLoger::log($params, $user);
```
Locate your browser to http://localhost:9527 for realtime watching your logs.
