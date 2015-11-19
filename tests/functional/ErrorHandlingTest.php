<?php
namespace anlutro\L4SmartErrors\Tests;

use anlutro\LaravelTesting\PkgAppTestCase;
use anlutro\L4SmartErrors\Traits\ConfigCompatibilityTrait;
use Illuminate\Foundation\Application;
use Mockery as m;

class ErrorHandlingTest extends PkgAppTestCase
{
	use ConfigCompatibilityTrait;

	public function getVendorPath()
	{
		return __DIR__.'/../../vendor';
	}

	public function getExtraProviders()
	{
		if (version_compare(Application::VERSION, '5.0', '>=')) {
			return ['anlutro\L4SmartErrors\L5SmartErrorsServiceProvider'];
		} else {
			return ['anlutro\L4SmartErrors\L4SmartErrorsServiceProvider'];
		}
	}

	public function setUp()
	{
		if (version_compare(Application::VERSION, '5.0', '>=')) {
			$path = $this->getVendorPath().'/laravel/laravel/storage/app/l4-smart-errors.json';
		} else {
			$path = $this->getVendorPath().'/laravel/laravel/app/storage/meta/l4-smart-errors.json';
		}
		if (file_exists($path)) {
			unlink($path);
		}
		parent::setUp();
		$this->app['env'] = 'production';
		$this->setConfig('smarterror::dev-email', 'foo@bar.com');
		$this->setConfig('app.debug', false);
		$this->setConfig('mail.driver', 'sendmail');
		$this->setConfig('mail.pretend', false);
		$this->setConfig('mail.from', ['name' => 'FooBar', 'address' => 'foo@bar.com']);

		$this->app['router']->get('exception', function() {
			throw new \LogicException('L4SmartErrors test exception');
		});

		$this->app['router']->get('alert', function() {
			$this->app['log']->alert('L4SmartErrors test alert', ['foo' => 'bar']);
			return 'Logged!';
		});

		if (version_compare(Application::VERSION, '5.0', '>=')) {
			$this->setConfig('smarterror::storage-path',
				$this->getVendorPath().'/laravel/laravel/storage/app/l4-smart-errors.json');
		}
		$storPath = $this->getConfig('smarterror::storage-path');
		$this->app['files']->put($storPath, '{}');
	}

	public function tearDown()
	{
		m::close();
	}

	/**
	 * Mock a class on Laravel's IoC container.
	 *
	 * @param  string $key The IoC key - 'mail', 'log' etc
	 *
	 * @return \Mockery\MockInterface
	 */
	public function mock($key)
	{
		// more laravel retardation
		if ($key === 'swift.mailer') {
			$this->app->make('mailer')->setSwiftMailer($mock = m::mock('Swift_Mailer'));
		} else {
			$mock = m::mock(get_class($this->app->make($key)));
			$this->app->instance($key, $mock);
		}

		return $mock;
	}

	/**
	 * Define a set of strings that are expected to be in both the plaintext
	 * and HTML emails being sent by the error handler.
	 *
	 * @param  array  $strings
	 * @param  int    $times
	 *
	 * @return void
	 */
	public function expectMailBodiesContain(array $strings, $times = 1)
	{
		$this->mock('swift.mailer')->shouldReceive('send')->times($times)
			->andReturnUsing(function($msg) use($strings) {
				$this->assertMailBodiesContain($msg, $strings);
			});
	}

	public function assertMailBodiesContain($msg, array $strings)
	{
		$oldEnv = $this->app['env'];
		$this->app['env'] = 'testing';
		$html = $msg->getBody(); $children = $msg->getChildren();
		$plain = $children[0]->getBody();
		foreach ([$html, $plain] as $body) {
			foreach ($strings as $string) {
				$this->assertContains($string, $body);
			}
		}
		$this->app['env'] = $oldEnv;
	}

	/**
	 * Get the response object.
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function getResponse()
	{
		if (isset($this->client)) {
			$response = $this->client->getResponse();
		} else {
			$response = $this->response;
		}

		// If an exception happens inside the exception handler, including
		// exceptions on failed assertions by PHPUnit, the exception will not be
		// thrown but instead a 200 response with a plain string content will be
		// returned. Therefore we do a manual check and fail if this happens
		if (strpos($response->getContent(), 'Error in exception handler') !== false) {
			$this->fail($response->getContent());
		}

		return $response;
	}

	/** @test */
	public function basicErrorHandling()
	{
		$this->expectMailBodiesContain([
			'LogicException', 'L4SmartErrors test exception', __FILE__,
		]);
		$this->call('get', '/exception');
		$response = $this->getResponse();
		$this->assertEquals(500, $response->getStatusCode());
		$this->assertContains(
			$this->app['translator']->get('smarterror::error.genericErrorTitle'),
			$response->getContent()
		);
	}

