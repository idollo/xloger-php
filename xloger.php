<?php
/**
 * PHP 控制台Trace记录生成器
 * 
 * 使用方法
 * // log 数据
 * XLoger::log( $data );
 * 
 * // 警告信息
 * XLoger::warning( "warning_message" , $somedata=[] );
 * 
 * // 错误信息
 * XLoger::error( "error_message", $somedata=[] );
 * 
 * // 异常信息
 * XLoger::exception( new Exception("error message") );
 */

# -------------------------------------
# 系统定义, 不要修改其属性
# -------------------------------------
# 
# 自定义 Log 级别属性
# 
define("XLOGER_CUSTOM_NONE", 0);
define("XLOGER_CUSTOM_LOG", 1);
define("XLOGER_CUSTOM_WARNING", 2);
define("XLOGER_CUSTOM_ERROR", 4);
define("XLOGER_CUSTOM_SQL", 8);
define("XLOGER_CUSTOM_ALL", 15);


$console_config_file = dirname(__FILE__).DIRECTORY_SEPARATOR."xloger.config.php";
if(file_exists( $console_config_file  )){
	require($console_config_file);
}
/*!
 * 以下是自定义配置
 * ---------------------------------------
 * 服务配置
 */

# Xloger 配置
if(!defined("XLOGER_SERVER_HOST")){ define("XLOGER_SERVER_HOST", "XLogerServer"); }
if(!defined("XLOGER_SERVER_PORT")){ define("XLOGER_SERVER_PORT", 19527 ); }
# timeout ms
if(!defined("XLOGER_SOCKET_CONNECT_TIMEOUT")){ define("XLOGER_SOCKET_CONNECT_TIMEOUT", 3 ); }

# 是否监控页面线程
if(!defined("XLOGER_TRACE_THREAD")){
	define("XLOGER_TRACE_THREAD", 1);
}

# 监控的错误类型  e.g.  E_ALL ^ E_NOTICE
if(!defined("XLOGER_TRACE_ERROR")){
	define("XLOGER_TRACE_ERROR", E_ALL );
}

if(!defined("XLOGER_DISPLAY_ERRORS")){
	define("XLOGER_DISPLAY_ERRORS", 0);
}

# 自定义监控信息 ::log()  ::warning()  ::error()
# 关闭所有 XLoger::$TRACE_LOG = XLOGER_CUSTOM_NONE;
if(!defined("XLOGER_TRACE_LOG")){
	define("XLOGER_TRACE_LOG", XLOGER_CUSTOM_ALL );
}

# 总是发送log信息
if(!defined("XLOGER_ALWAYS_TRACE_LOG")){
	define("XLOGER_ALWAYS_TRACE_LOG", 0 );
}



/**
 * Set default value to which if variable is unset.
 * 设置默认值，如果变量未赋值
 * @param mixed var - The variable being evaluted.
 * @param mixed default - The variable instead of unset var.
 * @return mixed - &var if var is seted, default otherwish.
 */
function xloger_set_def(&$var, $default = null) {
	if (! isset( $var )) return $default;
	return $var;
}

/**
 * utf8_encode data
 */
function utf8_encode_deep(&$input, $depth=8, $cur=1) {
	if($cur>=$depth) return;
    if (is_string($input)) {
        $input = mb_convert_encoding($input,'UTF-8','UTF-8');
    } else if (is_array($input)) {
        foreach ($input as &$value) {
            utf8_encode_deep($value, $depth, ++$cur);
        }
        unset($value);
    } else if (is_object($input)) {
        $vars = array_keys(get_object_vars($input));
        foreach ($vars as $var) {
            utf8_encode_deep($input->$var, $depth, ++$cur);
        }
    }
}
// JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR = 320
function utf8_json_encode($data, $options = 320 ){
	utf8_encode_deep($data);
	return json_encode($data, $options);
}


/**
 * Console 静态类
 */
