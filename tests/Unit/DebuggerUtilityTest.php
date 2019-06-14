<?php

namespace OmegaCode\Tests\Unit;

/*
 * This file is part of the "t3-debugger-utility-standalone" library.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * (c) 2019 Wolf Utz
 */

use OmegaCode\DebuggerUtility;
use PHPUnit\Framework\TestCase;

/**
 * Class DebuggerUtilityTest.
 */
class DebuggerUtilityTest extends TestCase
{
    /**
     * @test
     */
    public function debugContainsObjectType()
    {
        $var = new \stdClass();

        $content = $this->callDebuggerUtility($var);

        $this->assertContains('stdClass', $content);
    }

    /**
     * @test
     */
    public function debugContainsTitle()
    {
        $title = 'My cool debug title';

        $content = $this->callDebuggerUtility([], $title);

        $this->assertContains($title, $content);
    }

    /**
     * @test
     */
    public function debugOnlyRendersTillSecondDepthLevel()
    {
        $maxDepthLevel = 2;
        $var = [
            'l2' => [
                'l3' => [
                    'l4' => [
                        'l5' => [],
                    ],
                ],
            ],
        ];

        $content = $this->callDebuggerUtility($var, 'title', $maxDepthLevel);

        $this->assertContains('l2', $content);
        $this->assertNotContains('l3', $content);
    }

    /**
     * @test
     */
    public function debugCanReturnPlainText()
    {
        $needle = 'extbase-debugger';
        $var = new \stdClass();

        $content = $this->callDebuggerUtility($var, 'title', 8, true);

        $this->assertNotContains($needle, $content);
    }

    /**
     * @test
     */
    public function debugCanBeReturnedAsString()
    {
        $var = new \stdClass();

        $content = DebuggerUtility::var_dump($var, 'title', 8, true, true, true);

        $this->assertContains('stdClass', $content);
    }

    /**
     * @test
     */
    public function debugExcludesBlacklistedClass()
    {
        $obj = new \stdClass();
        $obj->shouldNotSeeMe = true;
        $var = [
            'shouldSeeMe',
            $obj,
        ];

        $content = $this->callDebuggerUtility($var, 'title', 8, false, true, false, ['stdClass']);

        $this->assertContains('shouldSeeMe', $content);
        $this->assertNotContains('shouldNotSeeMe', $content);
    }

    /**
     * @test
     */
    public function debugExcludesBlacklistedProperties()
    {
        $var = new \stdClass();
        $var->shouldSeeMe = true;
        $var->shouldNotSeeMe = false;

        $content = $this->callDebuggerUtility($var, 'title', 8, false, true, false, null, ['shouldNotSeeMe']);

        $this->assertContains('shouldSeeMe', $content);
        $this->assertNotContains('shouldNotSeeMe', $content);
    }

    /**
     * @param mixed ...$args
     *
     * @return false|string
     */
    private function callDebuggerUtility(...$args)
    {
        ob_start();
        call_user_func_array('\\OmegaCode\\DebuggerUtility::var_dump', $args);
        $content = ob_get_contents();
        ob_clean();
        ob_end_flush();

        return $content;
    }
}