	/** @test */
	public function expandedExceptionTrace()
	{
		$this->setConfig('smarterror::expand-stack-trace', true);
		$this->expectMailBodiesContain([
			'LogicException', 'L4SmartErrors test exception', __FILE__,
		]);
		$this->call('get', '/exception');
		$response = $this->getResponse();
		$this->assertEquals(500, $response->getStatusCode());
		$this->assertContains(
			$this->app['translator']->get('smarterror::error.genericErrorTitle'),
			$response->getContent()
		);
	}

	/** @test */
	public function alertLoggerHandling()
	{
		$this->expectMailBodiesContain([
			'An event with the highest possible logging level, ALERT, was registered',
			'L4SmartErrors test alert',
		]);
		
		$this->call('get', '/alert');
		$response = $this->getResponse();
		$this->assertEquals('Logged!', $response->getContent());
	}

	/** @test */
	public function missingHandling()
	{
		$this->mock('log')->shouldReceive('getMonolog')->andReturn($logger = m::mock('Psr\Log\LoggerInterface'));
		$logger->shouldReceive('warning')->once()
			->with('404 for URL http://localhost/does/not/exist - Referer: none');
		$this->call('get', '/does/not/exist');
		$response = $this->getResponse();
		$this->assertEquals(404, $response->getStatusCode());
		$this->assertContains(
			$this->app['translator']->get('smarterror::error.missingTitle'),
			$response->getContent()
		);
	}

	/**
	 * @test
	 * @dataProvider getCsrfRequestData
	 */
	public function csrfHandling($method, $referer = null)
	{
		$this->app['router']->enableFilters();
		$this->app['session']->set('_token', 'realtoken');
		$this->app['router']->any('/csrf-mismatch', ['before' => 'csrf', function() { return 'Success!'; }]);
		$this->app['router']->any('/csrf-mismatch-2', ['before' => 'csrf', function() { return 'Success!'; }]);
		$this->mock('log')->shouldReceive('getMonolog')->andReturn($logger = m::mock('Psr\Log\LoggerInterface'));
		$logger->shouldReceive('warning')->once();
		if ($referer) {
			$this->client->setServerParameter('HTTP_REFERER', $referer);
		}
		$this->call($method, '/csrf-mismatch', ['_token' => 'faketoken']);
		$response = $this->getResponse();
		$this->assertInstanceOf('Illuminate\Http\Response', $response);
		$this->assertEquals(400, $response->getStatusCode());
		$this->assertContains(
			$this->app['translator']->get('smarterror::error.csrfTitle'),
			$response->getContent()
		);
	}

	public function getCsrfRequestData()
	{
		return [
			['post', null],
			['post', 'http://localhost/csrf-mismatch'],
			['get', 'http://localhost/csrf-mismatch-2'],
		];
	}

	/** @test */
	public function csrfHandlingWithRedirect()
	{
		$this->app['router']->enableFilters();
		$this->app['session']->set('_token', 'realtoken');
		$this->app['router']->post('/csrf-mismatch', ['before' => 'csrf', function() { return 'Success!'; }]);
		$this->mock('log')->shouldReceive('getMonolog')->andReturn($logger = m::mock('Psr\Log\LoggerInterface'));
		$logger->shouldReceive('warning')->once();
		$this->client->setServerParameter('HTTP_REFERER', '/foo/bar');
		$this->call('post', '/csrf-mismatch', ['_token' => 'faketoken']);
		$response = $this->getResponse();
		$this->assertInstanceOf('Illuminate\Http\RedirectResponse', $response);
		$this->assertEquals(302, $response->getStatusCode());
		$this->assertEquals('/foo/bar', $response->getTargetUrl());
	}

