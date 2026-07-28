<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay\Tests\Unit;

use Breakpoint\GooglePlay\GooglePlayManager;
use Breakpoint\GooglePlay\Tests\TestCase;
use Google\Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\RequestOptions;

class GooglePlayManagerTest extends TestCase
{
    public function test_the_http_client_is_bounded_and_keeps_googles_base_uri(): void
    {
        $manager = new class([]) extends GooglePlayManager
        {
            public function exposedHttpClient(Client $client): GuzzleClient
            {
                return $this->httpClient($client);
            }
        };

        $google = new Client;
        $guzzle = $manager->exposedHttpClient($google);

        $this->assertSame(3.0, $guzzle->getConfig(RequestOptions::CONNECT_TIMEOUT));
        $this->assertSame(6.0, $guzzle->getConfig(RequestOptions::TIMEOUT));
        $this->assertFalse($guzzle->getConfig(RequestOptions::HTTP_ERRORS));
        $this->assertSame(
            $google->getConfig('base_path'),
            (string) $guzzle->getConfig('base_uri'),
        );
    }

    public function test_the_timeouts_are_configurable(): void
    {
        $manager = new class(['http' => ['connect_timeout' => 1, 'timeout' => 2]]) extends GooglePlayManager
        {
            public function exposedHttpClient(Client $client): GuzzleClient
            {
                return $this->httpClient($client);
            }
        };

        $guzzle = $manager->exposedHttpClient(new Client);

        $this->assertSame(1.0, $guzzle->getConfig(RequestOptions::CONNECT_TIMEOUT));
        $this->assertSame(2.0, $guzzle->getConfig(RequestOptions::TIMEOUT));
    }
}
