<?php
/*
 * This file is part of the "t3-debugger-utility-standalone" library.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2019 Wolf Utz
 */

namespace OmegaCode;

/**
 * This class is a port of the corresponding class of the TYPO3 CMS (v9.5).
 * All credits go to the TYPO3 team.
 *
 * A debugging utility class
 */
class DebuggerUtility
{
    const PLAINTEXT_INDENT = '   ';
    const HTML_INDENT = '&nbsp;&nbsp;&nbsp;';
    const DEFAULT_TITLE = 'Extbase Variable Dump';

    /**
     * @var array
     */
    protected static $renderedObjects = [];

    /**
     * Hardcoded list of Extbase class names (regex) which should not be displayed during debugging.
     *
     * @var array
     */
    protected static $blacklistedClassNames = [
        'PHPUnit_Framework_MockObject_InvocationMocker',
    ];

    /**
     * Hardcoded list of property names (regex) which should not be displayed during debugging.
     *
     * @var array
     */
    protected static $blacklistedPropertyNames = ['warning'];

    /**
     * Is set to TRUE once the CSS file is included in the current page to prevent double inclusions of the CSS file.
     *
     * @var bool
     */
    protected static $stylesheetEchoed = false;

    /**
     * Defines the max recursion depth of the dump, set to 8 due to common memory limits.
     *
     * @var int
     */
    protected static $maxDepth = 8;

    /**
     * Clear the state of the debugger.
     */
    protected static function clearState()
    {
        self::$renderedObjects = [];
    }

