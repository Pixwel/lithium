<?php
namespace lithium\tests\cases\net\socket;

use lithium\net\socket\Context;
use ReflectionProperty;

class ContextTest extends \lithium\test\Unit {
	public function testWriteUpdatesStreamOptions() {
		$socket = new Context();
		$resource = fopen('php://temp', 'r+');
		$property = new ReflectionProperty($socket, '_resource');
		$property->setValue($socket, $resource);
		$message = new class {
			public function to($format, $options) {
				return ['http' => ['method' => 'POST', 'timeout' => $options['timeout']]];
			}
		};
		$this->assertTrue($socket->write($message));
		$options = stream_context_get_options($resource);
		$this->assertEqual('POST', $options['http']['method']);
		$this->assertEqual(30, $options['http']['timeout']);
		$socket->close();
	}
}
