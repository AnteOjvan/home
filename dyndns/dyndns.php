<?php
/**
 * API-script to change DNS A and AAAA record since my IP changes every day 
 *
 * Use this script with argv[1]=A-record argv[2]=AAAA-record
 */
require_once '/home/pi/work/dyndns/MyraApi.php';

$arec = $argv[1];
$aaaarec = $argv[2];

$prefix = '/tmp';

// Language we wan to use. Mainly for translated error messages.
$lang = 'de';

// URL of the myracloud API server
$site = 'app.myracloud.com';

//domain and subdomain
$domain = 'itsantetime.de';
$subdomain = 'media.' . $domain;

/*
 * Your API key and secret salt. You will get your credentials for accessing
 * Myra's API from your support team.
 * Save API key and API secret in $prefix directory with .key and .secret
 */
if ($argc < 2) {
    echo '[ERROR]: please provide ipv4 and ipv6' . "\n";
    exit(1);
}




$keysecname = "myra";

$apikey = trim(file_get_contents($prefix . '/' . $keysecname . '.key'));

if ($apikey == false) {
    MyraApi::log('reading apikey', 'error');

    exit(1);
}

$secret = trim(file_get_contents($prefix . '/' . $keysecname . '.secret'));

if ($secret == false) {
    MyraApi::log('reading apisecret', 'error');

    exit(1);
}

$myraApi = new MyraApi($lang, $site, $apikey, $secret);

$uri = $domain . '/1';

$ret = $myraApi->call('dnsRecords', 'list', $uri, array());

if (!$ret->error) {
    $pages = ceil($ret->count / $ret->pageSize);
} else {
    MyraApi::log('getting page count for ' . $subdomain, 'error', $ret);
    exit (1);
}

for ($i = 1; $i <= $pages; $i++) {
    $uri = $domain . '/' . $i;
    $ret = $myraApi->call('dnsRecords', 'list', $uri, array());


    if (!$ret->error) {
        foreach ($ret->list as $data) {
            if (($data->recordType == 'A' || $data->recordType == 'AAAA') && $data->name == $subdomain) {
                $rec[$data->recordType] = array(
                    'id'       => $data->id,
                    'modified' => $data->modified,
                );
            }
        }
    } else {
        MyraApi::log('listing dnsRecords for ' . $domain, 'error', $ret);
        exit (1);
    }

}

$body = array(
   'id'         => $rec['A']['id'],
   'value'      => $arec,
   'name'       => 'media.itsantetime.de',
   'active'     => true,
   'enabled'    => true,
   'recordType' => 'A',
   'ttl'        => '300',
   'modified'   => $rec['A']['modified'],
);

$ret = $myraApi->call('dnsRecords','update', $domain, $body);

$body = array(
   'id'         => $rec['AAAA']['id'],
   'enabled'    => true,
   'name'       => 'media.itsantetime.de',
   'value'      => $aaaarec,
   'ttl'        => '300',
   'recordType' => 'AAAA',
   'active'     => true,
   'modified'   => $rec['AAAA']['modified'],
);

$ret = $myraApi->call('dnsRecords','update', $domain, $body);

if ($ret->error) {
    MyraApi::log('DNS change '  , 'error', $ret);
    exit (1);
}

echo "\n";
