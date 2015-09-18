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
        $tests = $this->getTestResultsFromTap();
        $testSteps = $this->getTestResultsFromHtml();

        $run = $this->addRun(array_keys($tests));

        $runTests = $this->getTests($run['id']);

        $results = [];
        foreach ($runTests as $runTest) {

            if (!array_key_exists($runTest['case_id'], $tests)) {
                continue;
            }

            $results[] = [
                'test_id' => $runTest['id'],
                'status_id' => ($tests[$runTest['case_id']]['ok']) ? 1 : 5
            ];

        }
        $this->addResults($run['id'], $results);

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

            $id = substr($line, strpos($line, '[') +1);
            $id = substr($id, 0, strpos($id, ']'));

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

    private function getTestResultsFromHtml()
    {
        $htmlString = file_get_contents(
            $this->phpci->buildPath . '/tests/_output/report.html'
        );

        $dom = new Dom;
        $dom->load($htmlString);
        $a = $dom->find('table');
        die(var_dump($a));

        return $tests;
    }
} 