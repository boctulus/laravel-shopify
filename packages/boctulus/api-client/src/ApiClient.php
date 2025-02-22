<?php

namespace Boctulus\ApiClient;

use Boctulus\ApiClient\Exception;
use Boctulus\ApiClient\Helpers\Arrays;
use Boctulus\ApiClient\Helpers\Config;
use Boctulus\ApiClient\Helpers\FileCache;
use Boctulus\ApiClient\Helpers\Logger;
use Boctulus\ApiClient\Helpers\Strings;
use Boctulus\ApiClient\Helpers\Url;
use Boctulus\ApiClient\Helpers\Utils;
use Boctulus\ApiClient\Helpers\XML;
use Boctulus\ApiClient\Interface\ICache;

/*
    @author Pablo Bozzolo

    @version 1.0.0

    Ver interesante uso de cURL aqui:
    
    https://stackoverflow.com/a/42375193/980631
    https://stackoverflow.com/a/20605980/980631

    TO-DO:

    - IMPLEMENTAR alias de metodos restantes de distintas librerias

    https://www.twilio.com/blog/5-ways-to-make-http-requests-in-php

    -  Devolver la respuesta como objeto con el metodo send() y request()
    y ese objeto podria implementar metodos como:

    toArray()

    Mantener data() y getBody() sin cambios

    - Para cache, usar FileMemoization o bien el driver por defecto
    que se configure

    Tener en cuenta que si se genera error, no se debe cachear (y documentar
    este comportamiento)
*/
class ApiClient
{   
    const HTTP_METH_POST   = "POST";
    const HTTP_METH_GET    = "GET";
    const HTTP_METH_PATCH  = "PATCH";
    const HTTP_METH_PUT    = "PUT";
    const HTTP_METH_DELETE = "DELETE";
    const HTTP_METH_HEAD   = "HEAD";