    /**
     * Renders a dump of the given value.
     *
     * @param mixed $value
     * @param int   $level
     * @param bool  $plainText
     * @param bool  $ansiColors
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    protected static function renderDump($value, $level, $plainText, $ansiColors)
    {
        $dump = '';
        if (is_string($value)) {
            $croppedValue = strlen($value) > 2000 ? substr($value, 0, 2000).'...' : $value;
            if ($plainText) {
                $value = implode(
                    PHP_EOL.str_repeat(self::PLAINTEXT_INDENT, $level + 1),
                    str_split($croppedValue, 76)
                );
                $dump = Utility\OutputUtility::ansiEscapeWrap(
                    '"'.$value.'"',
                    '33',
                    $ansiColors
                );
                $dump .= ' ('.strlen($value).' chars)';
            } else {
                $lines = str_split($croppedValue, 76);
                $lines = array_map('htmlspecialchars', $lines);
                $dump = sprintf(
                    '\'<span class="extbase-debug-string">%s</span>\' (%s chars)',
                    implode(
                        '<br />'.str_repeat(self::HTML_INDENT, $level + 1),
                        $lines
                    ),
                    strlen($value)
                );
            }
        } elseif (is_numeric($value)) {
            $dump = sprintf(
                '%s (%s)',
                Utility\OutputUtility::ansiEscapeWrap($value, '35', $ansiColors),
                gettype($value)
            );
        } elseif (is_bool($value)) {
            $dump = $value ? Utility\OutputUtility::ansiEscapeWrap('TRUE', '32', $ansiColors) :
                Utility\OutputUtility::ansiEscapeWrap('FALSE', '32', $ansiColors);
        } elseif (null === $value || is_resource($value)) {
            $dump = gettype($value);
        } elseif (is_array($value)) {
            $dump = self::renderArray($value, $level + 1, $plainText, $ansiColors);
        } elseif (is_object($value)) {
            if ($value instanceof \Closure) {
                $dump = self::renderClosure($value, $level + 1, $plainText, $ansiColors);
            } else {
                $dump = self::renderObject($value, $level + 1, $plainText, $ansiColors);
            }
        }

        return $dump;
    }

    /**
     * Renders a dump of the given array.
     *
     * @param array|\Traversable $array
     * @param int                $level
     * @param bool               $plainText
     * @param bool               $ansiColors
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    protected static function renderArray($array, $level, $plainText = false, $ansiColors = false)
    {
        $content = '';
        $count = count((array) $array);
        if ($plainText) {
            $header = Utility\OutputUtility::ansiEscapeWrap('array', '36', $ansiColors);
        } else {
            $header = '<span class="extbase-debug-type">array</span>';
        }
        $header .= $count > 0 ? '('.$count.' item'.($count > 1 ? 's' : '').')' : '(empty)';
        if ($level >= self::$maxDepth) {
            if ($plainText) {
                $header .= ' '.Utility\OutputUtility::ansiEscapeWrap('max depth', '47;30', $ansiColors);
            } else {
                $header .= '<span class="extbase-debug-filtered">max depth</span>';
            }
        } else {
            $content = self::renderCollection($array, $level, $plainText, $ansiColors);
            if (!$plainText) {
                $header = ($level > 1 && $count > 0 ?
                        '<input type="checkbox" /><span class="extbase-debug-header" >' : '<span>').$header.'</span >';
            }
        }
        if ($level > 1 && $count > 0 && !$plainText) {
            $dump = '<span class="extbase-debugger-tree">'.$header.'<span class="extbase-debug-content">'.
                $content.'</span></span>';
        } else {
            $dump = $header.$content;
        }

        return $dump;
    }

    /**
     * Renders a dump of the given object.
     *
     * @param object $object
     * @param int    $level
     * @param bool   $plainText
     * @param bool   $ansiColors
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    protected static function renderObject($object, $level, $plainText = false, $ansiColors = false)
    {
        $header = self::renderHeader($object, $level, $plainText, $ansiColors);
        if ($level < self::$maxDepth && !self::isBlacklisted($object) &&
            !(self::isAlreadyRendered($object) && true !== $plainText)
        ) {
            $content = self::renderContent($object, $level, $plainText, $ansiColors);
        } else {
            $content = '';
        }
        if ($plainText) {
            return $header.$content;
        }

        return '<span class="extbase-debugger-tree">'.$header.'<span class="extbase-debug-content">'.
            $content.'</span></span>';
    }

    /**
     * Renders a dump of the given closure.
     *
     * @param \Closure $object
     * @param int      $level
     * @param bool     $plainText
     * @param bool     $ansiColors
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    protected static function renderClosure($object, $level, $plainText = false, $ansiColors = false)
    {
        $header = self::renderHeader($object, $level, $plainText, $ansiColors);
        if ($level < self::$maxDepth && (!self::isAlreadyRendered($object) || $plainText)) {
            $content = self::renderContent($object, $level, $plainText, $ansiColors);
        } else {
            $content = '';
        }
        if ($plainText) {
            return $header.$content;
        }

        return '<span class="extbase-debugger-tree"><input type="checkbox" /><span class="extbase-debug-header">'.
            $header.'</span><span class="extbase-debug-content">'.$content.'</span></span>';
    }

    /**
     * Checks if a given object or property should be excluded/filtered.
     *
     * @param object $value An ReflectionProperty or other Object
     *
     * @return bool TRUE if the given object should be filtered
     */
    protected static function isBlacklisted($value)
    {
        $result = false;
        if ($value instanceof \ReflectionProperty) {
            $result = in_array($value->getName(), self::$blacklistedPropertyNames, true);
        } elseif (is_object($value)) {
            $result = in_array(get_class($value), self::$blacklistedClassNames, true);
        }

        return $result;
    }

    /**
     * Checks if a given object was already rendered.
     *
     * @param object $object
     *
     * @return bool TRUE if the given object was already rendered
     */
    protected static function isAlreadyRendered($object)
    {
        return self::renderedObjectsContains($object);
    }

