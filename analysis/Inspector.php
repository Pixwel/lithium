<?php
/**
 * li₃: the most RAD framework for PHP (http://li3.me)
 *
 * Copyright 2009, Union of RAD. All rights reserved. This source
 * code is distributed under the terms of the BSD 3-Clause License.
 * The full license text can be found in the LICENSE.txt file.
 */

namespace lithium\analysis;

use Exception;
use ReflectionClass;
use ReflectionProperty;
use ReflectionException;
use InvalidArgumentException;
use SplFileObject;
use lithium\core\Libraries;
use lithium\analysis\Docblock;

/**
 * General source code inspector.
 *
 * This inspector provides a simple interface to the PHP Reflection API that
 * can be used to gather information about any PHP source file for purposes of
 * test metrics or static analysis.
 */
class Inspector {

	/**
	 * Class dependencies.
	 *
	 * @var array
	 */
	protected static $_classes = [
		'collection' => 'lithium\util\Collection'
	];

	/**
	 * Maps reflect method names to result array keys.
	 *
	 * @var array
	 */
	protected static $_methodMap = [
		'name'      => 'getName',
		'start'     => 'getStartLine',
		'end'       => 'getEndLine',
		'file'      => 'getFileName',
		'comment'   => 'getDocComment',
		'namespace' => 'getNamespaceName',
		'shortName' => 'getShortName'
	];

	/**
	 * Determines if a given method can be called on an object/class.
	 *
	 * @param string|object $object Class or instance to inspect.
	 * @param string $method Name of the method.
	 * @param boolean $internal Should be `true` if you want to check from inside the
	 *                class/object. When `false` will also check for public visibility,
	 *                defaults to `false`.
	 * @return boolean Returns `true` if the method can be called, `false` otherwise.
	 */
	public static function isCallable($object, $method, $internal = false) {
		$methodExists = method_exists($object, $method);
		return $internal ? $methodExists : $methodExists && is_callable([$object, $method]);
	}

	/**
	 * Determines if a given $identifier is a class property, a class method, a class itself,
	 * or a namespace identifier.
	 *
	 * @param string $identifier The identifier to be analyzed
	 * @return string Identifier type. One of `property`, `method`, `class` or `namespace`.
	 */
	public static function type($identifier) {
		$identifier = ltrim($identifier, '\\');

		if (strpos($identifier, '::')) {
			return (strpos($identifier, '$') !== false) ? 'property' : 'method';
		}
		if (is_readable(Libraries::path($identifier))) {
			if (class_exists($identifier) && in_array($identifier, get_declared_classes())) {
				return 'class';
			}
		}
		return 'namespace';
	}

	/**
	 * Detailed source code identifier analysis.
	 *
	 * Analyzes a passed $identifier for more detailed information such
	 * as method/property modifiers (e.g. `public`, `private`, `abstract`)
	 *
	 * @param string $identifier The identifier to be analyzed
	 * @param array $info Optionally restrict or expand the default information
	 *        returned from the `info` method. By default, the information returned
	 *        is the same as the array keys contained in the `$_methodMap` property of
	 *        Inspector.
	 * @return array An array of the parsed meta-data information of the given identifier.
	 */
	public static function info($identifier, $info = []) {
		$info = $info ?: array_keys(static::$_methodMap);
		$type = static::type($identifier);
		$result = [];
		$class = null;

		if ($type === 'method' || $type === 'property') {
			list($class, $identifier) = explode('::', $identifier);

			try {
				$classInspector = new ReflectionClass($class);
			} catch (Exception $e) {
				return null;
			}

			if ($type === 'property') {
				$identifier = substr($identifier, 1);
				$accessor = 'getProperty';
			} else {
				$identifier = str_replace('()', '', $identifier);
				$accessor = 'getMethod';
			}

			try {
				$inspector = $classInspector->{$accessor}($identifier);
			} catch (Exception $e) {
				return null;
			}
			$result['modifiers'] = static::_modifiers($inspector);
		} elseif ($type === 'class') {
			$inspector = new ReflectionClass($identifier);
			$classInspector = null;
		} else {
			return null;
		}

		foreach ($info as $key) {
			if (!isset(static::$_methodMap[$key])) {
				continue;
			}
			if (method_exists($inspector, static::$_methodMap[$key])) {
				$setAccess = (
					($type === 'method' || $type === 'property') &&
					array_intersect($result['modifiers'], ['private', 'protected']) !== [] &&
					method_exists($inspector, 'setAccessible')
				);

				if ($setAccess) {
					$inspector->setAccessible(true);
				}
				$result[$key] = $inspector->{static::$_methodMap[$key]}();

				if ($setAccess) {
					$inspector->setAccessible(false);
				}
			}
		}

		if ($type === 'property' && $classInspector && !$classInspector->isAbstract()) {
			$inspector->setAccessible(true);

			try {
				$result['value'] = $inspector->getValue(static::_class($class));
			} catch (Exception $e) {
				return null;
			}
		}

		if (isset($result['start']) && isset($result['end'])) {
			$result['length'] = $result['end'] - $result['start'];
		}
		if (isset($result['comment'])) {
			$result += Docblock::comment($result['comment']);
		}
		return $result;
	}

