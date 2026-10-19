# Smithy PHP Client Interceptors

Interceptors observe or modify client calls at defined execution stages.
They can also wrap an entire call to add timing, caching, fallback, or
circuit-breaking behavior.

Extend `AbstractInterceptor` and override the hooks needed by the
implementation. The abstract class supplies forwarding defaults for every
hook.

## Registering an Interceptor

Add an interceptor directly to a `ClientConfig`:

```php
$config = $config->withInterceptor(
    new CorrelationIdInterceptor()
);

$client = new WeatherClient($config);
```

`withInterceptor()` appends the interceptor.
`withPrependedInterceptor()` places it at the front of the chain.
`withInterceptors()` replaces the chain.

Reusable client features should register their interceptors through a plugin:

```php
use Smithy\Client\ClientConfig;
use Smithy\Client\Plugin\AbstractPlugin;

final class CorrelationIdPlugin extends AbstractPlugin
{
    public function configure(ClientConfig $config): ClientConfig
    {
        return $config->withInterceptor(
            new CorrelationIdInterceptor()
        );
    }
}
```

Pass the plugin collection when constructing the client:

```php
use Smithy\Client\Plugin\PluginCollection;

$plugins = new PluginCollection(
    new CorrelationIdPlugin()
);

$client = new WeatherClient($config, $plugins);
```

Plugins run during client construction. The resulting interceptor chain is
shared by every operation executed by that client.

## Modifying a Request

Modify hooks return the value passed to the next interceptor and pipeline
stage. This interceptor adds a request header before the retry loop:

```php
use Smithy\Client\Interceptor\AbstractInterceptor;
use Smithy\Client\Interceptor\RequestContext;
use Smithy\Client\Transport\TransportRequestInterface;
use Smithy\Http\Message\HttpTransportRequest;

final class CorrelationIdInterceptor extends AbstractInterceptor
{
    public function modifyBeforeRetryLoop(
        RequestContext $ctx
    ): TransportRequestInterface
    {
        $request = HttpTransportRequest::expect($ctx->request);

        return $request->withRequest(
            $request->request->withHeader(
                'x-correlation-id',
                bin2hex(random_bytes(16))
            )
        );
    }
}
```

Read hooks return `void` and observe the current context:

```php
use Smithy\Client\Interceptor\AbstractInterceptor;
use Smithy\Client\Interceptor\ResponseContext;
use Smithy\Http\Message\HttpTransportResponse;

final class StatusLogger extends AbstractInterceptor
{
    public function readAfterTransmit(ResponseContext $ctx): void
    {
        error_log(sprintf(
            '%s returned HTTP %d',
            $ctx->operation->getName(),
            HttpTransportResponse::expect($ctx->response)
                ->response
                ->getStatusCode()
        ));
    }
}
```

## Modifying Call Configuration

`modifyBeforeCall()` can replace client configuration for one operation
without changing the configuration stored by the client. Hooks run in
interceptor registration order, and each returned configuration is passed to
the next hook.

This example switches the protocol for the current call:

```php
use Smithy\Client\ClientConfig;
use Smithy\Client\Interceptor\AbstractInterceptor;
use Smithy\Client\Interceptor\CallContext;
use Smithy\Protocol\ProtocolInterface;

final class ProtocolOverrideInterceptor extends AbstractInterceptor
{
    public function __construct(
        private readonly ProtocolInterface $protocol
    ) {}

    public function modifyBeforeCall(CallContext $ctx): ClientConfig
    {
        return $ctx->config->withProtocol($this->protocol);
    }
}
```

The returned configuration determines the protocol, transport, endpoint,
retry, authentication, interceptor, and wrapper behavior for the call.
Interceptors added by `modifyBeforeCall()` participate in call wrapping and
pipeline execution, but do not receive `modifyBeforeCall()` during that same
call.

Call-scoped plugins run before `modifyBeforeCall()`. The interceptors present
after those plugins are applied participate in this hook.

## Wrapping a Call

Call wrappers run outside the retry loop and enclose one complete pipeline
execution. Override both wrapper methods to opt in:

```php
use Smithy\Client\Interceptor\AbstractInterceptor;
use Smithy\Client\Interceptor\InputContext;
use Smithy\Core\Serde\SerializableStructInterface;

final class TimingInterceptor extends AbstractInterceptor
{
    public function interceptCalls(): bool
    {
        return true;
    }

    public function interceptCall(
        InputContext $ctx,
        callable $next
    ): SerializableStructInterface
    {
        $started = hrtime(true);

        try {
            return $next($ctx);
        } finally {
            $duration = (hrtime(true) - $started) / 1_000_000;
            error_log(sprintf(
                '%s completed in %.2f ms',
                $ctx->operation->getName(),
                $duration
            ));
        }
    }
}
```

