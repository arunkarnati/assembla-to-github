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
        $this->execute = new Execute(new Client());
    }

    public function testReadDumpFile()
    {
        print_r($this->execute->readDumpFile());
    }

    public function testGetMilestones()
    {
        print_r($this->execute->getMilestones());
    }

    public function testGetTickets()
    {
        print_r($this->execute->getTickets());
    }

    public function testCreateIssuesOnGitHub()
    {
        $this->execute->createIssuesOnGitHub();
    }

    public function testGetRateLimit()
    {
        print_r($this->execute->getRateLimit());
    }

    public function testReplaceUrls()
    {
        $subject = '[[url:http:\/\/www.examiner.com\/article\/jia-jia-the-robot-eerily-human-like-creation-is-jaw-dropping-conversationalist|YouTube Embeds]]';
        print_r($this->execute->replaceUrls($subject));
    }
}