    /**
     * Renders the header of a given object/collection. It is usually the class name along with some flags.
     *
     * @param object $object
     * @param int    $level
     * @param bool   $plainText
     * @param bool   $ansiColors
     *
     * @return string The rendered header with tags
     *
     * @throws \ReflectionException
     */
    protected static function renderHeader($object, $level, $plainText, $ansiColors)
    {
        $dump = '';
        $className = get_class($object);
        $classReflection = new \ReflectionClass($className);
        if ($plainText) {
            $dump .= Utility\OutputUtility::ansiEscapeWrap($className, '36', $ansiColors);
        } else {
            $dump .= '<span class="extbase-debug-type">'.htmlspecialchars($className).'</span>';
        }
        if (!$object instanceof \Closure) {
            $scope = 'prototype';
            if ($plainText) {
                $dump .= ' '.Utility\OutputUtility::ansiEscapeWrap($scope, '44;37', $ansiColors);
            } else {
                $dump .= '<span class="extbase-debug-scope">'.$scope.'</span>';
            }
            $domainObjectType = 'object';
            if ($plainText) {
                $dump .= ' '.Utility\OutputUtility::ansiEscapeWrap(
                    $domainObjectType,
                    '42;30',
                    $ansiColors
                );
            } else {
                $dump .= '<span class="extbase-debug-ptype">'.$domainObjectType.'</span>';
            }
        }
        if (strpos(implode('|', self::$blacklistedClassNames), get_class($object)) > 0) {
            if ($plainText) {
                $dump .= ' '.Utility\OutputUtility::ansiEscapeWrap('filtered', '47;30', $ansiColors);
            } else {
                $dump .= '<span class="extbase-debug-filtered">filtered</span>';
            }
        } elseif (self::renderedObjectsContains($object) && !$plainText) {
            $dump = '<a href="javascript:;" onclick="document.location.hash=\'#'.spl_object_hash($object).
                '\';" class="extbase-debug-seeabove">'.$dump.
                '<span class="extbase-debug-filtered">see above</span></a>';
        } elseif ($level >= self::$maxDepth && !$object instanceof \DateTimeInterface) {
            if ($plainText) {
                $dump .= ' '.Utility\OutputUtility::ansiEscapeWrap('max depth', '47;30', $ansiColors);
            } else {
                $dump .= '<span class="extbase-debug-filtered">max depth</span>';
            }
        } elseif ($level > 1 && !$object instanceof \DateTimeInterface && !$plainText) {
            if (($object instanceof \Countable && empty($object)) || empty($classReflection->getProperties())) {
                $dump = '<span>'.$dump.'</span>';
            } else {
                $dump = '<input type="checkbox" id="'.spl_object_hash($object).
                    '" /><span class="extbase-debug-header">'.$dump.'</span>';
            }
        }
        if ($object instanceof \Countable) {
            $objectCount = count($object);
            $dump .= $objectCount > 0 ? ' ('.$objectCount.' items)' : ' (empty)';
        }
        if ($object instanceof \DateTimeInterface) {
            $dump .= ' ('.$object->format('Y-m-d\TH:i:sP').', '.$object->getTimestamp().')';
        }

        return $dump;
    }

