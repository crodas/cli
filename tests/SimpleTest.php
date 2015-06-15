<?php

use crodas\cli\Cli;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

class SimpleTest extends PHPUnit_Framework_Testcase
{
    public static function app()
    {
        $x = new Cli;
        $x->addDirectory(__DIR__);
        $console = $x->prepare();
        return array(
            array($console, $console->all())
        );
    }

    /**
     * @dataProvider app
     */
    public function testSetup($app, Array $commands)
    {
        $text = $app->asText();
        $this->assertTrue(count($commands) >= 3);
        $this->assertTrue($app->find('something:group') instanceof Symfony\Component\Console\Command\Command);
        $this->assertEquals($app->find('s:g'), $app->find('something:group'));
        $this->assertTrue(strpos($text, '<comment>something</comment>') > 0);
        $this->assertTrue(strpos($text, 'Let find out') > 0);
    }

    /**
     *  @dataProvider app
     */
    public function testExecute($app)
    {
        $input = new ArgvInput(array(__FILE__, 's:g'));
        $output = new ConsoleOutput();
        $app->find('s:g')->run($input, $output);
    }

    /**
     *  @dataProvider app
     */
    public function testAppWithArgs($app)
    {
        $argv   = uniqid(true);
        $tmp    = tmpfile();
        $input  = new ArgvInput(array(__FILE__, 's:w', $argv));
        $output = new StreamOutput($tmp);
        $app->find('s:w')->run($input, $output);
        fseek($tmp, SEEK_SET, 0);
        $this->assertEquals($argv, trim(fread($tmp, 8096)));
    }

    /**
     *  @dataProvider app
     */
    public function testAppWithArgsAndOptions($app)
    {
        $argv   = uniqid(true);
        $tmp    = tmpfile();
        $input  = new ArgvInput(array(__FILE__, 's:w', $argv, '--foobar', 'one', '--foobar', 'two'));
        $output = new StreamOutput($tmp);
        $app->find('s:w')->run($input, $output);
        fseek($tmp, SEEK_SET, 0);
        $output  = "foobar:one\nfoobar:two\n$argv";
        $this->assertEquals($output, trim(fread($tmp, 8096)));
    }

    /**
     *  @dataProvider app
     *  @expectedException RuntimeException
     */
    public function testAppWithArgsExceptionOptionNoValue($app)
    {
        $input = new ArgvInput(array(__FILE__, 's:w', 'xxx', '--foobar'));
        $output = new ConsoleOutput();
        $app->find('s:w')->run($input, $output);
    }

    /**
     *  @dataProvider app
     *  @expectedException RuntimeException
     */
    public function testAppWithArgsExceptionRubbishOption($app)
    {
        $input = new ArgvInput(array(__FILE__, 's:w', 'xxx', '--' . uniqid(true)));
        $output = new ConsoleOutput();
        $app->find('s:w')->run($input, $output);
    }

    /**
     *  @dataProvider app
     *  @expectedException RuntimeException
     */
    public function testAppWithArgsTooMuchArgs($app)
    {
        $input = new ArgvInput(array(__FILE__, 's:w', uniqid(true), uniqid(true)));
        $output = new ConsoleOutput();
        $app->find('s:w')->run($input, $output);
    }

    /**
     *  @dataProvider app
     *  @expectedException RuntimeException
     */
    public function testAppWithArgsExceptionn($app)
    {
        $input = new ArgvInput(array(__FILE__, 's:w'));
        $output = new ConsoleOutput();
        $app->find('s:w')->run($input, $output);
    }

    /**
     *  @dataProvider app
     */
    public function testOne($app)
    {
        $input = new ArgvInput(array(__FILE__, 'crontab:task1'));
        $output = new ConsoleOutput();
        ob_start();
        $app->find('crontab:task1')->run($input, $output);
        $this->assertEquals("Just run once\n", ob_get_clean());
    }

    /**
     *  @dataProvider app
     *  @dependsOn testOne
     *  @expectedException RuntimeException
     */
    public function testOneFailure($app)
    {
        $input = new ArgvInput(array(__FILE__, 'crontab:task1'));
        $output = new ConsoleOutput();
        $app->find('crontab:task1')->run($input, $output);
    }

    /**
     *  @Cli("crontab:task1")
     *  @One
     */
    public static function _callable_just_once($input, $output)
    {
        echo "Just run once\n";
    }

    /**
     *  @Cli("something:with_args")
     *  @Arg("arg1", REQUIRED)
     *  @Option('foobar', VALUE_REQUIRED|VALUE_IS_ARRAY, default=[])
     */
    public static function callable_with_args(InputInterface $input, OutputInterface $output)
    {
        $data = $input->getArgument('arg1');
        $foobar = $input->getOption('foobar');
        foreach ($foobar as $val) {
            $output->writeLn("foobar:$val");
        }
        $output->writeLn("<error>$data</error>");
    }

    /**
     *  @Cli("something:group", "Let find out")
     */
    public function callable_fnc($input, $output)
    {
        $this->assertTrue($input instanceof InputInterface);
        $this->assertTrue($output instanceof OutputInterface);
    }
}