class XLoger {
	const C_LOG = 1;
	const C_WARNING = 2;
	const C_ERROR = 4;
	const C_ALL = 7;

	static $SERVER;
	static $args;

	static $TRACE_LOG = XLOGER_TRACE_LOG;
	static $TRACE_ERROR = XLOGER_TRACE_ERROR;
	static $helper;

	public static function __init(){
		self::$SERVER = isset($_SERVER)?$_SERVER:array();
		self::$helper = new XLogerHelper();
	}

	// log
	public static function log($vars){
		return self::$helper->log($vars);
	}

	// 创建文件日志用助手
	public static function fileLoger($logfile ){
		return new XFileLoger($logfile );
	}

	// warning
	public static function warning($msg, $data = array()){
		return self::$helper->warning($msg, $data);
	}
	// error
	public static function error($msg, $data=array()){
		return self::$helper->error($msg, $data);
	}

	public static function trace($type, $data=array()){
		return self::$helper->trace($type, $data);
	}

	// 获取线程
	public static function thread(){
		return self::$helper->thread();
	}
	// create a SqlQueryTrace
	public static function sql($query, $note=""){
		return self::$helper->sql($query, $note);
	}

	public static function shellArgs($args){
		self::$args = $args;
	}

	// get the server variable
	public static function s($name, $default){
		return xloger_set_def(XLoger::$SERVER[$name], $default );
	}
	/**
	 * 致命错误
	 */
	public static function fatal_handler(){
		$error = error_get_last();
		self::error_handler($error['type'], $error['message'], $error['file'], $error['line']);
	}

	/**
	 * 错误捕获
	 */
	public static function error_handler($error_type, $message, $file, $line, $info=null){
		//	$error_types = array(
		//		1=>'ERROR', 2=>'WARNING', 4=>'PARSE', 8=>'NOTICE', 16=>'CORE_ERROR', 
		//		32=>'CORE_WARNING', 64=>'COMPILE_ERROR', 128=>'COMPILE_WARNING', 256=>'USER_ERROR', 512=>'USER_WARNING', 1024=>'USER_NOTICE', 2047=>'ALL', 2048=>'STRICT'
		//	);
		$data = array("type"=>$error_type,  "message" => $message, "file" => $file, "line" => $line );

		// 注册error_handler后, 不会再打印错误, 这里重新打印PHP的错误设置
		if( XLOGER_DISPLAY_ERRORS && ini_get("display_errors") && (ini_get('error_reporting')&$error_type) ){
			self::display_error($data);
		}


		// 不符合错误配置
		if(!(XLOGER_TRACE_ERROR & $error_type) || !ini_get('error_reporting')) return;
		switch($error_type){
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
				self::trace("error", $data );
				break;
			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				self::trace("warning", $data );
				break;
			case E_PARSE:
				self::trace("parse", $data );
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
				self::trace("notice", $data);
		}
	}

	public static function display_error($data){
		$type = '';
		switch($data['type']){
			case E_ERROR:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
				$type ="Error";
				break;
			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				$type = "Warning";
				break;
			case E_PARSE:
				$type = "Parse";
				break;
			case E_NOTICE:
			case E_USER_NOTICE:
				$type = "Notice";
				break;
		}
		echo "<b>{$type}:</b> {$data['message']} in {$data['file']} on line {$data['line']}<br/>".PHP_EOL;
	}

	/**
	 * 异常捕获
	 */
	public static function exception_handler($exception){
		XLoger::$helper->exception( $exception );
	}
}


/**
 * XLogerHelper
 */
class XLogerHelper {
	private $_thread;	// 线程信息
	private $_client_ip;
	private $_host;
	private $_watched;
	private $_config;

	public $requestTime;	// 请求时间
	public $destructTime;	// 页面结束时间

