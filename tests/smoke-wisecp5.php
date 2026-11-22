<?php
/**
 * Smoke test for the WISECP 5 module against a real WISECP 5 core and a fake gateway.
 *
 * Not part of the package. With the module installed, copy this file to the temp/
 * directory of a WISECP 5 test installation and run:  php temp/smoke-wisecp5.php
 * Nothing is written to the database: the service has no id and the API client is
 * injected without a logger.
 */
require dirname(__DIR__) . '/bootstrap.php';

use DomainNameApi\Ssl\Client;

$ok = 0; $fail = 0;
function check($label, $cond, $extra = '') { global $ok, $fail; if ($cond) { $ok++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label $extra\n"; } }
function attempt($label, callable $fn) { try { return $fn(); } catch (\Throwable $e) { echo "  ..   $label threw " . get_class($e) . ': ' . $e->getMessage() . "\n"; return $e; } }

$state = ['init' => 0, 'renewed' => 0, 'current' => '', 'configured' => null, 'issued' => false, 'revoked' => 0, 'reissue' => null,
          'listed' => 0, 'wrongId' => []];
// Certificate ids are GUIDs; "current" is the one the service must be using.
$guid = fn ($n) => sprintf('c0ffee00-0000-4000-8000-%012d', $n);
$fake = function ($method, $url, $headers, $body) use (&$state, $guid) {
    $path = parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $b = $body ? json_decode($body, true) : [];
    $j = fn ($d, $s = 200) => [$s, json_encode($d)];

    // The gateway no longer finds a certificate by domain name: every call for an
    // existing certificate must carry the current certificate id and no domain.
    $byId = ['PUT /api/v1/ssls/configure' => 'b', 'PUT /api/v1/ssls/check-status' => 'b', 'POST /api/v1/ssls/reissue' => 'b',
             'GET /api/v1/ssls/certificate' => 'q', 'GET /api/v1/ssls/certificate-content' => 'q', 'DELETE /api/v1/ssls/revoke' => 'q'];
    if (isset($byId[$method . ' ' . $path])) {
        $sentId   = $byId[$method . ' ' . $path] === 'b' ? ($b['certificateId'] ?? null) : ($q['CertificateId'] ?? null);
        $sentName = $byId[$method . ' ' . $path] === 'b' ? isset($b['domainName']) : isset($q['DomainName']);
        if ($sentId !== $state['current'] || $sentName) {
            $state['wrongId'][] = $method . ' ' . $path;
            return [403, json_encode(['error' => ['code' => 'Ssl:10026', 'message' => 'Certificate not found']])];
        }
    }

    switch ($method . ' ' . $path) {
        case 'GET /api/v1/products/ssls':
            return $j(['totalCount' => 1, 'items' => [[
                'productCode' => 'SECTIGO-PSSL', 'name' => 'PositiveSSL', 'vendorName' => 'Sectigo', 'validationType' => 'DV',
                'prices' => [['priceGroup' => 'D', 'create' => [['period' => 1, 'price' => 7.0, 'currency' => 'USD'], ['period' => 2, 'price' => 13.0, 'currency' => 'USD']]]],
            ]]]);
        case 'POST /api/v1/ssls/initialize':
            $state['init']++;
            $state['current'] = $guid($state['init']);
            return $j(['success' => true, 'certificateId' => $state['current']]);
        case 'POST /api/v1/ssls/renew':
            if (!empty($state['renewTooEarly'])) return [403, json_encode(['error' => ['code' => 'Ssl:10057', 'message' => 'Renewal opens on 2027-10-01.']])];
            if (($b['certificateId'] ?? '') !== $state['current']) return [403, json_encode(['error' => ['code' => 'Ssl:10026', 'message' => 'Certificate not found']])];
            $state['renewed']++;
            $state['current'] = $guid(100 + $state['renewed']);
            return $j(['success' => true, 'certificateId' => $state['current'], 'status' => 'WaitingForInstall']);
        case 'GET /api/v1/ssls':
            $state['listed']++;
            return $j(['totalCount' => 2, 'items' => [
                ['certificateId' => $state['current'], 'domainName' => $q['DomainName'] ?? '', 'status' => 'Active'],
                ['certificateId' => $guid(999), 'domainName' => $q['DomainName'] ?? '', 'status' => 'Cancelled'],
            ]]);
        case 'PUT /api/v1/ssls/configure':
            $state['configured'] = $b;
            return $j(['success' => true]);
        case 'GET /api/v1/ssls/certificate':
            return $j(['domainName' => 'secure.example.com', 'status' => $state['issued'] ? 'Issued' : 'Pending', 'packageName' => 'PositiveSSL',
                'isConfigured' => true, 'isIssued' => $state['issued'],
                'configuration' => ['authenticationType' => 'HTTP', 'approverMail' => 'admin@secure.example.com',
                    'dcvDetails' => json_encode(['http' => ['fileName' => '/.well-known/pki-validation/AB12.txt', 'fileContent' => "hash\nsectigo.com"]])],
                'certificate' => ['issuedAt' => '2026-10-24T10:00:00', 'validUntil' => $state['issued'] ? '2027-05-12T10:00:00' : null]]);
        case 'GET /api/v1/ssls/certificate-content':
            return $j(['certificate' => '-----BEGIN CERTIFICATE-----L', 'intermediateCertificate1' => '-----BEGIN CERTIFICATE-----I']);
        case 'POST /api/v1/ssls/reissue':
            $state['reissue'] = $b;
            return $j(['success' => true]);
        case 'DELETE /api/v1/ssls/revoke':
            $state['revoked']++;
            return $j(['success' => true]);
    }
    return [500, json_encode(['error' => ['message' => "unexpected $method $path"]])];
};

echo "== load\n";
$m = Modules::getInstance('Product', 'DNASSL');
check('instance class', $m instanceof \WISECP\Modules\Product\DNASSL, is_object($m) ? get_class($m) : 'null');
check('is SslProductModule', $m instanceof \SslProductModule);
check('lang loaded', ($m->lang['api-reseller-id'] ?? '') !== '');
check('config group ssl', ($m->config['group'] ?? '') === 'ssl');

$client = new Client('reseller', 'key', true);
$client->setTransport($fake);
(function ($c) { $this->api = $c; })->call($m, $client);

$m->config['settings']['revoke_on_cancel'] = 1;
$m->product = ['module_data' => ['product_id' => 'SECTIGO-PSSL', 'product_type' => 'DV', 'max_sans' => 0, 'included_sans' => 0]];
$m->options = ['domain' => 'Secure.Example.com'];
$m->user = ['name' => 'Ada', 'surname' => 'Lovelace', 'email' => 'ada@example.com', 'company_name' => '', 'phone' => '905321234567',
            'address' => ['address' => 'Street 1', 'city' => 'Istanbul', 'counti' => 'IST', 'zipcode' => '34000', 'country_code' => 'tr']];
$m->requirement_params = ['csr' => "-----BEGIN CERTIFICATE REQUEST-----\nX\n-----END CERTIFICATE REQUEST-----", 'dcv_method' => 'http', 'verification_email' => 'webmaster'];

echo "== create\n";
check('create', attempt('create', fn () => $m->create()) === true);
$c = $state['configured'];
check('initialize once', $state['init'] === 1 && $m->certificateId() === $guid(1));
check('domain normalised and stored', ($m->options['config']['domain'] ?? '') === 'secure.example.com');
check('configure payload', $c && $c['authenticationType'] === 'HTTP' && $c['approverMail'] === 'webmaster@secure.example.com' && $c['webServerType'] === 'Apache', json_encode($c));
check('configure addresses the certificate by id', $c['certificateId'] === $guid(1) && !isset($c['domainName']), json_encode($c));
check('contact', $c['contact']['fax'] === '5321234567' && $c['contact']['country'] === 'TR' && $c['contact']['phoneCountryCode'] === '90' && $c['contact']['phone'] === '5321234567');
check('create idempotent', $m->create() === true && $state['init'] === 1);

echo "== status\n";
$s = attempt('fetchRemoteStatus', fn () => $m->fetchRemoteStatus());
check('pending + http dcv', is_array($s) && $s['status'] === 'pending' && $s['validation_method'] === 'http' && $s['dcv_file']['filename'] === '/.well-known/pki-validation/AB12.txt', json_encode($s));
$state['issued'] = true;
$s = $m->fetchRemoteStatus();
check('active + pem', $s['status'] === 'active' && str_contains($s['crt_code'], 'BEGIN') && str_contains($s['ca_code'], 'BEGIN') && $s['expires_at'] === '2027-05-12T10:00:00');

echo "== actions\n";
$r = (function () { return $this->apiReissue("-----BEGIN CERTIFICATE REQUEST-----\nNEW\n-----END CERTIFICATE REQUEST-----", 'email', 'hostmaster'); })->call($m);
check('reissue', $r === true && $state['reissue']['approverMail'] === 'hostmaster@secure.example.com');
$r = (function () { return $this->approverMail('secure.example.com', 'thief@evil.test'); })->call($m);
check('foreign approver rejected', $r === 'admin@secure.example.com');
$r = (function () { return $this->apiResendValidation(); })->call($m);
check('resend unsupported, explained', $r === false && $m->error !== '');
check('add SAN refused', attempt('add_san', fn () => $m->handle_add_san()) instanceof \Exception);

echo "== catalogue\n";
include_once $m->dir . 'Catalog.php';
$cat = new \WISECP\Modules\Product\DNASSL\Catalog(['reseller_id' => 'r', 'api_key' => 'k', 'test_mode' => 1]);
(function ($c) { $this->api = $c; })->call($cat, $client);
(function ($c) { $this->catalogue = $c; })->call($m, $cat);
check('product options from catalogue', (function () { return $this->sslProductOptions(); })->call($m) === ['SECTIGO-PSSL' => 'PositiveSSL (Sectigo)']);
$rows = $cat->fetchCatalog();
check('catalogue row', count($rows) === 1 && $rows[0]['code'] === 'SECTIGO-PSSL' && $rows[0]['min_cost'] === 7.0 && count($rows[0]['prices']) === 2, json_encode($rows));

echo "== services that only know their domain\n";
// Imported with an earlier version, or linked by typing the domain on the service page:
// matched once to the current certificate on that domain, then remembered.
$m->options['config']['id'] = 'secure.example.com';
$s = attempt('fetchRemoteStatus', fn () => $m->fetchRemoteStatus());
check('looked up by domain', is_array($s) && $s['status'] === 'active' && $state['listed'] === 1 && $m->certificateId() === $guid(1));
$m->fetchRemoteStatus();
check('the id found is remembered', $state['listed'] === 1);

echo "== renew / cancel\n";
$state['renewTooEarly'] = true;
$early = attempt('renew', fn () => $m->renew());
check('renew too early buys nothing and says why', $early instanceof \Exception && $early->getMessage() === 'Renewal opens on 2027-10-01.' && $state['init'] === 1 && $m->certificateId() === $guid(1));
$state['renewTooEarly'] = false;
check('renew uses the renew call, not a second purchase', $m->renew() === true && $state['renewed'] === 1 && $state['init'] === 1 && $m->certificateId() === $guid(101));
check('renewal configured by its own id', $state['configured']['certificateId'] === $guid(101));
check('cancel revokes when enabled', $m->cancel() === true && $state['revoked'] === 1);
$m->config['settings']['revoke_on_cancel'] = 0;
check('cancel no-op when disabled', $m->cancel() === true && $state['revoked'] === 1);

check('every call for an existing certificate carried its id', $state['wrongId'] === [], json_encode($state['wrongId']));

echo "\n$ok passed, $fail failed\n";
