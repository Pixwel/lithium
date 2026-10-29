<?php
/**
 * li₃: the most RAD framework for PHP (http://li3.me)
 *
 * Copyright 2009, Union of RAD. All rights reserved. This source
 * code is distributed under the terms of the BSD 3-Clause License.
 * The full license text can be found in the LICENSE.txt file.
 */

namespace lithium\tests\cases\data\source;

use lithium\data\source\MongoDb;

class MongoDbConditionsTest extends \lithium\test\Unit {
	public function testArrayAndOperatorConditions() {
		$db = new MongoDb(['autoConnect' => false]);
		$this->assertEqual(['_id' => ['$in' => []]], $db->conditions(['_id' => []], null));
		$this->assertEqual(['_id' => ['$in' => [1, 2]]], $db->conditions(['_id' => [1, 2]], null));
		$this->assertEqual(['age' => ['$gt' => 3]], $db->conditions(['age' => ['>' => 3]], null));
	}
}
