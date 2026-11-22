<?php
/**
 * DNA SSL - shared client for the Domain Name API SSL gateway.
 *
 * One method per gateway operation, named after the Swagger path
 * (https://api.domainresellerapi.com/swagger/v1/swagger.json). Every method returns the
 * decoded JSON body as an array or throws ApiException; there is no false/error-string
 * channel to forget to check.
 *
 * A certificate that exists is addressed by its certificateId (a GUID), never by its
 * domain name: one domain can have several certificates (a renewal next to the one it
 * renews, cancelled ones). initialize and renew return the id; certificates() and
 * findCertificateId() look it up.
 *
 * Kept PHP 7.4 compatible (see ApiException.php).
 */

namespace DomainNameApi\Ssl;

require_once __DIR__ . '/ApiException.php';

class Client
{
    const URL_PROD = 'https://api.domainresellerapi.com/api/v1';
    const URL_OTE  = 'https://ote.domainresellerapi.com/api/v1';

    const VERSION = '1.1.0';

    /** Cloudflare statuses meaning the request never reached the gateway. */
    const UNREACHED = [521, 522, 523, 525, 526];

    /** Extra attempts after an unreached answer. */
    const RETRIES = 2;

    /** Gateway error codes this client reacts to. */
    const ERR_ALREADY_RENEWED = 'Ssl:10058';
    const ERR_NOT_RENEWABLE   = 'Ssl:10059';

    /** Request and response keys whose values never reach a log line. */
    const SECRET_KEYS = ['privateKey', 'privatekey', 'private_key', 'X-API-KEY', 'apiKey', 'api_key'];

    /** @var string */
    private $resellerId;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    /** @var callable|null fn(string $action, array $request, mixed $response): void */
    private $logger;

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    private $transport;

    /** @var int microseconds before a retry (multiplied by the attempt number) */
    private $retryDelay = 750000;

    public function __construct(string $resellerId, string $apiKey, bool $testMode = false, int $timeout = 30)
    {
        $this->resellerId = trim($resellerId);
        $this->apiKey     = trim($apiKey);
        $this->baseUrl    = $testMode ? self::URL_OTE : self::URL_PROD;
        $this->timeout    = max(5, $timeout);
    }

