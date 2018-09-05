<?php

namespace Smichaelsen\MelonImages\Tests\Functional;

use Nimut\TestingFramework\TestCase\FunctionalTestCase;

class MyFunctionalTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function example()
    {
        $this->assertTrue(true);
        $this->importDataSet('ntf://Database/pages.xml');
        $this->importDataSet('ntf://Database/pages_language_overlay.xml');
        $this->importDataSet('ntf://Database/sys_file_storage.xml');
        $this->importDataSet('ntf://Database/sys_language.xml');
        $this->importDataSet('ntf://Database/tt_content.xml');
    }

}
