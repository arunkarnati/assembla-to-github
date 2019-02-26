<?php
declare(strict_types=1);

namespace Migrator;

use Github\Client;
use Github\Exception\MissingArgumentException;
use Monolog\Handler\FirePHPHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Dotenv\Dotenv;

class Execute
{
    /**
     * used to skip the milestone existence check
     */
    const MILESTONE_CHECK_ENABLED = false;

    /**
     * Map with assembla milestones as keys and github milestones as values for first project.
     */
    const MILESTONE_MAP = [];

    /**
     * Map with assembla milestones as keys and github milestones as values for second project.
     */
    const SECOND_MILESTONE_MAP = [];

    /**
     * only the Highest and lowest gets a label in GitHub
     */
    const PRIORITY_LABEL_MAP = array(
        1 => 'priority:high',
        5 => 'priority:low',
    );

    const WORKFLOW_PROPERTY_DEFS = array(
        579473  => 'Device',
        579513  => 'Ticket Type',
        680473  => 'Build Discovered',
        1081463 => 'Build Fixed',
        1096783 => 'App',
    );

    /**
     * ticket states that are equivalent to CLOSED
     */
    const ASSEMBLA_TICKET_STATES = array(
        10643853,       // complete
        15864333,       // done - code complete
        17424963,       // tested
        8654183,        // invalid
    );

    /**
     * used to create a link to the ticket on Assembla
     */
    const ASSEMBLA_WORKSPACE = 'tmz-mobile-app';

    /** @var string */
    public $dumpFileName;

    /** @var string */
    public $logFileName;

    /** @var string */
    public $ticketsFileName;

    /** @var string */
    public $milestonesFileName;

    /** @var string */
    public $debugLogFileName;

    /** @var Client */
    public $client;

    /** @var Logger */
    public $logger;

    /** @var array */
    public $milestoneFields;

    /** @var array */
    public $ticketFields;

    /** @var array */
    public $commentFields;

    /** @var array */
    public $workflowPropertyValsFields;

    /** @var array */
    public $tickets;

    /** @var array
     *
     * milestone_id => milestone_title
     */
    public $milestones;

    /** @var string */
    protected $gitHubUsername;

    /** @var string */
    protected $gitHubIosRepo;

    /** @var string */
    protected $gitHubAndroidRepo;