	/**
	 * Gets the executable lines of a class, by examining the start and end lines of each method.
	 *
	 * @param mixed $class Class name as a string or object instance.
	 * @param array $options Set of options:
	 *        - `'self'` _boolean_: If `true` (default), only returns lines of methods defined in
	 *          `$class`, excluding methods from inherited classes.
	 *        - `'methods'` _array_: An arbitrary list of methods to search, as a string (single
	 *          method name) or array of method names.
	 *        - `'filter'` _boolean_: If `true`, filters out lines containing only whitespace or
	 *          braces. Note: for some reason, the Zend engine does not report `switch` and `try`
	 *          statements as executable lines, as well as parts of multi-line assignment
	 *          statements, so they are filtered out as well.
	 * @return array Returns an array of the executable line numbers of the class.
	 */
	public static function executable($class, array $options = []) {
		$defaults = [
			'self' => true,
			'filter' => true,
			'methods' => [],
			'empty' => [' ', "\t", '}', ')', ';'],
			'pattern' => null,
			'blockOpeners' => ['switch (', 'try {', '} else {', 'do {', '} while']
		];
		$options += $defaults;

		if (empty($options['pattern']) && $options['filter']) {
			$pattern = str_replace(' ', '\s*', join('|', array_map(
				function($str) { return preg_quote($str, '/'); },
				$options['blockOpeners']
			)));
			$pattern = join('|', [
				"({$pattern})",
				"\\$(.+)\($",
				"\s*['\"]\w+['\"]\s*=>\s*.+[\{\(]$",
				"\s*['\"]\w+['\"]\s*=>\s*['\"]*.+['\"]*\s*"
			]);
			$options['pattern'] = "/^({$pattern})/";
		}

		if (!$class instanceof ReflectionClass) {
			$class = new ReflectionClass(is_object($class) ? get_class($class) : $class);
		}
		$options += ['group' => false];
		$result = array_filter(static::methods($class, 'ranges', $options));

		if ($options['filter'] && $class->getFileName() && $result) {
			$lines = static::lines($class->getFileName(), $result);
			$start = key($lines);

			$code = implode("\n", $lines);
			$tokens = token_get_all('<' . '?php' . $code);
			$tmp = [];

			foreach ($tokens as $token) {
				if (is_array($token)) {
					if (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
						$tmp[] = $token[2];
					}
				}
			}

			$filteredLines = array_values(array_map(
				function($ln) use ($start) { return $ln + $start - 1; },
				array_unique($tmp))
			);

			$lines = array_intersect_key($lines, array_flip($filteredLines));

			$result = array_keys(array_filter($lines, function($line) use ($options) {
				$line = trim($line);
				$empty = preg_match($options['pattern'], $line);
				return $empty ? false : (str_replace($options['empty'], '', $line) !== '');
			}));
		}
		return $result;
	}

