# xloger-php
The xloger-php library provides and API for communicating with [Xloger](https://github.com/idollo/xloger)


## Requirement
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
require "xloger.php" in your index.php
```php
define("XLOGER_SOCKET_ADDRESS", "127.0.0.1");   // the xloger server address
define("XLOGER_SOCKET_PORT", "19527");          // the xloger server socket port

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
