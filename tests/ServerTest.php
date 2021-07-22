<?php

namespace Clue\Tests\React\Socks;

use Clue\React\Socks\Server;
use React\Promise\Promise;
use React\Promise\Timer\TimeoutException;

class ServerTest extends TestCase
{
    private $loop;
    private $connector;

    /** @var Server */
    private $server;

    /**
     * @before
     */
    public function setUpServer()
    {
        $this->loop = $this->getMockBuilder('React\EventLoop\LoopInterface')
            ->getMock();

        $this->connector = $this->getMockBuilder('React\Socket\ConnectorInterface')
            ->getMock();

        $this->server = new Server($this->loop, $this->connector);
    }

    public function testConstructWithoutLoopAssignsLoopAutomatically()
    {
        $server = new Server();

        $ref = new \ReflectionProperty($server, 'loop');
        $ref->setAccessible(true);
        $loop = $ref->getValue($server);

        $this->assertInstanceOf('React\EventLoop\LoopInterface', $loop);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testListen()
    {
        $socket = $this->getMockBuilder('React\Socket\ServerInterface')->getMock();

        $this->server->listen($socket);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testConstructorWithEmptyAuthArray()
    {
        $this->server = new Server($this->loop, $this->connector, array());
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testConstructorWithStaticAuthArray()
    {
        $this->server = new Server($this->loop, $this->connector, array(
            'name1' => 'password1',
            'name2' => 'password2'
        ));
    }

    public function testConstructorWithInvalidAuthenticatorThrows()
    {
        $this->setExpectedException("InvalidArgumentException");
        new Server($this->loop, $this->connector, true);
    }

    public function testConnectWillCreateConnection()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectWillCreateConnectionWithSourceUri()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80?source=socks%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80, 'socks://10.20.30.40:5060'));

        $this->assertInstanceOf('React\Promise\PromiseInterface', $promise);
    }