`interceptCalls()` is evaluated when the interceptor chain is built. Return
a stable value from this method.

A wrapper controls whether and how the call continues:

- Call `$next($ctx)` to execute the next wrapper or the client pipeline.
- Return the operation's output type without calling `$next` to
  short-circuit.
- Pass `$ctx->withInput($replacement)` to execute with another input.
- Call `$next` more than once to perform multiple pipeline executions.

Each invocation of `$next` runs a complete execution, including its retry
loop and execution hooks. Repeating a call can duplicate side effects for
non-idempotent operations.

`InputContext::$client` contains the current client while a call wrapper is
running. Re-entering that client starts a new call and applies its wrapper
chain again. Re-entry logic must prevent unintended recursion.

## Ordering

Ordinary read and modify hooks run in interceptor registration order.
Modify hooks pass each returned value to the next interceptor.

Call wrappers nest. The first registered wrapper is the outermost wrapper:

```text
first before
    second before
        client pipeline
    second after
first after
```

Interceptors that return `false` from `interceptCalls()` are excluded from
the wrapper chain. Their read and modify hooks still run at their registered
pipeline stages.

## Lifecycle

| Scope | Hooks | Context | Frequency |
|---|---|---|---|
| Call configuration | `modifyBeforeCall` | `CallContext` | Once per registered interceptor |
| Call wrapping | `interceptCall` | `InputContext` | Once per wrapper entry |
| Execution input | `readBeforeExecution`, `modifyBeforeSerialization`, `readBeforeSerialization` | `InputContext` | At most once per pipeline execution |
| Serialized request | `readAfterSerialization`, `modifyBeforeRetryLoop` | `RequestContext` | At most once per pipeline execution |
| Attempt request | `readBeforeAttempt`, `modifyBeforeSigning`, `readBeforeSigning`, `readAfterSigning`, `modifyBeforeTransmit`, `readBeforeTransmit` | `RequestContext` | At most once per attempt |
| Attempt response | `readAfterTransmit`, `modifyBeforeDeserialization`, `readBeforeDeserialization` | `ResponseContext` | At most once per received response |
| Deserialization completion | `readAfterDeserialization` | `OutputContext` | When deserialization is attempted |
| Attempt completion | `modifyBeforeAttemptCompletion`, `readAfterAttempt` | `OutputContext` | Once per attempt |
| Execution completion | `modifyBeforeCompletion`, `readAfterExecution` | `OutputContext` | Once per pipeline execution |

`modifyBeforeRetryLoop()` runs before the retry loop. Request signing,
transmission, response handling, and attempt completion repeat for each
attempt.

## Contexts

| Context | Available values |
|---|---|
| `CallContext` | Operation, input, and call configuration |
| `InputContext` | Operation, input, client configuration, and current client for call wrappers |
| `RequestContext` | Operation, input, configuration, and request |
| `ResponseContext` | Operation, input, configuration, request, and response |
| `OutputContext` | Operation, input, configuration, nullable request, nullable response, and nullable output |

Call-configuration hooks return a `ClientConfig`. Call wrappers use
`withInput()` to continue with a replacement input. Other modify hooks return
their replacement value. The chain passes each returned value to later
interceptors.

## Errors and Completion

An exception from a modify hook or ordinary read hook stops the current
stage. Execution then proceeds to its enclosing completion hooks.

The execution and attempt boundary read hooks invoke every interceptor before
surfacing the latest error:

- `readBeforeExecution`
- `readBeforeAttempt`
- `readAfterAttempt`
- `readAfterExecution`

Completion modify hooks receive the current error separately from the
`OutputContext`. `AbstractInterceptor` forwards that error by rethrowing it.
An override can return a valid output to recover from the error or throw
another error to replace it.

## Shared Instances

One client reuses its interceptor instances across calls, including
concurrent calls. Store request state in local variables inside
`interceptCall()` or in request-scoped data owned by the application. Shared
mutable properties can mix state between calls.

## Testing

Test an interceptor through `InterceptorChain` when verifying ordering or
value propagation. Test call wrappers through a client when verifying
short-circuiting, input replacement, retries, or transport behavior.

See [Interceptor Design](../../../docs/design/client/interceptor/client-interceptor-design.md)
for lifecycle and error-propagation invariants.

Run the interceptor tests from the package root:

```shell
vendor/bin/phpunit tests/Client/Interceptor
```
