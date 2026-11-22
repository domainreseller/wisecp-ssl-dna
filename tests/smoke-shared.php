<?php
/**
 * Smoke test for the shared client and normalizer, against a fake gateway.
 *
 *   php tests/smoke-shared.php
 */
require dirname(__DIR__) . '/coremio/modules/Product/DNASSL/lib/DnaSsl/Client.php';
require dirname(__DIR__) . '/coremio/modules/Product/DNASSL/lib/DnaSsl/Normalizer.php';

use DomainNameApi\Ssl\ApiException;
use DomainNameApi\Ssl\Client;
use DomainNameApi\Ssl\Normalizer as N;

$ok = 0; $fail = 0;
function check($label, $cond, $extra = '') { global $ok, $fail; if ($cond) { $ok++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label $extra\n"; } }

$sent = [];
$responses = [];
$fake = function ($method, $url, $headers, $body) use (&$sent, &$responses) {
    $sent[] = compact('method', 'url', 'headers', 'body');
    return array_shift($responses) ?: [500, ''];
};

$logs = [];
$api = new Client('0f2c-reseller', 'SECRET-API-KEY', true);
$api->setTransport($fake);
$api->setLogger(function ($a, $req, $resp) use (&$logs) { $logs[] = [$a, $req, $resp]; });

// Certificate ids are GUIDs; the client refuses anything else before sending.
const ID  = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
const ID2 = '99999999-5717-4562-b3fc-2c963f66afa6';
const ID3 = '11111111-5717-4562-b3fc-2c963f66afa6';

echo "== transport\n";
check('test mode uses OTE', $api->baseUrl() === Client::URL_OTE);

$page = function ($n, $total) {
    $items = [];
    for ($i = 0; $i < $n; $i++) $items[] = ['productCode' => 'P' . mt_rand(), 'name' => 'x', 'prices' => []];
    return [200, json_encode(['items' => $items, 'totalCount' => $total])];
};
$responses = [$page(100, 130), $page(30, 130)];
$all = $api->products(['VendorName' => 'Sectigo', 'FilterText' => '']);
check('products paginates', count($all) === 130 && count($sent) === 2);
check('second page skips 100', strpos($sent[1]['url'], 'SkipCount=100') !== false);
check('empty filter dropped', strpos($sent[0]['url'], 'FilterText') === false && strpos($sent[0]['url'], 'VendorName=Sectigo') !== false);
check('auth headers', in_array('X-API-KEY: SECRET-API-KEY', $sent[0]['headers'], true) && in_array('__reseller: 0f2c-reseller', $sent[0]['headers'], true));

$sent = []; $logs = [];
$responses = [[200, json_encode(['csr' => '-----BEGIN CERTIFICATE REQUEST-----', 'privateKey' => '-----BEGIN PRIVATE KEY-----abc'])]];
$gen = $api->generateCsr(['domainName' => 'example.com', 'country' => 'TR']);
check('generateCsr returns key to caller', $gen['privateKey'] === '-----BEGIN PRIVATE KEY-----abc');
check('generateCsr body is JSON', json_decode($sent[0]['body'], true)['domainName'] === 'example.com');
$flat = json_encode($logs);
check('private key masked in log', strpos($flat, 'BEGIN PRIVATE KEY') === false && strpos($flat, '***') !== false);
check('api key never logged', strpos($flat, 'SECRET-API-KEY') === false);

echo "== errors\n";
$responses = [[200, json_encode(['success' => false, 'message' => 'Domain already has an active order'])]];
try { $api->initialize('PKG', 'example.com'); check('success=false throws', false); }
catch (ApiException $e) { check('success=false throws', $e->getMessage() === 'Domain already has an active order'); }

$responses = [[400, json_encode(['error' => ['code' => 'Ssl:01', 'message' => 'Validation failed', 'validationErrors' => [['message' => 'Csr is required']]]])]];
try { $api->configure(['certificateId' => ID]); check('ABP error parsed', false); }
catch (ApiException $e) { check('ABP error parsed', $e->getMessage() === 'Validation failed Csr is required' && $e->errorCode() === 'Ssl:01' && $e->httpStatus() === 400); }

$responses = [[404, '']];
try { $api->revoke(ID); check('404 is not-found', false); }
catch (ApiException $e) { check('404 is not-found', $e->isNotFound()); }

$responses = [[403, json_encode(['error' => ['code' => 'Ssl:10026', 'message' => 'Certificate not found']])]];
try { $api->certificate(ID); check('Ssl:10026 is not-found', false); }
catch (ApiException $e) { check('Ssl:10026 is not-found', $e->isNotFound() && $e->getMessage() === 'Certificate not found'); }

$responses = [[401, '']];
try { $api->checkStatus(ID); check('401 message', false); }
catch (ApiException $e) { check('401 message', strpos($e->getMessage(), 'Reseller ID / API Key') !== false); }

$responses = [[200, '<html>maintenance</html>']];
try { $api->certificate(ID); check('non-JSON rejected', false); }
catch (ApiException $e) { check('non-JSON rejected', $e->errorCode() === 'InvalidResponse'); }

$sent = [];
$responses = [[525, '<html>cf</html>'], [525, ''], [200, json_encode(['success' => true, 'certificateId' => 'c1'])]];
check('525 retried until it goes through', $api->initialize('P', 'x.com')['certificateId'] === 'c1' && count($sent) === 3);
$responses = [[525, ''], [525, ''], [525, '']];
try { $api->checkStatus(ID); check('525 gives up after 2 retries', false); }
catch (ApiException $e) { check('525 gives up after 2 retries', $e->httpStatus() === 525 && strpos($e->getMessage(), 'could not be reached') !== false); }
$sent = [];
$responses = [[502, ''], [200, '{}']];
try { $api->checkStatus(ID); } catch (ApiException $e) {}
check('502 not retried (may have reached the gateway)', count($sent) === 1);

$responses = [[200, json_encode(['mails' => ['admin@x.com', '', 'webmaster@x.com']])]];
check('approvers filtered', $api->approvers('x.com', 'PKG') === ['admin@x.com', 'webmaster@x.com']);

$sent = [];
$responses = [[200, json_encode(['success' => true])]];
$api->revoke(' ' . ID . ' ');
check('revoke is DELETE with query', $sent[0]['method'] === 'DELETE' && substr($sent[0]['url'], -strlen('/ssls/revoke?CertificateId=' . ID)) === '/ssls/revoke?CertificateId=' . ID && $sent[0]['body'] === null);

echo "== certificate id\n";
$ok200 = function ($d = []) { return [200, json_encode($d)]; };
$sent = [];
$responses = [$ok200(['status' => 'Active']), $ok200(), $ok200(), $ok200(['success' => true]), $ok200(['success' => true])];
$api->checkStatus(ID);
$api->certificate(ID);
$api->certificateContent(ID);
$api->reissue(ID, 'CSR', 'admin@x.com');
$api->configure(['certificateId' => ID, 'csr' => 'CSR']);
check('check-status body carries the id', $sent[0]['method'] === 'PUT' && json_decode($sent[0]['body'], true) === ['certificateId' => ID]);
check('certificate and content query by id', strpos($sent[1]['url'], '/ssls/certificate?CertificateId=' . ID) !== false && strpos($sent[2]['url'], '/ssls/certificate-content?CertificateId=' . ID) !== false);
check('reissue and configure bodies carry the id', json_decode($sent[3]['body'], true)['certificateId'] === ID && json_decode($sent[4]['body'], true)['certificateId'] === ID);
check('no call sends a domain name', strpos(json_encode($sent), 'omainName') === false);

// The gateway used to take the domain name here. A caller that still passes one gets a
// clear error and nothing is sent.
$sent = [];
foreach (['checkStatus', 'certificate', 'certificateContent', 'reissue', 'revoke', 'renew'] as $call) {
    try { $api->$call('example.com'); check("$call refuses a domain name", false); }
    catch (ApiException $e) { check("$call refuses a domain name", $e->errorCode() === 'InvalidCertificateId' && strpos($e->getMessage(), 'not by its domain name') !== false); }
}
try { $api->configure(['domainName' => 'example.com', 'csr' => 'CSR']); check('configure refuses a missing id', false); }
catch (ApiException $e) { check('configure refuses a missing id', $e->errorCode() === 'InvalidCertificateId'); }
check('nothing sent without a valid id', $sent === []);
check('id shape', Client::isCertificateId(strtoupper(ID)) && !Client::isCertificateId('example.com') && !Client::isCertificateId('') && !Client::isCertificateId(ID . '0'));

echo "== list / lookup\n";
$row = function ($id, $domain, $status, $renewedFrom = null) {
    return ['certificateId' => $id, 'domainName' => $domain, 'status' => $status, 'renewedFromCertificateId' => $renewedFrom];
};
$list = function (array $rows, $total = null) use ($ok200) { return $ok200(['totalCount' => $total === null ? count($rows) : $total, 'items' => $rows]); };

$sent = [];
$responses = [$list(array_fill(0, 100, $row(ID, 'x.com', 'Active')), 101), $list([$row(ID2, 'x.com', 'Active')], 101)];
check('certificates paginates', count($api->certificates('x.com')) === 101 && count($sent) === 2
    && strpos($sent[0]['url'], '/ssls?DomainName=x.com&SkipCount=0') !== false && strpos($sent[1]['url'], 'SkipCount=100') !== false);
$sent = [];
$responses = [$list([])];
check('certificates without a filter', $api->certificates() === [] && strpos($sent[0]['url'], 'DomainName') === false);

// Newest first, as the gateway returns them.
$responses = [$list([$row(ID3, 'x.com', 'Cancelled'), $row(ID2, 'x.com', 'Active'), $row(ID, 'x.com', 'Renewed')])];
check('lookup: newest current certificate', $api->findCertificateId('X.com') === ID2);
$responses = [$list([$row(ID3, 'x.com', 'Cancelled'), $row(ID, 'x.com', 'Renewed')])];
check('lookup: nothing current falls back to the newest', $api->findCertificateId('x.com') === ID3);
$responses = [$list([$row(ID2, '*.x.com', 'Active'), $row(ID, 'x.com', 'Active')])];
check('lookup: exact name beats the wildcard twin', $api->findCertificateId('x.com') === ID);
$responses = [$list([$row(ID2, '*.x.com', 'Active')])];
check('lookup: twin used when it is all there is', $api->findCertificateId('x.com') === ID2);
$responses = [$list([])];
check('lookup: none', $api->findCertificateId('x.com') === '');
$sent = [];
check('lookup: empty name asks nothing', $api->findCertificateId(' ') === '' && $sent === []);

echo "== renew\n";
$err = function ($code, $message, $status = 403) { return [$status, json_encode(['error' => ['code' => $code, 'message' => $message]])]; };

$sent = [];
$responses = [$ok200(['success' => true, 'certificateId' => ID2, 'status' => 'WaitingForInstall'])];
$r = $api->renew(ID);
check('renew posts the id, returns the new certificate', $sent[0]['method'] === 'POST' && substr($sent[0]['url'], -11) === '/ssls/renew'
    && json_decode($sent[0]['body'], true) === ['certificateId' => ID] && $r['certificateId'] === ID2);

$sent = [];
$responses = [$ok200(['success' => true, 'certificateId' => ID2])];
$r = $api->renewOrReplace(ID, 'PKG', 'x.com');
check('renewOrReplace: renews', $r['mode'] === 'renewed' && $r['certificateId'] === ID2 && count($sent) === 1);

$sent = [];
$responses = [$err('Ssl:10058', 'already renewed'), $list([$row(ID2, 'x.com', 'WaitingForInstall', ID), $row(ID, 'x.com', 'Active')])];
$r = $api->renewOrReplace(ID, 'PKG', 'x.com');
check('renewOrReplace: adopts an existing renewal, buys nothing', $r['mode'] === 'adopted' && $r['certificateId'] === ID2
    && count($sent) === 2 && strpos(json_encode($sent), 'initialize') === false);

$sent = [];
$responses = [$err('Ssl:10058', 'already renewed'), $list([$row(ID2, 'x.com', 'Cancelled', ID)])];
try { $api->renewOrReplace(ID, 'PKG', 'x.com'); check('renewOrReplace: already renewed but no live renewal is an error', false); }
catch (ApiException $e) { check('renewOrReplace: already renewed but no live renewal is an error', $e->errorCode() === 'Ssl:10058' && strpos(json_encode($sent), 'initialize') === false); }

$sent = [];
$responses = [$err('Ssl:10026', 'Certificate not found'), $ok200(['success' => true, 'certificateId' => ID3])];
$r = $api->renewOrReplace(ID, 'PKG', 'x.com');
check('renewOrReplace: unknown certificate gets a new order', $r['mode'] === 'new' && $r['certificateId'] === ID3
    && json_decode($sent[1]['body'], true) === ['packageCode' => 'PKG', 'domainName' => 'x.com']);

$sent = [];
$responses = [$err('Ssl:10059', 'cannot be renewed'), $list([]), $ok200(['status' => 'Cancelled']), $ok200(['success' => true, 'certificateId' => ID3])];
$r = $api->renewOrReplace(ID, 'PKG', 'x.com');
check('renewOrReplace: cancelled certificate gets a new order', $r['mode'] === 'new' && count($sent) === 4 && substr($sent[3]['url'], -16) === '/ssls/initialize');

$sent = [];
$responses = [$err('Ssl:10059', 'cannot be renewed'), $list([]), $ok200(['status' => 'WaitingForInstall'])];
try { $api->renewOrReplace(ID, 'PKG', 'x.com'); check('renewOrReplace: a pending certificate is never doubled', false); }
catch (ApiException $e) { check('renewOrReplace: a pending certificate is never doubled', $e->errorCode() === 'Ssl:10059' && strpos(json_encode($sent), 'initialize') === false); }

$sent = [];
$responses = [$err('Ssl:10057', 'Renewal opens on 2027-10-01')];
try { $api->renewOrReplace(ID, 'PKG', 'x.com'); check('renewOrReplace: too early buys nothing', false); }
catch (ApiException $e) { check('renewOrReplace: too early buys nothing', $e->getMessage() === 'Renewal opens on 2027-10-01' && count($sent) === 1); }

$sent = [];
$responses = [$ok200(['success' => true, 'certificateId' => ID3])];
$r = $api->renewOrReplace('x.com', 'PKG', 'x.com');
check('renewOrReplace: no id known opens a new order', $r['mode'] === 'new' && count($sent) === 1 && substr($sent[0]['url'], -16) === '/ssls/initialize');

try { (new Client('', ''))->products(); check('missing credentials', false); }
catch (ApiException $e) { check('missing credentials', strpos($e->getMessage(), 'required') !== false); }

echo "== normalizer\n";
$p = N::product([
    'name' => 'PositiveSSL Wildcard', 'productCode' => 'SECTIGO-PSSL-WC', 'vendorName' => 'Sectigo',
    'validationType' => 'Domain Validation', 'isWildcard' => true, 'isMultiDomain' => false,
    'defaultSanCount' => 0, 'maxSanCount' => 0, 'requiresCsr' => true,
    'prices' => [
        ['priceGroup' => 'Empty', 'create' => []],
        ['priceGroup' => 'Default', 'create' => [
            ['period' => 2, 'price' => 150.0, 'currency' => 'usd'],
            ['period' => 1, 'price' => 80.5, 'currency' => 'usd'],
            ['period' => 1, 'price' => 90.0, 'currency' => 'usd'],
        ]],
    ],
]);
check('product code/type', $p['code'] === 'SECTIGO-PSSL-WC' && $p['type'] === 'DV' && $p['wildcard']);
check('prices: first non-empty group, cheapest per term, years->months', $p['prices'] === [['months' => 12, 'cost' => 80.5], ['months' => 24, 'cost' => 150.0]], json_encode($p['prices']));
check('currency upper', $p['currency'] === 'USD');
check('min cost', $p['min_cost'] === 80.5);
check('no code => null', N::product(['name' => 'x']) === null);
$groups = [['priceGroup' => 'Reseller', 'create' => [['period' => 1, 'price' => 9.99, 'currency' => 'USD']]], ['priceGroup' => 'VIP', 'create' => [['period' => 1, 'price' => 8.99, 'currency' => 'USD']]]];
check('reseller price group chosen', N::prices($groups, 'vip')['terms'] === [['months' => 12, 'cost' => 8.99]] && N::prices($groups, 'Nope')['terms'][0]['cost'] === 9.99);
check('single-domain SAN 1/1 => 0 extra', N::product(['productCode' => 'a', 'defaultSanCount' => 1, 'maxSanCount' => 1, 'prices' => $groups])['max_sans'] === 0);
check('wildcard 1/100 not multi => 0 extra', N::product(['productCode' => 'a', 'isWildcard' => true, 'defaultSanCount' => 1, 'maxSanCount' => 100, 'prices' => $groups])['max_sans'] === 0);
$ucc = N::product(['productCode' => 'a', 'isMultiDomain' => true, 'defaultSanCount' => 4, 'maxSanCount' => 100, 'prices' => $groups]);
check('multi-domain 4/100 => 3 included, 99 max', $ucc['included_sans'] === 3 && $ucc['max_sans'] === 99);
$cat = N::catalogue([['productCode' => '412', 'name' => 'First', 'prices' => $groups], ['productCode' => '412', 'name' => 'Second', 'prices' => $groups], ['productCode' => 'x', 'prices' => []]]);
check('duplicate codes: first kept, priceless dropped', array_map('strval', array_keys($cat)) === ['412'] && $cat['412']['name'] === 'First');
check('web server default valid', isset(N::WEB_SERVERS[N::DEFAULT_WEB_SERVER]));
check('month periods kept', N::periodToMonths(24) === 24 && N::periodToMonths(3) === 36);
check('type from name', N::validationType('', 'Sectigo EV SSL') === 'EV' && N::validationType('OV') === 'OV');

check('status issued', N::status(['status' => 'Whatever', 'isIssued' => true, 'certificateExpiresAt' => date('c', time() + 86400)]) === 'active');
check('status issued but past', N::status(['isIssued' => true, 'certificateExpiresAt' => '2020-01-01']) === 'expired');
check('status not configured', N::status(['status' => 'Pending', 'isConfigured' => false, 'isIssued' => false]) === 'awaiting_configuration');
check('status configured', N::status(['status' => 'Pending', 'isConfigured' => true, 'isIssued' => false]) === 'processing');
check('status revoked text wins', N::status(['status' => 'Revoked', 'isIssued' => true]) === 'revoked');
check('detail dto validUntil', N::status(['isIssued' => true, 'certificate' => ['validUntil' => date('c', time() + 9999)]]) === 'active');

check('dcv map', N::dcvToApi('dns') === 'CNAME' && N::dcvToApi('https') === 'HTTP' && N::dcvFromApi('CNAME') === 'dns' && N::dcvFromApi('FILE') === 'http' && N::dcvToApi('bogus') === 'EMAIL' && N::dcvFromApi('CNAME_CSR_HASH') === 'dns' && N::dcvFromApi('HTTP_CSR_HASH') === 'http');
$d = N::dcv('{"http":{"FileName":"/.well-known/pki-validation/ABC.txt","FileContent":"hash\ncomodoca.com"}}');
check('dcv json file', $d['file']['filename'] === '/.well-known/pki-validation/ABC.txt' && strpos($d['file']['content'], 'comodoca') !== false);
$d = N::dcv('{"dns":{"host":"_abc.example.com","type":"cname","target":"x.sectigo.com"}}');
check('dcv json dns', $d['dns'] === ['host' => '_abc.example.com', 'type' => 'CNAME', 'value' => 'x.sectigo.com']);
$d = N::dcv('_abc.example.com. CNAME x.sectigo.com.');
check('dcv dns line', $d['dns']['type'] === 'CNAME' && $d['dns']['value'] === 'x.sectigo.com.');
check('dcv email', N::dcv('admin@example.com')['email'] === 'admin@example.com');
check('dcv raw kept', N::dcv('Please wait for the CA')['raw'] === 'Please wait for the CA');
check('dcv empty', N::dcv('') === ['method' => '', 'file' => [], 'dns' => [], 'email' => '', 'raw' => '']);

// Sectigo-style dcvDetails as the gateway sends it; hashes come from the CSR's DER
// (expected values checked against `openssl req -outform DER | md5 / shasum -a 256`).
$csr = <<<CSR
-----BEGIN CERTIFICATE REQUEST-----
MIICrjCCAZYCAQAwaTELMAkGA1UEBhMCVFIxETAPBgNVBAgMCElzdGFuYnVsMREw
DwYDVQQHDAhJc3RhbmJ1bDERMA8GA1UECgwIRE5BIFRlc3QxITAfBgNVBAMMGGRu
YS1zc2wtcHJvYmUtMjYxMDI0LmNvbTCCASIwDQYJKoZIhvcNAQEBBQADggEPADCC
AQoCggEBALYqfcVwmoZR8ldb5OOhVCCCXQrkM0IPfT2rNKh2J6jrnnymZVVihxbw
+Su1mM4/pNwgZeZC+0+nhhSc9zg1xBkdamT8WDjJXYXwpiM03SdCKFFAID7t2IKs
vpYjG1Z7v3PhIK9zW5TdgBvrWoQJ5/JIaEFvtY4CBlsTyeQ+nOnoA/nNpX+KzmJ3
pjC+RSRc4/JUvcKPwrO34FhkNcTT7i3JGEbfpmGxQycoi+oHKcr9mOxF6GnId8aV
D6Z2g4BZt1D8XLs1/73aNaW5kzeXSNqLSkHvOAB5J5Fz6x49dVYKFOKZaYKU24uu
2v1XjDfZ2+eyhrJlVwE+2XkaDWC3xvkCAwEAAaAAMA0GCSqGSIb3DQEBCwUAA4IB
AQBo7xqFp2ynx5sox89PtqJiJvSnoKo8aHQ2kkVkd/1wB/k9vYEJBw/bzpWz+nIW
oqCWmeuDayduQAPDECVeUshifhMhLWFPQFTsFkD/gFe+PN5ZKnulXuilAoUAEuR6
y/EI31+PuDpyJ+qjJoD1SyhFePx1mC/WS1jI9Zc/n3i1pRG0VxH8549yUmCnTEjA
bX+Elis5xv6h3jSO0wK5ai46LcZZqSFAFHJ5rDDJcW7aTfEPdqUR90xx9RwDD72H
BsTS4dF5yACnZfZI/FRGuIsgZuZrl7JpZKLA3xviYkGmTMhq5Yns+rOOiIHGzxBn
7lP+RLBtPWstU4k0hGb08ccb
-----END CERTIFICATE REQUEST-----
CSR;
check('csr hashes', N::csrHashes($csr) === ['md5' => '3937de717f6ee144d491752da05a01cd', 'sha256' => '8bace3afa697dfaaf470ba3c1aa4dbb0da65346c650e711a38ac5c4d087fc138']);
$sect = function ($m) { return json_encode(['ApprovalMethod' => $m, 'ApprovalEmail' => 'admin@dna-ssl-probe-261024.com', 'MD5' => '2f1c0b6e-0000-4000-8000-000000000000', 'SHA1' => 'x', 'UniqueValue' => 'AbC123']); };
$d = N::dcv($sect('HTTPCSRHASH'), 'Http', $csr, 'dna-ssl-probe-261024.com');
check('sectigo http file', $d['method'] === 'http' && $d['file']['filename'] === '/.well-known/pki-validation/3937DE717F6EE144D491752DA05A01CD.txt'
    && $d['file']['content'] === "8BACE3AFA697DFAAF470BA3C1AA4DBB0DA65346C650E711A38AC5C4D087FC138\nsectigo.com\nAbC123"
    && $d['file']['link'] === 'http://dna-ssl-probe-261024.com/.well-known/pki-validation/3937DE717F6EE144D491752DA05A01CD.txt', json_encode($d));
$d = N::dcv($sect('CNAMECSRHASH'), 'Cname', $csr, '*.dna-ssl-probe-261024.com');
check('sectigo cname', $d['method'] === 'dns' && $d['dns'] === ['host' => '_3937de717f6ee144d491752da05a01cd.dna-ssl-probe-261024.com', 'type' => 'CNAME',
    'value' => '8bace3afa697dfaaf470ba3c1aa4dbb0.da65346c650e711a38ac5c4d087fc138.abc123.sectigo.com'], json_encode($d));
$d = N::dcv($sect('EMAIL'), 'Email', $csr, 'x');
check('sectigo email', $d['method'] === 'email' && $d['email'] === 'admin@dna-ssl-probe-261024.com');
$d = N::dcv($sect('HTTPCSRHASH'), 'Http', '', 'x.com');
check('sectigo without CSR or real MD5 shown raw', $d['file'] === [] && strpos($d['raw'], 'HTTPCSRHASH') !== false);
check('status WaitingForValidation', N::status(['status' => 'WaitingForValidation', 'isConfigured' => true, 'isIssued' => false]) === 'processing');
check('status Active', N::status(['status' => 'Active', 'isConfigured' => true, 'isIssued' => true, 'certificateExpiresAt' => '2030-01-01T00:00:00']) === 'active');
check('Ssl:10003 is unsupported', (new ApiException('Package is not supported', 403, 'Ssl:10003'))->isUnsupported());

check('sanList clean', N::sanList("b.com\nA.com, a.com;bad_domain *.c.com example.com", 'example.com') === ['b.com', 'a.com', '*.c.com']);
check('sanList limit', N::sanList('a.com b.com c.com', '', 2) === ['a.com', 'b.com']);
check('domain normalised', N::domain(' Example.COM. ') === 'example.com');
if (function_exists('idn_to_ascii')) check('domain idn', N::domain('örnek.com') === 'xn--rnek-4qa.com');
check('key size from CSR', N::keyInfo('', $csr)['bits'] === 2048);
check('key size unreadable', N::keyInfo('garbage', 'garbage') === ['bits' => 0, 'signature' => '']);
$pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'x.test'], $pk), null, $pk, 1, ['digest_alg' => 'sha256']);
openssl_x509_export($x509, $crtPem);
$ki = N::keyInfo($crtPem, '');
check('key size + signature from certificate', $ki['bits'] === 2048 && stripos($ki['signature'], 'sha256') !== false, json_encode($ki));
check('pem split', N::pem(['certificate' => 'C', 'intermediateCertificate1' => 'I1', 'intermediateCertificate2' => '', 'rootCertificate' => 'R']) === ['C', "I1\nR"]);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