    public function testConnectWillRejectIfConnectionFails()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function ($_, $reject) { $reject(new \RuntimeException()); });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillCancelConnectionIfStreamCloses()
    {
        $stream = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('close'))->getMock();

        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });


        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $stream->emit('close');

        $promise->then(null, $this->expectCallableOnce());
    }

    public function testConnectWillAbortIfPromiseIsCanceled()
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = new Promise(function () { }, function () {
            throw new \RuntimeException();
        });

        $this->connector->expects($this->once())->method('connect')->with('google.com:80')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $promise->cancel();

        $promise->then(null, $this->expectCallableOnce());
    }

    public function provideConnectionErrors()
    {
        return array(
            array(
                new \RuntimeException('', defined('SOCKET_EACCES') ? SOCKET_EACCES : 13),
                Server::ERROR_NOT_ALLOWED_BY_RULESET
            ),
            array(
                new \RuntimeException('', defined('SOCKET_ENETUNREACH') ? SOCKET_ENETUNREACH : 101),
                Server::ERROR_NETWORK_UNREACHABLE
            ),
            array(
                new \RuntimeException('', defined('SOCKET_EHOSTUNREACH') ? SOCKET_EHOSTUNREACH : 113),
                Server::ERROR_HOST_UNREACHABLE,
            ),
            array(
                new \RuntimeException('', defined('SOCKET_ECONNREFUSED') ? SOCKET_ECONNREFUSED : 111),
                Server::ERROR_CONNECTION_REFUSED
            ),
            array(
                new \RuntimeException('Connection refused'),
                Server::ERROR_CONNECTION_REFUSED
            ),
            array(
                new \RuntimeException('', defined('SOCKET_ETIMEDOUT') ? SOCKET_ETIMEDOUT : 110),
                Server::ERROR_TTL
            ),
            array(
                new TimeoutException(1.0),
                Server::ERROR_TTL
            ),
            array(
                new \RuntimeException(),
                Server::ERROR_GENERAL
            )
        );
    }

    /**
     * @dataProvider provideConnectionErrors
     * @param \Exception $error
     * @param int       $expectedCode
     */
    public function testConnectWillReturnMappedSocks5ErrorCodeFromConnector($error, $expectedCode)
    {
        $stream = $this->getMockBuilder('React\Socket\ConnectionInterface')->getMock();

        $promise = \React\Promise\reject($error);

        $this->connector->expects($this->once())->method('connect')->willReturn($promise);

        $promise = $this->server->connectTarget($stream, array('google.com', 80));

        $code = null;
        $promise->then(null, function ($error) use (&$code) {
            $code = $error->getCode();
        });

        $this->assertEquals($expectedCode, $code);
    }

    public function testHandleSocksConnectionWillEndOnInvalidData()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();
        $connection->expects($this->once())->method('pause');
        $connection->expects($this->once())->method('end');

        $this->server->onConnection($connection);

        $connection->emit('data', array('asdasdasdasdasd'));
    }

    public function testHandleSocks4ConnectionWithIpv4WillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . pack('N', ip2long('127.0.0.1')) . "\x00"));
    }

    public function testHandleSocks4aConnectionWithHostnameWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "example.com" . "\x00"));
    }

    public function testHandleSocks4aConnectionWithHostnameAndSourceAddressWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://10.20.30.40:5060');

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80?source=socks4%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "example.com" . "\x00"));
    }

    public function testHandleSocks4aConnectionWithSecureTlsSourceAddressWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tls://10.20.30.40:5060');

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80?source=socks4s%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "example.com" . "\x00"));
    }

    public function testHandleSocks4aConnectionWithInvalidHostnameWillNotEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $this->connector->expects($this->never())->method('connect');

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . "\x00\x00\x00\x01" . "\x00" . "tls://example.com:80?" . "\x00"));
    }

    public function testHandleSocks5ConnectionWithIpv4WillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x01" . pack('N', ip2long('127.0.0.1')) . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithIpv4AndSourceAddressWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tcp://10.20.30.40:5060');

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80?source=socks%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x01" . pack('N', ip2long('127.0.0.1')) . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithSecureTlsIpv4AndSourceAddressWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write', 'getRemoteAddress'))->getMock();
        $connection->expects($this->once())->method('getRemoteAddress')->willReturn('tls://10.20.30.40:5060');

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80?source=sockss%3A%2F%2F10.20.30.40%3A5060')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x01" . pack('N', ip2long('127.0.0.1')) . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithIpv6WillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('[::1]:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x04" . inet_pton('::1') . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithHostnameWillEstablishOutgoingConnection()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = new Promise(function () { });

        $this->connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x03\x0B" . "example.com" . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithConnectorRefusedWillReturnReturnRefusedError()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $promise = \React\Promise\reject(new \RuntimeException('Connection refused'));

        $this->connector->expects($this->once())->method('connect')->with('example.com:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->expects($this->exactly(2))->method('write')->withConsecutive(array("\x05\x00"), array("\x05\x05" . "\x00\x01\x00\x00\x00\x00\x00\x00"));

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x03\x0B" . "example.com" . "\x00\x50"));
    }

    public function testHandleSocks5UdpCommandWillNotEstablishOutgoingConnectionAndReturnCommandError()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $this->connector->expects($this->never())->method('connect');

        $this->server->onConnection($connection);

        $connection->expects($this->exactly(2))->method('write')->withConsecutive(array("\x05\x00"), array("\x05\x07" . "\x00\x01\x00\x00\x00\x00\x00\x00"));

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x03\x00\x03\x0B" . "example.com" . "\x00\x50"));
    }

    public function testHandleSocks5ConnectionWithInvalidHostnameWillNotEstablishOutgoingConnectionAndReturnGeneralError()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end', 'write'))->getMock();

        $this->connector->expects($this->never())->method('connect');

        $this->server->onConnection($connection);

        $connection->expects($this->exactly(2))->method('write')->withConsecutive(array("\x05\x00"), array("\x05\x01" . "\x00\x01\x00\x00\x00\x00\x00\x00"));

        $connection->emit('data', array("\x05\x01\x00" . "\x05\x01\x00\x03\x15" . "tls://example.com:80?" . "\x00\x50"));
    }

    public function testHandleSocksConnectionWillCancelOutputConnectionIfIncomingCloses()
    {
        $connection = $this->getMockBuilder('React\Socket\Connection')->disableOriginalConstructor()->setMethods(array('pause', 'end'))->getMock();

        $promise = new Promise(function () { }, $this->expectCallableOnce());

        $this->connector->expects($this->once())->method('connect')->with('127.0.0.1:80')->willReturn($promise);

        $this->server->onConnection($connection);

        $connection->emit('data', array("\x04\x01" . "\x00\x50" . pack('N', ip2long('127.0.0.1')) . "\x00"));
        $connection->emit('close');
    }
}
