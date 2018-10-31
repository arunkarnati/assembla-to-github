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
        3820293  => 1, // Backlog
        12345924 => 2, // SEO
        10195553 => 3, // Tech Backlog
    );
    /**
     * Map with assembla milestones as keys and github labels as values.
     */
    const MILESTONE_LABEL_MAP = array(
        3820293  => 'backlog',        // Backlog
        12345924 => 'seo',            // SEO
        10195553 => 'tech-backlog',   // Tech Backlog
    );
    const PRIORITY_LABEL_MAP = array(
        1 => 'priority:high',
        2 => 'priority:high',
        4 => 'priority:low',
        5 => 'priority:low',
    );
    /** @var array - closed ticket state */
    const ASSEMBLA_TICKET_STATE = array(
        391028,     // invalid
        15864473,   // fixed
        495710,     // complete
        24127694,   // duplicate
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
        $this->readDumpFile();
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

                    // tickets with milestones from the map and without closed state
                    if (in_array($ticket['milestone_id'],
                            array_keys(Execute::MILESTONE_MAP)) && !in_array($ticket['ticket_status_id'],
                            Execute::ASSEMBLA_TICKET_STATE)) {
                        $this->tickets[$ticket['id']] = $ticket;
                    }
                    break;
                case 'ticket_comments':
                    $data = explode('ticket_comments, ', trim($parts[1]));
                    $comment = array_combine($this->assemblaCommentFields, json_decode($data[0]));

                    // only add comments of tickets in the milestone map and is not a code commit comment
                    if ($comment['comment'] !== '' && strpos($comment['comment'],
                            '[[r:3:') === false && isset($this->tickets[$comment['ticket_id']])) {
                        $this->tickets[$comment['ticket_id']]['comments'][] = $comment;
                    }
                    break;
            }
        }
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
     * creates issues on GitHub using API and saves to ticket map
     *
     * @return array|null|string - An array with issue information or an exception message
     * @throws \Exception
     */
    public function createIssuesOnGitHub()
    {
        $milestones = $this->getAllMilestoneIds(getenv('GH_MOBILE_REPO'));
        if (count(array_diff($milestones, Execute::MILESTONE_MAP)) !== 0) {
            throw new \Exception('All milestones don\'t exist in GitHub');
        }

        if (!isset($this->tickets)) {
            return null;
        }

        $response = '';
        foreach ($this->tickets as $ticket) {
            $ticketNumber = $ticket['number'];
            $ticketSummary = $ticket['summary'];
            $description = $ticket['description'].'<br /><br />Assembla Ticket Link: https://app.assembla.com/spaces/'.Execute::ASSEMBLA_WORKSPACE.'/tickets/realtime_cardwall?ticket='.$ticketNumber;
            $ticketParams = [
                "title"     => $ticketSummary,
                "body"      => $description,
                "milestone" => Execute::MILESTONE_MAP[$ticket['milestone_id']],
                "labels"    => ['assembla', Execute::MILESTONE_LABEL_MAP[$ticket['milestone_id']]],
            ];
            $repo = getenv('GH_REPO');

            // mobile-web ticket check
            if (($ticketNumber > 6863 && $ticketNumber < 8401) || ($ticketNumber > 8400 && strpos($ticketSummary,
                        '[M]') !== false)) {
                $repo = getenv('GH_MOBILE_REPO');
            }

            // if the priority is set and is in the map then add an appropriate priority label to GitHub issue
            if (isset($ticket['priority']) && in_array($ticket['priority'], array_keys(Execute::PRIORITY_LABEL_MAP))) {
                array_push($ticketParams['labels'], Execute::PRIORITY_LABEL_MAP[$ticket['priority']]);
            }

            try {
                $response = $this->client->issues()->create(getenv('GH_USERNAME'), $repo, $ticketParams);

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

    /**
     * @param string $repo
     *
     * @return array
     */
    public function getAllMilestoneIds(string $repo)
    {
        $response = $this->client->repo()->milestones(getenv('GH_USERNAME'), $repo);
        $milestones = [];
        foreach ($response as $milestone) {
            array_push($milestones, $milestone['number']);
        }

        return $milestones;
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
        } catch (\ErrorException $e) {
            return $e->getMessage();
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
}
