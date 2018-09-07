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
use Notoj\TObject\zCallable;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Remember\Remember;

class Cli
{
    protected $plugins = array();
    protected $dirs = array();
    protected $cache = null;

    public function __construct()
    {
        $this->dirs  = array(__DIR__);
    }

    public function addDirectory($dir)
    {
        $this->dirs[] = $dir;
        return $this;
    }

    protected function processPrompt(Array &$opts, zCallable $function)
    {
        $questions = array();
        foreach ($function->get('prompt') as $opt) {
            $type = $opt->getArg();
            $text = $opt->getArg(1);
            $args = $opt->getArgs();
            $question = new Question($text . ': ');
            if (!empty($args['secret'])) {
                $question->setHidden(true);
            }
            if (!empty($args['validate'])) {
                $question->validate = constant($args['validate']);
            }
            $opts[] = new InputOption($type, null, InputOption::VALUE_REQUIRED, $text);
            $questions[$type] = $question;
        }

        $function->questions = $questions;
    }

    protected function processCommandArgs(Array &$opts, $ann, ReflectionClass $reflection, zCallable $function)
    { 
        $annotation = substr($ann, 0, 3) . "," . $ann;
        foreach ($function->get($annotation) as $opt) {
            $args = $opt->getArgs();
            $name = $args[0];
            $hint = empty($args[2]) ? $name : $args[2];
            $flag = null;

            if (!empty($args[1])) {
                $settings  = array_map('trim', explode("|", trim($args[1])));
                $constants = $reflection->getConstants();
                if (count($settings) == 1 && empty($constants[$settings[0]]) && count($args) == 2) {
                    $hint = $settings[0];
                } else {
                    $flag = 0;
                    foreach($settings as $type) {
                        $type = strtoupper(trim($type));
                        if (empty($constants[$type])) {
                            throw new InvalidArgumentException("$type is not a valid constant (it can be " . implode(",", $constants) . ")"); 
                        }
                        $flag |= $constants[$type];
                    }
                }
            }

            if ($ann == 'Argument') {
                $cArgs = array($name, $flag, $hint);
            } else if (array_key_exists('default', $args)) {
                $cArgs = array($name, null, $flag | InputOption::VALUE_OPTIONAL, $hint, $args['default']);
            } else {
                $cArgs = array($name, null, $flag,  $hint);
            }
            $opts[] = $reflection->newInstanceArgs($cArgs);
        }
    }

    public function fork(zCallable $function, $input, $output)
    {
        $workers = max((int)$input->getOption('workers'), 1);
        $pids = array();
        for ($i = 0; $i < $workers; ++$i) {
            $pid = pcntl_fork();
            if ($pid > 0) {
                $pids[] = $pid;
            } else {
                return $function->exec($input, $output);
            }
        }

        while (count($pids) > 0) {
            $pid = pcntl_wait($status, WNOHANG);
            array_splice($pids, array_search($pid, $pids), 1);
            if ($function->has('respawn')) {
                $pid = pcntl_fork();
                if ($pid > 0) {
                    $pids[] = $pid;
                } else {
                    return $function->exec($input, $output);
                }
            }
        }

        exit;
    }

    protected function wrapper(zCallable $function, $helper)
    {
        $self = $this;
        return function($input, $output) use ($function, $self, $helper) {
            foreach ($self->getPlugins() as $name => $callbacks) {
                if ($function->has($name)) {
                    foreach ($callbacks as $callback) {
                        $callback->exec($function, $input, $output);
                    }
                }
            }

            foreach ($function->questions as $type => $question) {
                $value = $input->getOption($type);
                if (!$value) {
                    $value = $helper->ask($input, $output, $question);
                    $input->setOption($type, $value);
                }
                if (!empty($question->validate) && !filter_var($value, $question->validate)) {
                    throw new InvalidArgumentException("$value is not a valid $type");
                }
            }
            
            if ($function->has('spawn,spawnable')) {
                return $this->fork($function, $input, $output);
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

            $question = $app->getHelperSet()->get('question');

            $app->register($args[0])
                ->setDescription(!empty($args[1]) ? $args[1] : $args[0])
                ->setDefinition($opts)
                ->setCode($this->wrapper($function, $question));
        }
    }

    public function getPlugins()
    {
        return $this->plugins;
    }

    protected function processWorker(Array & $opts, $function)
    {
        if (!$function->has('spawnable,spawn')) {
            return false;
        }

        $opts[] = new InputOption('workers', 'w', InputOption::VALUE_REQUIRED, "Spawn the commands many times", 1);
    }

    public function prepare()
    {
        $loader = Remember::wrap('cli', function($args, $files) {
            $dirAnn  = new Dir($args);

            return array(
               $dirAnn->getCallable('cli'),
               $dirAnn->get('CliPlugin', 'Callable'),
            );
        });
        
        $Arg     = new ReflectionClass('Symfony\Component\Console\Input\InputArgument');
        $Option  = new ReflectionClass('Symfony\Component\Console\Input\InputOption'); 
        $console = new Application();

        list($functions, $plugins) = $loader($this->dirs);

        foreach ($plugins as $plugin) {
            $name = current($plugin->getArgs());
            $this->plugins[$name][] = $plugin->getObject();
        }

        foreach ($functions as $function) {
            $opts = array();
            $this->processCommandArgs($opts, 'Argument', $Arg, $function);
            $this->processCommandArgs($opts, 'Option', $Option, $function);
            $this->processWorker($opts, $function);
            $this->processPrompt($opts, $function);

            $this->registerCommand($console, $function, $opts);
        }
    
        return $console;
    }

    public function main()
    {
        $this->prepare()->run();
    }
}

