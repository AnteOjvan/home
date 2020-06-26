<?php
/**
 * Wrapper for abstracting Myra API operations
 *
 * @author Hakan Kueucekyilmaz, <hakan dot kyilmaz at myrasecurity dot com>, 2014-05-07
 */

if (isset($_SERVER['DEBUG'])) {
    define('DEBUG', true);
} else {
    define('DEBUG', false);
}

class MyraApi
{
    /**
     * Language settings
     */
    protected $lang = '';

    /**
     * The Myra API server
     */
    protected $site = '';

    /**
     * Your API key
     */
    protected $apiKey = '';

    /**
     * Your API secret
     */
    protected $secret = '';

    /**
     * Supported targets
     */
    protected $supportedTargets = array(
        'cacheClear',
        'cacheSettings',
        'certificates',
        'dnsRecords',
        'domains',
        'errorpages',
        'ipfilter',
        'maintenance',
        'networks',
        'permissions',
        'redirects',
        'statistic',
        'statistic',
        'statistic/query',
        'subdomainSetting',
        'tag',
        'waf',
        'waf/rule',
        'waf/rules',
        'waf/rules/domain',

    );

    public function __construct($lang, $site, $apiKey, $secret)
    {
        if (!function_exists('curl_init')) {
            throw new Exception('[ERROR]: Please install php-curl');
        }

        if (!function_exists('json_encode')) {
            throw new Exception('[ERROR]: Please install php-json');
        }

        $this->lang   = $lang;
        $this->site   = $site;
        $this->apiKey = $apiKey;
        $this->secret = $secret;
    }

    /**
     * Call a Myra API routine
     *
     * @param String $target  The API call target [redirects | cacheSettings]
     * @param String $method  [create | update | list | delete]
     * @param String $domain  The Domain we are working on. For general domains settings please use the 'ALL:' notation
     * @param Array  $body    The parameters of the API call we want to make
     *
     * @return Object A PHP object decoded from JSON or Exception on errors
     */
    public function call($target, $method, $domain, $body, $options = array(), $skipErrors = 0)
    {
        if (!in_array($target, $this->supportedTargets)) {
            throw new Exception('[ERROR]: unsupported $target given: ' . $target);
        }

        switch ($method) {
            case 'create':
                $httpMethod = 'PUT';
                break;

            case 'update':
            case 'query':
                $httpMethod = 'POST';
                break;

            case 'list':
                $httpMethod = 'GET';
                $body   = null;
                break;

            case 'delete':
                $httpMethod = 'DELETE';
                break;

            default:
                throw new Exception("[ERROR]: unknown $method used!");
                break;
        }

        $date = date('c');
        $uri  = '/' . $this->lang . '/rapi/' . $target . '/' . $domain;
        $uri  = rtrim($uri, '/');

        if ($body != null) {
            $body = json_encode($body);
        }

        $content_type   = 'application/json';
        $signing_string = md5($body) . '#' . $httpMethod . '#' . $uri . '#' . $content_type . '#' . $date;
        $date_key       = hash_hmac('sha256', $date, 'MYRA' . $this->secret);
        $signing_key    = hash_hmac('sha256', 'myra-api-request', $date_key);
        $signature      = base64_encode(hash_hmac('sha512', $signing_string, $signing_key, true));

        $genOptions   = array();
        $genOptions[] = 'Content-Type: application/json';
        $genOptions[] = 'Content-Length: ' . strlen($body);
        $genOptions[] = 'Host: ' . $this->site;
        $genOptions[] = 'Date: ' . $date;
        $genOptions[] = 'Authorization: MYRA ' . $this->apiKey . ':' . $signature;

        $options = array_merge($genOptions, $options);

        $ret = $this->curlExec('https://' . $this->site . $uri, $httpMethod, $body, $options);

        if ($ret['statusCode'] == 200) {
            $ret = json_decode($ret['content']);

            $message = '';

            // MYRA-2828: API: subdomainSetting and statistic is missing "error: false" on success
            if (isset($ret->error) && $ret->error == true) {
                foreach ($ret->violationList as $data) {
                    $message  = sprintf("Message: %s\n",   $data->message);
                    $message .= sprintf("Path:    %s\n\n", $data->propertyPath);
                }

                $message = "\n" . '[ERROR] ' . $target . ' ' . $method . "\n" . $message;

                if ($skipErrors) {
                    echo $message;
                } else {
                    throw new Exception($message);
                }
            }
        } else {
            throw new Exception('[ERROR]: API call returned HTTP status: ' . $ret['statusCode']);
        }

        return $ret;
    }