    /*
        User Agents
    */
    const USER_AG_FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:98.0) Gecko/20100101 Firefox/98.0';
    const USER_AG_SAFARI  = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/15.3 Safari/605.1.15';
    const USER_AGT_EDGE   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.79 Safari/537.36 Edg/100.0.4896.79';
    const USER_AG_CHROME  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.75 Safari/537.36';
    const USER_AG_POSTMAN = 'PostmanRuntime/7.34.0';


    // Cookies
    protected $cookieJar;
    protected $curl;

    // Request
    protected $url;
    protected $verb;
    protected $req_headers;
    protected $options = [];
    protected $body;
    protected $req_body;
    protected $encode_body;
    protected $max_retries = 1;
    protected $cert_ssl  = null;

    // Username & password
    protected $username;
    protected $password;

    // Response
    protected $raw_response;
    protected $response;

    protected $filename;
    protected $res_headers;
    protected $auto_decode;
    protected $status;
    protected $ignore_status_codes = false;
    protected $error;

    // Response Info
    protected $effective_url;
    protected $content_type;

    // Cache
    protected $expiration;
    protected $read_only = false;
    protected $cache_post_request = false;

    // Mock
    protected $mocked;

    // Logs
    protected $log_req    = false;
    protected $log_res    = false;
    protected $logger_fn  = 'log';

    // Extras
    protected $query_params = [];
    protected $debug   =  false;
    protected $show_req = false;
    protected $show_res = false;

    function __construct($url = null)
    {
        if ($url !== null){
            $this->setUrl($url);
        }

        $this->curl = curl_init();
    }

    function showRequest(){
        $this->show_req = true;
    }

    function getResource(){
        return $this->curl;
    }

    function close(){
        curl_close($this->curl);
    }

    function logReq($log_file  = 'req.txt'){
        if ($log_file === true  ||  $log_file === 1){
            $log_file = 'req.txt';
        }

        $this->log_req = $log_file;
        return $this;
    }

    function logRes($log_file = 'res.txt'){
        if ($log_file === true  ||  $log_file === 1){
            $log_file = 'res.txt';
        }

        $this->log_res = $log_file;
        return $this;
    }

    /*
        Debe usarse *antes* de llamar a request(), get(), post(), etc

        $mock puede ser la ruta a un archivo .json, .php o un array
    */
    function mock($mock, bool $ignore_empty = false)
    {   
        if (!$ignore_empty && empty($mock)){
            throw new \Exception("Empty mock!");
        }

        if (is_string($mock) && Strings::endsWith('.php', $mock)){
            if (!file_exists($mock)){
                throw new \Exception("Mock file '$mock' not found");
            }

            $mock = require $mock;
        }

        if (is_string($mock) && Strings::endsWith('.json', $mock)){
            if (!file_exists($mock)){
                throw new \Exception("Mock file '$mock' not found");
            }

            $mock = file_get_contents($mock);
        }

        // if (is_array($mock)){
        //     $mock = json_encode($mock, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_SLASHES);
        // }

        $this->response = $mock;
        $this->mocked   = true;

        if ($this->auto_decode == false){
            if (!Strings::isJSON($this->response)){
                $this->response = json_encode($this->response);
            }
        }
    }

    function dump(){
        return [
            'url'         => $this->url,
            'verb'        => $this->verb,
            'headers'     => $this->req_headers,
            'options'     => $this->options,
            'body'        => $this->body,
            'encode_body' => $this->encode_body,
            'max_retries' => $this->max_retries,
            'ssl'         => $this->cert_ssl,
            'cache_path'  => $this->getCachePath()
        ];
    }

    function exec(Array $args){
        $this->url         = $args['url'];
        $this->verb        = $args['verb'];
        $this->req_headers = $args['headers'];
        $this->body        = $args['body'];
        $this->options     = $args['options'];
        $this->encode_body = $args['encode_body'];
        $this->max_retries = $args['max_retries'];
        $this->cert_ssl    = $args['ssl'];

        return $this->request($this->url, $this->verb, $this->body, $this->req_headers, $this->options);
    }

    function debug(){
        $this->debug = true;        
		$this->option(CURLOPT_VERBOSE, True);
        
        return $this;
    }

    function setTimeOut(int $value){
        $this->option(CURLOPT_TIMEOUT, $value); 
    }

    function setConnectionTimeOut(int $value){
        $this->option(CURLOPT_CONNECTTIMEOUT, $value); 
    }
    
    function setUrl($url){
        $this->url = Url::normalize($url);
        return $this;
    }
    
    // alias
    function url($url){
        return $this->setUrl($url);
    }

    function useCookieJar(){
        $this->cookieJar = new CookieJar();
    }
    
	public function setCookieOptions($params = array())
	{
		if (is_array($params))
		{
			$params = http_build_query($params, '', '&');
		}

		$this->option(CURLOPT_COOKIE, $params);
		return $this;
	}

    static function instance($url = null) : ApiClient {
        return new ApiClient($url);
    }

    // Add new query param
    function queryParam(string $name, $value){
        $this->query_params[$name] = $value;
        return $this;
    }

    /*
        Add array of params
    */
    function queryParams(array $params){
        foreach ($params as $name => $value){
            $this->query_params[$name] = $value;
        }
        
        return $this;
    }

    function readOnly(bool $flag = true){
        $this->read_only = $flag;
        return $this;
    }

    function addHeader($key, $value){
        $this->req_headers[$key] = $value;
        return $this;
    }

    function setHeaders(Array $headers){
        $this->req_headers = $headers;
        return $this;
    }

    /*
        Ejecuta un callback cuano $cond es verdadero
    */
    function when($cond, callable $fn_success,  callable $fn_fail = null, ...$args){
        if ($cond){
            $fn_success($this, ...$args);
        } elseif ($fn_fail != null){
            $fn_fail($this, ...$args);
        }
        
        return $this;
    }

    function setOption($key, $val){
        $this->options[$key] = $val;
        return $this;
    }

    // alias
    function option($key, $val){
        $this->options[$key] = $val;
        return $this;
    }

    function setOptions($options){
        if (!empty($this->options) && !empty($options)){
            $this->options = array_merge($this->options, $options);
        } else {
            $this->options = $options ?? $this->options ?? null;
        }

        return $this;
    }

    // redirect
    function followLocations($max_redirs = 10){
        $this->options[CURLOPT_FOLLOWLOCATION] = ($max_redirs > 0);
        $this->options[CURLOPT_MAXREDIRS] = $max_redirs;

        return $this;
    }

    // alas
    function redirect($max_redirs = 10){
        return $this->followLocations($max_redirs);
    }

    function setBody($body, $encoded = true){
        $this->body = $body;
        $this->encode_body = $encoded;
        return $this;
    }

    function setDecode(bool $auto = true){
        $this->auto_decode = $auto;
        return $this;
    }

    // alias
    function decode(bool $val = true){
        return $this->setDecode($val);
    }

    function noDecode(){
        return $this->setDecode(false);
    }

    function contentType($type){
        $this->addHeader('Content-Type', $type);
        return $this;
    }
    
    function accept($type){
        $this->addHeader('Accept', $type);
        return $this;
    }

    function userAgent($str){
        $this->addHeader('User-Agent', $str);
        return $this;
    }
    
    // Ej: "Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6"
    function setUserAgent($str){
        $this->option(CURLOPT_USERAGENT, $str);
        return $this;
    }

    function authorization($str){
        $this->addHeader('Authorization', $str);
        return $this;
    }

    /*
        @param $expiration_time int seconds 
    */
    function setCache(int $expiration_time = 60){
        $this->expiration = $expiration_time;
        return $this;
    }

    // alias de setCache()
    function cache(int $expiration_time = 60){
        return $this->setCache($expiration_time);
    }

    /*
        Revisar. No funcionaria bien
    */
    function cacheUntil(string $datetime){
        $diffInSeconds = function(string $date2, string $date1 = '') {
            $d1 = new \DateTime($date1);
            $d2 = new \DateTime($date2);
        
            return $d2->getTimestamp() - $d1->getTimestamp();
        };

        $expiration_time = $diffInSeconds($datetime);

        // dd($expiration_time, 'EXP TIME (SECS)');

        return $this->setCache($expiration_time);
    }

    /*
        Que diferencia hay con FileCache::forget($this->url) ????
    */
    function clearCache(){
        unlink($this->getCachePath());
        return $this;
    }

    function enablePostRequestCache(){
        $this->cache_post_request = true;
        return $this;
    }

    function getRawResponse(){
        return $this->raw_response;
    }

    // Get Status Code
    function getStatus(){
        return $this->status;
    }

    // alias de getStatus()
    function status(){
        return $this->getStatus();
    }

    function ignoreStatusCodes(array $codes){
        $this->ignore_status_codes = $codes;
        return $this;
    }

    function getError(){
        return $this->error;
    }

    // alias de getError()
    function error(){
        return $this->error;
    }

    function data(bool $raw = false){
        if ($raw === false){
            if ($this->auto_decode && Strings::isJSON($this->response)){
                return json_decode($this->response, true);
            }
        }

        return $raw ? $this->raw_response : $this->response;
    }

    function getDataOrFail(bool $raw = false){
        if ($this->error()){
            throw new \Exception('ApiClient: ' . $this->error());
        }

        return $this->data($raw);
    }

    function getResponse($decode = null, $as_array = null){       
        if ($decode === null){
            $decode = $this->auto_decode;
        }

        if ($as_array == null){
            $as_array = true;
        }

        $data = $this->response;

        // dd($this->content_type. 'CONTENT TYPE');        

        if ((!empty($this->content_type) && Strings::startsWith('application/json', $this->content_type)) || ($decode && Strings::isJSON($data))){
            $data = json_decode($this->response, $as_array);
        } else 
        
        if ((!empty($this->content_type) && Strings::containsAny(['/xml', '+xml'], $this->content_type))  || ($decode && XML::isValidXML($data))){
            $data = XML::toArray($data);
        }
        
        $res = [
            'data'      => $data,
            'http_code' => $this->status,
            'error'     => $this->error
        ];

        return $res;
    }

    function setRetries($qty){
        $this->max_retries = $qty;
        return $this;
    }

    function disableSSL(){
        // dejo claro se aplican settings
        $this->cert_ssl = true;

        $this->setOption(CURLOPT_SSL_VERIFYHOST, 0);
        $this->setOption(CURLOPT_SSL_VERIFYPEER, 0);

        return $this;
    }

    function withoutStrictSSL(){
        return $this->disableSSL();
    }

    /*
        Set SSL certification
    */
    function setSSLCrt(string $crt_path){
        // dejo claro se aplican settings
        $this->cert_ssl = true;

        $this->setOption(CURLOPT_CAINFO, $crt_path);
        $this->setOption(CURLOPT_CAPATH, $crt_path);
        
        return $this;
    }

    // alias
    function certificate(string $cert_path){
        return $this->setSSLCrt($cert_path);
    }

    function consumeAPI(string $url, string $http_verb, $data = null, $headers = null, $options = null, $decode = true, $encode_body = true)
    {
        if (!extension_loaded('curl'))
		{
            throw new \Exception("Curl extension is not enabled");
        }

        if ($headers === null){
            $headers = [];
        } else {
            if (!Arrays::isAssoc($headers)){
                $_hs = [];
                foreach ($headers as $h){
                    list ($k, $v)= explode(':', $h, 2);
                    $_hs[$k] = $v;
                }

                $headers = $_hs;
            }
        }

        if ($options === null){
            $options = [];
        }

        $keys = array_keys($headers);
        
        foreach ($keys as $key){
            if (strtolower($key) == 'content-type'){
                $this->content_type = $key;
                break;
            }
        }

        if ($encode_body && is_array($data)){
            $data = json_encode($data);
        } 

        $http_verb  = strtoupper($http_verb);

        if ($this->curl == null){
            $this->curl = curl_init($url ?? $this->url);
        }

        if ($http_verb != 'GET' && !empty($data)){
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, $data);

            if ($encode_body){
                $headers['Content-Length']   = strlen($data);
            }
        }

        $h = [];
        foreach ($headers as $key => $header){
            $h[] = "$key: $header";
        }

        $options = [
            CURLOPT_HTTPHEADER => $h
        ] + ($options ?? []);

        curl_setopt_array($this->curl, $options);
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_ENCODING, '' );
        curl_setopt($this->curl, CURLOPT_TIMEOUT, 0); // Sin límite de tiempo para la solicitud
        curl_setopt($this->curl, CURLOPT_CONNECTTIMEOUT, 0); // Sin límite de tiempo para la conexión

        curl_setopt($this->curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $http_verb);

        // https://stackoverflow.com/a/6364044/980631
        curl_setopt($this->curl, CURLOPT_FAILONERROR, false);
        curl_setopt($this->curl, CURLOPT_HTTP200ALIASES, [
            400,
            500
        ]);  //

        curl_setopt($this->curl, CURLINFO_HEADER_OUT, true); // 23-abr-2024

         // Agregar manejo de cookies
        if ($this->cookieJar !== null){
            curl_setopt($this->curl, CURLOPT_COOKIEJAR,  $this->cookieJar->getCookieFile());
            curl_setopt($this->curl, CURLOPT_COOKIEFILE, $this->cookieJar->getCookieFile());    
        }
 
        $__headers  = [];
        $__filename = null;

        $header_fn = function ($curl_handle, $header) use (&$__headers, &$__filename) {
            $pieces = explode(":", $header, 2);

            if (count($pieces) == 2)
                $__headers[trim($pieces[0])] = trim($pieces[1]);


            if (isset($__headers['Content-Disposition'])){
                if (preg_match('/filename="([a-z-_.]+)";/i', $__headers['Content-Disposition'], $matches)){
                    $__filename= $matches[1];
                }
            }

            return strlen($header); // <-- this is the important line!
        };

        curl_setopt($this->curl, CURLOPT_HEADERFUNCTION,
            $header_fn
        );

        $response      = curl_exec($this->curl);
        $err_msg       = curl_error($this->curl);
        $http_code     = (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        $content_type  = curl_getinfo($this->curl,CURLINFO_CONTENT_TYPE);
        $effective_url = curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);

        // Guardar las cookies después de cada solicitud
        if ($this->cookieJar !== null){
            // Obtener información sobre las cookies antes de cerrar la sesión cURL
            $cookie_info   = curl_getinfo($this->curl, CURLINFO_COOKIELIST);

            $this->cookieJar->saveCookies($cookie_info);
        }
            
        // Preservo la respuesta
        $this->raw_response = $response;

        $data = ($decode && $response !== false) ? json_decode($response, true) : $response;

        $ret = [
            'data'          => $data,
            'http_code'     => $http_code,
            'error'         => $err_msg
        ];

        $this->res_headers   = $__headers;
        $this->filename      = $__filename;
        $this->content_type  = $content_type;
        $this->effective_url = $effective_url;

        return $ret;
    }

    function getRequestHeaders(){
        return $this->req_headers;
    }

    function getHeaders(){
        return $this->res_headers;
    }

    function getContentType(){
        return $this->content_type;
    }

    function getEffectiveUrl(){
        return $this->effective_url;
    }

    function request(string $url, string $http_verb, $body = null, $headers = null, $options = null){
        static $access;
        
        $domain = Url::getHostname($url);

        if (isset(Config::get()['sleep_time'])){
            if ($access === null){
                $access = [
                    $domain => time()
                ];
            }
        }

        if ($this->mocked){
            return $this;
        }

        $url = $url ?? $this->url;

        if (!empty($this->query_params)){
            foreach($this->query_params as $param_name => $param_value){
                $url = Url::addQueryParam($url, $param_name, $param_value);
            }
        }

        if ($this->debug){
            dd($url, 'URL');
        }

        $this->setUrl($url);
        $this->verb = strtoupper($http_verb);

        $this->req_body = $body;
        
        //
        // Sino se aplico nada sobre SSL, vale lo que diga el config
        // 
        if (!$this->cert_ssl){    
            $cert = Config::get()['ssl_cert'];
            
            if ($cert === false){
                $this->disableSSL();
            }

            if (!empty($cert)){
                $this->setSSLCrt($cert);
            }    
        }

        if (!empty($this->options) && !empty($options)){
            $options = array_merge($this->options, $options);
        } else {
            $options = $options ?? $this->options ?? null;
        }

        $body    = $body    ?? $this->body    ?? null;
        $headers = $headers ?? $this->req_headers ?? null;    
        
        // Para dump()
        $this->body = $body;
    
        if ($this->expiration == null){
            $expired = true;
        } else {
            $cached_path     = $this->getCachePath();
            $expired         = is_file($cached_path) ? FileCache::expired(filemtime($cached_path), $this->expiration) : true;  // fixex on jun-17/24
        }
       
        // Logs

        if ($this->log_req){
            Logger::{$this->logger_fn}(static::dump(), $this->log_req);
        }

        if ($this->show_req){
            dd(
                $this->dump()
            );
        }

        if (!$expired){
            $res = $this->getCache();

            if ($res !== null){
                if (is_string($res)){
                    //dd('DECODING...');
                    $data = json_decode($res['data'], true); 
                    
                    if ($data !== null){
                        //throw new \Exception("Unable to decode response '$res'");
                        $res['data'] = $data;
                    } else {
                        //dd('DECODED!');
                    }
                }
                
                // Solo sino hay errores (hago un return y con eso) evito continuar obteniendo una respuesta fresca
                if (empty($res['error']))
                {    
                    $this->status   = $res['http_code'];
                    $this->error    = $res['error'];
                    $this->response = $res['data'];

                    return $this;
                }
            }
        }

        if (isset(Config::get()['sleep_time'])){
            /*
                Solo si se ha solicitado antes (en principio en el mismo request),
                hago la pausa

                En vez de usar $access como variable estatica deberia ser 
                con uso de transcientes

                Tambien deberia guardarse y tomarse en cuenta cuando fue la ultima
                solicitud http a ese dominio
            */
            if ($access[$domain] + 1000000 < microtime()){
                sleep(Config::get()['sleep_time']);
            } 
        }

        $ok = null;
        $retries = 0;

        /*
            Con cada intento podría ir incrementando el tiempo máximo para conectar y para obtener resultados
            Esos valores ¨optimos¨ podrían persistirse en un transiente para la url 
        */
        while (!$ok && $retries < $this->max_retries)
        {   
            $res = $this->consumeAPI($url, $http_verb, $body, $headers, $options, false, $this->encode_body);

            $this->status   = $res['http_code'];
            $this->error    = $res['error'];
            $this->response = $res['data'];

            $this->filename     = $this->getFilename();
            $this->res_headers  = $this->getHeaders();

            /*
                Si hay errores && el status code es 0 
                =>
                Significa que fall'o la conexion!

                --| STATUS
                0

                --| ERRORS
                Failed to connect to 200.6.78.1 port 80: Connection refused

                --| RES
                NULL
            */

            $ok = empty($this->error);
            $retries++;

            $access[ $domain ] = time();
            //d($ok ? 'ok' : 'fail', 'INTENTOS: '. $retries);
        }

        // dd($res, 'RES');

        // Logs

        if ($this->log_res){
            Logger::{$this->logger_fn}($res, $this->log_res);
        }

        $status_code = (int) $this->status;

        if ($this->expiration && $res !== null && !$this->read_only){
            if (!empty($this->ignore_status_codes)){
                foreach ($this->ignore_status_codes as $code){
                    if ($status_code == $code){
                        $this->saveResponse($res);
                        break;
                    }
                }
            } else if ($status_code >=200 && $status_code < 400){
                $this->saveResponse($res);
            }            
        }       

        return $this;
    }

    function get($url = null, $headers = null, $options = null){    
        $url = $this->url ?? $url;

        if ($url === null){
            throw new \InvalidArgumentException("Param url is needed. Set in " . __METHOD__ . " or constructor or setUrl()");
        }
        
        return $this->request($url, 'GET', null, $headers, $options);
    }

    function delete($url = null, $headers = null, $options = null){
        $url = $this->url ?? $url;

        if ($url === null){
            throw new \InvalidArgumentException("Param url is needed. Set in " . __METHOD__ . " or constructor or setUrl()");
        }

        return $this->request($url, 'DELETE', null, $headers, $options);
    }

    function post($url = null, $body = null, $headers = null, $options = null){
        $url  = $this->url ?? $url;
        $body = $body      ?? $this->body ?? null;

        if ($url === null){
            throw new \InvalidArgumentException("Param url is needed. Set in " . __METHOD__ . "() or constructor or setUrl()");
        }

        return $this->request($url, 'POST', $body, $headers, $options);
    }

    function put($url = null, $body = null, $headers = null, $options = null){
        $url = $this->url ?? $url;

        if ($url === null){
            throw new \InvalidArgumentException("Param url is needed. Set in " . __METHOD__ . " or constructor or setUrl()");
        }

        return $this->request($url, 'PUT', $body, $headers, $options);
    }

    function patch($url = null, $body = null, $headers = null, $options = null){
        $url = $this->url ?? $url;

        if ($url === null){
            throw new \InvalidArgumentException("Param url is needed. Set in " . __METHOD__ . " or constructor or setUrl()");
        }

        return $this->request($url, 'PATCH', $body, $headers, $options);
    }

    function setMethod(string $verb){
        $verb = strtoupper($verb);
        
        if (!in_array($verb, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])){
            throw new \InvalidArgumentException("Unsupported verb \"$verb\"");
        }

        $this->verb = $verb;
        return $this;
    }

    function send($url = null, $body = null, $headers = null, $options = null){
        return $this->request($url ?? $this->url, $this->verb, $body, $headers, $options);
    }

    function getBody(){
        return $this->data();
    }
    
    // Para descargar archivos binarios
    function setBinary(){
        $this->option(CURLOPT_RETURNTRANSFER, true);
        return $this;
    }

    /*
        Ej:

        $url = 'https://www.learningcontainer.com/wp-content/uploads/2020/05/sample.tar';
        $cli = new ApiClient($url); // setea $url dentro de la clase

        $cli
        ->setBinary()
        ->withoutStrictSSL();

        $bytes = $cli->download();

        dd($bytes, 'BYTES escritos');
    */
    function download($filepath, $url = null, $body = null, $headers = null, $options = null)
    {   
        $fp = fopen($filepath, 'w+');

        if ($this->curl == null){
            $this->curl = curl_init($url ?? $this->url);
        }
   
        $this->setOption(CURLOPT_RETURNTRANSFER, false);
        $this->setOption(CURLOPT_FILE, $fp);

        $url = $url ?? $this->url;

        if (!empty($this->query_params)){
            foreach($this->query_params as $param_name => $param_value){
                $url = Url::addQueryParam($url, $param_name, $param_value);
            }
        }

        $this->url = $url;

        //
        // Sino se aplico nada sobre SSL, vale lo que diga el config
        // 
        if (!$this->cert_ssl){    
            $cert = Config::get()['ssl_cert'];
            
            if ($cert === false){
                $this->disableSSL();
            }

            if (!empty($cert)){
                $this->setSSLCrt($cert);
            }    
        }

        if (!empty($this->options) && !empty($options)){
            $options = array_merge($this->options, $options);
        } else {
            $options = $options ?? $this->options ?? null;
        }

        $body    = $body    ?? $this->body    ?? null;
        $headers = $headers ?? $this->req_headers ?? null;        
    
        if (!empty($this->options)){
            foreach ($this->options as $_op => $value){
                curl_setopt($this->curl, $_op, $value);
            }    
        }
        
        curl_exec($this->curl);

        // if (!empty($this->header_callback)){
        //     curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, $this->header_callback);
        // }

        $response      = curl_exec($this->curl);
        $err_msg       = curl_error($this->curl);
        $http_code     = (int) curl_getinfo($this->curl, CURLINFO_HTTP_CODE);

        $content_type  = curl_getinfo($this->curl,CURLINFO_CONTENT_TYPE);
        $effective_url = curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL);
   
        // curl_close($this->curl);
        fclose($fp);

        $this->response      = $response;
        $this->status        = $http_code;
        $this->error         = $err_msg;
        $this->content_type  = $content_type;
        $this->effective_url = $effective_url;

        return filesize($filepath);
    }   

    /*
        Authentication
    */

    // BASIC
    function setBasicAuth($username = null, $password = null){
        $username = $username ?? $this->username;
        $password = $password ?? $this->password;
        $this->addHeader('Authorization', 'Basic '. base64_encode("$username:$password"));

        return $this;
    }

    // JWT
    function setJWTAuth($token_jwt){
        $this->setHeaders([
            "Authorization: Bearer $token_jwt"
        ]);

        return $this;
    }

    function getFilename(){
        return $this->filename;
    }

    /*
        CACHE

        En vez de guardar en disco..... usar Transientes con drivers como Memcached o REDIS !

        Debe generar un HASH con todos los parametros y sino son iguales... se considera otra cache
    */

    function getCachePath(){
        if (empty($this->url)){
            throw new \Exception("Undefined URL");
        }

        $input = str_replace(['https://', 'http://'], '', $this->url);

        if ($this->cache_post_request && $this->verb == 'POST'){
            if (is_array($this->req_body)){
                $this->req_body = md5(json_encode($this->req_body));
            }

            $input .= "+body={$this->req_body}";
        }

        $full_path = FileCache::getCachePath($input);

        return $full_path;
    }

	protected function saveResponse(Array $response)
    {
        if ($this->cache_post_request === false && $this->verb != 'GET'){
            return;
        }

        $path = $this->getCachePath();

        if ($path === null){
            return;
        }

        file_put_contents($path, '<?php return ' . var_export($response, true) . ';');
    }

    protected function getCache(){
        $path = $this->getCachePath();

        if ($path === null){
            return;
        }

        if (file_exists($path)){
            if ($this->debug){
                dd($path, 'CACHE PATH');
            }

            return include $path;
        }
    }

    /*

        From CI ---------------------------------------->

    */

    public function simpleFtpGet($url, $file_path, $username = '', $password = '')
	{
		// If there is no ftp:// or any protocol entered, add ftp://
		if ( ! preg_match('!^(ftp|sftp)://! i', $url))
		{
			$url = 'ftp://' . $url;
		}

		// Use an FTP login
		if ($username != '')
		{
			$auth_string = $username;

			if ($password != '')
			{
				$auth_string .= ':' . $password;
			}

			// Add the user auth string after the protocol
			$url = str_replace('://', '://' . $auth_string . '@', $url);
		}

		// Add the filepath
		$url .= $file_path;

		//$this->option(CURLOPT_BINARYTRANSFER, TRUE);

		return $this->get();
	}

	public function httpHeader($header, $content = NULL)
	{
		$this->req_headers[] = $content ? $header . ': ' . $content : $header;
		return $this;
	}

	public function httpMethod($method)
	{
		$this->options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
		return $this;
	}

	public function httpLogin($username = '', $password = '', $type = 'any')
	{
		$this->option(CURLOPT_HTTPAUTH, constant('CURLAUTH_' . strtoupper($type)));
		$this->option(CURLOPT_USERPWD, $username . ':' . $password);
		return $this;
	}

	public function proxy($url = '', $port = 80)
	{
		$this->option(CURLOPT_HTTPPROXYTUNNEL, TRUE);
		$this->option(CURLOPT_PROXY, $url . ':' . $port);
		return $this;
	}

	public function proxyLogin($username = '', $password = '')
	{
		$this->option(CURLOPT_PROXYUSERPWD, $username . ':' . $password);
		return $this;
	}

	public function isEnabled()
	{
		return function_exists('curl_init');
	}
}



