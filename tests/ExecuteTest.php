<?php
declare(strict_types=1);

namespace Tests;

use Github\Client;
use Migrator\Execute;
use PHPUnit\Framework\TestCase;

class ExecuteTest extends TestCase
{
    /** @var Execute */
    public $execute;

    public function setUp()
    {
        $this->execute = new Execute();
    }

    public function testGetRepos()
    {
        print_r($this->execute->getRepos());
    }
}