    /** Receives every call, with secrets already masked. */
    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    /** Test seam: replaces the cURL transport (and drops the retry delay). */
    public function setTransport(?callable $transport): void
    {
        $this->transport  = $transport;
        $this->retryDelay = $transport ? 0 : 750000;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Which gateway a key belongs to: 'live', 'test' or '' when neither answered.
     *
     * Live and test keys are different and each gateway answers the other's key with
     * HTTP 401 (verified 2026-10-24), so one cheap read on each tells them apart and the
     * admin does not have to say which environment a key is for.
     */
    public static function environmentFor(string $resellerId, string $apiKey, ?callable $transport = null): string
    {
        foreach (['live' => false, 'test' => true] as $env => $testMode) {
            $probe = new self($resellerId, $apiKey, $testMode, 15);
            if ($transport) $probe->setTransport($transport);

            try {
                $probe->account();
                return $env;
            } catch (ApiException $e) {
                // 401/403 = not this environment's key; anything else = cannot tell.
                if (!in_array($e->httpStatus(), [401, 403], true)) return '';
            }
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /**
     * GET /products/ssls - every certificate product, all pages.
     *
     * @param array $filter FilterText, Currency, ValidationType, IsWildcard, IsMultiDomain, VendorName
     * @return array[] ProductSslListDto rows
     */
    public function products(array $filter = []): array
    {
        $items = [];
        $skip  = 0;
        $page  = 100;

        // Hard stop well above any real catalogue so a gateway that ignores SkipCount
        // cannot keep us looping.
        for ($guard = 0; $guard < 50; $guard++) {
            $query = array_filter($filter, function ($v) { return $v !== null && $v !== ''; })
                + ['SkipCount' => $skip, 'MaxResultCount' => $page];

            $result = $this->request('GET', '/products/ssls', $query);
            $rows   = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
            $total  = isset($result['totalCount']) ? (int) $result['totalCount'] : count($rows);

            foreach ($rows as $row) {
                if (is_array($row)) $items[] = $row;
            }

            $skip += count($rows);
            if (!$rows || $skip >= $total) break;
        }

        return $items;
    }

    /**
     * GET /deposit/accounts/me - resellerName, resellerGroupName (the price group the
     * reseller pays), balances.
     */
    public function account(): array
    {
        return $this->request('GET', '/deposit/accounts/me');
    }

    /** The reseller's price group name ("Reseller", "Premium", ...), '' when unknown. */
    public function priceGroup(): string
    {
        try {
            return trim((string) ($this->account()['resellerGroupName'] ?? ''));
        } catch (ApiException $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // CSR helpers
    // ------------------------------------------------------------------

    /** POST /ssls/generate-csr - returns ['csr' => ..., 'privateKey' => ...]. */
    public function generateCsr(array $input): array
    {
        return $this->request('POST', '/ssls/generate-csr', [], [
            'domainName'       => (string) ($input['domainName'] ?? ''),
            'country'          => (string) ($input['country'] ?? ''),
            'state'            => (string) ($input['state'] ?? ''),
            'locality'         => (string) ($input['locality'] ?? ''),
            'organization'     => (string) ($input['organization'] ?? ''),
            'organizationUnit' => (string) ($input['organizationUnit'] ?? ''),
            'alternativeNames' => array_values((array) ($input['alternativeNames'] ?? [])),
        ]);
    }

    /** POST /ssls/check-csr - decodes a CSR (domainName, isWildcard, isSan, ...). */
    public function checkCsr(string $csr): array
    {
        return $this->request('POST', '/ssls/check-csr', [], ['csr' => trim($csr)]);
    }

    // ------------------------------------------------------------------
    // Certificate lifecycle
    // ------------------------------------------------------------------

    /**
     * POST /ssls/initialize - opens the order. Returns SslOperationResponse; keep its
     * certificateId, every later call needs it.
     */
    public function initialize(string $packageCode, string $domainName): array
    {
        return $this->operation('POST', '/ssls/initialize', [], [
            'packageCode' => $packageCode,
            'domainName'  => $domainName,
        ]);
    }

    /**
     * POST /ssls/renew - opens a renewal order linked to the certificate, at the renewal
     * price. Returns SslOperationResponse whose certificateId is the NEW certificate,
     * which is then configured like a new order. Repeating the call while that renewal
     * waits for configuration returns the same renewal and does not charge again.
     */
    public function renew(string $certificateId): array
    {
        return $this->operation('POST', '/ssls/renew', [], ['certificateId' => self::certificateId($certificateId)]);
    }

    /**
     * PUT /ssls/configure - submits CSR, DCV choice and contact. Returns SslOperationResponse.
     *
     * @param array $input certificateId, csr, privateKey?, approverMail, authenticationType,
     *                     webServerType, sanList?, wildcardList?, contact{...}
     */
    public function configure(array $input): array
    {
        $input['certificateId'] = self::certificateId((string) ($input['certificateId'] ?? ''));

        return $this->operation('PUT', '/ssls/configure', [], $input);
    }

    /** PUT /ssls/check-status - CertificateStatusDto (status, isConfigured, isIssued, ...). */
    public function checkStatus(string $certificateId): array
    {
        return $this->request('PUT', '/ssls/check-status', [], ['certificateId' => self::certificateId($certificateId)]);
    }

    /** GET /ssls/certificate - CertificateDetailDto. */
    public function certificate(string $certificateId): array
    {
        return $this->request('GET', '/ssls/certificate', ['CertificateId' => self::certificateId($certificateId)]);
    }

    /** GET /ssls/certificate-content - PEM bodies (certificate, intermediates, root). */
    public function certificateContent(string $certificateId): array
    {
        return $this->request('GET', '/ssls/certificate-content', ['CertificateId' => self::certificateId($certificateId)]);
    }

    /** POST /ssls/reissue - Returns SslOperationResponse. */
    public function reissue(string $certificateId, string $csr = '', string $approverMail = '', string $privateKey = ''): array
    {
        $body = ['certificateId' => self::certificateId($certificateId)];
        if ($csr !== '')          $body['csr']          = trim($csr);
        if ($approverMail !== '') $body['approverMail'] = $approverMail;
        if ($privateKey !== '')   $body['privateKey']   = $privateKey;

        return $this->operation('POST', '/ssls/reissue', [], $body);
    }

    /**
     * GET /ssls/approvers - the e-mail addresses allowed for e-mail DCV, for a domain and
     * the package bought (or about to be bought) for it. The gateway needs both.
     */
    public function approvers(string $domainName, string $packageCode): array
    {
        $result = $this->request('GET', '/ssls/approvers', [
            'DomainName'  => $domainName,
            'PackageCode' => $packageCode,
        ]);

        $mails = isset($result['mails']) && is_array($result['mails']) ? $result['mails'] : [];

        return array_values(array_filter(array_map('strval', $mails), function ($m) {
            return $m !== '';
        }));
    }

    /** DELETE /ssls/revoke - Returns SslOperationResponse. */
    public function revoke(string $certificateId): array
    {
        return $this->operation('DELETE', '/ssls/revoke', ['CertificateId' => self::certificateId($certificateId)]);
    }

    // ------------------------------------------------------------------
    // Finding a certificate
    // ------------------------------------------------------------------

    /**
     * GET /ssls - the reseller's certificates, newest first, all pages.
     *
     * @param string $domainName only this domain's ('' for all); the gateway matches a
     *                           wildcard with and without its "*." prefix
     * @return array[] CertificateListItemDto rows
     */
    public function certificates(string $domainName = ''): array
    {
        $items = [];
        $skip  = 0;
        $page  = 100;

        // Same guard as products(): a gateway that ignores SkipCount must not loop us.
        for ($guard = 0; $guard < 50; $guard++) {
            $query = ['SkipCount' => $skip, 'MaxResultCount' => $page];
            if ($domainName !== '') $query = ['DomainName' => $domainName] + $query;

            $result = $this->request('GET', '/ssls', $query);
            $rows   = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
            $total  = isset($result['totalCount']) ? (int) $result['totalCount'] : count($rows);

            foreach ($rows as $row) {
                if (is_array($row)) $items[] = $row;
            }

            $skip += count($rows);
            if (!$rows || $skip >= $total) break;
        }

        return $items;
    }

    /**
     * The certificate a domain name stands for: the id of its newest certificate that is
     * still current (not cancelled, not superseded by a renewal), else its newest one,
     * '' when the domain has none. For records that only know the domain.
     */
    public function findCertificateId(string $domainName): string
    {
        $domainName = strtolower(trim($domainName));
        if ($domainName === '') return '';

        $rows = $this->certificates($domainName);

        // The gateway also returns the wildcard/base twin of the name; an exact match wins.
        $exact = array_values(array_filter($rows, function ($row) use ($domainName) {
            return strtolower((string) ($row['domainName'] ?? '')) === $domainName;
        }));
        if ($exact) $rows = $exact;

        $fallback = '';
        foreach ($rows as $row) {
            $id = (string) ($row['certificateId'] ?? '');
            if (!self::isCertificateId($id)) continue;
            if (self::isCurrent($row)) return $id;
            if ($fallback === '') $fallback = $id;
        }

        return $fallback;
    }

    /** The id of the renewal certificate opened for $certificateId, '' when there is none. */
    public function findRenewalId(string $certificateId, string $domainName = ''): string
    {
        $certificateId = strtolower(self::certificateId($certificateId));

        foreach ($this->certificates($domainName) as $row) {
            $id = (string) ($row['certificateId'] ?? '');
            if (strtolower((string) ($row['renewedFromCertificateId'] ?? '')) !== $certificateId) continue;
            if (self::isCertificateId($id) && stripos((string) ($row['status'] ?? ''), 'cancel') === false) return $id;
        }

        return '';
    }

    /**
     * Renews the certificate behind a billing renewal, or opens a new order when there is
     * nothing left to renew. Returns SslOperationResponse plus 'mode':
     *
     *   renewed  - POST /ssls/renew opened a renewal (the normal case)
     *   adopted  - a renewal already existed (opened from the reseller panel, or by an
     *              earlier attempt); it is used and nothing is charged again
     *   new      - the certificate is cancelled or unknown to the gateway, so a new order
     *              was opened with initialize
     *
     * Everything else is thrown: too early to renew (Ssl:10057), insufficient balance,
     * and a certificate that is still waiting for its first configuration - a second
     * certificate is never bought while one is live or pending.
     */
    public function renewOrReplace(string $certificateId, string $packageCode, string $domainName): array
    {
        if (self::isCertificateId($certificateId)) {
            try {
                return $this->renew($certificateId) + ['mode' => 'renewed'];
            } catch (ApiException $e) {
                if (in_array($e->errorCode(), [self::ERR_ALREADY_RENEWED, self::ERR_NOT_RENEWABLE], true)) {
                    $renewal = $this->findRenewalId($certificateId, $domainName);
                    if ($renewal !== '') {
                        return ['success' => true, 'certificateId' => $renewal, 'mode' => 'adopted'];
                    }
                }

                $gone = $e->isNotFound()
                    || ($e->errorCode() === self::ERR_NOT_RENEWABLE && $this->isCancelled($certificateId));
                if (!$gone) throw $e;
            }
        }

        return $this->initialize($packageCode, $domainName) + ['mode' => 'new'];
    }

    /** True for a GUID, the only thing the gateway accepts as a certificate id. */
    public static function isCertificateId(string $id): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', trim($id));
    }

    /**
     * The id as sent to the gateway. A domain name here means the caller still works
     * the way the gateway did before certificates had ids; say so instead of sending a
     * request that can only be rejected.
     */
    private static function certificateId(string $id): string
    {
        $id = trim($id);
        if (!self::isCertificateId($id)) {
            throw new ApiException(
                'The certificate ID is missing or not valid. Domain Name API identifies a certificate by its ID, not by its domain name.',
                0,
                'InvalidCertificateId'
            );
        }

        return $id;
    }

    /** A list row that is neither cancelled nor superseded by a renewal. */
    private static function isCurrent(array $row): bool
    {
        $status = strtolower((string) ($row['status'] ?? ''));

        return strpos($status, 'cancel') === false && $status !== 'renewed';
    }

    private function isCancelled(string $certificateId): bool
    {
        try {
            return stripos((string) ($this->certificate($certificateId)['status'] ?? ''), 'cancel') !== false;
        } catch (ApiException $e) {
            return $e->isNotFound();
        }
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /** Like request(), but an SslOperationResponse with success=false is an error too. */
    private function operation(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $result = $this->request($method, $path, $query, $body);

        if (array_key_exists('success', $result) && !$result['success']) {
            $message = trim((string) ($result['message'] ?? ''));
            throw new ApiException($message !== '' ? $message : 'The SSL gateway rejected the request.', 200, 'OperationFailed');
        }

        return $result;
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->resellerId === '' || $this->apiKey === '') {
            throw new ApiException('Reseller ID and API Key are required.');
        }

        $url = $this->baseUrl . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'Accept: application/json',
            'X-API-KEY: ' . $this->apiKey,
            '__reseller: ' . $this->resellerId,
            'User-Agent: DNA-SSL/' . self::VERSION . ' (+https://www.domainnameapi.com)',
        ];

        $payload = null;
        if ($body !== null) {
            $payload   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $action = $method . ' ' . $path;

        // Cloudflare answers 521/522/523/525/526 when it could not reach the gateway at all
        // (seen intermittently on OTE from some networks). The request never arrived, so it
        // is safe to send again - for writes too.
        $attempt = 0;
        while (true) {
            try {
                list($status, $raw) = $this->transport
                    ? call_user_func($this->transport, $method, $url, $headers, $payload)
                    : $this->curl($method, $url, $headers, $payload);
            } catch (ApiException $e) {
                $this->log($action, $query, $body, $e->getMessage());
                throw $e;
            }

            if (!in_array((int) $status, self::UNREACHED, true) || $attempt >= self::RETRIES) break;
            $this->log($action, $query, $body, 'HTTP ' . $status . ' (gateway not reached), retrying');
            $attempt++;
            if ($this->retryDelay > 0) usleep($this->retryDelay * $attempt);
        }

        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $this->log($action, $query, $body, is_array($decoded) ? $decoded : $raw);

        if ($status < 200 || $status >= 300) {
            throw $this->httpError((int) $status, is_array($decoded) ? $decoded : [], (string) $raw);
        }

        if ($raw !== '' && !is_array($decoded)) {
            throw new ApiException('The SSL gateway returned a response that is not JSON.', (int) $status, 'InvalidResponse');
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, ?string $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('The PHP cURL extension is required.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno) {
            throw new ApiException('Could not reach the SSL gateway: ' . ($error !== '' ? $error : 'cURL error ' . $errno), 0, 'Transport');
        }

        return [$status, (string) $raw];
    }

    private function httpError(int $status, array $decoded, string $raw): ApiException
    {
        $error   = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        $message = trim((string) ($error['message'] ?? ''));
        $details = trim((string) ($error['details'] ?? ''));
        $code    = (string) ($error['code'] ?? '');

        if (!empty($error['validationErrors']) && is_array($error['validationErrors'])) {
            $parts = [];
            foreach ($error['validationErrors'] as $v) {
                if (is_array($v) && !empty($v['message'])) $parts[] = (string) $v['message'];
            }
            if ($parts) $details = trim($details . ' ' . implode(' ', $parts));
        }

        if ($message === '') {
            switch ($status) {
                case 401: $message = 'The SSL gateway rejected the Reseller ID / API Key.'; break;
                case 403: $message = 'This reseller account is not allowed to use the SSL gateway.'; break;
                case 404: $message = 'The SSL gateway could not find the requested certificate.'; break;
                case 521: case 522: case 523: case 525: case 526:
                $message = 'The SSL gateway could not be reached (HTTP ' . $status . '). Try again in a moment.'; break;
            default:  $message = 'The SSL gateway answered HTTP ' . $status . '.';
            }
        }

        if ($details !== '' && strpos($message, $details) === false) $message .= ' ' . $details;

        return new ApiException($message, $status, $code, $details);
    }

    private function log(string $action, array $query, ?array $body, $response): void
    {
        if (!$this->logger) return;

        $request = ['url' => $this->baseUrl, 'query' => $query];
        if ($body !== null) $request['body'] = $body;

        try {
            call_user_func($this->logger, $action, self::mask($request), is_array($response) ? self::mask($response) : $response);
        } catch (\Throwable $e) {
            // A broken logger must never break provisioning.
        }
    }

    /** Recursively replaces secret values with a fixed marker. */
    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::mask($value);
            } elseif (is_string($key) && in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
