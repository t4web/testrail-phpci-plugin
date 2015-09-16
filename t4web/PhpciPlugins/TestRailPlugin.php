<?php
namespace t4web\PhpciPlugins;

use PHPCI\Plugin;
use PHPCI\Builder;
use PHPCI\Model\Build;
use GuzzleHttp;

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
     * @var string
     */
    protected $login;

    /**
     * @var string
     */
    protected $pass;

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
        $client = new GuzzleHttp\Client();
        $res = $client->request(
            'GET',
            $this->url,
            [
                'auth' => [$this->login, $this->pass]
            ]
        );

        echo $res->getStatusCode();
        echo $res->getHeader('content-type');
        echo $res->getBody();

        $this->phpci->executeCommand('ls -la');

        return true;
    }
} 