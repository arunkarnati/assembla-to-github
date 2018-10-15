<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;
use Symfony\Component\Dotenv\Dotenv;

class Execute
{
    /** @var Client */
    public $client;

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

    public function readFile()
    {
        print_r(file(__DIR__.'/../dump.json'));
    }

    public function getRepos()
    {
        return $this->client->currentUser()->repositories();
    }
}
