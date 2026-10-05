# NetSuite PHP

[![Tests](https://github.com/max-dernovyi/netsuite-php/actions/workflows/tests.yml/badge.svg)](https://github.com/max-dernovyi/netsuite-php/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/max-dernovyi/netsuite-php)](https://packagist.org/packages/max-dernovyi/netsuite-php)
[![PHP](https://img.shields.io/packagist/dependency-v/max-dernovyi/netsuite-php/php)](https://packagist.org/packages/max-dernovyi/netsuite-php)
[![License](https://img.shields.io/packagist/l/max-dernovyi/netsuite-php)](#license)

PHP client for NetSuite SuiteTalk web services. Maintained fork of
[netsuitephp/netsuite-php](https://github.com/netsuitephp/netsuite-php) with the same
API and generated `NetSuite\Classes`, PHP 7.4+ support, and an optional REST transport
for the retirement of SOAP web services.

## Installation

```
composer require max-dernovyi/netsuite-php
```

Requires PHP 7.4+ with the `soap`, `simplexml`, `openssl`, `curl` and `json` extensions.

**Migrating from `ryanwinchester/netsuite-php`:** swap the package in `composer.json`;
namespaces, classes and config stay the same. This package replaces
`ryanwinchester/netsuite-php` 2025.2, so wrappers that require it, such as
[netsuite-laravel](https://github.com/netsuitephp/netsuite-laravel), install against it.

## Quickstart

```php
use NetSuite\NetSuiteService;
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;

$service = new NetSuiteService([
    'endpoint'       => '2025_2',
    'host'           => 'https://123456.suitetalk.api.netsuite.com',
    'account'        => '123456',
    'consumerKey'    => '...',
    'consumerSecret' => '...',
    'token'          => '...',
    'tokenSecret'    => '...',
]);

$request = new GetRequest();
$request->baseRef = new RecordRef();
$request->baseRef->type = 'customer';
$request->baseRef->internalId = '1234';

$response = $service->get($request);
if ($response->readResponse->status->isSuccess) {
    $customer = $response->readResponse->record;
}
```

More in [EXAMPLES.md](EXAMPLES.md): search with paging, add, upsert, custom fields,
item fulfillment.

## Configuration

| Key | Required | Notes |
|---|---|---|
| `account` | yes | Account id: `123456`, or `123456_SB1` for a sandbox |
| `consumerKey`, `consumerSecret`, `token`, `tokenSecret` | yes | Token-based authentication (TBA) |
| `endpoint` | SOAP | WSDL version: `2025_2` |
| `host` | SOAP | Account domain: `https://<account>.suitetalk.api.netsuite.com` |
| `signatureAlgorithm` | no | `sha256` (default) |
| `transport` | no | `soap` (default) or `rest`, see [REST transport](#rest-transport) |
| `logging`, `log_path`, `log_format`, `log_dateformat` | no | See [Logging](#logging) |

`new NetSuiteService()` without arguments reads the same settings from `NETSUITE_*`
environment variables; see `.env.example`.

With `host` set to `https://webservices.netsuite.com`, the client first looks up the
account domain, which costs one extra request per service instance.

## REST transport

NetSuite is retiring SOAP web services: no new SOAP integrations from 2027.1, and all
SOAP endpoints are disabled with 2028.2
([Oracle FAQ](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/article_2104046421.html)).
With `transport` set to `rest`, the same calls go to the NetSuite REST Record API and
your code does not change.

```php
$config['transport'] = 'rest';

// Optional: OAuth 2.0 client credentials instead of TBA (all three keys).
$config['oauth2ClientId']      = '...';
$config['oauth2CertificateId'] = '...';
$config['oauth2PrivateKey']    = '/path/to/private.pem'; // PEM contents or a file path
$config['oauth2Algorithm']     = 'PS256';                // or 'ES256'
```

- The REST host is derived from `account`; `endpoint` and `host` are optional.
- `timeout` (seconds, default 60) and `maxAttempts` (default 3) are optional. GET,
  PUT and DELETE are retried; POST and PATCH are not.
- On REST: `get`, `getList`, `add`, `update`, `upsert`, `delete`, `addList`,
  `updateList`, `upsertList`, `deleteList`. Every other operation is sent via SOAP and
  logs the warning `NetSuite REST: operation "<op>" is not supported, sent via SOAP`.
  Without TBA keys there is no SOAP fallback, and
  `NetSuite\Rest\Exception\NotSupportedOnRestException` is thrown.

Differences from SOAP:

- Business errors come back as `status.isSuccess = false`, as with SOAP. Auth,
  throttling and transport failures throw `NetSuite\Rest\Exception\RestFault`, which
  extends `\SoapFault`.
- List operations make one request per record. A fault on the first record is thrown;
  a fault on a later record becomes that record's failed status.
- `getClient()` returns an object with `__getLastRequest()`, `__getLastResponse()`,
  `__getLastRequestHeaders()` and `__getLastResponseHeaders()` for the last call,
  with `Authorization` redacted.
- Preferences, search preferences, application info and custom headers apply only to
  SOAP calls. `deletionReason` is ignored.
- External ids may contain only letters, digits, `_` and `-`. Custom records need
  their `customrecord_*` script id.

## Logging

With `logging` on and `log_path` set, each request and response is written to a file
named by `log_format` (tokens `%date` and `%operation`) and `log_dateformat`:

```php
$service->setLogPath('/var/log/netsuite');
$service->logRequests(true);
```

To use your own PSR-3 logger, pass it as the fourth constructor argument:
`new NetSuiteService($config, [], null, $logger)`. REST exchanges are logged one entry
each, with credentials redacted; the SOAP-fallback warning goes to the same logger.

## Development

Tests run in Docker, no local PHP needed (`PHP` defaults to 8.5):

```
make test PHP=7.4    # PHPUnit and phpspec
make lint PHP=7.4
make coverage
```

CI runs the suite on PHP 7.4–8.5. The `parity` group compares SOAP and REST responses
on a real account and is skipped unless `NETSUITE_PARITY_ACCOUNT` and the TBA env keys
are set; see `tests/Parity/`.

`NetSuite\Classes` and `NetSuiteService` are generated from the NetSuite PHP Toolkit
(`composer generate`, see `utilities/`). 2025.2 is NetSuite's last planned SOAP
endpoint.

## Contributing

Issues and pull requests are welcome. Keep PHP 7.4 compatibility, add no runtime
dependencies, and follow [PSR-12](https://www.php-fig.org/psr/psr-12/).

## License

The generated code (`NetSuite\Classes`, `NetSuiteService`) comes from the NetSuite PHP
Toolkit, © NetSuite Inc., under the
[NetSuite Application Developer License Agreement](original/NetSuite%20Application%20Developer%20License%20Agreement.txt).
Everything else is [Apache-2.0](LICENSE.txt).

NetSuite is a trademark of Oracle. This project is not affiliated with or endorsed by
Oracle.
