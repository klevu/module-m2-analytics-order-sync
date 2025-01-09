<?php

/**
 * Copyright © Klevu Oy. All rights reserved. See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Klevu\AnalyticsOrderSync\Test\Integration;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Reader as DeploymentConfigReader;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Module\ModuleList;
use Magento\Framework\ObjectManagerInterface;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;

class ModuleConfigurationTest extends TestCase
{
    private const MODULE_NAME = 'Klevu_AnalyticsOrderSync';

    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $objectManager = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManager = ObjectManager::getInstance();
    }

    public function testModuleIsRegistered(): void
    {
        $registrar = $this->objectManager->create(ComponentRegistrar::class);
        $modulePaths = $registrar->getPaths(ComponentRegistrar::MODULE);

        $this->assertArrayHasKey(self::MODULE_NAME, $modulePaths);
    }

    public function testModuleIsEnabledInTheTestEnvironment(): void
    {
        $moduleList = $this->objectManager->create(ModuleList::class);

        $this->assertTrue($moduleList->has(self::MODULE_NAME));
    }

    public function testModuleIsEnabledInTheRealEnvironment(): void
    {
        $dirList = $this->objectManager->create(DirectoryList::class, [
            'root' => BP,
        ]);
        $configReader = $this->objectManager->create(DeploymentConfigReader::class, [
            'dirList' => $dirList,
        ]);
        $deploymentConfig = $this->objectManager->create(DeploymentConfig::class, [
            'reader' => $configReader,
        ]);
        $moduleList = $this->objectManager->create(ModuleList::class, [
            'config' => $deploymentConfig,
        ]);

        $this->assertTrue($moduleList->has(self::MODULE_NAME));
    }
}
