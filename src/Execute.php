<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;
use Github\Exception\MissingArgumentException;
use Symfony\Component\Dotenv\Dotenv;
use Psr\Log\LoggerInterface;

class Execute
{
    /**
     * Map with assembla milestones as keys and github milestones as values for desktop project.
     */
    const MILESTONE_MAP = array(
        3820293  => 1, // Backlog
        12345924 => 2, // SEO
        10195553 => 3, // Tech Backlog
    );

    const MOBILE_MILESTONE_MAP = array(
        3820293  => 1, // Backlog
        12345924 => 2, // SEO
        10195553 => 3, // Tech Backlog
    );

    /** @var array - only Highest and lowest gets a label */
    const PRIORITY_LABEL_MAP = array(
        1 => 'priority:high',
        5 => 'priority:low',
    );

    /** @var array - closed ticket state */
    const ASSEMBLA_TICKET_STATES = array(
        391028,     // invalid
        15864473,   // fixed
        495710,     // complete
        24127694,   // duplicate
    );

    /** @var string - used to create assembla ticket link added in the GitHub issue description */
    const ASSEMBLA_WORKSPACE = 'crowd-fusion-tmz';

    /** @var string */
    const DUMP_FILE_NAME = 'dump.json';

    /** @var Client */
    public $client;

    /** @var LoggerInterface */
    public $logger;

    /** @var array */
    public $milestoneFields;

    /** @var array */
    public $ticketFields;

    /** @var array */
    public $commentFields;

    /** @var array */
    public $tickets;

    /** @var array - milestone_id => milestone_title */
    public $milestones;

    /** @var string */
    protected $gitHubUsername;

    /** @var string */
    protected $gitHubDesktopRepo;

    /** @var string */
    protected $gitHubMobileRepo;

    /**
     * GitHub authentication is done in the constructor.
     *
     * @param Client|null     $client
     * @param LoggerInterface $logger
     */
    public function __construct(Client $client = null, LoggerInterface $logger = null)
    {
        $this->client = $client ?: new Client();
        $this->logger = $logger;
        $dotEnv = new Dotenv();
        $dotEnv->load(__DIR__.'/../.env');
        $this->client->authenticate(getenv('GH_PERSONAL_ACCESS_TOKEN'), Client::AUTH_HTTP_PASSWORD);
        $this->gitHubUsername = getenv('GH_USERNAME');
        $this->gitHubDesktopRepo = getenv('GH_REPO');
        $this->gitHubMobileRepo = getenv('GH_MOBILE_REPO');
    }

    /**
     * Reads the ticket:fields from dump file to an array
     *
     * @return array
     */
    public function getTicketFields()
    {
        if (!isset($this->ticketFields)) {
            $this->readDumpFile();
        }

        return $this->ticketFields;
    }

