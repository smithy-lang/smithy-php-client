# Smithy PHP Client

Schema-driven client runtime for generated and runtime-loaded Smithy services.
Requires PHP 8.2 or later.

## Installation

```shell
composer require smithy/client
```

## Client Execution

Generated service clients extend `AbstractClient`. Each generated operation
method delegates to `executeOperation()` with operation metadata and a typed
input shape.

```php
use Smithy\Client\AbstractClient;

final class WeatherClient extends AbstractClient
{
    public function getForecast(
        GetForecastInput $input
    ): GetForecastOutput {
        return $this->executeOperation(
            GetForecastOperation::instance(),
            $input,
        );
    }
}
```

`ClientPipeline` resolves an endpoint, serializes the request, selects an
authentication scheme, transmits through the configured HTTP client, applies
retry decisions, and deserializes the response. Interceptors run at the
[documented pipeline stages](Interceptor/README.md#lifecycle).

## Configuration

`ClientConfig` holds the runtime dependencies for one client:

- protocol implementation
- HTTP client
- endpoint resolver
- protocol resolver and optional explicit protocol selection
- authentication schemes and identity resolvers
- retry strategy
- interceptors and plugin state

Configuration objects are immutable. The `withXxx()` methods return a copy
with one dependency replaced.

## Runtime Features

| Namespace | Responsibility |
|---|---|
| `Smithy\Client\Auth` | Authentication selection, identities, and signers |
| `Smithy\Client\Endpoint` | Static and bytecode endpoint resolvers; the resolved `Endpoint` value lives in `Smithy\Core\Endpoint` |
| [`Smithy\Client\Interceptor`](Interceptor/README.md) | Request pipeline hooks and contexts |
| `Smithy\Client\Pagination` | Schema-driven paginators |
| [`Smithy\Client\Plugin`](Plugin/README.md) | Reusable client configuration changes |
| `Smithy\Client\Retry` | Retry strategies and request flags |
| `Smithy\Client\Waiter` | Modeled polling and acceptor evaluation |

## Dynamic Clients

`DynamicClient` loads a Smithy JSON AST at runtime and invokes operations by
name using document-backed inputs and outputs. This example uses a model with
the built-in `smithy.protocols#rpcv2Cbor` protocol.

```php
use Smithy\Client\Dynamic\DynamicClient;

$client = DynamicClient::create(
    model: __DIR__ . '/weather.json',
    endpoint: 'http://localhost:8080',
);

$output = $client->call('GetForecast', [
    'city' => 'Raleigh',
]);
```

Modeled paginated operations return a lazy paginator that yields one
`StructDocument` per response page:

```php
$pages = $client->paginate('ListForecasts', [
    'city' => 'Raleigh',
    'pageSize' => 25,
]);

// Equivalent to the generated-client naming convention.
$pages = $client->listForecastsPaginated([
    'city' => 'Raleigh',
    'pageSize' => 25,
]);

foreach ($pages as $page) {
    $forecasts = $page->getMember('forecasts')?->asList() ?? [];
}
```

Modeled waiters are available by their Smithy waiter name. The result retains
the output or modeled error that satisfied the success acceptor:

```php
$result = $client->waitUntil(
    waiterName: 'ForecastReady',
    input: ['city' => 'Raleigh'],
    maxWaitTime: 60.0,
);

$result = $client->waitUntilForecastReady(
    ['city' => 'Raleigh'],
    60.0,
);

$forecast = $result->output;
```

Generated and dynamic clients use the same protocol resolver. It selects the
first registered implementation modeled as a trait on the service schema,
unless the caller explicitly chooses a protocol implementation or shape ID.
Provider subclasses add protocols, authentication schemes, endpoint
functions, and schema traits through an internal `DynamicClientRuntime`.

## Dependencies

This package builds on `smithy/async`, `smithy/core`, `smithy/http`,
`smithy/protocol`, and `psr/http-message`. Waiter path expressions use
`mtdowling/jmespath.php`.

## Design

See [Client Runtime Design](../../docs/design/client/client-runtime-design.md).

## Contributing

Run the component tests from the monorepo root:

```shell
vendor/bin/phpunit tests/Client
```
