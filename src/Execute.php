<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;
use Github\Exception\MissingArgumentException;
use Symfony\Component\Dotenv\Dotenv;

class Execute
{
    /**
     * Map with assembla milestones as keys and github milestones as values.
     */
    const MILESTONE_MAP = array(
        "3820293"  => 1, // Backlog
        "12345924" => 2, // SEO
        "10195553" => 3, // Tech Backlog
        "12379264" => 4, // Sprint - Biryani
    );
    /** @var string - used in the assembla ticket link */
    const ASSEMBLA_WORKSPACE = 'crowd-fusion-tmz';
    /** @var string */
    const DUMP_FILE_NAME = 'dump.json';
    /** @var Client */
    public $client;
    /** @var array */
    public $assemblaTicketFields;
    /** @var array */
    public $assemblaCommentFields;
    /** @var array */
    public $tickets;

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

    /**
     * Reads the ticket:fields from dump file to an array
     *
     * @return array
     */
    public function getAssemblaTicketFields()
    {
        if (!isset($this->assemblaTicketFields)) {
            $this->readDumpFile();
        }

        return $this->assemblaTicketFields;
    }

    /**
     * read the dump and save tickets and its fields to class members
     */
    public function readDumpFile()
    {
        $arr = file(__DIR__.'/../'.Execute::DUMP_FILE_NAME);
        foreach ($arr as $value) {
            $parts = explode(',', $value, 2);
            switch ($parts[0]) {
                case 'tickets:fields':
                    $this->assemblaTicketFields = json_decode(trim($parts[1]));
                    break;
                case 'ticket_comments:fields':
                    $this->assemblaCommentFields = json_decode(trim($parts[1]));
                    break;
                case 'tickets':
                    $data = explode('tickets, ', trim($parts[1]));
                    $ticket = array_combine($this->assemblaTicketFields, json_decode($data[0]));
                    if (in_array($ticket['milestone_id'], array_keys(Execute::MILESTONE_MAP))) {
                        $this->tickets[$ticket['id']] = $ticket;
                    }
                    break;
                case 'ticket_comments':
                    $data = explode('ticket_comments, ', trim($parts[1]));
                    $comment = array_combine($this->assemblaCommentFields, json_decode($data[0]));
                    // only add comments of tickets in the milestone set and that is not a code commit comment
                    if ($comment['comment'] !== '' && strpos($comment['comment'],
                            '[[r:3:') === false && isset($this->tickets[$comment['ticket_id']])) {
                        $this->tickets[$comment['ticket_id']]['comments'][] = $comment;
                    }
                    break;
            }
        }
    }

    /**
     * creates issues on GitHub using API and saves to ticket map
     *
     * @return array|string - An array with issue information or an exception message
     */
    public function createIssuesOnGitHub()
    {
        if (!$this->getTickets()) {
            $this->readDumpFile();
        }

        $tickets = $this->getTickets();
        $response = '';

        foreach ($tickets as $ticket) {
            $description = $ticket['description'].'<br /><br />Assembla Ticket Link: https://app.assembla.com/spaces/'.Execute::ASSEMBLA_WORKSPACE.'/tickets/realtime_cardwall?ticket='.$ticket['number'];

            try {
                $response = $this->client->issues()->create(getenv('GH_USERNAME'), getenv('GH_REPO'), [
                    "title"     => $ticket['summary'],
                    "body"      => $description,
                    "milestone" => Execute::MILESTONE_MAP[$ticket['milestone_id']],
                ]);

                if (isset($ticket['comments']) && isset($response['number'])) {
                    foreach ($ticket['comments'] as $comment) {
                        $this->addCommentToIssue($response['number'], $comment['comment']);
                    }
                }
            } catch (MissingArgumentException $e) {
                return $e->getMessage();
            }
        }

        return $response;
    }

    public function getTickets()
    {
        if (!isset($this->tickets)) {
            $this->readDumpFile();
        }

        return $this->tickets;
    }

    /**
     * @param int    $issueNumber
     * @param string $comment
     *
     * @return array|string
     */
    public function addCommentToIssue(int $issueNumber, string $comment)
    {
        try {
            $response = $this->client->issues()->comments()->create(getenv('GH_USERNAME'), getenv('GH_REPO'),
                $issueNumber, ['body' => $comment]);
        } catch (MissingArgumentException $e) {
            return $e->getMessage();
        }

        return $response;
    }
}
