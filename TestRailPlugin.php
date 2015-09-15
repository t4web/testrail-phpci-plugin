<?php
namespace t4web\PhpciPlugins;

use PHPCI\Plugin;

class TestRailPlugin implements Plugin
{
    public function execute()
    {
        $this->phpci->executeCommand('ls -la');
    }
} 