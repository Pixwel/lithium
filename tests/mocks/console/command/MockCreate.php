<?php
/**
 * li₃: the most RAD framework for PHP (http://li3.me)
 *
 * Copyright 2009, Union of RAD. All rights reserved. This source
 * code is distributed under the terms of the BSD 3-Clause License.
 * The full license text can be found in the LICENSE.txt file.
 */

namespace lithium\tests\mocks\console\command;

class MockCreate extends \lithium\console\command\Create {

	protected $_classes = [
		'response' => 'lithium\tests\mocks\console\MockResponse'
	];

	public function save($template, $params = []) {
		return $this->_save($template, $params);
	}

	public function namespace($request, $options  = []) {
		return parent::_namespace($request, $options);
	}
}

?>