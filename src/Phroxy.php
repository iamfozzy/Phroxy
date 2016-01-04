<?php

namespace Fozzy;

/**
 * Class Phroxy
 */
class Phroxy
{
    /**
     * @var null
     */
    protected static $instance = null;

    /**
     * @var array
     * @link RFC2616
     */
    protected $propagateHeaders = array(
        'content-type',
        'content-language',
        'set-cookie',
        'cache-control',
        'content-length',
        'x-frame-options',
        'date',
        'expires',
        'pragma',
        'server'
    );

    /**
     * @var int
     */
    protected $timeout = 15;

    /**
     * @var int
     */
    protected $connectTimeout = 3;

    /**
     * @var string      - Url will split on this
     */
    protected $urlKey = '__cors';

    /**
     * @var string
     */
    protected $userAgent = 'Phroxy';

    /**
     * @var string
     */
    protected $requestMethod = 'GET';

    /**
     * @var
     */
    protected $protocol;

    /**
     * @var array
     */
    protected $headers = array();

    /**
     * @var string
     */
    protected $payload;

    /**
     * @param string $urlKey - Url key to split on
     * @param string $to     - Url to proxy too
     * @param string $urlKey - The key in the url to split requests up with
     */
    private function __construct($to, $urlKey, $schemeOverride = null)
    {
        $this->scheme = null === $schemeOverride ? (isset($_SERVER['HTTPS']) ? 'https' : 'http') : $schemeOverride;
        $this->url    = $this->scheme . '://' . $to . '/' . $this->splitUrl($urlKey);

        // Now some config...
        $this->userAgent     = $_SERVER['HTTP_USER_AGENT'];
        $this->requestMethod = $_SERVER['REQUEST_METHOD'];
        $this->protocol      = $_SERVER['SERVER_PROTOCOL'];
        $this->headers       = $this->fetchAllHeaders();

        // Unset some headers which shouldn't get forwarded
        if (isset($request['headers']['If-None-Match'])) {
            unset($request['headers']['If-None-Match']);
        }

        if (isset($request['headers']['If-Modified-Since'])) {
            unset($request['headers']['If-Modified-Since']);
        }
    }

    /**
     * This is a singleton
     *
     * @param string $to        - Url to proxy too
     * @param string $urlKey    - The key in the url to split requests up with
     * @return CorsProxy
     */
    public static function proxyRequest($to, $urlKey = '__cors')
    {
        self::$instance = new self($to, $urlKey);

        // Make request to online
        $response = self::$instance->makeRequest();

        // send the response back to the client
        self::$instance->sendResponse($response);
    }

    /**
     * Splits the url on $urlKey, trims the slashes and returns the second part of the url.
     *
     * Example:
     *  Splitting on __cors
     *  http://example.com/__cors/hello.php?hello=world
     *
     * Becomes:
     *  hello.php?hello=world
     *
     * @param  string $urlKey
     * @return string mixed
     */
    public function splitUrl($urlKey)
    {
        // Fetch the current url
        $url = "{$this->scheme}://{$_SERVER['HTTP_HOST']}/{$_SERVER['REQUEST_URI']}";

        if (strpos($url, $urlKey) === false) {
            $this->sendFailedResponse();
        }

        // Split
        list($trash, $url) = explode($urlKey, $url, 2);

        // remove any slashes and return the split url
        return trim($url, '\\/');
    }

