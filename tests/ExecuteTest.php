<?php
declare(strict_types=1);

namespace Tests;

use Github\Client;
use Migrator\Execute;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class ExecuteTest extends TestCase
{
    /** @var Execute */
    public $execute;

    public function setUp()
    {
        $logger = new Logger('debug_logger');
        $logger->pushHandler(new StreamHandler(__DIR__.'/../debug.log', Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $this->execute = new Execute(new Client(), $logger);
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
