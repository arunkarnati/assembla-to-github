<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;

class Execute
{
    /** @var Client */
    public $client;

    public function __construct(Client $client = null)
    {
        $this->client = $client ?: new Client();
        $this->client->authenticate("arunkarnati", "xxx", Client::AUTH_HTTP_PASSWORD);
    }

    public function getRepos()
    {
        return $this->client->currentUser()->repositories();
    }
}
