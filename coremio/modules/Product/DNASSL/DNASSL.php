<?php
/**
 * DNA SSL for WISECP 5 - sells Domain Name API SSL certificates.
 *
 * Built on WISECP's SslProductModule: the client dashboard, reissue page, catalogue
 * import and cost-sync cron come from the core; this class maps them onto the Domain
 * Name API SSL gateway (/api/v1/ssls/*).
 *
 * The gateway addresses a certificate by its certificate id (one domain can have
 * several certificates), so the id returned when the order is opened or renewed is
 * stored with the service and every later call uses it. The domain is kept for display
 * and to match services that only know their domain.
 */
    namespace WISECP\Modules\Product;

    defined('CORE_FOLDER') or exit('You can not get in here!');

    use DomainNameApi\Ssl\ApiException;
    use DomainNameApi\Ssl\Client;
    use DomainNameApi\Ssl\Normalizer;
    use WISECP\Modules\Product\DNASSL\Catalog;

    require_once __DIR__ . DS . 'lib' . DS . 'DnaSsl' . DS . 'Client.php';
    require_once __DIR__ . DS . 'lib' . DS . 'DnaSsl' . DS . 'Normalizer.php';

    class DNASSL extends \SslProductModule
    {
        protected ?Client $api = null;
        private ?Catalog $catalogue = null;

        /** The core offers SAN add/remove; the gateway takes SANs only with the order. */
        public array $client_callable_methods = [
            'reissue', 'resend_validation', 'revalidate', 'change_validation_method', 'download_certificate',
        ];

        // --------------------------------------------------------------
        // Admin: module page
        // --------------------------------------------------------------

        public function page_configuration(): string
        {
            return $this->configurationNotice() . $this->sslConfigurationTabs();
        }

        /** Says why the catalogue is empty instead of leaving an unexplained blank table. */
        private function configurationNotice(): string
        {
            $s = $this->config['settings'] ?? [];
            if (empty($s['reseller_id']) || empty($s['api_key'])) {
                return '<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>'
                    . htmlspecialchars($this->lang['missing-credentials'] ?? 'Reseller ID and API Key are required.') . '</div>';
            }

            $catalogue = $this->catalogue();
            if (!$catalogue->fetchCatalog() && $catalogue->apiError() !== '') {
                return '<div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i>'
                    . htmlspecialchars(($this->lang['catalog-error'] ?? 'The Domain Name API catalogue could not be loaded:') . ' ' . $catalogue->apiError())
                    . '</div>';
            }

            return '';
        }

        protected function sslSettingsFields(): array
        {
            $s      = $this->config['settings'] ?? [];
            $hasKey = !empty($s['api_key']);

            return [
                [
                    'name'        => 'reseller_id',
                    'label'       => $this->lang['api-reseller-id'] ?? 'Reseller ID',
                    'value'       => (string) ($s['reseller_id'] ?? ''),
                    'description' => $this->lang['api-credentials-desc'] ?? null,
                ],
                [
                    'name'        => 'api_key',
                    'type'        => 'password',
                    'label'       => $this->lang['api-key'] ?? 'API Key',
                    'placeholder' => $hasKey ? '••••••••' : '',
                    'description' => $hasKey ? ($this->lang['api-key-set'] ?? 'Leave blank to keep the current key.') : null,
                ],
                [
                    'name'    => 'test_mode',
                    'type'    => 'checkbox',
                    'label'   => $this->lang['test-mode'] ?? 'Test Mode',
                    'text'    => $this->lang['test-mode-desc'] ?? '',
                    'checked' => (int) ($s['test_mode'] ?? 0) === 1,
                ],
                [
                    'name'    => 'revoke_on_cancel',
                    'type'    => 'checkbox',
                    'label'   => $this->lang['revoke-on-cancel'] ?? 'Revoke on Cancellation',
                    'text'    => $this->lang['revoke-on-cancel-desc'] ?? '',
                    'checked' => (int) ($s['revoke_on_cancel'] ?? 0) === 1,
                ],
            ];
        }

        protected function sslCatalogue(): ?\SslCatalog
        {
            return $this->catalogue();
        }

        /**
         * The core table labels the sale price with the system currency although the
         * catalogue prices (and the products it creates) are in the gateway's currency.
         */
        protected function sslCatalogTable(): \WISECP\Components\Table
        {
            $table = new \WISECP\Components\Table($this->sslUiId('Catalog'));
            $money = static fn ($v, $cur) => number_format((float) $v, 2) . ' ' . htmlspecialchars((string) $cur);

            $table->setColumns([
                'name'   => ['title' => $this->lang['cat-name'] ?? 'Certificate'],
                'vendor' => ['title' => $this->lang['cat-vendor'] ?? 'Brand', 'attributes' => ['class' => 'text-center'], 'sortable' => false],
                'type'   => [
                    'title'      => $this->lang['cat-type'] ?? 'Type',
                    'attributes' => ['class' => 'text-center'],
                    'sortable'   => false,
                    'cellRender' => function ($v) {
                        $cls = ['DV' => 'text-bg-secondary', 'OV' => 'text-bg-info', 'EV' => 'text-bg-success'][$v] ?? 'text-bg-light';
                        return '<span class="badge rounded ' . $cls . '">' . htmlspecialchars((string) $v) . '</span>';
                    },
                ],
                'cost'   => ['title' => $this->lang['cat-cost'] ?? 'Cost', 'attributes' => ['class' => 'text-end'], 'sortable' => false],
                'sale'   => ['title' => $this->lang['cat-sale'] ?? 'Sale', 'attributes' => ['class' => 'text-end'], 'sortable' => false],
                'status' => ['title' => '', 'attributes' => ['class' => 'text-end'], 'sortable' => false],
            ]);

            $notImported = htmlspecialchars($this->lang['cat-not-imported'] ?? 'Not imported');
            $table->setRowRender(static function ($row) use ($notImported, $money) {
                $cur      = (string) ($row['model']['currency'] ?? '');
                $imported = (int) ($row['model']['imported'] ?? 0);

                $row['data']['cost']['value']   = $money($row['model']['cost'] ?? 0, $cur);
                $row['data']['sale']['value']   = $money($row['model']['sale'] ?? 0, $cur);
                $row['data']['status']['value'] = $imported
                    ? '<a href="' . \LinkGenerator::admin('products', ['edit'], '', ['id' => $imported]) . '" class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle me-1"></i>#' . $imported . '</a>'
                    : '<span class="badge rounded text-bg-light text-muted">' . $notImported . '</span>';

                return $row;
            });

            return $table;
        }

        protected function sslImportIdLabel(): string
        {
            return $this->lang['import-domain'] ?? 'Certificate Domain or ID';
        }

        public function controller_save(): array
        {
            $config = $this->config;

            $config['settings']['reseller_id']      = trim((string) \Filter::init('POST/reseller_id', 'hclear'));
            $config['settings']['test_mode']        = (int) (bool) \Filter::init('POST/test_mode', 'numbers');
            $config['settings']['revoke_on_cancel'] = (int) (bool) \Filter::init('POST/revoke_on_cancel', 'numbers');

            $key = trim((string) \Filter::init('POST/api_key', 'hclear'));
            if ($key !== '' && !str_starts_with($key, '••') && !str_starts_with($key, '****'))
                $config['settings']['api_key'] = $this->encode_str($key, 'system');

            $this->save_config($config);

            return ['status' => 'successful', 'message' => $this->lang['settings-saved'] ?? 'Settings saved successfully.'];
        }

        public function controller_test_connection(): array
        {
            $key = trim((string) \Filter::init('POST/api_key', 'hclear'));
            if ($key === '' || str_starts_with($key, '••') || str_starts_with($key, '****'))
                $key = $this->decode_str((string) ($this->config['settings']['api_key'] ?? ''), 'system');

            $reseller = trim((string) \Filter::init('POST/reseller_id', 'hclear'));
            if ($reseller === '' || $key === '')
                return ['status' => 'error', 'message' => $this->lang['missing-credentials'] ?? 'Reseller ID and API Key are required.'];

            $api = new Client($reseller, $key, (bool) \Filter::init('POST/test_mode', 'numbers'));

            try {
                $count = count($api->products());
            } catch (ApiException $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            return [
                'status'  => 'successful',
                'message' => sprintf($this->lang['connection-success'] ?? 'Connection successful. %d certificate product(s) available.', $count),
            ];
        }

        public function controller_catalog_list(): string
        {
            return $this->sslCatalogRows();
        }

        public function controller_catalog_import(): array
        {
            $result = $this->catalogue()->import($this->sslSaveMargin());

            return [
                'status'  => 'successful',
                'message' => sprintf($this->lang['catalog-imported'] ?? '%d product(s) created, %d already existed.', $result['created'], $result['skipped']),
            ];
        }

        public function controller_catalog_sync(): array
        {
            $result = $this->catalogue()->syncPrices($this->sslSaveMargin());

            return [
                'status'  => 'successful',
                'message' => sprintf($this->lang['catalog-synced'] ?? '%d product(s) checked, %d price(s) updated.', $result['checked'], $result['updated']),
            ];
        }

        public function controller_catalog_autosync(): array
        {
            $on = (int) \Filter::init('POST/auto_sync', 'numbers') === 1;

            $config                               = $this->config;
            $config['settings']['auto_sync_cost'] = $on;
            $this->save_config($config);

            return [
                'status'  => 'successful',
                'message' => $on
                    ? ($this->lang['cat-autosync-on'] ?? 'Automatic price update enabled.')
                    : ($this->lang['cat-autosync-off'] ?? 'Automatic price update disabled.'),
            ];
        }

        /** Imports a certificate that already exists at Domain Name API as a service. */
        public function controller_import(): array
        {
            // A certificate ID, or the domain: the newest current certificate on it.
            $entered    = trim((string) \Filter::init('POST/order_id', 'hclear'));
            $id         = Client::isCertificateId($entered) ? strtolower($entered) : '';
            $domain     = $id === '' ? Normalizer::domain($entered) : '';
            $user_id    = (int) \Filter::init('POST/user_id', 'numbers');
            $product_id = (int) \Filter::init('POST/product_id', 'numbers');
            $cycle      = trim((string) \Filter::init('POST/cycle', 'route'));

            if ($id === '' && $domain === '')
                return ['status' => 'error', 'message' => $this->lang['import-no-id'] ?? 'Enter the certificate domain or ID.'];
            if (!$user_id || !$product_id)
                return ['status' => 'error', 'message' => $this->lang['import-missing-fields'] ?? 'Client and product are required.'];

            try {
                $this->initApi();
                if ($id === '') $id = $this->api->findCertificateId($domain);
                if ($id === '')
                    return ['status' => 'error', 'message' => sprintf($this->lang['import-not-found'] ?? 'No certificate for %s was found in your Domain Name API account.', $domain)];

                $detail = $this->api->certificate($id);
            } catch (ApiException $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            $domain = Normalizer::domain((string) ($detail['domainName'] ?? '')) ?: $domain;
            if ($domain === '' || $this->serviceForDomain($domain))
                return ['status' => 'error', 'message' => $this->lang['import-exists'] ?? 'This certificate has already been imported.'];

            $now        = \DateManager::Now();
            $issued     = $this->toDate((string) ($detail['subscription']['purchasedAt'] ?? ''), $now);
            $expires    = $this->toDate((string) ($detail['subscription']['expiresAt'] ?? ''), $now);
            $service_id = $this->import_service([
                'owner_id'    => $user_id,
                'product_id'  => $product_id,
                'cycle'       => $cycle,
                'cdate'       => $issued,
                'duedate'     => $expires,
                'renewaldate' => $now,
                'options'     => [
                    'config' => [
                        'id'         => $id,
                        'domain'     => $domain,
                        'package'    => (string) ($detail['packageCode'] ?? ''),
                        'configured' => !empty($detail['isConfigured']),
                    ],
                    'domain' => $domain,
                ],
            ]);

            if (!$service_id)
                return ['status' => 'error', 'message' => $this->lang['import-failed'] ?? 'The certificate could not be imported.'];

            \User::addAction($this->admin['id'] ?? 0, 'alteration', 'ssl-certificate-imported', [
                'module'   => $this->config['meta']['name'] ?? $this->_name,
                'imported' => $domain . ' (#' . $service_id . ')',
            ]);

            return ['status' => 'successful', 'message' => sprintf($this->lang['import-success'] ?? '%s certificate(s) imported successfully.', 1)];
        }

        // --------------------------------------------------------------
        // Admin: service page
        // --------------------------------------------------------------

        public function service_configuration(): array
        {
            return [
                'fields' => [
                    'domain' => [
                        'name'        => $this->lang['config-domain'] ?? 'Certificate Domain',
                        'type'        => 'text',
                        'value'       => $this->options['config']['domain'] ?? '',
                        'input_name'  => 'config',
                        'description' => $this->lang['config-domain-desc'] ?? null,
                    ],
                ],
            ];
        }

        public function save_service_configuration(array &$values): void
        {
            $domain = Normalizer::domain((string) ($values['config']['domain'] ?? ''));
            $values['config']['domain'] = $domain;
            if ($domain !== '' && empty($values['config']['id'] ?? ($this->options['config']['id'] ?? '')))
                $values['config']['id'] = $domain;
        }

        // --------------------------------------------------------------
        // Lifecycle
        // --------------------------------------------------------------

        public function certificateId(): string
        {
            // Truthy as soon as an order exists; the dashboard keys off this.
            return (string) ($this->options['config']['id'] ?? '');
        }

        /**
         * The gateway id of the service's certificate.
         *
         * The gateway addresses a certificate by its id only, since one domain can have
         * several. A service that still carries the domain where the id belongs (imported
         * before the gateway had ids, or typed on the service page) is matched once to
         * the newest current certificate on that domain and the id is stored. Throws a
         * not-found ApiException when the gateway has no certificate for the domain.
         */
        private function remoteId(): string
        {
            $id = trim($this->certificateId());
            if (Client::isCertificateId($id)) return $id;

            $this->initApi();
            $domain = $this->domain();
            $id     = $domain !== '' ? $this->api->findCertificateId($domain) : '';
            if ($id === '') {
                throw new ApiException(
                    sprintf($this->lang['err-not-found'] ?? 'No certificate for %s was found in your Domain Name API account.', $domain),
                    404,
                    'Ssl:10026'
                );
            }

            $this->options['config']['id'] = $id;
            $this->save_options();

            return $id;
        }

        public function create(): bool|array
        {
            $config = (array) ($this->options['config'] ?? []);
            if (!empty($config['id']) && !empty($config['configured'])) return true;

            $package = $this->packageCode();
            $domain  = (string) ($config['domain'] ?? '') ?: $this->certificateDomain();
            $csr     = trim((string) ($this->requirement_params['csr'] ?? ''));

            if ($package === '') throw new \Exception($this->lang['err-no-product'] ?? 'No certificate is configured for this product.');
            if ($domain === '')  throw new \Exception($this->lang['err-no-domain'] ?? 'A domain is required to issue the certificate.');
            if ($csr === '')     throw new \Exception($this->lang['err-no-csr'] ?? 'A CSR is required to issue the certificate.');

            $this->initApi();

            // Two steps, persisted in between: a configure failure must not open a second
            // order when the admin retries.
            if (empty($config['id'])) {
                $result = $this->call(fn () => $this->api->initialize($package, $domain));

                $this->options['config'] = [
                    'id'             => (string) ($result['certificateId'] ?? '') ?: $domain,
                    'domain'         => $domain,
                    'package'        => $package,
                    'configured'     => false,
                ] + $config;
                $this->options['domain'] = $this->options['domain'] ?? $domain;
                $this->save_options();
            }

            $this->call(fn () => $this->api->configure($this->configureInput($domain, $csr)));

            $this->options['config']['configured'] = true;
            $this->save_options();

            return true;
        }

        /**
         * Renewal is the gateway's renew call: a new certificate linked to the current
         * one, at the renewal price, configured with the CSR the client gave last time.
         *
         * A certificate that is cancelled or unknown to the gateway cannot be renewed and
         * gets a new order instead. While the gateway refuses for any other reason
         * (renewal opens 30 days before the subscription ends, balance, ...) nothing is
         * bought and its message is reported.
         */
        public function renew(): bool|array
        {
            $config = (array) ($this->options['config'] ?? []);
            if (empty($config['id'])) return true;

            $domain = (string) ($config['domain'] ?? '') ?: $this->certificateDomain();
            $csr    = trim((string) ($this->requirement_params['csr'] ?? ''));
            if ($csr === '') throw new \Exception($this->lang['err-no-csr'] ?? 'A CSR is required to issue the certificate.');

            $this->initApi();
            $result = $this->call(function () use ($domain) {
                try {
                    $current = $this->remoteId();
                } catch (ApiException $e) {
                    if (!$e->isNotFound()) throw $e;
                    $current = '';
                }

                return $this->api->renewOrReplace($current, $this->packageCode(), $domain);
            });
            $this->options['config']['id']         = (string) ($result['certificateId'] ?? '') ?: $domain;
            $this->options['config']['configured'] = false;
            $this->save_options();

            $this->call(fn () => $this->api->configure($this->configureInput($domain, $csr)));
            $this->options['config']['configured'] = true;
            $this->save_options();

            return true;
        }

        public function cancel(): bool|array
        {
            if (!$this->certificateId() || (int) ($this->config['settings']['revoke_on_cancel'] ?? 0) !== 1) return true;

            $this->initApi();
            try {
                $this->api->revoke($this->remoteId());
            } catch (ApiException $e) {
                // Cancellation must not stall on a package that cannot be revoked.
                if ($e->isUnsupported()) {
                    $this->save_log('revoke-skipped', ['domain' => $this->domain()], $e->getMessage());
                    return true;
                }
                if (!$e->isNotFound()) throw new \Exception($e->getMessage());
            }

            return true;
        }

        public function fetchRemoteStatus(): array
        {
            if (!$this->certificateId()) return [];

            $domain = $this->domain();
            $this->initApi();
            [$id, $detail] = $this->call(function () {
                $id = $this->remoteId();

                return [$id, $this->api->certificate($id)];
            });

            $status  = $this->mapStatus(Normalizer::status($detail));
            $conf    = (array) ($detail['configuration'] ?? []);
            $dcv     = Normalizer::dcv((string) ($conf['dcvDetails'] ?? ''), (string) ($conf['authenticationType'] ?? ''),
                (string) ($this->requirement_params['csr'] ?? ''), $domain);
            $method  = $dcv['method'] ?: Normalizer::dcvFromApi((string) ($conf['authenticationType'] ?? ''));
            $sans    = Normalizer::sanList((string) ($conf['sanList'] ?? '') . ',' . (string) ($conf['wildcardList'] ?? ''), $domain);

            $crt = $ca = '';
            if ($status === 'active') {
                try {
                    [$crt, $ca] = Normalizer::pem($this->api->certificateContent($id));
                } catch (ApiException $e) {
                    // Status is still worth showing without the PEM.
                }
            }

            // The CSR the client gave, or the one the gateway kept (imported certificates).
            $key = Normalizer::keyInfo($crt, (string) ($this->requirement_params['csr'] ?? '') ?: (string) ($conf['csr'] ?? ''));

            return [
                'certificate_id'    => $this->certificateId(),
                'status'            => $status,
                'domain'            => (string) ($detail['domainName'] ?? '') ?: $domain,
                'ssl_type'          => $this->sslType(),
                'sans'              => array_map(fn ($d) => [
                    'name' => $d, 'method' => $method, 'validated' => $status === 'active',
                    'email' => '', 'file' => [], 'dns' => [],
                ], $sans),
                'sans_count'        => count($sans),
                'sans_included'     => $this->includedSans(),
                'sans_addon'        => $this->addonSans(),
                'sans_max'          => (int) ($this->product['module_data']['max_sans'] ?? 0),
                'issued_at'         => $this->cleanDate((string) ($detail['certificate']['issuedAt'] ?? '')),
                'expires_at'        => $this->cleanDate((string) ($detail['certificate']['validUntil'] ?? ($detail['subscription']['expiresAt'] ?? ''))),
                'validation_method' => $method,
                'approver_email'    => $method === 'email' ? ((string) ($conf['approverMail'] ?? '') ?: $dcv['email']) : '',
                'dcv_file'          => $dcv['file'],
                'dcv_dns'           => $dcv['dns'],
                'validation_status' => $status === 'active' ? 'validated' : 'pending',
                'issuer'            => (string) ($detail['packageName'] ?? '') ?: '-',
                'serial_number'     => $this->certificateId() ?: '-',
                'signature_algo'    => $key['signature'] !== '' ? $key['signature'] : '-',
                'key_size'          => $key['bits'],
                'crt_code'          => $crt,
                'ca_code'           => $ca,
                'minor_status'      => (string) ($detail['status'] ?? ''),
            ];
        }

        protected function mapStatus(string $status): string
        {
            return match ($status) {
                'active'  => 'active',
                'revoked' => 'revoked',
                'expired' => 'expired',
                default   => 'pending',
            };
        }

        protected function sslProductOptions(): array
        {
            $options = [];
            foreach ($this->catalogue()->fetchCatalog() as $item)
                $options[$item['code']] = $item['name'] . ($item['vendor'] !== '' ? ' (' . $item['vendor'] . ')' : '');

            return $options;
        }

        protected function apiReissue(string $csr, string $dcv_method, string $approver_email): bool|array
        {
            $domain   = $this->domain();
            $approver = $this->approverMail($domain, $approver_email);

            try {
                $this->initApi();
                $this->api->reissue($this->remoteId(), $csr, $approver);
            } catch (ApiException $e) {
                $this->error = $e->getMessage();
                return false;
            }

            return true;
        }

        protected function apiResendValidation(string $domain = ''): bool|array
        {
            $this->error = $this->lang['not-supported-resend'] ?? 'Domain Name API does not resend validation e-mails on request. Reissue the certificate to start validation again.';
            return false;
        }

        protected function apiRevalidate(string $domain = ''): bool|array
        {
            try {
                $this->initApi();
                $this->api->checkStatus($this->remoteId());
            } catch (ApiException $e) {
                $this->error = $e->getMessage();
                return false;
            }

            return true;
        }

        protected function apiChangeValidationMethod(string $domain, string $method, string $approver = 'admin'): bool|array
        {
            $this->error = $this->lang['not-supported-method'] ?? 'The validation method cannot be changed on an existing order. Reissue the certificate instead.';
            return false;
        }

        public function handle_add_san(): array
        {
            throw new \Exception($this->lang['not-supported-san'] ?? 'Additional domains can only be added when the certificate is ordered.');
        }

        public function handle_remove_san(): array
        {
            throw new \Exception($this->lang['not-supported-san'] ?? 'Additional domains can only be added when the certificate is ordered.');
        }

        protected function initApi(): void
        {
            if ($this->api) return;

            $s = $this->config['settings'] ?? [];
            $this->api = new Client(
                (string) ($s['reseller_id'] ?? ''),
                $this->decode_str((string) ($s['api_key'] ?? ''), 'system'),
                (int) ($s['test_mode'] ?? 0) === 1,
            );
            $this->api->setLogger(fn ($action, $req, $resp) => $this->save_log($action, $req, $resp));
        }

        // --------------------------------------------------------------
        // Helpers
        // --------------------------------------------------------------

        /** Runs a gateway call, turning its exception into the one WISECP reports. */
        private function call(callable $fn): array
        {
            try {
                return $fn();
            } catch (ApiException $e) {
                throw new \Exception($e->getMessage());
            }
        }

        private function configureInput(string $domain, string $csr): array
        {
            $method   = $this->normalizeDcv((string) ($this->requirement_params['dcv_method'] ?? 'email'));
            $approver = $this->approverMail($domain, (string) ($this->requirement_params['verification_email'] ?? 'admin'));
            $sans     = Normalizer::sanList(
                implode("\n", $this->additionalDomains()),
                $domain,
                $this->includedSans() + $this->provisioningAddonSans()
            );

            $input = [
                'certificateId'      => $this->remoteId(),
                'csr'                => $csr,
                'approverMail'       => $approver,
                'authenticationType' => Normalizer::dcvToApi($method),
                'webServerType'      => isset(Normalizer::WEB_SERVERS[(string) ($this->product['module_data']['webserver_type'] ?? '')])
                    ? (string) $this->product['module_data']['webserver_type']
                    : Normalizer::DEFAULT_WEB_SERVER,
                'contact'            => $this->contact(),
            ];
            if ($sans) $input['sanList'] = implode(',', $sans);

            return $input;
        }

        /** "admin" or a full address; e-mail DCV needs an approver even for other methods. */
        private function approverMail(string $domain, string $choice): string
        {
            $base   = preg_replace('/^\*\./', '', $domain);
            $choice = strtolower(trim($choice)) ?: 'admin';

            // Only a mailbox on the certificate's own domain: DCV mail must never be
            // steerable to a third-party inbox.
            if (str_contains($choice, '@'))
                return filter_var($choice, FILTER_VALIDATE_EMAIL) && str_ends_with($choice, '@' . $base) ? $choice : 'admin@' . $base;

            $allowed = ['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster'];
            if (!in_array($choice, $allowed, true)) $choice = 'admin';

            return $choice . '@' . $base;
        }

        private function additionalDomains(): array
        {
            $raw = $this->requirement_params['additional_domains'] ?? [];
            $out = [];

            foreach (is_array($raw) ? $raw : explode("\n", (string) $raw) as $row) {
                $d = is_array($row) ? ($row['domain'] ?? '') : explode(':', trim((string) $row))[0];
                if (trim((string) $d) !== '') $out[] = (string) $d;
            }

            return $out;
        }

        private function contact(): array
        {
            $u       = $this->user;
            $address = (array) ($u['address'] ?? []);
            $phone   = preg_replace('/\D/', '', (string) ($u['phone'] ?? ''));
            $cc      = (string) ($u['phone_country_code'] ?? '');

            // WISECP stores the phone as country code + number in one string when the
            // separate code is absent; a two/three digit prefix is the best split we have.
            if ($cc === '' && strlen($phone) > 10) {
                $cc    = substr($phone, 0, strlen($phone) - 10);
                $phone = substr($phone, -10);
            }

            return [
                'firstName'        => (string) ($u['name'] ?? ''),
                'lastName'         => (string) ($u['surname'] ?? ''),
                'companyName'      => (string) ($u['company_name'] ?? '') ?: trim(($u['name'] ?? '') . ' ' . ($u['surname'] ?? '')),
                'eMail'            => (string) ($u['email'] ?? ''),
                'address'          => trim(str_replace('=', ' ', (string) ($address['address'] ?? ''))),
                'phoneCountryCode' => $cc,
                'phone'            => $phone,
                // Required in practice although Swagger says nullable (OTE, 2026-10-24).
                'faxCountryCode'   => $cc,
                'fax'              => $phone,
                'country'          => strtoupper((string) ($address['country_code'] ?? '')),
                'city'             => (string) ($address['city'] ?? ''),
                'state'            => (string) ($address['counti'] ?? ''),
                'postalCode'       => (string) ($address['zipcode'] ?? ''),
            ];
        }

        private function packageCode(): string
        {
            $md = ($this->options['creation_info'] ?? []) ?: ($this->product['module_data'] ?? []);

            return trim((string) ($this->options['config']['package'] ?? '') ?: (string) ($md['product_id'] ?? ''));
        }

        private function domain(): string
        {
            return (string) ($this->options['config']['domain'] ?? '') ?: $this->certificateDomain();
        }

        private function certificateDomain(): string
        {
            return Normalizer::domain((string) ($this->requirement_params['domain'] ?? $this->options['domain'] ?? ''));
        }

        private function sslType(): string
        {
            $md = $this->product['module_data'] ?? [];
            if (!empty($md['wildcard']))       return 'wc';
            if ((int) ($md['max_sans'] ?? 0))  return 'mdc';

            return strtolower((string) ($md['product_type'] ?? 'dv')) ?: 'dv';
        }

        private function catalogue(): Catalog
        {
            if ($this->catalogue) return $this->catalogue;

            include_once __DIR__ . DS . 'Catalog.php';

            $s            = (array) ($this->config['settings'] ?? []);
            $s['api_key'] = $this->decode_str((string) ($s['api_key'] ?? ''), 'system');

            return $this->catalogue = new Catalog($s);
        }

        private function serviceForDomain(string $domain): int
        {
            $stmt = \WDB::select('id, options')->from('users_products')
                ->where('module', '=', $this->_name, '&&')
                ->where('status', '!=', 'cancelled');
            $rows = $stmt->build() ? \WDB::fetch_assoc() : [];

            foreach ($rows as $row) {
                $options = $row['options'] ? \Utility::jdecode($row['options'], true) : [];
                if (strtolower((string) ($options['config']['domain'] ?? '')) === $domain) return (int) $row['id'];
            }

            return 0;
        }

        private function toDate(string $value, string $fallback): string
        {
            $ts = trim($value) !== '' ? strtotime($value) : false;

            return $ts ? date('Y-m-d H:i:s', $ts) : $fallback;
        }
    }
