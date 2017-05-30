<?php namespace Limoncello\Tests\Templates;

/**
 * Copyright 2015-2017 info@neomerx.com
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use Limoncello\Contracts\Settings\SettingsProviderInterface;
use Limoncello\Contracts\Templates\TemplatesInterface;
use Limoncello\Templates\Package\TemplatesContainerConfigurator;
use Limoncello\Templates\Package\TemplatesProvider;
use Limoncello\Templates\Package\TemplatesSettings;
use Limoncello\Tests\Templates\Data\Templates;
use Limoncello\Tests\Templates\Data\TestContainer;
use Mockery;
use Mockery\Mock;
use PHPUnit\Framework\TestCase;

/**
 * @package Limoncello\Tests\Templates
 */
class PackageTest extends TestCase
{
    /**
     * @inheritdoc
     */
    protected function tearDown()
    {
        parent::tearDown();

        Mockery::close();
    }

    /**
     * Test ContainerConfigurator.
     */
    public function testContainerConfigurator()
    {
        $settings = (new Templates())->get();

        /** @var Mock $settingsMock */
        $settingsMock = Mockery::mock(SettingsProviderInterface::class);
        $settingsMock->shouldReceive('get')->once()->with(TemplatesSettings::class)->andReturn($settings);

        $container = new TestContainer();

        $container[SettingsProviderInterface::class] = $settingsMock;

        TemplatesContainerConfigurator::configureContainer($container);

        $this->assertTrue($container->has(TemplatesInterface::class));
        $this->assertNotNull($container->get(TemplatesInterface::class));
    }

    /**
     * Test template provider.
     */
    public function testTemplateProvider()
    {
        $this->assertNotEmpty(TemplatesProvider::getContainerConfigurators());
        $this->assertNotEmpty(TemplatesProvider::getCommands());
    }

    /**
     * Test template settings.
     */
    public function testSettings()
    {
        $settings = (new Templates())->get();

        $this->assertNotEmpty($settings[Templates::KEY_CACHE_FOLDER]);
        $this->assertNotEmpty($settings[Templates::KEY_TEMPLATES_FOLDER]);
        $this->assertEquals(['test.html.twig'], $settings[Templates::KEY_TEMPLATES_LIST]);
    }
}