    /**
     *
     */
    public function makeRequest()
    {
        $curl = curl_init($this->url);

        // POST Requests
        if ('post' === strtolower($this->requestMethod)) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $_POST);
        }

        // Set some curl options
        curl_setopt($curl, CURLOPT_PORT, $this->scheme === 'https' ? 443 : 80);
        curl_setopt($curl, CURLOPT_URL, $this->url);
        curl_setopt($curl, CURLOPT_COOKIE, $this->getCookieString());
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_FAILONERROR, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);

        // SSL
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($curl, CURLOPT_SSLVERSION, 2);

        // timeout
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);

        // Exec request - split md into header/content
        $response = curl_exec($curl);
        $status   = curl_getinfo($curl);

        // No response or bad status code
        if (false === $response || null === $response) {
            $this->sendFailedResponse($status['http_code'], $this->getHttpStatusText($status['http_code']));
        }

        $header = $body = '';
        $responseSplit = preg_split('/([\r\n][\r\n])\\1/', curl_exec($curl), 2); // allow 5 redirect headers

        // Now sort the headers > content - there can be multple sets of headers - so split well
        foreach ($responseSplit as $k => $row) {
            if (0 === strpos($row, 'HTTP/1.')) {

                // Overrite the last header
                $header = $row;

                // this is a header so remove
                unset($responseSplit[$k]);

            } else {

                // No longer a header - the rest is content body - implode all
                $body = implode("\r\n\r\n", $responseSplit);

                break;
            }
        }

        // and close
        curl_close($curl);

        return array(
            'status' => $status,
            'header' => $header,
            'body'   => $body
        );
    }

    /**
     * @param array $response - The response returned from makeRequest
     */
    public function sendResponse(array $response)
    {
        $headers         = preg_split('/[\r\n]+/', $response['header']);
        $implodedHeaders = implode('|', $this->propagateHeaders);

        foreach ($headers as $header) {
            if (preg_match('/^(?:' . $implodedHeaders . '):/i', $header)) {
                header($header);
            }
        }

        echo $response['body'];
    }

    /**
     * @param int    $code
     * @param string $message
     */
    public function sendFailedResponse($code = 400, $message = 'Bad Request')
    {
        header('HTTP/1.1 ' . $code . ' ' . $message, true, $code);
        exit ('HTTP/1.1 ' . $code . ' ' . $message);
    }

    /**
     * @return string
     */
    public function getCookieString()
    {
        // Send Cookie
        $cookie = array();
        foreach ($_COOKIE as $key => $value) {
            $cookie[] = $key . '=' . urlencode($value);
        }

        // Append SessionID
        if (defined('SID')) {
            $cookie[] = SID;
        }

        // return combined cookie
        return implode('; ', $cookie);
    }


    /**
     * @return array
     */
    protected function fetchAllHeaders()
    {
        if (function_exists('getallheaders')) {
            return getallheaders();
        }

        $headers = array();

        // Backup plan
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$name] = $value;
            } elseif ($name == "CONTENT_TYPE") {
                $headers["Content-Type"] = $value;
            } elseif ($name == "CONTENT_LENGTH") {
                $headers["Content-Length"] = $value;
            }
        }

        error_log(json_encode($headers));

        return $headers;
    }


    /**
     * Returns text for a status code
     *
     * @param  int $code
     * @return string
     */
    protected function getHttpStatusText($code)
    {
        switch ($code) {
            case 100:
                $text = 'Continue';
                break;
            case 101:
                $text = 'Switching Protocols';
                break;
            case 200:
                $text = 'OK';
                break;
            case 201:
                $text = 'Created';
                break;
            case 202:
                $text = 'Accepted';
                break;
            case 203:
                $text = 'Non-Authoritative Information';
                break;
            case 204:
                $text = 'No Content';
                break;
            case 205:
                $text = 'Reset Content';
                break;
            case 206:
                $text = 'Partial Content';
                break;
            case 300:
                $text = 'Multiple Choices';
                break;
            case 301:
                $text = 'Moved Permanently';
                break;
            case 302:
                $text = 'Moved Temporarily';
                break;
            case 303:
                $text = 'See Other';
                break;
            case 304:
                $text = 'Not Modified';
                break;
            case 305:
                $text = 'Use Proxy';
                break;
            case 400:
                $text = 'Bad Request';
                break;
            case 401:
                $text = 'Unauthorized';
                break;
            case 402:
                $text = 'Payment Required';
                break;
            case 403:
                $text = 'Forbidden';
                break;
            case 404:
                $text = 'Not Found';
                break;
            case 405:
                $text = 'Method Not Allowed';
                break;
            case 406:
                $text = 'Not Acceptable';
                break;
            case 407:
                $text = 'Proxy Authentication Required';
                break;
            case 408:
                $text = 'Request Time-out';
                break;
            case 409:
                $text = 'Conflict';
                break;
            case 410:
                $text = 'Gone';
                break;
            case 411:
                $text = 'Length Required';
                break;
            case 412:
                $text = 'Precondition Failed';
                break;
            case 413:
                $text = 'Request Entity Too Large';
                break;
            case 414:
                $text = 'Request-URI Too Large';
                break;
            case 415:
                $text = 'Unsupported Media Type';
                break;
            case 500:
                $text = 'Internal Server Error';
                break;
            case 501:
                $text = 'Not Implemented';
                break;
            case 502:
                $text = 'Bad Gateway';
                break;
            case 503:
                $text = 'Service Unavailable';
                break;
            case 504:
                $text = 'Gateway Time-out';
                break;
            case 505:
                $text = 'HTTP Version not supported';
                break;
            default:
                $text = 'Unknown http status code "' . htmlentities($code) . '"';
                break;
        }

        return $text;
    }
}