    /**
     * @param object $object
     * @param int    $level
     * @param bool   $plainText
     * @param bool   $ansiColors
     *
     * @return string The rendered body content of the Object(Storage)
     *
     * @throws \ReflectionException
     */
    protected static function renderContent($object, $level, $plainText, $ansiColors)
    {
        $dump = '';
        self::$renderedObjects[] = $object;
        if (!$plainText) {
            $dump .= '<a name="'.spl_object_hash($object).'" id="'.spl_object_hash($object).'"></a>';
        }
        if ($object instanceof \Closure) {
            $dump .= PHP_EOL.str_repeat(self::PLAINTEXT_INDENT, $level)
                .($plainText ? '' : '<span class="extbase-debug-closure">')
                .Utility\OutputUtility::ansiEscapeWrap('function (', '33', $ansiColors).($plainText ? '' : '</span>');

            $reflectionFunction = new \ReflectionFunction($object);
            $params = [];
            foreach ($reflectionFunction->getParameters() as $parameter) {
                $parameterDump = '';
                if ($parameter->isArray()) {
                    if ($plainText) {
                        $parameterDump .= Utility\OutputUtility::ansiEscapeWrap('array ', '36', $ansiColors);
                    } else {
                        $parameterDump .= '<span class="extbase-debug-type">array </span>';
                    }
                } elseif ($parameter->getClass()) {
                    if ($plainText) {
                        $parameterDump .= Utility\OutputUtility::ansiEscapeWrap(
                            $parameter->getClass()->name ?? ''.' ',
                            '36',
                            $ansiColors
                        );
                    } else {
                        $parameterDump .= '<span class="extbase-debug-type">'
                            .htmlspecialchars($parameter->getClass()->name ?? '').'</span>';
                    }
                }
                if ($parameter->isPassedByReference()) {
                    $parameterDump .= '&';
                }
                if ($parameter->isVariadic()) {
                    $parameterDump .= '...';
                }
                if ($plainText) {
                    $parameterDump .= Utility\OutputUtility::ansiEscapeWrap('$'.$parameter->name, '37', $ansiColors);
                } else {
                    $parameterDump .= '<span class="extbase-debug-property">'
                        .htmlspecialchars('$'.$parameter->name).'</span>';
                }
                if ($parameter->isDefaultValueAvailable()) {
                    $parameterDump .= ' = ';
                    if ($plainText) {
                        $parameterDump .= Utility\OutputUtility::ansiEscapeWrap(
                            var_export($parameter->getDefaultValue(), true),
                            '33',
                            $ansiColors
                        );
                    } else {
                        $parameterDump .= '<span class="extbase-debug-string">'
                            .htmlspecialchars(var_export($parameter->getDefaultValue(), true)).'</span>';
                    }
                }
                $params[] = $parameterDump;
            }
            $dump .= implode(', ', $params);
            if ($plainText) {
                $dump .= Utility\OutputUtility::ansiEscapeWrap(') {'.PHP_EOL, '33', $ansiColors);
            } else {
                $dump .= '<span class="extbase-debug-closure">) {'.PHP_EOL.'</span>';
            }
            $lines = file(strval($reflectionFunction->getFileName()));
            for ($l = $reflectionFunction->getStartLine(); $l < $reflectionFunction->getEndLine() - 1; ++$l) {
                $dump .= $plainText ? $lines[$l] : htmlspecialchars($lines[$l]);
            }
            $dump .= str_repeat(self::PLAINTEXT_INDENT, $level);
            if ($plainText) {
                $dump .= Utility\OutputUtility::ansiEscapeWrap('}'.PHP_EOL, '33', $ansiColors);
            } else {
                $dump .= '<span class="extbase-debug-closure">}</span>';
            }
        } else {
            if ('stdClass' === get_class($object)) {
                $objReflection = new \ReflectionObject($object);
                $properties = $objReflection->getProperties();
            } else {
                $classReflection = new \ReflectionClass(get_class($object));
                $properties = $classReflection->getProperties();
            }
            foreach ($properties as $property) {
                if (self::isBlacklisted($property)) {
                    continue;
                }
                $dump .= PHP_EOL.str_repeat(self::PLAINTEXT_INDENT, $level);
                if ($plainText) {
                    $dump .= Utility\OutputUtility::ansiEscapeWrap($property->getName(), '37', $ansiColors);
                } else {
                    $dump .= '<span class="extbase-debug-property">'
                        .htmlspecialchars($property->getName()).'</span>';
                }
                $dump .= ' => ';
                $property->setAccessible(true);
                $visibility = ($property->isProtected() ? 'protected' :
                    ($property->isPrivate() ? 'private' : 'public'));
                if ($plainText) {
                    $dump .= Utility\OutputUtility::ansiEscapeWrap($visibility, '42;30', $ansiColors).' ';
                } else {
                    $dump .= '<span class="extbase-debug-visibility">'.$visibility.'</span>';
                }
                $dump .= self::renderDump($property->getValue($object), $level, $plainText, $ansiColors);
            }
        }

        return $dump;
    }