	/** @test */
	public function csrfJsonHandling()
	{
		$this->app['router']->enableFilters();
		$this->app['session']->set('_token', 'realtoken');
		$this->app['router']->post('/csrf-mismatch', ['before' => 'csrf', function() { return 'Success!'; }]);
		$this->mock('log')->shouldReceive('getMonolog')->andReturn($logger = m::mock('Psr\Log\LoggerInterface'));
		$logger->shouldReceive('warning')->once();
		$this->client->setServerParameter('HTTP_X-Requested-With', 'XMLHttpRequest');
		$this->client->setServerParameter('HTTP_CONTENT_TYPE', 'application/json');
		$this->client->setServerParameter('HTTP_ACCEPT', 'application/json');
		$this->client->setServerParameter('HTTP_REFERER', '/foo/bar');
		$this->call('post', '/csrf-mismatch', ['_token' => 'faketoken']);
		$response = $this->getResponse();
		$this->assertInstanceOf('Illuminate\Http\JsonResponse', $response);
		$this->assertEquals(400, $response->getStatusCode());
		$data = $response->getData();
		$this->assertEquals([$this->app['translator']->get('smarterror::error.csrfText')], $data->errors);
	}

	/** @test */
	public function sameExceptionIsNotEmailedTwice()
	{
		// expect send() to be called only once
		$this->expectMailBodiesContain([
			'LogicException', 'L4SmartErrors test exception', __FILE__,
		]);

		// put these on the same line to make sure stack traces are identical
		$this->call('get', '/exception'); $this->call('get', '/exception');
	}

	public function getThrottleData()
	{
		return [
			[9, 2],
			[11, 3],
			[9, 3, 500],
			[3, 2, 200],
		];
	}

	/**
	 * @test
	 * @dataProvider getThrottleData
	 */
	public function reportThrottling($interval, $expectedCount, $age = null)
	{
		if ($age !== null) {
			$this->setConfig('smarterror::throttle-age', $age);
		}
		$this->app['router']->get('/time-exception', function() use($interval) {
			\Carbon\Carbon::setTestNow(\Carbon\Carbon::now()->addMinutes($interval));
			throw new \LogicException('L4SmartErrors test exception');
		});

		$this->expectMailBodiesContain([
			'LogicException', 'L4SmartErrors test exception', __FILE__,
		], $expectedCount);

		// put these on the same line to make sure stack traces are identical
		$this->call('get', '/time-exception'); $this->call('get', '/time-exception'); $this->call('get', '/time-exception');
	}

	/** @test */
	public function customAppInfoGenerator()
	{
		$this->app->bind('anlutro\L4SmartErrors\AppInfoGenerator',
			__NAMESPACE__.'\CustomAppInfoGenerator');
		$this->expectMailBodiesContain(['Custom info added!']);
		$this->call('get', '/exception');
	}

	/** @test */
	public function canSendToMultipleRecipients()
	{
		$this->setConfig('smarterror::dev-email', ['dev1@test.com', 'dev2@test.com']);
		$this->mock('swift.mailer')->shouldReceive('send')->once()
			->andReturnUsing(function($msg) {
				$this->assertEquals(['dev1@test.com' => null, 'dev2@test.com' => null], $msg->getTo());
			});
		$this->call('get', '/exception');
	}

	/** @test */
	public function sessionIsSanitized()
	{
		$this->app['session']->set('foo', 'bar');
		$this->app['session']->set('password', 'SuperSecret');
		$this->app['session']->set('nested.foo.password', 'OtherSecretStuff');
		$this->app['config']->set('smarterror::session-wipe', ['password']);

		$this->expectMailBodiesContain([
			"'foo' =>\n  string(3) \"bar\"",
			"'password' =>\n  string(6) \"HIDDEN\"",
			"'password' =>\n      string(6) \"HIDDEN\"",
		]);
		$this->call('get', '/exception');
	}

	/** @test */
	public function inputIsSanitized()
	{
		$this->app['config']->set('smarterror::input-wipe', ['password']);

		$this->expectMailBodiesContain([
			"'foo' =>\n  string(3) \"bar\"",
			"'password_confirmation' =>\n  string(6) \"HIDDEN\"",
		    "'password' =>\n  string(6) \"HIDDEN\"",
		]);
		$this->call('get', '/exception', ['foo' => 'bar', 'password' => 'foo', 'password_confirmation' => 'foo']);
	}
}

class CustomAppInfoGenerator extends \anlutro\L4SmartErrors\AppInfoGenerator
{
	public function getExtraStrings()
	{
		return ['foo' => 'Custom info added!'];
	}
}
