<?php
namespace t4web\PhpciPlugins;

use PHPCI\Plugin;
use PHPCI\Builder;
use PHPCI\Model\Build;
use GuzzleHttp;
use PHPHtmlParser\Dom;

class TestRailPlugin implements Plugin
{
    /**
     * @var Builder
     */
    protected $phpci;

    /**
     * @var Build
     */
    protected $build;

    /**
     * @var string
     */
    protected $url;

    /**
     * @var int
     */
    protected $projectId;

    /**
     * @var string
     */
    protected $login;

    /**
     * @var string
     */
    protected $pass;

    /**
     * @var string
     */
    protected $phpciHost;

    /**
     * @var string
     */
    protected $buildDomain;

    /**
     * @var GuzzleHttp\Client
     */
    private $api;

    /**
     * Set up the plugin, configure options, etc.
     * @param Builder $phpci
     * @param Build $build
     * @param array $options
     */
    public function __construct(Builder $phpci, Build $build, array $options = array())
    {
        $this->phpci = $phpci;
        $this->build = $build;

        if (!array_key_exists('url', $options)) {
            throw new \Exception('option "url" must be filled in');
        }

        $this->url = $options['url'];

        if (!array_key_exists('phpciHost', $options)) {
            throw new \Exception('option "phpciHost" must be filled in');
        }

        $this->phpciHost = $options['phpciHost'];

        if (!array_key_exists('buildDomain', $options)) {
            throw new \Exception('option "buildDomain" must be filled in');
        }

        $this->buildDomain = $options['buildDomain'];

        if (!array_key_exists('projectId', $options)) {
            throw new \Exception('option "projectId" must be filled in');
        }

        $this->projectId = $options['projectId'];

        if (!array_key_exists('login', $options)) {
            throw new \Exception('option "login" must be filled in');
        }

        $this->login = $options['login'];

        if (!array_key_exists('pass', $options)) {
            throw new \Exception('option "pass" must be filled in');
        }

        $this->pass = $options['pass'];
    }

    public function execute()
    {
        $this->phpci->log('Start parse tap file');
        $tests = $this->getTestResultsFromTap();

        $this->phpci->log('Start parse html report');
        $testSteps = $this->getTestResultsFromHtml();

        $this->phpci->log(sprinf("Found %s tests", count($tests)));
        $this->phpci->log('Add Test Run');
        $run = $this->addRun(array_keys($tests));

        $runTests = $this->getTests($run['id']);

        $results = [];
        foreach ($runTests as $runTest) {

            $steps = '';
            if (!array_key_exists($runTest['case_id'], $tests)) {
                continue;
            }

            if (array_key_exists($runTest['case_id'], $testSteps)) {
                $steps = implode(PHP_EOL, $testSteps[$runTest['case_id']]['steps']);
                $host = $this->build->getId() . $this->buildDomain;
                $steps .= PHP_EOL . PHP_EOL . "---";
                $steps .= PHP_EOL . "Host: [$host](http://$host)";
                $steps .= PHP_EOL . "[Build details]({$this->phpciHost}/build/view/{$this->build->getId()})";
                $steps .= " | [Recorded result](http://$host/tests/_output/records.html)";
                $steps .= " | [Step details](http://$host/tests/_output/report.html)";
            }

            $results[] = [
                'test_id' => $runTest['id'],
                'status_id' => ($tests[$runTest['case_id']]['ok']) ? 1 : 5,
                'comment' => $steps
            ];

        }
        $this->phpci->log('Add Test Results');
        $this->addResults($run['id'], $results);

        $this->phpci->log('Add Close Test Run');
        $this->closeRun($run['id']);

        return true;
    }

    private function api($method, $uri, array $params = [])
    {
        if (!$this->api) {
            $this->api = new GuzzleHttp\Client([
                'base_uri' => $this->url,
                'headers' => ["Content-Type" => "application/json"]
            ]);
        }

        $options = [
            'auth' => [$this->login, $this->pass],
            'debug' => true
        ];

        if (!empty($params)) {
            $options['json'] = $params;
        }

        return $this->api->request($method, $uri, $options);
    }

    private function addRun(array $caseIds = [])
    {
        /** @var GuzzleHttp\Psr7\Response $responce */
        $responce = $this->api(
            'POST',
            'index.php?/api/v2/add_run/' . $this->projectId,
            [
                "suite_id" => 1,
                "name" => "Automated tests for branch " . $this->build->getBranch(),
                "assignedto_id" => 1,
                "include_all" => false,
                "case_ids" => $caseIds
            ]
        );

        return json_decode((string)$responce->getBody(), true);
    }

    private function getTests($runId)
    {
        /** @var GuzzleHttp\Psr7\Response $responce */
        $responce = $this->api(
            'GET',
            'index.php?/api/v2/get_tests/' . $runId
        );

        return json_decode((string)$responce->getBody(), true);
    }

    private function addResults($runId, array $results = [])
    {
        $this->api(
            'POST',
            'index.php?/api/v2/add_results/' . $runId,
            ['results' => $results]
        );
    }

    private function closeRun($id)
    {
        $this->api(
            'POST',
            'index.php?/api/v2/close_run/' . $id
        );
    }

    private function getTestResultsFromTap()
    {
        $tapString = file_get_contents(
            $this->phpci->buildPath . '/tests/_output/report.tap.log'
        );

        $linesRaw = explode("\n", $tapString);
        $tests = [];

        foreach ($linesRaw as $line) {
            $line = trim($line);

            $id = $this->parseId($line);

            if (strpos($line, 'not ok ') !== false) {
                $tests[$id] = [
                    'id' => $id,
                    'ok' => false,
                ];

                continue;
            }

            if ((strpos($line, 'ok ') !== false)) {
                $tests[$id] = [
                    'id' => $id,
                    'ok' => true,
                ];
            }
        }

        return $tests;
    }

    private function parseId($str)
    {
        $id = substr($str, strpos($str, '[') +1);
        $id = substr($id, 0, strpos($id, ']'));

        return $id;
    }

    private function getTestResultsFromHtml()
    {
        $htmlString = file_get_contents(
            $this->phpci->buildPath . '/tests/_output/report.html'
        );

        $tests = [];

        $dom = new Dom;
        $dom->load($htmlString);

        /** @var Dom\HtmlNode $table */
        $table = $dom->find('table')[0];
        $id = 0;
        foreach($table->find('tr') as $lineNo => $tr) {

            if ($lineNo == 0) {
                continue;
            }

            if (strpos($tr->find('td')[0]->innerHtml, 'Summary')) {
                break;
            }

            if ($lineNo % 2 == 1) {
                $p = $tr->find('td p')[0];
                $name = strip_tags($p->innerHtml);
                $name = str_replace('[+]', '', $name);
                $id = $this->parseId($name);
                $tests[$id] = [
                    'id' => $id,
                    'name' => trim(strip_tags($p->innerHtml)),
                    'steps' => []
                ];
            } else {
                foreach($tr->find('td table tr') as $lineNo => $tr) {
                    $tests[$id]['steps'][] = trim(strip_tags($tr->innerHtml));
                }
            }
        }

        return $tests;
    }
} 