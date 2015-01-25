<?php

/** 
 *  @Cli("foobar", "foobar this is foobar") 
 *  @Arg('name', OPTIONAL, 'add name')
 *  @Option('foobar', VALUE_REQUIRED|VALUE_IS_ARRAY, default='add name')
 */
function cli_foo($input, $output)
{
    $arg = $input->getArgument('name');
    $opt = $input->getOption('foobar');

    $output->writeLn(json_encode(compact('arg', 'opt')));
}