    /**
     * GitHub authentication is done within the constructor.
     *
     * @param Client|null $client
     * @param Logger      $logger
     */
    public function __construct(Client $client = null, Logger $logger = null)
    {
        $this->client = $client ?: new Client();
        $this->logger = $logger ?: new Logger('debug-logger');

        $dotEnv = new Dotenv();
        $dotEnv->load(__DIR__.'/../.env');

        $this->client->authenticate(getenv('GH_PERSONAL_ACCESS_TOKEN'), Client::AUTH_HTTP_PASSWORD);
        $this->gitHubUsername = getenv('GH_USERNAME');
        $this->gitHubIosRepo = getenv('GH_IOS_REPO');
        $this->gitHubAndroidRepo = getenv('GH_ANDROID_REPO');
        $this->dumpFileName = getenv('DUMP_FILE_NAME');
        $this->logFileName = getenv('LOG_FILE_NAME');
        $this->ticketsFileName = getenv('TICKETS_FILE_NAME');
        $this->milestonesFileName = getenv('MILESTONES_FILE_NAME');
        $this->debugLogFileName = getenv('DEBUG_LOG_FILE_NAME');

        $this->logger->pushHandler(new StreamHandler(__DIR__.'/../'.$this->debugLogFileName, Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
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
        if (Execute::MILESTONE_CHECK_ENABLED) {
            // milestone check for FIRST repo
            try {
                $this->checkMilestonesExistOnGitHub($this->gitHubIosRepo, Execute::MILESTONE_MAP);
            } catch (\Exception $e) {
                return $e->getMessage();
            }

            // milestone check for SECOND repo
            try {
                $this->checkMilestonesExistOnGitHub($this->gitHubAndroidRepo, Execute::SECOND_MILESTONE_MAP);
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }

        $arr = file(__DIR__.'/../'.$this->dumpFileName);
        foreach ($arr as $value) {
            $parts = explode(',', $value, 2);
            switch ($parts[0]) {
                case 'milestones:fields':
                    $this->milestoneFields = json_decode(trim($parts[1]));
                    break;
                case 'milestones':
                    $milestone = array_combine($this->milestoneFields, json_decode(trim($parts[1])));
                    $milestoneMap = Execute::MILESTONE_MAP;

                    if (empty($milestoneMap) || in_array($milestone['id'], array_keys($milestoneMap))) {
                        $this->milestones[$milestone['id']] = $milestone;
                    }
                    break;
                case 'tickets:fields':
                    $this->ticketFields = json_decode(trim($parts[1]));
                    break;
                case 'tickets':
                    $ticket = array_combine($this->ticketFields, json_decode(trim($parts[1])));

                    // tickets with milestones in the map, without a closed state and is not created yet
                    if (!in_array($ticket['ticket_status_id'], Execute::ASSEMBLA_TICKET_STATES)) {
                        $ticket['description'] = $this->replaceUrls($ticket['description']);
                        $this->tickets[$ticket['id']] = $ticket;

                        if (isset($ticket['milestone_id'])) {
                            $this->tickets[$ticket['id']]['milestone'] = $this->milestones[$ticket['milestone_id']]['title'];
                        }
                    }
                    break;
                case 'ticket_comments:fields':
                    $this->commentFields = json_decode(trim($parts[1]));
                    break;
                case 'ticket_comments':
                    $comment = array_combine($this->commentFields, json_decode(trim($parts[1])));
                    $commentText = $comment['comment'];

                    // only add comments that are not code commit comments and associated with filtered tickets
                    if (isset($commentText) && strlen($commentText) > 0 && isset($this->tickets[$comment['ticket_id']])
                        && preg_match('/\[\[r\:3\:/i', $commentText) === 0
                        && preg_match('/Re\:\s*\#/i', $commentText) === 0) {
                        // add Assembla user_id of the commenter to the actual comment.
                        $comment['comment'] = $this->replaceUrls($commentText)."<br /><br /> originally commented by Assembla user: ".$comment['user_id'];
                        $this->tickets[$comment['ticket_id']]['comments'][] = $comment;
                    }
                    break;
                case 'workflow_property_vals:fields':
                    $this->workflowPropertyValsFields = json_decode(trim($parts[1]));
                    break;
                case 'workflow_property_vals':
                    $val = array_combine($this->workflowPropertyValsFields, json_decode(trim($parts[1])));
                    $ticketId = $val['workflow_instance_id'];
                    $propertyId = $val['workflow_property_def_id'];
                    $workflowPropertyDefs = Execute::WORKFLOW_PROPERTY_DEFS;

                    if (isset($this->tickets[$ticketId]) && in_array($propertyId, array_keys($workflowPropertyDefs))) {
                        $this->tickets[$ticketId][$workflowPropertyDefs[$propertyId]] = $val['value'];
                    }
            }
        }
        $firstRepoCount = $this->setRepoForTickets(); // applicable when the tickets must be split into two repos based on a condition
        $secondRepoCount = count($this->tickets) - $firstRepoCount;

        file_put_contents(__DIR__.'/../'.$this->ticketsFileName,
            isset($this->tickets) ? json_encode($this->tickets) : '');
        file_put_contents(__DIR__.'/../'.$this->milestonesFileName,
            isset($this->milestones) ? json_encode($this->milestones) : '');

        if ($this->logger) {
            $this->logger->info('Completed: Reading the dump to a file with tickets in JSON format');
            $this->logger->info('Number of tickets to be imported to repo 1 - '.$firstRepoCount);
            $this->logger->info('Number of tickets to be imported to repo 2 - '.$secondRepoCount);
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

    public function replaceUrls($subject)
    {
        // replace links
        $text = preg_replace_callback('/\[\[url:([^\]]+)\|(.+?)\]\]/i',
            function ($matches) {
                return '['.$matches[2].']('.$matches[1].')';
            },
            $subject
        );

        // replace image shortcodes
        $text = preg_replace_callback('/\[\[image\:(.+?)\]\]/i',
            function ($matches) {
                return 'link to image: https://app.assembla.com/spaces/'.Execute::ASSEMBLA_WORKSPACE.'/documents/'.$matches[1].'/download/'.$matches[1];
            },
            $text
        );

        return $text;
    }

    /**
     * @return int
     */
    public function setRepoForTickets()
    {
        $counter = 0;
        foreach ($this->tickets as $ticket) {
            $this->tickets[$ticket['id']]['repo'] = $this->gitHubAndroidRepo;

            if (strpos($ticket['Device'], 'iOS') !== false || (!empty($ticket['App']) && strpos($ticket['App'],
                        'iOS') !== false) || (!empty($ticket['milestone']) && strpos($ticket['milestone'], 'iOS') !== false)) {
                $this->tickets[$ticket['id']]['repo'] = $this->gitHubIosRepo;
                $counter = $counter + 1;
            }
        }

        return $counter;
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

        if (count($tickets) === 0) {
            return null;
        }

        // exclude tickets that are already created on GitHub
        $createdTicketData = array_filter(file(__DIR__.'/../'.$this->logFileName), function ($v) {
            return $v !== null && $v !== '' && $v !== [] && $v !== "\n";
        });
        $createdTicketNumbers = $this->getCreatedTicketNumbers($createdTicketData);

        $response = '';
        foreach ($tickets as $ticket) {
            if (in_array($ticket['number'], $createdTicketNumbers)) {
                continue;
            }

            $ticketNumber = $ticket['number'];
            $ticketSummary = $ticket['summary'];
            $description = $ticket['description'].'<br /><br />Assembla Ticket Link: https://app.assembla.com/spaces/'.Execute::ASSEMBLA_WORKSPACE.'/tickets/realtime_cardwall?ticket='.$ticketNumber;
            $repo = $ticket['repo']; // default

            $ticketParams = [
                "title"  => $ticketSummary,
                "body"   => $description,
                "labels" => [
                    'assembla',
                    strtolower(str_replace(' ', '-', $ticket['milestone'])),
                ],
            ];

            // if the priority is set and is in the map then add an appropriate priority label to GitHub issue
            if (isset($ticket['priority']) && in_array($ticket['priority'],
                    array_keys(Execute::PRIORITY_LABEL_MAP))) {
                array_push($ticketParams['labels'], Execute::PRIORITY_LABEL_MAP[$ticket['priority']]);
            }

            if ($ticket['estimate'] > 0) {
                array_push($ticketParams['labels'], 'estimate-'.$ticket['estimate']);
            }

            if (!is_null($ticket['due_date'])) {
                array_push($ticketParams['labels'], $ticket['due_date']);
            }

            if (!empty($ticket['Ticket Type'])) {
                array_push($ticketParams['labels'], $ticket['Ticket Type']);
            }

            if (isset($ticket['Build Discovered'])) {
                array_push($ticketParams['labels'], 'build-discovered '.$ticket['Build Discovered']);
            }

            if (isset($ticket['Build Fixed'])) {
                array_push($ticketParams['labels'], 'build-fixed '.$ticket['Build Fixed']);
            }

            try {
                // step 1 - create issue
                $this->logger->info('creating '.$ticketNumber.' on GitHub');
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

        return $response;
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

    /**
     * @param $repo
     * @param $milestone
     *
     * @throws MissingArgumentException
     */
    public function createMilestone($repo, $milestone)
    {
        if (!isset($milestone['title'])) {
            throw new MissingArgumentException('title');
        }

        $params['title'] = $milestone['title'];

        if (isset($milestone['description'])) {
            $params['milestone'] = $milestone['description'];
        }

        if (isset($milestone['due_date'])) {
            $params['due_on'] = $milestone['due_date'];
        }
        $this->client->issues()->milestones()->create($this->gitHubUsername, $repo, $params);
    }
}