	public function __construct(){
		// 线程ID参数
		$this->_thread = $this->createThreadID();
		$headers = function_exists("getallheaders")? getallheaders() : array();
		if(isset($headers["xloger-thread"])){
			$super_thread = $headers["xloger-thread"];
		}else{
			$super_thread =  isset($_REQUEST['xloger_thread'])?$_REQUEST['xloger_thread'] : null;
		}
		if($super_thread){
			$this->_thread = $super_thread."_".$this->_thread;
		}
		// HttpHost
		$this->_host = XLoger::s("HTTP_HOST", "PHPScript: ");
		// 客户端IP
		$this->_client_ip = $this->clientIP();

		// 报告错误日志
		if(XLOGER_CUSTOM_ERROR & XLoger::$TRACE_LOG){
			// 致命错误
			register_shutdown_function( array("XLoger", "fatal_handler") );
			// 捕获错误
			set_error_handler( array('XLoger','error_handler') );
			// 自动捕获异常
			set_exception_handler( array('XLoger','exception_handler') );
		}

		$handshake_data = null;

		// socket connection
		$socket = self::socket();
		if($socket !== false){
			$threaddata =  $this->_threadData();
			// unset postData, 避免大量数据
			unset($threaddata["postData"]);
			$this->publish("checkin", $this->_threadData() );
			$handshake_data = socket_read( $socket , 1024*1024 , PHP_NORMAL_READ );
			@socket_set_nonblock($socket);
			$handshake_data = json_decode( $handshake_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		// 开始时间
		$this->requestTime =  xloger_set_def(XLoger::$SERVER['REQUEST_TIME_FLOAT'], microtime(true) );

		
		// 是否监控线程
		if(isset($handshake_data['accepted']) && $handshake_data['accepted']){
			$this->_watched = true;
		}else{
			$this->_watched = false;
			return;
		}

		// 线程开始
		$this->_traceThreadStart();
	}

	/**
	 * 线程销毁前回调
	 */
	public function __destruct(){
		if($this->_watched) $this->_traceThreadEnd();
	}

	
	/**
	 * log 数据
	 */
	public function log(){
		if(!XLOGER_ALWAYS_TRACE_LOG && !$this->_watched) return;
		if(!(XLoger::$TRACE_LOG & XLOGER_CUSTOM_LOG) ) return;
		return $this->trace( "log", $this->_pitchBacktrace(debug_backtrace()) );
	}

	/**
	 * 自定义警告
	 */
	public function warning(){
		if( !(XLoger::$TRACE_LOG & XLOGER_CUSTOM_WARNING) ) return;
		return $this->trace( "cwarning", $this->_pitchBacktrace( debug_backtrace()) );
	}

	/**
	 * 自定义错误
	 */
	public function error($msg, $data=array()){
		if( !(XLoger::$TRACE_LOG & XLOGER_CUSTOM_ERROR) ) return;
		return $this->trace( "cerror", $this->_pitchBacktrace( debug_backtrace()) );
	}

	/**
	 * trace 错误
	 * @param Exception $e, 错误对象 
	 */
	public function exception($e){
		// class Exception ...
		// final function getMessage(); // 返回异常信息
		// final function getCode(); // 返回异常代码
		// final function getFile(); // 返回发生异常的文件名
		// final function getLine(); // 返回发生异常的代码行号
		// final function getTrace(); // backtrace() 数组
		// final function getTraceAsString(); // 已格成化成字符串的 getTrace() 信息 
		return $this->trace("error", array(
			"message"=> $e->getMessage(),
			"code" => $e->getCode(),
			"file" => $e->getFile(),
			"line" => $e->getLine(),
			"traceback" => utf8_json_encode($e->getTrace())
		));
	}

	/**
	 * 创建SqlQuery Trace
	 */
	public function sql($query, $note=""){
		if(!$this->_watched || !(XLoger::$TRACE_LOG & XLOGER_CUSTOM_SQL)){
			return new XLogerSqlQueryTrace("", "", null );
		}
		$backtrace = $this->_pitchBacktrace( debug_backtrace());
		return new XLogerSqlQueryTrace($query, $note, $backtrace );
	}

	public function traceSql($sqltrace){
		if( !(XLoger::$TRACE_LOG & XLOGER_CUSTOM_SQL) || !$sqltrace instanceof XLogerSqlQueryTrace){return;}
		if(!$this->_watched) return; 
		$data = $sqltrace->traceData();
		if($data['error']){
			$this->trace('sqlerror', array_merge($data, array(
				"message"=> "{$data['error']} With [{$data['query']}]"
			)));
		}
		return $this->trace( "sqlquery", $data );
	}

	/**
	 * 定位代码行
	 */ 
	private function _pitchBacktrace($backtrace){
		$point = array_splice($backtrace, 0, 1)[0];
		foreach($backtrace as $t){
			if(strtolower($t["class"])=="xloger"){
				$point = $t;
				break;
			}
		}
		$data = array(
			"file" => $point["file"],
			"line" => $point["line"],
			"message" => (isset($point["args"][0]) && is_string($point["args"][0])) ?  $point["args"][0] : "no-message",
			"args" => $point["args"]
		);
		return $data;
	}


	/**
	 * 获取客户端IP
	 */
	public function clientIP(){
	    $unk = "unknown";
	    // customize clientip function
	    if( function_exists("xloger_client_ip")){
	    	return xloger_client_ip();
	    }

	    // 已声明的Client IP
    	$cip = xloger_set_def(XLoger::$SERVER["HTTP_CLIENT_IP"],null);
    	if( $cip && strcasecmp($cip, $unk)){ return $cip; }
	    
	    // 代理服务器转发
    	$hxff = xloger_set_def(XLoger::$SERVER["HTTP_X_FORWARDED_FOR"],null);
    	if( $hxff && strcasecmp($hxff, $unk)){ return $hxff; }

	    // 代理,  Nginx配置中传递这个参数
    	$xreal = xloger_set_def(XLoger::$SERVER["HTTP_X_REAL_IP"],null);
    	if( $xreal && strcasecmp($xreal, $unk)){ return $xreal; }
	   
	    // 直连远程
    	$rmadd = xloger_set_def(XLoger::$SERVER['HTTP_REMOTE_ADDR'], null);
    	if( $rmadd && strcasecmp($rmadd, $unk)){ return $rmadd; }

	    // 直连远程
    	$rmadd = xloger_set_def(XLoger::$SERVER['REMOTE_ADDR'],null);
    	if( $rmadd && strcasecmp($rmadd, $unk)){ return $rmadd; }
	    return null;
	}

	// 取得socket连接对象
	protected static function socket(){
		static $socket;
		if(isset($socket)) return $socket;
		if( ($socket = @socket_create(AF_INET, SOCK_STREAM, 0)) === false) {
			$socket = false;
		}
		if(is_resource($socket)){
			// switch to non-blocking
			socket_set_nonblock($socket);
			// store the current time
			$start_time = microtime(true)*1000;
			// loop until a connection is gained or timeout reached
			while (!@socket_connect($socket, XLOGER_SERVER_HOST, XLOGER_SERVER_PORT)) {
			    $err = socket_last_error($socket);
			    // success! connected ok
			    if($err === 56) { break; }
			    // if timeout reaches then close socket, return false;
			    if ((microtime(true)*1000 - $start_time) >= XLOGER_SOCKET_CONNECT_TIMEOUT) {
			        socket_close($socket);
			        $socket = false;
			        break;
			    }
			    usleep(2);
			}
			// re-block the socket if needed
			$socket && socket_set_block($socket);
		}
		return $socket;
	}


	/**
	 * trace 接口
	 * 需要 XLOGER_TRACE_ADDR 
	 */
	public function trace($type, $data){
		$fire = @utf8_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		// json_encode 异常
		if(json_last_error()!==JSON_ERROR_NONE){
			switch (json_last_error()) {
		        case JSON_ERROR_NONE:
		            $jserr =  ' - No errors';
		        	break;
		        case JSON_ERROR_DEPTH:
		            $jserr =   ' - Maximum stack depth exceeded';
		        	break;
		        case JSON_ERROR_STATE_MISMATCH:
		            $jserr =   ' - Underflow or the modes mismatch';
		        	break;
		        case JSON_ERROR_CTRL_CHAR:
		            $jserr =   ' - Unexpected control character found';
		        	break;
		        case JSON_ERROR_SYNTAX:
		            $jserr =   ' - Syntax error, malformed JSON';
		        	break;
		        case JSON_ERROR_UTF8:
		            $jserr =   ' - Malformed UTF-8 characters, possibly incorrectly encoded';
		        	break;
		        default:
		            $jserr =   ' - Unknown error';
		        	break;
		    }


			$error = array(
				"type"=> E_WARNING,  
				"message" => "PHP Warning:  json_encode(): {$jserr} >>". @utf8_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				"file" => $data['file'], 
				"line" => $data['line'] 
			);
			$this->trace("error", $error);
			return;
		}
		// 数据拼装
		$post = array(
			"type" => $type,
			"thread" => $this->thread(),
			"timestamp" => time(),
			"fire" => $fire
		);
		if(strtolower($type)!="filelog"){ // 补全进程信息
			$post = array_merge($this->_threadData(), $post );
		}
		$this->publish("trace", $post);
	}


	/**
	 * 通用页面参数
	 * 
	 */
	public function _threadData(){
		static $data;
		if(isset($data)) return $data;
		$method = XLoger::s("REQUEST_METHOD", "Execute");
		$data = array(
			"thread" => $this->thread(),
			"timestamp" => time(),
			"host" => Xloger::s("HTTP_HOST", "PHPScript: "),
			"userAgent" => Xloger::s("HTTP_USER_AGENT" ,"none"),
			"clientIP" => $this->_client_ip,
			"httpMethod" => $method,
			"postData"=>  strtolower($method)=="post"?file_get_contents("php://input"):'',
			"requestURI" => Xloger::s("REQUEST_URI" , XLoger::$args?getcwd().DIRECTORY_SEPARATOR. implode(" ", XLoger::$args):"unknown" ),
			"cookie" => Xloger::s("HTTP_COOKIE" ,"")
		);
		foreach ($data as $key => $value) {
			if(is_string($value)){
				$data[$key] = mb_convert_encoding($value,'UTF-8','UTF-8');
			}
		}
		return $data;
	}

	// 线程开始
	private function _traceThreadStart(){
		if(!$this->_watched) return;
		$post = array_merge( array("type"=>"threadStart" ), $this->_threadData() );
		$this->publish("trace", $post);
	}

	// 线程结束
	private function _traceThreadEnd(){
		if(!$this->_watched) return;
		$this->publish( "trace", array(
			"type"=>"threadEnd",
			"thread" => $this->thread(),
			"timestamp" => time(),
			"duration" => microtime(true) - $this->requestTime
		));
	}

	/**
	 * 发起异步请求
	 */
	public function publish($action, $data = array()){
		$data = array("action"=> $action, "data"=>$data );
		$stream = @json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )."\n";
		$socket = self::socket();
		if($socket===false) return;
		@socket_write($socket, $stream, strlen($stream));
	}

	// 线程
	public function thread(){
		return $this->_thread;
	}

	// 生成唯一的线程ID
	private function createThreadID($namespace = '') {
        return md5(base64_encode( uniqid( $namespace.rand(1, 10000000).microtime(), true ) ));
    }
}


/**
 * class XLogerSqlQueryTrace, mysql 查询跟踪对象;
 * @author idolo
 */
class XLogerSqlQueryTrace {
	private $query_pushed;
	private $backtrace;

