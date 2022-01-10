<?php
/**
 * li₃: the most RAD framework for PHP (http://li3.me)
 *
 * Copyright 2009, Union of RAD. All rights reserved. This source
 * code is distributed under the terms of the BSD 3-Clause License.
 * The full license text can be found in the LICENSE.txt file.
 */

namespace lithium\analysis;

use lithium\util\Set;
use lithium\util\Collection;

/**
 * The parser class uses PHP's tokenizer to provide methods and tools for performing static analysis
 * on PHP code.
 */
class Parser extends \lithium\core\StaticObjectDeprecated {

	/**
	 * Convenience method to get the token name of a PHP code string. If multiple tokens are
	 * present in the string, only the first is returned.
	 *
	 * @param string $string String of PHP code to get the token name of, i.e. `'=>'` or `'static'`.
	 * @param array $options
	 * @return mixed
	 */
	public static function token($string, array $options = []) {
		$defaults = ['id' => false];
		$options += $defaults;

		if (empty($string) && $string !== '0') {
			return false;
		}
		list($token) = static::tokenize($string);
		return $token[($options['id']) ? 'id' : 'name'];
	}

	/**
	 * Splits the provided `$code` into PHP language tokens.
	 *
	 * @param string $code Source code to be tokenized.
	 * @param array $options Options consists of:
	 *        -'wrap': Boolean indicating whether or not to wrap the supplied
	 *          code in PHP tags.
	 *        -'ignore': An array containing PHP language tokens to ignore.
	 *        -'include': If supplied, an array of the only language tokens
	 *         to include in the output.
	 * @return array An array of tokens in the supplied source code.
	 */
	public static function tokenize($code, array $options = []) {
		$defaults = ['wrap' => true, 'ignore' => [], 'include' => []];
		$options += $defaults;
		$tokens = [];
		$line = 1;

		if ($options['wrap']) {
			$code = "<?php {$code}?>";
		}
		foreach (token_get_all($code) as $token) {
			$token = (isset($token[1])) ? $token : [null, $token, $line];
			list($id, $content, $line) = $token;
			$name = $id ? token_name($id) : $content;

			if (!empty($options['include'])) {
				if (!in_array($name, $options['include']) && !in_array($id, $options['include'])) {
					continue;
				}
			}

			if (!empty($options['ignore'])) {
				if (in_array($name, $options['ignore']) || in_array($id, $options['ignore'])) {
					continue;
				}
			}
			$tokens[] = ['id' => $id, 'name' => $name, 'content' => $content, 'line' => $line];

			$line += count(preg_split('/\r\n|\r|\n/', $content)) - 1;
		}

		if ($options['wrap'] && empty($options['include'])) {
			$tokens = array_slice($tokens, 1, count($tokens) - 2);
		}
		return $tokens;
	}

	/**
	 * Finds a pattern in a block of code.
	 *
	 * @param string $code
	 * @param string $pattern
	 * @param array $options The list of options to be used when parsing / matching `$code`:
	 *              - 'ignore': An array of token names to ignore while parsing, defaults to
	 *               `array('T_WHITESPACE')`
	 *              - 'lineBreaks': If true, all tokens in a single pattern match must appear on the
	 *                same line of code, defaults to false
	 *              - 'startOfLine': If true, the pattern must match starting with the beginning of
	 *                the line of code to be matched, defaults to false
	 * @return array
	 */
	public static function find($code, $pattern, array $options = []) {
		$defaults = [
			'all' => true, 'capture' => [], 'ignore' => ['T_WHITESPACE'],
			'return' => true, 'lineBreaks' => false, 'startOfLine' => false
		];
		$options += $defaults;
		$results = [];
		$matches = [];
		$patternMatch = [];
		$ret = $options['return'];

		$tokens = new Collection(['data' => static::tokenize($code, $options)]);
		$pattern = new Collection(['data' => static::tokenize($pattern, $options)]);

		$breaks = function($token) use (&$tokens, &$matches, &$patternMatch, $options) {
			if (!$options['lineBreaks']) {
				return true;
			}
			if (empty($patternMatch) && !$options['startOfLine']) {
				return true;
			}

			if (empty($patternMatch)) {
				$prev = $tokens->prev();
				$tokens->getNext();
			} else {
				$prev = reset($patternMatch);
			}

			if (empty($patternMatch) && $options['startOfLine']) {
				return ($token['line'] > $prev['line']);
			}
			return ($token['line'] === $prev['line']);
		};

		$capture = function($token) use (&$matches, &$patternMatch, $tokens, $breaks, $options) {
			if ($token === null) {
				$matches = $patternMatch = [];
				return false;
			}

			if (empty($patternMatch)) {
				$prev = $tokens->prev();
				$tokens->getNext();
				if ($options['startOfLine'] && $token['line'] === $prev['line']) {
					$patternMatch = $matches = [];
					return false;
				}
			}
			$patternMatch[] = $token;

			if (empty($options['capture']) || !in_array($token['name'], $options['capture'])) {
				return true;
			}
			if (!$breaks($token)) {
				$matches = [];
				return true;
			}
			$matches[] = $token;
			return true;
		};

		$executors = [
			'*' => function(&$tokens, &$pattern) use ($options, $capture) {
				$closing = $pattern->getNext();
				$tokens->prev();

				while (($t = $tokens->getNext()) && !Parser::matchToken($closing, $t)) {
					$capture($t);
				}
				$pattern->getNext();
			}
		];

		$tokens->rewind();
		$pattern->rewind();

		while ($tokens->valid()) {
			if (!$pattern->valid()) {
				$pattern->rewind();

				if (!empty($matches)) {
					$results[] = array_map(
						function($i) use ($ret) { return isset($i[$ret]) ? $i[$ret] : $i; },
						$matches
					);
				}
				$capture(null);
			}

			$p = $pattern->current();
			$t = $tokens->current();

			switch (true) {
				case (static::matchToken($p, $t)):
					$capture($t) ? $pattern->getNext() : $pattern->getRewind();
				break;
				case (isset($executors[$p['name']])):
					$exec = $executors[$p['name']];
					$exec($tokens, $pattern);
				break;
				default:
					$capture(null);
					$pattern->rewind();
				break;
			}
			$tokens->getNext();
		}
		return $results;
	}

