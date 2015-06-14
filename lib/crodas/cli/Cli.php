<?php
/*
  +---------------------------------------------------------------------------------+
  | Copyright (c) 2013 César Rodas                                                  |
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
use Notoj\Annotations;
use Notoj\Dir;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class Cli
{
    protected $dirs = array();
    protected $cache = null;

    public function __construct($cache = null)
    {
        $this->cache = $cache;
    }

    public function addDirectory($dir)
    {
        $this->dirs[] = $dir;
        return $this;
    }

    public function prepare()
    {
        $dirAnn  = new Dir($this->dirs);
        $Arg     = new ReflectionClass('Symfony\Component\Console\Input\InputArgument');
        $Option  = new ReflectionClass('Symfony\Component\Console\Input\InputOption'); 
        $console = new Application();
        foreach ($dirAnn->getCallable('Cli') as $function) {
            $zargs = [];
            foreach (array('Arg' => 'InputArgument', 'Option' => 'InputOption') as $ann => $class) {
                $class = 'Symfony\Component\Console\Input\\' . $class;
                foreach ($function->get($ann) as $args) {
                    $args = $args->GetArgs();
                    $name = current($args);
                    $flag = NULL;
                    if (!empty($args[1])) {
                        $flag = 0;
                        foreach(explode("|", $args[1]) as $type) {
                            $flag |= $$ann->getConstant($type);
                        }
                    }
                    $hint = empty($args[2]) ? $name : $args[2];

                    if ($ann == 'Arg') {
                        $zargs[] = new $class($name, $flag, $hint);
                    } else {
                        if (!empty($args['default'])) {
                            $zargs[] = new $class($name, null, InputOption::VALUE_OPTIONAL, $hint, $args['default']);
                        } else {
                            $zargs[] = new $class($name, null, $flag, $hint);
                        }
                    }
                }
            }

            foreach ($function->get('Cli') as $annotation) {
                $args = $annotation->GetArgs();
                $name = current($args ?: []);
                $desc = !empty($args[1]) ? $args[1] : '';
                $console->register($name)
                    ->setDescription($desc)
                    ->setDefinition($zargs)
                    ->setCode(function($input, $output) use ($function) {
                        return $function->exec($input, $output);
                    });
            }
        }

        return $console;
    }

    public function main()
    {
        $this->prepare()->run();
    }
}