	public $query_string;
	public $note;
	public $error;
	public $time_start;
	public $time_end;
	
	public function __construct($querystring,$note="", $backtrace){
		// 去掉args属性, 改用 error, query, note ...
		unset($backtrace['args']);

		$this->backtrace = $backtrace;
		$this->time_start = microtime(true);
		$this->query_string = $querystring;
		$this->note = $note;
		$this->query_pushed = false;
	}

	// 输出 trace 数据,供参 XLoger::trace()
	public function traceData(){
		return array_merge($this->backtrace, array(
			"error" => $this->error,
			"query" => $this->query_string,
			"note" => $this->note,
			"duration" => $this->time_end - $this->time_start
		));
	}
	
	/**
	 * set query note
	 * @param string $error
	 */
	public function note($note){
		$this->note = $note;
	}
	
	/**
	 * set query error
	 * @param string $error
	 */
	public function error($error){
		$this->error = $error;
	}

	/**
	 * query finish method
	 * @param string $error
	 */
	public function finish(){
		if($this->query_pushed){ return; } //防止执行多次finish();
		$this->time_end = microtime( true );
		$this->query_pushed = true;
		XLoger::$helper->traceSql($this);
	}
}


/**
 * FileLoger
 * 全局关闭文件日志
 * define("X_FILE_LOGER_DISABLED", true);
 */
class XFileLoger {
	protected	$_logfile;
	public	$date_format = "Y-m-d H:i:s";
	public	$sep = "\n---\n";
	public	$argformat = "--[{i}]:{arg}";
	public	$argsep = "\n";
	public	$format = "[{date}] Logs on {file} in line {line}\n{logs}\n--{method}:{url} -- {clientip}";
	