	/**
	 * Returns various information on the methods of an object, in different formats.
	 *
	 * @param string|object $class A string class name for purely static classes or an object
	 *        instance, from which to get methods.
	 * @param string $format The type and format of data to return. Available options are:
	 *        - `null`: Returns a `Collection` object containing a `ReflectionMethod` instance
	 *         for each method.
	 *        - `'extents'`: Returns a two-dimensional array with method names as keys, and
	 *         an array with starting and ending line numbers as values.
	 *        - `'ranges'`: Returns a two-dimensional array where each key is a method name,
	 *         and each value is an array of line numbers which are contained in the method.
	 * @param array $options Set of options applied directly (check `_items()` for more options):
	 *        - `'methods'` _array_: An arbitrary list of methods to search, as a string (single
	 *          method name) or array of method names.
	 *        - `'group'`: If true (default) the array is grouped by context (ex.: method name), if
	 *         false the results are sequentially appended to an array.
	 *        -'self': If true (default), only returns properties defined in `$class`,
	 *         excluding properties from inherited classes.
	 * @return mixed Return value depends on the $format given:
	 *        - `null` on failure.
	 *        - `lithium\util\Collection` if $format is `null`
	 *        - `array` if $format is either `'extends'` or `'ranges'`.
	 */
	public static function methods($class, $format = null, array $options = []) {
		$defaults = ['methods' => [], 'group' => true, 'self' => true];
		$options += $defaults;

		if (!(is_object($class) && $class instanceof ReflectionClass)) {
			try {
				$class = new ReflectionClass($class);
			} catch (ReflectionException $e) {
				return null;
			}
		}
		$options += ['names' => $options['methods']];
		$methods = static::_items($class, 'getMethods', $options);
		$result = [];

		switch ($format) {
			case null:
				return $methods;
			case 'extents':
				if ($methods->getName() === []) {
					return [];
				}

				$extents = function($start, $end) { return [$start, $end]; };
				$result = array_combine($methods->getName(), array_map(
					$extents, $methods->getStartLine(), $methods->getEndLine()
				));
			break;
			case 'ranges':
				$ranges = function($lines) {
					list($start, $end) = $lines;
					return ($end <= $start + 1) ? [] : range($start + 1, $end - 1);
				};
				$result = array_map($ranges, static::methods(
					$class, 'extents', ['group' => true] + $options
				));
			break;
		}

		if ($options['group']) {
			return $result;
		}
		$tmp = $result;
		$result = [];

		array_map(function($ln) use (&$result) { $result = array_merge($result, $ln); }, $tmp);
		return $result;
	}

	/**
	 * Returns various information on the properties of an object.
	 *
	 * @param string|object $class A string class name for purely static classes or an object
	 *        instance, from which to get properties.
	 * @param array $options Set of options applied directly (check `_items()` for more options):
	 *        - `'properties'`: array of properties to gather information from.
	 *        - `'self'`: If true (default), only returns properties defined in `$class`,
	 *         excluding properties from inherited classes.
	 * @return mixed Returns an array with information about the properties from the class given in
	 *               $class or null on error.
	 */
	public static function properties($class, array $options = []) {
		$defaults = ['properties' => [], 'self' => true];
		$options += $defaults;

		try {
			$reflClass = new ReflectionClass($class);
		} catch (ReflectionException $e) {
			return null;
		}
		$options += ['names' => $options['properties']];

		return static::_items($reflClass, 'getProperties', $options)->map(function($item) use ($class) {
			$modifiers = array_values(static::_modifiers($item));
			$setAccess = (
				array_intersect($modifiers, ['private', 'protected']) !== []
			);
			if ($setAccess) {
				$item->setAccessible(true);
			}
			if (is_string($class)) {
				if (!$item->isStatic()) {
					$message = 'Must provide an object instance for non-static properties.';
					throw new InvalidArgumentException($message);
				}
				$value = $item->getValue($item->getDeclaringClass());
			} else {
				$value = $item->getValue($class);
			}
			$result = compact('modifiers', 'value') + [
				'docComment' => $item->getDocComment(),
				'name' => $item->getName()
			];
			if ($setAccess) {
				$item->setAccessible(false);
			}
			return $result;
		}, ['collect' => false]);
	}