    /**
     * @param mixed $collection
     * @param int   $level
     * @param bool  $plainText
     * @param bool  $ansiColors
     *
     * @return string
     *
     * @throws \ReflectionException
     */
    protected static function renderCollection($collection, $level, $plainText, $ansiColors)
    {
        $dump = '';
        foreach ($collection as $key => $value) {
            $dump .= PHP_EOL.str_repeat(self::PLAINTEXT_INDENT, $level);
            if ($plainText) {
                $dump .= Utility\OutputUtility::ansiEscapeWrap($key, '37', $ansiColors);
            } else {
                $dump .= '<span class="extbase-debug-property">'.htmlspecialchars($key).'</span>';
            }
            $dump .= ' => ';
            $dump .= self::renderDump($value, $level, $plainText, $ansiColors);
        }
        if ($collection instanceof \Iterator && !$collection instanceof \Generator) {
            $collection->rewind();
        }

        return $dump;
    }

    /**
     * A var_dump function optimized for Extbase's object structures.
     *
     * @param mixed  $variable                 The value to dump
     * @param string $title                    optional custom title for the debug output
     * @param int    $maxDepth                 Sets the max recursion depth of the dump. De- or increase the number
     *                                         according to your needs and memory limit.
     * @param bool   $plainText                if TRUE, the dump is in plain text, if FALSE the debug output is in HTML
     *                                         format
     * @param bool   $ansiColors               if TRUE (default), ANSI color codes is added to the output, if FALSE the
     *                                         debug output  not colored
     * @param bool   $return                   if TRUE, the dump is returned for custom post-processing (e.g. embed in
     *                                         custom HTML). If FALSE (default), the dump is directly displayed.
     * @param array  $blacklistedClassNames    An array of class names (RegEx) to be filtered. Default is an array of
     *                                         some common class names.
     * @param array  $blacklistedPropertyNames An array of property names and/or array keys (RegEx) to be filtered.
     *                                         Default is an array of some common property names.
     *
     * @return string if $return is TRUE, the dump is returned. By default, the dump is directly displayed, and nothing
     *                is returned.
     *
     * @throws \ReflectionException
     */
    public static function var_dump(
        $variable,
        $title = null,
        $maxDepth = 8,
        $plainText = false,
        $ansiColors = true,
        $return = false,
        $blacklistedClassNames = null,
        $blacklistedPropertyNames = null
    ) {
        self::$maxDepth = $maxDepth;
        $title = (null === $title ? self::DEFAULT_TITLE : $title);
        $ansiColors = $plainText && $ansiColors;
        $title = boolval($ansiColors) ? '[1m'.$title.'[0m' : $title;
        $backupBlacklistedClassNames = self::$blacklistedClassNames;
        if (is_array($blacklistedClassNames)) {
            self::$blacklistedClassNames = $blacklistedClassNames;
        }
        $backupBlacklistedPropertyNames = self::$blacklistedPropertyNames;
        if (is_array($blacklistedPropertyNames)) {
            self::$blacklistedPropertyNames = $blacklistedPropertyNames;
        }
        self::clearState();
        $css = '';
        if (!$plainText && false === self::$stylesheetEchoed) {
            $styleContent = file_get_contents(__DIR__.'/../res/style.css');
            $css = '<style type=\'text/css\'>'.$styleContent.'</style>';
            self::$stylesheetEchoed = true;
        }
        if ($plainText) {
            $output = $title.PHP_EOL.self::renderDump($variable, 0, true, $ansiColors).PHP_EOL.PHP_EOL;
        } else {
            $output = '
				<div class="extbase-debugger '.($return ? 'extbase-debugger-inline' : 'extbase-debugger-floating').'">
				<div class="extbase-debugger-top">'.htmlspecialchars($title).'</div>
				<div class="extbase-debugger-center">
					<pre dir="ltr">'.self::renderDump($variable, 0, false, false).'</pre>
				</div>
			</div>
			';
        }
        self::$blacklistedClassNames = $backupBlacklistedClassNames;
        self::$blacklistedPropertyNames = $backupBlacklistedPropertyNames;
        if (true === $return) {
            return $css.$output;
        }
        echo $css.$output;

        return '';
    }

    /**
     * @param mixed $object
     *
     * @return bool
     */
    private static function renderedObjectsContains($object)
    {
        foreach (self::$renderedObjects as $renderedObject) {
            if ($object == $renderedObject) {
                return true;
            }
        }

        return false;
    }
}
