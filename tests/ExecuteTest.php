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

    public function testReadDumpFile()
    {
        $this->execute->readDumpFile();
    }

    public function testGetTickets()
    {
        print_r($this->execute->getTickets());
    }

    public function testCreateIssuesOnGitHub()
    {
        print_r($this->execute->createIssuesOnGitHub());
    }
}
