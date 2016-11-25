<?php

use Clue\React\Socks\Client;
use React\Promise\Promise;

class ClientTest extends TestCase
{
    private $loop;

    /** @var  Client */
    private $client;

    public function setUp()
    {
        $this->loop = React\EventLoop\Factory::create();
        $this->client = new Client('127.0.0.1:9050', $this->loop);
    }

    public function testCtorAcceptsUriWithHostAndPort()
    {
        $client = new Client('127.0.0.1:9050', $this->loop);
    }

    public function testCtorAcceptsUriWithScheme()
    {
        $client = new Client('socks://127.0.0.1:9050', $this->loop);
    }

    public function testCtorAcceptsUriWithHostOnlyAssumesDefaultPort()
    {
        $client = new Client('127.0.0.1', $this->loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testCtorThrowsForInvalidUri()
    {
        new Client('////', $this->loop);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidAuthInformation()
    {
        $this->client->setAuth(str_repeat('a', 256), 'test');
    }

    /**
     * @expectedException UnexpectedValueException
     * @dataProvider providerInvalidAuthVersion
     */
    public function testInvalidAuthVersion($version)
    {
        $this->client->setAuth('username', 'password');
        $this->client->setProtocolVersion($version);
    }

    public function providerInvalidAuthVersion()
    {
        return array(array('4'), array('4a'));
    }

    public function testValidAuthVersion()
    {
        $this->client->setAuth('username', 'password');
        $this->assertNull($this->client->setProtocolVersion(5));
    }

    public function testValidAuthAndVersionFromUri()
    {
        $this->client = new Client('socks5://username:password@127.0.0.1:9050', $this->loop);
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testInvalidCanNotSetAuthenticationForSocks4()
    {
        $this->client->setProtocolVersion(4);
        $this->client->setAuth('username', 'password');
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testInvalidCanNotSetAuthenticationForSocks4Uri()
    {
        $this->client = new Client('socks4://username:password@127.0.0.1:9050', $this->loop);
    }

    public function testUnsetAuth()
    {
        // unset auth even if it's not set is valid
        $this->client->unsetAuth();

        $this->client->setAuth('username', 'password');
        $this->client->unsetAuth();
    }

    /**
     * @dataProvider providerValidProtocolVersion
     */
    public function testValidProtocolVersion($version)
    {
        $this->assertNull($this->client->setProtocolVersion($version));
    }

    public function providerValidProtocolVersion()
    {
        return array(array('4'), array('4a'), array('5'));
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testInvalidProtocolVersion()
    {
        $this->client->setProtocolVersion(3);
    }

    public function testValidResolveLocal()
    {
        $this->assertNull($this->client->setResolveLocal(false));
        $this->assertNull($this->client->setResolveLocal(true));
        $this->assertNull($this->client->setProtocolVersion('4'));
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testInvalidResolveRemote()
    {
        $this->client->setProtocolVersion('4');
        $this->client->setResolveLocal(false);
    }

    /**
     * @expectedException UnexpectedValueException
     */
    public function testInvalidResolveRemoteVersion()
    {
        $this->client->setResolveLocal(false);
        $this->client->setProtocolVersion('4');
    }

    public function testSetTimeout()
    {
        $this->client->setTimeout(1);
        $this->client->setTimeout(2.0);
        $this->client->setTimeout(3);
    }

    public function testCreateConnector()
    {
        $this->assertInstanceOf('\React\SocketClient\ConnectorInterface', $this->client->createConnector());
    }

    public function testCreateSecureConnector()
    {
        $this->assertInstanceOf('\React\SocketClient\SecureConnector', $this->client->createSecureConnector());
    }

    public function testCancelConnectionDuringConnectionWillCancelConnection()
    {
        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);
        $this->client = new Client('127.0.0.1', $this->loop, $connector);

        $promise = $this->client->createConnection('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringConnectionWillCancelConnectionAndCloseStreamIfItResolvesDespite()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function () { }, function ($resolve) use ($stream) { $resolve($stream); });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);
        $this->client = new Client('127.0.0.1', $this->loop, $connector);

        $promise = $this->client->createConnection('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringDnsWillCancelDns()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function ($resolve) use ($stream) { $resolve($stream); });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);

        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $resolver = $this->getMockBuilder('React\Dns\Resolver\Resolver')->disableOriginalConstructor()->getMock();
        $resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn($promise);

        $this->client = new Client('127.0.0.1', $this->loop, $connector, $resolver);

        $promise = $this->client->createConnection('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCancelConnectionDuringSessionWillCloseStream()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function ($resolve) use ($stream) { $resolve($stream); });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);
        $this->client = new Client('127.0.0.1', $this->loop, $connector);
        $this->client->setResolveLocal(false);

        $promise = $this->client->createConnection('google.com', 80);
        $promise->cancel();

        $this->expectPromiseReject($promise);
    }

    public function testCloseConnectionIfDnsLookupFails()
    {
        $stream = $this->getMockBuilder('React\Stream\Stream')->disableOriginalConstructor()->getMock();
        $stream->expects($this->once())->method('close');

        $promise = new Promise(function ($resolve) use ($stream) { $resolve($stream); });

        $connector = $this->getMock('React\SocketClient\ConnectorInterface');
        $connector->expects($this->once())->method('create')->with('127.0.0.1', 1080)->willReturn($promise);

        $promise = new Promise(function ($_, $reject) { $reject(new RuntimeException()); });

        $resolver = $this->getMockBuilder('React\Dns\Resolver\Resolver')->disableOriginalConstructor()->getMock();
        $resolver->expects($this->once())->method('resolve')->with('google.com')->willReturn($promise);

        $this->client = new Client('127.0.0.1', $this->loop, $connector, $resolver);

        $promise = $this->client->createConnection('google.com', 80);

        $this->expectPromiseReject($promise);
    }

    /**
     * @dataProvider providerAddress
     */
    public function testCreateConnection($host, $port)
    {
        $this->assertInstanceOf('\React\Promise\PromiseInterface', $this->client->createConnection($host, $port));
    }

    public function providerAddress()
    {
        return array(
            array('localhost','80'),
            array('invalid domain','non-numeric')
        );
    }
}