    /**
     * cURL wrapper for executing an API call
     *
     * @param String $url     The URL to be called
     * @param String $method  HTTP method [GET | DELETE | POST | PUT]. Default is GET
     * @param String $body    The reuqest body in JSON format
     * @param Array  $options HTTP header options
     *
     * @return String Response from API server
     */
    protected function curlExec($url, $method, $body, array $options = array())
    {
        $ch = curl_init();

        curl_setopt_array(
            $ch,
            array(
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADER         => false,
                CURLOPT_HTTPHEADER     => $options,
                CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_SSL_VERIFYPEER => 0,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_URL            => $url,
                CURLOPT_VERBOSE        => false,
            )
        );

        if ($method == 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $content = curl_exec($ch);

        if ($content === false) {
            throw new Exception('[ERROR]: executing cURL. ' . curl_error($ch));
        }

        $info = curl_getinfo($ch);

        curl_close($ch);

        return array(
            'content'    => $content,
            'info'       => $info,
            'statusCode' => $info['http_code'],
        );
    }

    /**
     * Log a message
     *
     * @var $message string
     * @var $status string
     * @var $ret JSON object
     */
    static function log($message, $status = '', $ret = '')
    {
        if ($status != '') {
            $message = '[' . strtoupper($status) .']: ' . $message;
        }

        if ($status == 'success' || $status == '') {
            $length = strlen($message) + 2;

            echo str_repeat('=', $length), "\n";
            echo ' ', $message, "\n";
            echo str_repeat('=', $length), "\n";
        } else if ($ret != '') {
            echo $message, "\n";

            foreach ($ret->violationList as $data) {
                echo 'Message: ', $data->message,      "\n";
                echo 'Path:    ', $data->propertyPath, "\n";
            }
        } else {
            echo $message, "\n";
        }
    }

     /**
     * Get all redirects
     *
     * @param String $domain Currently only a subdomain can be used.
     * @return Array of RedirectVO
     */
    public function getRedirects($subdomain)
    {
        $uri = $subdomain . '/1';

        $ret = $this->call('redirects', 'list', $uri, array());

        if (!$ret->error) {
            $pages = ceil($ret->count / $ret->pageSize);
        } else {
            throw new Exception('[ERROR]: getting page count for  ' . $subdomain . ' ' . $ret);
        }

        for ($i = 1; $i <= $pages; $i++) {
            $uri = $subdomain . '/' . $i;

            $ret = $this->call('redirects', 'list', $uri, array());

            if (!$ret->error) {
                foreach ($ret->list as $data) {
                    if ($data->type == 'permanent') {
                        $data->type = '301';
                    } else {
                        $data->type = '302';
                    }

                    $arr[] = array(
                        'created'       => $data->created,
                        'destination'   => $data->destination,
                        'enabled'       => $data->enabled,
                        'id'            => $data->id,
                        'matchingType'  => $data->matchingType,
                        'modified'      => $data->modified,
                        'objectype'     => $data->objectType,
                        'sort'          => $data->sort,
                        'source'        => $data->source,
                        'subDomainName' => $data->subDomainName,
                        'type'          => $data->type,
                    );
                }
            } else {
                throw new Exception('[ERROR]: listing redirects for ' . $subdomain . ' ' . $ret);
            }
        }

        if (!empty($arr)) {
            return $arr;
        } else {
            throw new Exception('[ERROR]: no redirects for ' . $subdomain . ' found. ');
        }
    }

}
