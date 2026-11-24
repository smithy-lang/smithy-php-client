# Smithy PHP Client Plugins

Plugins package reusable changes to an immutable `AbstractClientConfig`. They can
replace client dependencies, register interceptors, contribute child plugins,
and declare when they run relative to other plugins.

Extend `AbstractPlugin` and override `configure()`:

```php
use Smithy\Client\AbstractClientConfig;
use Smithy\Client\Plugin\AbstractPlugin;

final class UserAgentPlugin extends AbstractPlugin
{
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        return $config->withInterceptor(
            new UserAgentInterceptor()
        );
    }
}
```

## Registering Plugins

Pass a collection to the client constructor:

```php
use Smithy\Client\Plugin\PluginCollection;

$client = new WeatherClient(
    $config,
    new PluginCollection(
        new UserAgentPlugin()
    )
);
```

Plugins can also be registered directly on a configuration:

```php
$config = $config
    ->withPlugin(new UserAgentPlugin())
    ->withPlugin(new MetricsPlugin());
```

Registration returns a new configuration without applying the plugin.
Generated and dynamic clients attach their service metadata and resolve all
registered plugins once during construction. This allows plugins to inspect
the same service schema in either client projection.

## Automatic Plugins

Packages can apply a plugin automatically by implementing
`AutoPluginInterface` and registering the class through
`ComposerAutoPluginRegistry`.

Automatic plugins must have a public constructor with no required arguments:

```php
use Smithy\Client\AbstractClientConfig;
use Smithy\Client\Plugin\{
    AbstractPlugin,
    AutoPluginInterface
};

final class TracingPlugin extends AbstractPlugin implements
    AutoPluginInterface
{
    public function configure(AbstractClientConfig $config): AbstractClientConfig
    {
        return $config->withInterceptor(
            new TracingInterceptor()
        );
    }
}
```

Register the class from a package file:

```php
use Smithy\Client\Plugin\ComposerAutoPluginRegistry;

ComposerAutoPluginRegistry::register(TracingPlugin::class);
```

List that registration file under the package's Composer `autoload.files`:

```json
{
    "autoload": {
        "psr-4": {
            "Vendor\\Tracing\\": "src/"
        },
        "files": [
            "src/register-smithy-plugins.php"
        ]
    }
}
```

Composer executes the registration file when its autoloader is initialized.
Registered plugin classes are instantiated once per process. The shared
instances are resolved after each new client attaches its service metadata.

Automatic plugins are children of the framework's default plugin. They use
the same phases, predicates, concrete-class deduplication, and persisted state
as explicit plugins. Within one phase, an automatically registered plugin is
considered before user plugins and generated defaults, so its instance wins a
concrete-class duplicate.

Disable automatic plugins before constructing a client:

```php
$config = $config->withoutAutoPlugins();
```

Rejecting the automatic-plugin parent prevents its registered children from
being instantiated or applied. Plugins already applied to a configuration
cannot be removed by deriving another configuration.

## Ordering

Plugins are ordered by `PluginPhase`, then by registration order within the
same phase. `AbstractPlugin` uses `PluginPhase::Apply` by default.

```php
use Smithy\Client\Plugin\PluginPhase;

public function getPluginPhase(): PluginPhase
{
    return PluginPhase::BeforeApply;
}
```

The phases run in this order:

1. `First`
2. `BeforeDefaults`
3. `Defaults`
4. `AfterDefaults`
5. `BeforeApply`
6. `Apply`
7. `AfterApply`
8. `Last`

Framework and automatic plugins are registered first, followed by user plugins
and shared modeled-behavior defaults. Within one phase, this means framework
and automatic behavior is applied first. A user plugin wins a concrete-class
duplicate with a modeled-behavior default.

The first registered instance of each concrete plugin class wins. A plugin
class is applied at most once as a configuration is copied or reused.

## Child Plugins

A plugin can contribute other plugins:

```php
public function getChildPlugins(): array
{
    return [
        new SigningPlugin(),
        new UserAgentPlugin()
    ];
}
```

The parent predicate is evaluated before its children are visited. Each child
is then evaluated independently and ordered according to its own phase.
Concrete-class deduplication applies across top-level and child plugins.

## Selecting Plugins

A configuration can filter registered plugins:

```php
use Smithy\Client\Plugin\PluginInterface;

$config = $config->withPluginPredicate(
    static fn(PluginInterface $plugin): bool =>
        !$plugin instanceof MetricsPlugin
);
```

`withPluginPredicate()` replaces the predicate.
`withAddedPluginPredicate()` combines another condition with the existing
predicate. Already applied plugins remain applied. Registered plugins that
were previously rejected are reconsidered when the predicate changes.

## Call-Scoped Plugins

Plugins can be applied to one operation through `RequestOverrideConfig`:

```php
use Smithy\Client\RequestOverrideConfig;

$overrides = RequestOverrideConfig::fromArray([])
    ->withPlugin(new AlternateProtocolPlugin());

$client->getForecast($input, $overrides);
```

Call-scoped plugins begin with the client's resolved plugin state, so a
construction plugin is not applied again. They produce a call-specific
configuration and do not change the client configuration used by later
calls.

Call-scoped plugins run before interceptor `modifyBeforeCall()` hooks. Either
mechanism can replace the protocol, transport, endpoint resolver, retry
strategy, authentication behavior, or interceptor chain for that call.

## Implementing the Interface Directly

`AbstractPlugin` supplies an empty child list and the `Apply` phase.
Implement `PluginInterface` directly only when explicit implementations of
all three methods are useful:

```php
public function configure(AbstractClientConfig $config): AbstractClientConfig;

public function getChildPlugins(): array;

public function getPluginPhase(): PluginPhase;
```

Plugin instances can be shared by clients and calls. Implementations should
be immutable or otherwise safe for concurrent use.

## Design

See [Plugin Design](https://github.com/smithy-lang/smithy-php/blob/main/docs/design/client/plugin/client-plugin-design.md).
