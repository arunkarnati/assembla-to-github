<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;
use Github\Exception\MissingArgumentException;
use Symfony\Component\Dotenv\Dotenv;

class Execute
{
    const MILESTONE_MAP = array(
        "3820293"  => 1,
        "12345924" => 2,
        "10195553" => 3,
        "12379264" => 4,
    );
    /** @var Client */
    public $client;
    /** @var array */
    public $assemblaTicketFields;
    /** @var array */
    public $tickets = [];

    /**
     * GitHub authentication is done in the constructor.
     *
     * @param Client|null $client
     */
    public function __construct(Client $client = null)
    {
        $this->client = $client ?: new Client();
        $dotEnv = new Dotenv();
        $dotEnv->load(__DIR__.'/../.env');
        $this->client->authenticate(getenv('GH_PERSONAL_ACCESS_TOKEN'), Client::AUTH_HTTP_PASSWORD);
    }

    public function getAssemblaTicketFields()
    {
        if (!isset($this->assemblaTicketFields)) {
            $this->readDumpFile();
        }

        return $this->assemblaTicketFields;
    }

    public function readDumpFile()
    {
        $arr = file(__DIR__.'/../dump.json');
        foreach ($arr as $value) {
            $parts = explode(',', $value, 2);
            switch ($parts[0]) {
                case 'tickets:fields':
                    $this->assemblaTicketFields = json_decode(trim($parts[1]));
                    break;
                case 'tickets':
                    $data = explode('tickets, ', trim($parts[1]));
                    foreach ($data as $item) {
                        $ticket = array_combine($this->assemblaTicketFields, json_decode($item));
                        if (in_array($ticket['milestone_id'], array_keys(Execute::MILESTONE_MAP))) {
                            $this->tickets[] = $ticket;
                        }
                    }
                    break;
            }
        }
    }

    public function createIssuesOnGitHub()
    {
        if (!$this->getTickets()) {
            $this->readDumpFile();
        }

        $tickets = $this->getTickets();

        foreach ($tickets as $ticket) {
            try {
                $this->client->issues()->create("arunkarnati", "assembla-to-github", [
                    "title"     => $ticket['summary'],
                    "body"      => $ticket['description'],
                    "milestone" => Execute::MILESTONE_MAP[$ticket['milestone_id']],
                ]);
            } catch (MissingArgumentException $e) {
                return $e->getMessage();
            }
        }
    }

    public function getTickets()
    {
        if (!isset($this->tickets)) {
            $this->readDumpFile();
        }

        return $this->tickets;
    }
}
