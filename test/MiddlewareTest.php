<?php
namespace PhlyTest\Conduit;

use Phly\Conduit\Http\Request as RequestDecorator;
use Phly\Conduit\Http\Response as ResponseDecorator;
use Phly\Conduit\Middleware;
use Phly\Conduit\Utils;
use Phly\Http\IncomingRequest as Request;
use Phly\Http\Response;
use PHPUnit_Framework_TestCase as TestCase;
use ReflectionProperty;

class MiddlewareTest extends TestCase
{
    public function setUp()
    {
        $this->request    = new Request('php://memory');
        $this->response   = new Response();
        $this->middleware = new Middleware();
    }

    public function invalidHandlers()
    {
        return [
            'null' => [null],
            'bool' => [true],
            'int' => [1],
            'float' => [1.1],
            'string' => ['non-function-string'],
            'array' => [['foo', 'bar']],
            'object' => [(object) ['foo' => 'bar']],
        ];
    }

    /**
     * @dataProvider invalidHandlers
     */
    public function testPipeThrowsExceptionForInvalidHandler($handler)
    {
        $this->setExpectedException('InvalidArgumentException');
        $this->middleware->pipe('/foo', $handler);
    }

    public function testHandleInvokesUntilFirstHandlerThatDoesNotCallNext()
    {
        $this->middleware->pipe(function ($req, $res, $next) {
            $res->write("First\n");
            $next();
        });
        $this->middleware->pipe(function ($req, $res, $next) {
            $res->write("Second\n");
            $next();
        });
        $this->middleware->pipe(function ($req, $res, $next) {
            $res->write("Third\n");
        });
        $phpunit = $this;
        $this->middleware->pipe(function ($req, $res, $next) use ($phpunit) {
            $phpunit->fail('Should not hit fourth handler!');
        });

        $this->request->setMethod('GET');
        $this->request->setUrl('http://local.example.com/foo');
        $this->middleware->__invoke($this->request, $this->response);
        $body = (string) $this->response->getBody();
        $this->assertContains('First', $body);
        $this->assertContains('Second', $body);
        $this->assertContains('Third', $body);
    }

    public function testHandleInvokesFirstErrorHandlerOnErrorInChain()
    {
        $this->middleware->pipe(function ($req, $res, $next) {
            $res->write("First\n");
            $next();
        });
        $this->middleware->pipe(function ($req, $res, $next) {
            $next('error');
        });
        $this->middleware->pipe(function ($req, $res, $next) {
            $res->write("Third\n");
        });
        $this->middleware->pipe(function ($err, $req, $res, $next) {
            $res->write("ERROR HANDLER\n");
        });
        $phpunit = $this;
        $this->middleware->pipe(function ($req, $res, $next) use ($phpunit) {
            $phpunit->fail('Should not hit fourth handler!');
        });

        $this->request->setMethod('GET');
        $this->request->setUrl('http://local.example.com/foo');
        $this->middleware->__invoke($this->request, $this->response);
        $body = (string) $this->response->getBody();
        $this->assertContains('First', $body);
        $this->assertContains('ERROR HANDLER', $body);
        $this->assertNotContains('Third', $body);
    }

    public function testHandleInvokesOutHandlerIfStackIsExhausted()
    {
        $triggered = null;
        $out = function ($err = null) use (&$triggered) {
            $triggered = true;
        };

        $this->middleware->pipe(function ($req, $res, $next) {
            $next();
        });
        $this->middleware->pipe(function ($req, $res, $next) {
            $next();
        });
        $this->middleware->pipe(function ($req, $res, $next) {
            $next();
        });

        $this->request->setMethod('GET');
        $this->request->setUrl('http://local.example.com/foo');
        $this->middleware->__invoke($this->request, $this->response, $out);
        $this->assertTrue($triggered);
    }

    public function testPipeWillCreateClosureForObjectImplementingHandle()
    {
        $handler = new TestAsset\NormalHandler();
        $this->middleware->pipe($handler);
        $r = new ReflectionProperty($this->middleware, 'stack');
        $r->setAccessible(true);
        $stack = $r->getValue($this->middleware);
        $route = $stack[$stack->count() - 1];
        $this->assertInstanceOf('Phly\Conduit\Route', $route);
        $handler = $route->handler;
        $this->assertInstanceOf('Closure', $handler);
        $this->assertEquals(3, Utils::getArity($handler));
    }

    public function testPipeWillCreateErrorClosureForObjectImplementingHandle()
    {
        $this->markTestIncomplete();
        $handler = new TestAsset\ErrorHandler();
        $this->middleware->pipe($handler);
        $r = new ReflectionProperty($this->middleware, 'stack');
        $r->setAccessible(true);
        $stack = $r->getValue($this->middleware);
        $route = $stack[$stack->count() - 1];
        $this->assertInstanceOf('Phly\Conduit\Route', $route);
        $handler = $route->handler;
        $this->assertInstanceOf('Closure', $handler);
        $this->assertEquals(4, Utils::getArity($handler));
    }

    public function testCanUseDecoratedRequestAndResponseDirectly()
    {
        $this->request->setMethod('GET');
        $this->request->setUrl('http://local.example.com/foo');

        $request  = new RequestDecorator($this->request);
        $response = new ResponseDecorator($this->response);
        $phpunit  = $this;
        $executed = false;

        $middleware = $this->middleware;
        $middleware->pipe(function ($req, $res, $next) use ($phpunit, $request, $response, &$executed) {
            $phpunit->assertSame($request, $req);
            $phpunit->assertSame($response, $res);
            $executed = true;
        });

        $middleware($request, $response, function ($err = null) use ($phpunit) {
            $phpunit->fail('Next should not be called');
        });

        $this->assertTrue($executed);
    }
}
