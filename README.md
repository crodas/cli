cli
===

Simple and silly abstraction on top of symfony/console


How does it work?
-----------------

The main goal is to give a generic and extensible way of registering console applications.

The application itself should like like this (`cli.php`).

```php
<?php
require __DIR__ . '/vendor/autoload.php';

$cli = new crodas\cli\Cli("/tmp/some.cache.tmp");
// the vendors cli
$cli->addDirectory(__DIR__ . '/vendor');
// add my APP directory
$cli->addDirectory(__DIR__ . '/apps');

// run
$cli->main();
```

Then inside `apps/` we could have `apps/cli/foobar.php` and it should look like this:

```php
<?php
namespace myApp\Cli;

/**
 * @Cli("foobar", "some text to describe my app")
 * @Arg('name', OPTIONAL, 'add name')
 * @Option('foobar', VALUE_REQUIRED|VALUE_IS_ARRAY, 'add name')
 */
function foobar_main($input, $output)
{
    $arg = $input->getArgument('name');
    $opt = $input->getOption('foobar');
    $output->writeLn(json_encode(compact('arg', 'opt')));
}

```

Now we can easily do `php cli.php foobar`, `foobar_main` function would be called.

Benefits
--------

1. Console applications are discovered
   - No autoloader needed
   - No conventions to follow
2. It can use some cache function to avoid scanning lots of directories and files everytime.
3. Annotations :-)
4. Plugins support
    1. `@One` or `@Crontab` make sure your command runs just once
