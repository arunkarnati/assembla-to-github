<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;
use Symfony\Component\Dotenv\Dotenv;

class Execute
{
    /** @var Client */
    public $client;

    /** @var array */
    public $assemblaTicketFields;

    const MILESTONE_BACKLOG = 1;
    const MILESTONE_SEO = 2;
    const MILESTONE_TECH_BACKLOG = 3;

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
            $data = file(__DIR__.'/../ticket_fields.txt');
            $this->assemblaTicketFields = $data;
        }
        return $this->assemblaTicketFields;
    }

    public function readFile()
    {
        $data = file(__DIR__.'/../dump.json');

    }

    public function getGitHubMileStoneId(int $milestone)
    {
        switch ($milestone) {
            case '1':
                $id = 1;
                break;
            default:
                $id = 0;
        }
        return $id;
    }

    public function convertTicketDataToArray($ticket)
    {

    }

    public function getRepos()
    {
        return $this->client->currentUser()->repositories();
    }
}