	/**
	 * Token pattern matching.
	 *
	 * @param string $code Source code to be analyzed.
	 * @param string $parameters An array containing token patterns to be matched.
	 * @param array $options The list of options to be used when matching `$code`:
	 *              - 'ignore': An array of language tokens to ignore.
	 *              - 'return': If set to 'content' returns an array of matching tokens.
	 * @return array Array of matching tokens.
	 */
	public static function match($code, $parameters, array $options = []) {
		$defaults = ['ignore' => ['T_WHITESPACE'], 'return' => true];
		$options += $defaults;
		$parameters = static::_prepareMatchParams($parameters);

		$tokens = is_array($code) ? $code : static::tokenize($code, $options);
		$results = [];

		foreach ($tokens as $i => $token) {
			if (!array_key_exists($token['name'], $parameters)) {
				if (!in_array('*', $parameters)) {
					continue;
				}
			}
			$param = $parameters[$token['name']];

			if (isset($param['before']) && $i > 0) {
				if (!in_array($tokens[$i - 1]['name'], (array) $param['before'])) {
					continue;
				}
			}

			if (isset($param['after']) && $i + 1 < count($tokens)) {
				 if (!in_array($tokens[$i + 1]['name'], (array) $param['after'])) {
					continue;
				}
			}
			$results[] = isset($token[$options['return']]) ? $token[$options['return']] : $token;
		}
		return $results;
	}

	/**
	 * Compares two PHP language tokens.
	 *
	 * @param array $pattern Pattern token.
	 * @param array $token Token to be compared.
	 * @return boolean Match result.
	 */
	public static function matchToken($pattern, $token) {
		if ($pattern['name'] !== $token['name']) {
			return false;
		}

		if (!isset($pattern['content'])) {
			return true;
		}

		$match = $pattern['content'];
		$content = $token['content'];

		if ($pattern['name'] === 'T_VARIABLE') {
			$match = substr($match, 1);
			$content = substr($content, 1);
		}

		switch (true) {
			case ($match === '_' || $match === $content):
				return true;
		}
		return false;
	}

	/**
	 * Helper function to normalize parameters for token matching.
	 *
	 * @see lithium\analysis\Parser::match()
	 * @param array|string $parameters Params to be normalized.
	 * @return array Normalized parameters.
	 */
	protected static function _prepareMatchParams($parameters) {
		foreach (Set::normalize($parameters) as $token => $scope) {
			if (strpos($token, 'T_') !== 0) {
				unset($parameters[$token]);

				foreach (['before', 'after'] as $key) {
					if (!isset($scope[$key])) {
						continue;
					}
					$items = [];

					foreach ((array) $scope[$key] as $item) {
						$items[] = (strpos($item, 'T_') !== 0)  ? static::token($item) : $item;
					}
					$scope[$key] = $items;
				}
				$parameters[static::token($token)] = $scope;
			}
		}
		return $parameters;
	}
}

?>