	/**
	 * Returns an array of lines from a file, class, or arbitrary string, where $data is the data
	 * to read the lines from and $lines is an array of line numbers specifying which lines should
	 * be read.
	 *
	 * @param string $data If `$data` contains newlines, it will be read from directly, and have
	 *        its own lines returned.  If `$data` is a physical file path, that file will be
	 *        read and have its lines returned.  If `$data` is a class name, it will be
	 *        converted into a physical file path and read.
	 * @param array $lines The array of lines to read. If a given line is not present in the data,
	 *        it will be silently ignored.
	 * @return array Returns an array where the keys are matching `$lines`, and the values are the
	 *         corresponding line numbers in `$data`.
	 * @todo Add an $options parameter with a 'context' flag, to pull in n lines of context.
	 */
	public static function lines($data, $lines) {
		$c = [];

		if (strpos($data, PHP_EOL) !== false) {
			$c = explode(PHP_EOL, PHP_EOL . $data);
		} else {
			if (!file_exists($data)) {
				$data = Libraries::path($data);
				if (!file_exists($data)) {
					return null;
				}
			}

			$file = new SplFileObject($data);
			foreach ($file as $current) {
				$c[$file->key() + 1] = rtrim($file->current());
			}
		}

		if (!count($c) || !count($lines)) {
			return null;
		}
		return array_intersect_key($c, array_combine($lines, array_fill(0, count($lines), null)));
	}

	/**
	 * Gets the full inheritance list for the given class.
	 *
	 * @param string $class Class whose inheritance chain will be returned
	 * @param array $options Option consists of:
	 *        - `'autoLoad'` _boolean_: Whether or not to call `__autoload` by default. Defaults
	 *          to `true`.
	 * @return array An array of the name of the parent classes of the passed `$class` parameter,
	 *         or `false` on error.
	 * @link http://php.net/function.class-parents.php PHP Manual: `class_parents()`.
	 */
	public static function parents($class, array $options = []) {
		$defaults = ['autoLoad' => false];
		$options += $defaults;
		$class = is_object($class) ? get_class($class) : $class;

		if (!class_exists($class, $options['autoLoad'])) {
			return false;
		}
		return class_parents($class);
	}

	/**
	 * Gets an array of classes and their corresponding definition files, or examines a file and
	 * returns the classes it defines.
	 *
	 * @param array $options Option consists of:
	 *        - `'group'`: Can be `classes` for grouping by class name or `files` for grouping by
	 *         filename.
	 *         - `'file': Valid file path for inspecting the containing classes.
	 * @return array Associative of classes and their corresponding definition files
	 */
	public static function classes(array $options = []) {
		$defaults = ['group' => 'classes', 'file' => null];
		$options += $defaults;

		$list = get_declared_classes();
		$files = get_included_files();
		$classes = [];

		if ($file = $options['file']) {
			$loaded = Libraries::instance(null, 'collection', ['data' => array_map(
				function($class) { return new ReflectionClass($class); }, $list
			)], static::$_classes);
			$classFiles = $loaded->getFileName();

			if (in_array($file, $files) && !in_array($file, $classFiles)) {
				return [];
			}
			if (!in_array($file, $classFiles)) {
				include $file;
				$list = array_diff(get_declared_classes(), $list);
			} else {
				$filter = function($class) use ($file) { return $class->getFileName() === $file; };
				$list = $loaded->find($filter)->map(function ($class) {
					return $class->getName() ?: $class->name;
				}, ['collect' => false]);
			}
		}

		foreach ($list as $class) {
			$inspector = new ReflectionClass($class);

			if ($options['group'] === 'classes') {
				$inspector->getFileName() ? $classes[$class] = $inspector->getFileName() : null;
			} elseif ($options['group'] === 'files') {
				$classes[$inspector->getFileName()][] = $inspector;
			}
		}
		return $classes;
	}

