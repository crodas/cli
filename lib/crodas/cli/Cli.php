<?php
/*
  +---------------------------------------------------------------------------------+
  | Copyright (c) 2015 César Rodas                                                  |
  +---------------------------------------------------------------------------------+
  | Redistribution and use in source and binary forms, with or without              |
  | modification, are permitted provided that the following conditions are met:     |
  | 1. Redistributions of source code must retain the above copyright               |
  |    notice, this list of conditions and the following disclaimer.                |
  |                                                                                 |
  | 2. Redistributions in binary form must reproduce the above copyright            |
  |    notice, this list of conditions and the following disclaimer in the          |
  |    documentation and/or other materials provided with the distribution.         |
  |                                                                                 |
  | 3. All advertising materials mentioning features or use of this software        |
  |    must display the following acknowledgement:                                  |
  |    This product includes software developed by César D. Rodas.                  |
  |                                                                                 |
  | 4. Neither the name of the César D. Rodas nor the                               |
  |    names of its contributors may be used to endorse or promote products         |
  |    derived from this software without specific prior written permission.        |
  |                                                                                 |
  | THIS SOFTWARE IS PROVIDED BY CÉSAR D. RODAS ''AS IS'' AND ANY                   |
  | EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED       |
  | WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE          |
  | DISCLAIMED. IN NO EVENT SHALL CÉSAR D. RODAS BE LIABLE FOR ANY                  |
  | DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES      |
  | (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;    |
  | LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND     |
  | ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT      |
  | (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS   |
  | SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE                     |
  +---------------------------------------------------------------------------------+
  | Authors: César Rodas <crodas@php.net>                                           |
  +---------------------------------------------------------------------------------+
*/
namespace crodas\cli;

use ReflectionClass;
use InvalidArgumentException;
use Notoj\Annotations;
use Notoj\Dir;
use Notoj\Object\zCallable;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class Cli
{
    protected $plugins = array();
    protected $dirs = array();
    protected $cache = null;

    public function __construct($cache = null)
    {
        $this->cache = $cache;
        $this->dirs  = array(__DIR__);
    }

    public function addDirectory($dir)
    {
        $this->dirs[] = $dir;
        return $this;
    }

    protected function processCommandArgs(Array &$opts, $ann, ReflectionClass $reflection, zCallable $function)
    { 
        foreach ($function->get($ann) as $opt) {
            $args = array_values($opt->GetArgs());
            $name = $args[0];
            $hint = empty($args[2]) ? $name : $args[2];
            $flag = null;

            if (!empty($args[1])) {
                $flag      = 0;
                $constants = $reflection->getConstants();
                foreach(explode("|", $args[1]) as $type) {
                    $type = strtoupper(trim($type));
                    if (empty($type)) {
                        throw new InvalidArgumentException("$type is not a valid constant (it can be " . implode(",", $constants) . ")"); 
                    }
                    $flag |= $constants[$type];
                }
            }

            if ($ann == 'Arg') {
                $cArgs = array($name, $flag, $hint);
            } else if (array_key_exists('default', $args)) {
                $cArgs = array($name, null, $flag | Input::VALUE_OPTIONAL, $hint, $args['default']);
            } else {
                $cArgs = array($name, null, $flag,  $hint);
            }
            $opts[] = $reflection->newInstanceArgs($cArgs);
        }
    }

    protected function wrapper(zCallable $function)
    {
        $self = $this;
        return function($input, $output) use ($function, $self) {
            foreach ($self->getPlugins() as $name => $callbacks) {
                if ($function->has($name)) {
                    foreach ($callbacks as $callback) {
                        $callback->exec($function, $input, $output);
                    }
                }
            }
            return $function->exec($input, $output);
        };
    }

    protected function registerCommand(Application $app, zCallable $function, Array $opts)
    {
        foreach ($function->get('cli') as $ann) {
            $args = array_values($ann->getArgs());
            if (empty($args)) {
                continue;
            }
            $app->register($args[0])
                ->setDescription(!empty($args[1]) ? $args[1] : $args[0])
                ->setDefinition($opts)
                ->setCode($this->wrapper($function));
        }
    }

    public function getPlugins()
    {
        return $this->plugins;
    }

    protected function loadPlugins(Dir $dir)
    {
        foreach ($dir->get('CliPlugin', 'Callable') as $plugin) {
            $name = current($plugin->getArgs());
            $this->plugins[$name][] = $plugin->getObject();
        }
    }

    public function prepare()
    {
        $dirAnn  = new Dir($this->dirs);
        $Arg     = new ReflectionClass('Symfony\Component\Console\Input\InputArgument');
        $Option  = new ReflectionClass('Symfony\Component\Console\Input\InputOption'); 
        $console = new Application();

        $this->loadPlugins($dirAnn);

        foreach ($dirAnn->getCallable('cli') as $function) {
            $opts = [];
            $this->processCommandArgs($opts, 'Arg', $Arg, $function);
            $this->processCommandArgs($opts, 'Option', $Option, $function);

            $this->registerCommand($console, $function, $opts);
        }

        return $console;
    }

    public function main()
    {
        $this->prepare()->run();
    }
}

