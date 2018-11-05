<?php

namespace Tests;

use Mockery as m;
use BotMan\BotMan\Http\Curl;
use PHPUnit_Framework_TestCase;
use BotMan\Drivers\Waboxapp\WaboxappDriver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WaboxappDriverTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    private function getDriver($responseData, $htmlInterface = null)
    {
        $request = m::mock(Request::class.'[getContent]');
        $request->shouldReceive('getContent')->andReturn($responseData);
        if ($htmlInterface === null) {
            $htmlInterface = m::mock(Curl::class);
        }

        return new WaboxappDriver($request, [], $htmlInterface);
    }

    public function getTestData(){
        return 'event=message&token=cb1e857548578858768748798d173d47&uid=5511999999999&contact[uid]=5511999999991&contact[name]=Joseph&contact[type]=user&message[dtm]=1541451012&message[uid]=725D2C1CDA5C2ED6CB80ED5E503001E2&message[cuid]=&message[dir]=i&message[type]=chat&message[body][text]=Test&message[ack]=3';
    }

    /** @test */
    public function it_returns_the_driver_name()
    {
        $driver = $this->getDriver('');
        $this->assertSame('Waboxapp', $driver->getName());
    }

    /** @test */
    public function it_matches_the_request()
    {
        $responseData = 'event=message&token=cb1e857548578858768748798d173d47&uid=5511999999999';
        $driver = $this->getDriver($responseData);
        $this->assertFalse($driver->matchesRequest());

        $driver = $this->getDriver($this->getTestData());
        $this->assertTrue($driver->matchesRequest());
    }

    /** @test */
    public function it_returns_the_message_object()
    {
        $driver = $this->getDriver($this->getTestData());
        $this->assertTrue(is_array($driver->getMessages()));
    }

    /** @test */
    public function it_returns_the_message_text()
    {
        $driver = $this->getDriver($this->getTestData());
        $this->assertSame('Test', $driver->getMessages()[0]->getText());
    }

    /** @test */
    public function it_detects_bots()
    {
        $driver = $this->getDriver($this->getTestData());
        $this->assertFalse($driver->isBot());
    }

    /** @test */
    public function it_returns_the_user_id()
    {
        $driver = $this->getDriver($this->getTestData());
        $this->assertSame('5511999999991', $driver->getMessages()[0]->getSender());
    }

    /** @test */
    public function it_returns_the_channel_id()
    {
        $driver = $this->getDriver($this->getTestData());
        $this->assertSame('5511999999999', $driver->getMessages()[0]->getRecipient());
    }

    /** @test */
    public function it_returns_the_user()
    {
        $driver = $this->getDriver($this->getTestData());

        $user = $driver->getUser($driver->getMessages()[0]);

        $this->assertSame('5511999999991', $user->getId());
        $this->assertSame('Joseph', $user->getFirstName());
        $this->assertSame(null, $user->getLastName());
        $this->assertSame('5511999999991', $user->getUsername());
    }
}