	/**
	 * Gets the static and dynamic dependencies for a class or group of classes.
	 *
	 * @param mixed $classes Either a string specifying a class, or a numerically indexed array
	 *        of classes
	 * @param array $options Option consists of:
	 *        - `'type'`: The type of dependency to check: `static` for static dependencies,
	 *         `dynamic`for dynamic dependencies or `null` for both merged in the same array.
	 *         Defaults to `null`.
	 * @return array An array of the static and dynamic class dependencies or each if `type` is
	 *         defined in $options.
	 */
	public static function dependencies($classes, array $options = []) {
		$defaults = ['type' => null];
		$options += $defaults;
		$static = $dynamic = [];
		$trim = function($c) { return trim(trim($c, '\\')); };
		$join = function($i) { return join('', $i); };

		foreach ((array) $classes as $class) {
			$data = explode("\n", file_get_contents(Libraries::path($class)));
			$data = "<?php \n" . join("\n", preg_grep('/^\s*use /', $data)) . "\n ?>";

			$classes = array_map($join, Parser::find($data, 'use *;', [
				'return'      => 'content',
				'lineBreaks'  => true,
				'startOfLine' => true,
				'capture'     => ['T_STRING', 'T_NS_SEPARATOR']
			]));

			if ($classes) {
				$static = array_unique(array_merge($static, array_map($trim, $classes)));
			}
			$classes = static::info($class . '::$_classes', ['value']);

			if (isset($classes['value'])) {
				$dynamic = array_merge($dynamic, array_map($trim, array_values($classes['value'])));
			}
		}

		if (empty($options['type'])) {
			return array_unique(array_merge($static, $dynamic));
		}
		$type = $options['type'];
		return isset(${$type}) ? ${$type} : null;
	}

	/**
	 * Returns an instance of the given class without directly instantiating it. Inspired by the
	 * work of Sebastian Bergmann on the PHP Object Freezer project.
	 *
	 * @link http://sebastian-bergmann.de/archives/831-Freezing-and-Thawing-PHP-Objects.html
	 *       Freezing and Thawing PHP Objects
	 * @param string $class The name of the class to return an instance of.
	 * @return object Returns an instance of the object given by `$class` without calling that
	 *        class' constructor.
	 */
	protected static function _class($class) {
		if (!class_exists($class)) {
			throw new RuntimeException(sprintf('Class `%s` could not be found.', $class));
		}
		return unserialize(sprintf('O:%d:"%s":0:{}', strlen($class), $class));
	}

	/**
	 * Helper method to get an array of `ReflectionMethod` or `ReflectionProperty` objects, wrapped
	 * in a `Collection` object, and filtered based on a set of options.
	 *
	 * @param ReflectionClass $class A reflection class instance from which to fetch.
	 * @param string $method A getter method to call on the `ReflectionClass` instance, which will
	 *               return an array of items, i.e. `'getProperties'` or `'getMethods'`.
	 * @param array $options The options used to filter the resulting method list.
	 *         - `'names'`: array of properties for filtering the result.
	 *         - `'self'`: If true (default), only returns properties defined in `$class`,
	 *         excluding properties from inherited classes.
	 *         - `'public'`: If true (default) forces the property to be recognized as public.
	 * @return object Returns a `Collection` object instance containing the results of the items
	 *         returned from the call to the method specified in `$method`, after being passed
	 *         through the filters specified in `$options`.
	 */
	protected static function _items($class, $method, $options) {
		$defaults = ['names' => [], 'self' => true, 'public' => true];
		$options += $defaults;

		$params = [
			'getProperties' => ReflectionProperty::IS_PUBLIC | (
				$options['public'] ? 0 : ReflectionProperty::IS_PROTECTED
			)
		];
		$data = isset($params[$method]) ? $class->{$method}($params[$method]) : $class->{$method}();

		if (!empty($options['names'])) {
			$data = array_filter($data, function($item) use ($options) {
				return in_array($item->getName(), (array) $options['names']);
			});
		}

		if ($options['self']) {
			$data = array_filter($data, function($item) use ($class) {
				return ($item->getDeclaringClass()->getName() === $class->getName());
			});
		}

		if ($options['public']) {
			$data = array_filter($data, function($item) { return $item->isPublic(); });
		}
		return Libraries::instance(null, 'collection', compact('data'), static::$_classes);
	}

	/**
	 * Helper method to determine if a class applies to a list of modifiers.
	 *
	 * @param string $inspector ReflectionClass instance.
	 * @param array|string $list List of modifiers to test.
	 * @return boolean Test result.
	 */
	protected static function _modifiers($inspector, $list = []) {
		$list = $list ?: ['public', 'private', 'protected', 'abstract', 'final', 'static'];
		return array_filter($list, function($modifier) use ($inspector) {
			$method = 'is' . ucfirst($modifier);
			return (method_exists($inspector, $method) && $inspector->{$method}());
		});
	}
}

?>