	public function __construct($logfile){
		$branch = isset($_SERVER["XLOGER_BRANCH"])?($_SERVER["XLOGER_BRANCH"]."/"):"";
		$this->_logfile = $branch.$logfile;
	}

	public function log(){
		if($this->_disabled()) return;
		$trace = array_splice(debug_backtrace(), 0, 1)[0];
		$message = $this->_build_message($trace);
		$this->_push($trace, $message);
	}

	public function logr(){
		if($this->_disabled()) return;
		$trace = array_splice(debug_backtrace(), 0, 1)[0];
		$message = $this->_build_message($trace, true);
		$this->_push($trace, $message);
	}

	private function _push($trace, $message){
		if($this->_disabled()) return;
		XLoger::$helper->trace("filelog", array(
			"file" => $trace['file'],
			"line" => $trace["line"],
			"logfile" => $this->_logfile,
			"message" => $message
		));
	}

	private function _disabled(){
		return !!(defined("X_FILE_LOGER_DISABLED") && X_FILE_LOGER_DISABLED);
	}

	private function _build_message($trace, $readable=false){
		$td = XLoger::$helper->_threadData();
		extract($td);
		$file = $trace['file'];
		$line = $trace['line'];
		$sep = $this->sep;
		$date = date($this->date_format);
		$method = $httpMethod;
		$uri = $requestURI;
		$url = "http://{$host}{$uri}";
		$clientip = $clientIP;
		$logs = array();
		$jsonopt = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if($readable) $jsonopt = $jsonopt | JSON_PRETTY_PRINT;
		foreach ($trace['args'] as $i => $arg) {
			$arg = is_string($arg) ? $arg : utf8_json_encode($arg, $jsonopt);
			$logs[] = preg_replace_callback("|\{(.*?)\}|", function($match) use($arg, $i){
				if(isset($match[1]) && isset($$match[1])){
					return $$match[1];
				}
				return $match[0];
			}, $this->argformat);
		}
		$logs = implode($this->argsep, $logs);

		$vars = compact("date","sep","file","line","method","host","uri","url","clientip","useragent","logs");
		$message = preg_replace_callback("|\{(.*?)\}|", function($match) use($vars){
			extract($vars);
			if(isset($match[1]) && isset($$match[1])){
				return $$match[1];
			}
			return $match[0];
		}, $this->format);
		return $message;
	}
}

// Fetch the Command Line args
if(isset($argv)){
	XLoger::shellArgs($argv);
}
XLoger::__init();
?>