    /**
     * @return bool|string
     */
    public function readDumpFile()
    {
        // milestone check for Desktop repo
        try {
            $this->checkMilestonesExistOnGitHub($this->gitHubDesktopRepo, Execute::MILESTONE_MAP);
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        // milestone check for Mobile repo
        try {
            $this->checkMilestonesExistOnGitHub($this->gitHubMobileRepo, Execute::MOBILE_MILESTONE_MAP);
        } catch (\Exception $e) {
            return $e->getMessage();
        }

        $arr = file(__DIR__.'/../'.Execute::DUMP_FILE_NAME);
        foreach ($arr as $value) {
            $parts = explode(',', $value, 2);
            switch ($parts[0]) {
                case 'milestones:fields':
                    $this->milestoneFields = json_decode(trim($parts[1]));
                    break;
                case 'milestones':
                    $data = explode('milestones, ', trim($parts[1]));
                    $milestone = array_combine($this->milestoneFields, json_decode($data[0]));

                    if (in_array($milestone['id'], array_keys(Execute::MILESTONE_MAP))) {
                        $this->milestones[$milestone['id']] = $milestone;
                    }
                    break;
                case 'tickets:fields':
                    $this->ticketFields = json_decode(trim($parts[1]));
                    break;
                case 'tickets':
                    $data = explode('tickets, ', trim($parts[1]));
                    $ticket = array_combine($this->ticketFields, json_decode($data[0]));

                    // tickets with milestones from the map and without closed state
                    if (in_array($ticket['milestone_id'],
                            array_keys(Execute::MILESTONE_MAP)) && !in_array($ticket['ticket_status_id'],
                            Execute::ASSEMBLA_TICKET_STATES)) {
                        $this->tickets[$ticket['id']] = $ticket;
                    }
                    break;
                case 'ticket_comments:fields':
                    $this->commentFields = json_decode(trim($parts[1]));
                    break;
                case 'ticket_comments':
                    $data = explode('ticket_comments, ', trim($parts[1]));
                    $comment = array_combine($this->commentFields, json_decode($data[0]));

                    // only add comments of tickets in the milestone map and is not a code commit comment
                    if ($comment['comment'] !== '' && strpos($comment['comment'],
                            '[[r:3:') === false && isset($this->tickets[$comment['ticket_id']])) {
                        $this->tickets[$comment['ticket_id']]['comments'][] = $comment;
                    }
                    break;
            }
        }

        file_put_contents(__DIR__.'/../milestones.json', json_encode($this->milestones));
        file_put_contents(__DIR__.'/../tickets.json', json_encode($this->tickets));

        if ($this->logger) {
            $this->logger->info('Completed: Reading the dump file');
            $this->logger->info('Number of tickets to be imported - '.count($this->tickets));
        }

        return true;
    }

    /**
     * @param string $repo
     * @param array  $milestoneMap
     *
     * @throws \Exception
     */
    public function checkMilestonesExistOnGitHub(string $repo, array $milestoneMap)
    {
        $milestones = $this->getAllMilestoneIds($repo);
        if (count(array_diff($milestoneMap, $milestones)) !== 0) {
            throw new \Exception('All assembla milestones don\'t exist in GitHub project');
        }
    }

    /**
     * @param string $repo
     *
     * @return array
     */
    public function getAllMilestoneIds(string $repo)
    {
        $response = $this->client->repo()->milestones($this->gitHubUsername, $repo);
        $milestones = [];
        foreach ($response as $milestone) {
            array_push($milestones, $milestone['number']);
        }

        return $milestones;
    }

    public function getMilestones()
    {
        if (!isset($this->milestones)) {
            $this->readDumpFile();
        }

        return $this->milestones;
    }

    /**
     * creates issues on GitHub using API and saves to ticket map
     *
     * @return array|null|string - An array with issue information or an exception message
     * @throws \Exception
     */
    public function createIssuesOnGitHub()
    {
        $tickets = json_decode(file_get_contents(__DIR__.'/../tickets.json'), true);
        $milestones = json_decode(file_get_contents(__DIR__.'/../milestones.json'), true);

        if (count($tickets) === 0) {
            return null;
        }

        // exclude tickets that are already created on GitHub
        $createdTicketData = array_filter(file(__DIR__.'/../log_table.txt'), function ($v) {
            return $v !== null && $v !== '' && $v !== [] && $v !== "\n";
        });
        $createdTicketNumbers = $this->getCreatedTicketNumbers($createdTicketData);

        $response = '';
        foreach ($tickets as $ticket) {
            $ticketNumber = $ticket['number'];
            if (!in_array($ticketNumber, $createdTicketNumbers)) {
                $this->logger->info('creating '.$ticketNumber);
                $ticketSummary = $ticket['summary'];
                $description = $ticket['description'].'<br /><br />Assembla Ticket Link: https://app.assembla.com/spaces/'.Execute::ASSEMBLA_WORKSPACE.'/tickets/realtime_cardwall?ticket='.$ticketNumber;
                $repo = $this->gitHubDesktopRepo; // default
                $milestoneMap = Execute::MILESTONE_MAP; // default

                // mobile-web ticket?
                if (($ticketNumber > 6863 && $ticketNumber < 8401) || ($ticketNumber > 8400 && strpos($ticketSummary,
                            '[M]') !== false)) {
                    $repo = $this->gitHubMobileRepo;
                    $milestoneMap = Execute::MOBILE_MILESTONE_MAP;
                }

                $ticketParams = [
                    "title"     => $ticketSummary,
                    "body"      => $description,
                    "milestone" => $milestoneMap[$ticket['milestone_id']],
                    "labels"    => [
                        'assembla',
                        strtolower(str_replace(' ', '-', $milestones[$ticket['milestone_id']]['title'])),
                    ],
                ];
                // if the priority is set and is in the map then add an appropriate priority label to GitHub issue
                if (isset($ticket['priority']) && in_array($ticket['priority'],
                        array_keys(Execute::PRIORITY_LABEL_MAP))) {
                    array_push($ticketParams['labels'], Execute::PRIORITY_LABEL_MAP[$ticket['priority']]);
                }

                try {
                    // step 1 - create issue
                    $response = $this->client->issues()->create($this->gitHubUsername, $repo, $ticketParams);
                    if (isset($response['number'])) {
                        $this->logger->info('created ticket '.$ticketNumber.' on GitHub and the Issue ID is '.$response['number']);
                        $gitHubTicketNumber = $response['number'];
                        $entry = $ticketNumber.','.$gitHubTicketNumber."\n";
                        file_put_contents(__DIR__.'/../log_table.txt', $entry, FILE_APPEND);
                    }

                    // step 2 - add comments to the issue
                    if (isset($ticket['comments'])) {
                        foreach ($ticket['comments'] as $comment) {
                            $this->addCommentToIssue($repo, $gitHubTicketNumber, $comment['comment']);
                        }
                    }
                } catch (MissingArgumentException $e) {
                    return $e->getMessage();
                }
            }
        }

        return $response;
    }

    /**
     * @param array $ticketData
     *  ticketData array looks like
     *
     *  Array(
     *      0 => 8499,10
     *      1 => 8500, 17
     *  )
     *
     * @return array
     */
    public function getCreatedTicketNumbers(array $ticketData)
    {
        $ticketNumbers = [];
        foreach ($ticketData as $row) {
            $temp = explode(',', $row);
            $ticketNumbers[] = $temp[0];
        }

        return $ticketNumbers;
    }

    /**
     * @param string $repo
     * @param int    $issueNumber
     * @param string $comment
     *
     * @return array|string
     */
    public function addCommentToIssue(string $repo, int $issueNumber, string $comment)
    {
        try {
            $response = $this->client->issues()->comments()->create($this->gitHubUsername, $repo,
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

    public function getRateLimit()
    {
        $response = $this->client->rateLimit()->getRateLimits();

        return $response['resources']['core']['remaining'];
    }
}
