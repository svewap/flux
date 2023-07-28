<?php
namespace FluidTYPO3\Flux\Tests\Unit\Service;

/*
 * This file is part of the FluidTYPO3/Flux project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Flux\Core;
use FluidTYPO3\Flux\Form;
use FluidTYPO3\Flux\Provider\Interfaces\GridProviderInterface;
use FluidTYPO3\Flux\Provider\ProviderResolver;
use FluidTYPO3\Flux\Service\FluxService;
use FluidTYPO3\Flux\Tests\Fixtures\Classes\AccessibleCore;
use FluidTYPO3\Flux\Tests\Fixtures\Classes\DummyFluxService;
use FluidTYPO3\Flux\Tests\Fixtures\Data\Xml;
use FluidTYPO3\Flux\Tests\Unit\AbstractTestCase;
use FluidTYPO3\Flux\Utility\ExtensionConfigurationUtility;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Fluid\View\TemplatePaths;

/**
 * FluxServiceTest
 */
class FluxServiceTest extends AbstractTestCase
{
    /**
     * Setup
     */
    public function setup(): void
    {
        parent::setUp();

        $providers = Core::getRegisteredFlexFormProviders();
        if (true === in_array(FluxService::class, $providers)) {
            Core::unregisterConfigurationProvider('FluidTYPO3\Flux\Service\FluxService');
        }
    }

    /**
     * @dataProvider getConvertFileReferenceToTemplatePathAndFilenameTestValues
     * @param string $reference
     * @param string|NULL $resourceFactoryOutput
     * @param string $expected
     * @return void
     */
    public function testConvertFileReferenceToTemplatePathAndFilename($reference, $resourceFactoryOutput, $expected)
    {
        $instance = $this->getMockBuilder(FluxService::class)
            ->setMethods(['resolveAbsolutePathForFilename'])
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('resolveAbsolutePathForFilename')->willReturnArgument(0);
        if (null !== $resourceFactoryOutput) {
            $file = $this->getMockBuilder(File::class)->setMethods(['getIdentifier'])->disableOriginalConstructor()->getMock();
            $file->method('getIdentifier')->willReturn($resourceFactoryOutput);
            /** @var ResourceFactory|MockObject $resourceFactory */
            $resourceFactory = $this->getMockBuilder(
                ResourceFactory::class
            )->setMethods(
                ['getFileObjectFromCombinedIdentifier']
            )->disableOriginalConstructor()->getMock();
            $resourceFactory->expects($this->once())->method('getFileObjectFromCombinedIdentifier')
                ->with($reference)->willReturn($file);
            $this->setInaccessiblePropertyValue($instance, 'resourceFactory', $resourceFactory);
        }
        $result = $instance->convertFileReferenceToTemplatePathAndFilename($reference);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getConvertFileReferenceToTemplatePathAndFilenameTestValues()
    {
        $relativeReference = 'Tests/Fixtures/Templates/Page/Dummy.html';
        return [
            [$relativeReference, null, $relativeReference],
            ['1', $relativeReference, $relativeReference],
        ];
    }

    /**
     * @dataProvider getViewConfigurationByFileReferenceTestValues
     * @param string $reference
     * @param string $expectedParameter
     * @return void
     */
    public function testGetViewConfigurationByFileReference($reference, $expectedParameter)
    {
        $templatePaths = $this->getMockBuilder(TemplatePaths::class)->setMethods(['toArray'])->getMock();
        $templatePaths->method('toArray')->willReturn($expectedParameter);
        $instance = $this->getMockBuilder(FluxService::class)
            ->setMethods(['createTemplatePaths'])
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('createTemplatePaths')->willReturn($templatePaths);
        $result = $instance->getViewConfigurationByFileReference($reference);
        $this->assertEquals($expectedParameter, $result);
    }

    /**
     * @return array
     */
    public function getViewConfigurationByFileReferenceTestValues()
    {
        $fluxPaths = [
            'templateRootPaths' => ['Resources/Private/Templates/'],
            'partialRootPaths' => ['Resources/Private/Partials/'],
            'layoutRootPaths' => ['Resources/Private/Layouts/'],
        ];
        return [
            ['some/file', $fluxPaths],
            ['EXT:flux/some/file', $fluxPaths],
        ];
    }

    /**
     * @dataProvider getPageConfigurationInvalidTestValues
     * @param mixed $input
     * @return void
     */
    public function testGetPageConfigurationReturnsEmptyArrayOnInvalidInput($input)
    {
        $instance = $this->createFluxServiceInstance();
        $result = $instance->getPageConfiguration($input);
        $this->assertEquals([], $result);
    }

    /**
     * @return array
     */
    public function getPageConfigurationInvalidTestValues()
    {
        return [
            [''],
            [0],
        ];
    }

    public function testGetPageConfigurationReturnsEmptyArrayOnInvalidPlugAndPlayDirectorySetting(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY] = true;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY_DIRECTORY]
            = ['foo'];

        $instance = $this->createFluxServiceInstance();

        $result = $instance->getPageConfiguration('Flux');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'], $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']);

        self::assertEquals([], $result);
    }

    public function testGetPageConfigurationReturnsExpectedArrayOnPlugAndPlayDirectorySetting(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY] = true;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY_DIRECTORY]
            = './';

        $instance = $this->createFluxServiceInstance();

        $result = $instance->getPageConfiguration('Flux');
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'], $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']);

        self::assertEquals(
            [
                TemplatePaths::CONFIG_TEMPLATEROOTPATHS => ['/Templates/Page/'],
                TemplatePaths::CONFIG_PARTIALROOTPATHS => ['/Partials/'],
                TemplatePaths::CONFIG_LAYOUTROOTPATHS => ['/Layouts/'],
            ],
            $result
        );
    }

    public function testGetPageConfigurationReturnsExpectedArrayOnPlugAndPlayDirectorySettingWithForeignExt(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY] = true;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['flux'][ExtensionConfigurationUtility::OPTION_PLUG_AND_PLAY_DIRECTORY]
            = './';

        $templatePaths = $this->getMockBuilder(TemplatePaths::class)
            ->setMethods(['toArray'])
            ->disableOriginalConstructor()
            ->getMock();
        $templatePaths->method('toArray')->willReturn(['foo' => 'bar']);

        $instance = $this->getMockBuilder(FluxService::class)
            ->setMethods(['createTemplatePaths'])
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('createTemplatePaths')->willReturn($templatePaths);

        Core::registerProviderExtensionKey('FluidTYPO3.Testing', 'Page');
        $result = $instance->getPageConfiguration(null);
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS'], $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']);
        AccessibleCore::resetQueuedRegistrations();

        self::assertEquals(
            [
                'FluidTYPO3.Testing' => ['foo' => 'bar'],
                'FluidTYPO3.Flux' => [
                    TemplatePaths::CONFIG_TEMPLATEROOTPATHS => ['/Templates/Page/'],
                    TemplatePaths::CONFIG_PARTIALROOTPATHS => ['/Partials/'],
                    TemplatePaths::CONFIG_LAYOUTROOTPATHS => ['/Layouts/'],
                ],
            ],
            $result
        );
    }

    public function testGetPageConfigurationReturnsDefaultTemplatePaths(): void
    {
        $templatePaths = $this->getMockBuilder(TemplatePaths::class)
            ->setMethods(['toArray'])
            ->disableOriginalConstructor()
            ->getMock();
        $templatePaths->method('toArray')->willReturn(['foo' => 'bar']);

        $instance = $this->getMockBuilder(FluxService::class)
            ->setMethods(['createTemplatePaths'])
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('createTemplatePaths')->willReturn($templatePaths);

        $result = $instance->getPageConfiguration('Flux');

        self::assertEquals(['foo' => 'bar'], $result);
    }

    /**
     * @return void
     */
    public function testGetPageConfigurationWithoutExtensionNameReadsRegisteredProviders()
    {
        $templatePaths = new TemplatePaths();
        $instance = $this->getMockBuilder(FluxService::class)
            ->setMethods(['createTemplatePaths'])
            ->disableOriginalConstructor()
            ->getMock();
        $instance->method('createTemplatePaths')->willReturn($templatePaths);
        Core::registerProviderExtensionKey('foo', 'Page');
        Core::registerProviderExtensionKey('bar', 'Page');
        $result = $instance->getPageConfiguration();
        $this->assertCount(2, $result);
    }

    /**
     * @test
     * @dataProvider getSortObjectsTestValues
     * @param array $input
     * @param string $sortBy
     * @param string $direction
     * @param array $expectedOutput
     */
    public function testSortObjectsByProperty($input, $sortBy, $direction, $expectedOutput)
    {
        $service = $this->createFluxServiceInstance();
        $sorted = $service->sortObjectsByProperty($input, $sortBy, $direction);
        $this->assertSame($expectedOutput, $sorted);
    }

    /**
     * @return array
     */
    public function getSortObjectsTestValues()
    {
        return [
            [
                [['foo' => 'b'], ['foo' => 'a']],
                'foo', 'ASC',
                [1 => ['foo' => 'a'], 0 => ['foo' => 'b']]
            ],
            [
                ['a1' => ['foo' => 'b'], 'a2' => ['foo' => 'a']],
                'foo', 'ASC',
                ['a2' => ['foo' => 'a'], 'a1' => ['foo' => 'b']],
            ],
            [
                ['a1' => ['foo' => 'b'], 'a2' => ['foo' => 'a']],
                'foo', 'DESC',
                ['a1' => ['foo' => 'b'], 'a2' => ['foo' => 'a']],
            ],
        ];
    }

    /**
     * @test
     */
    public function canInstantiateFluxService()
    {
        $service = $this->createFluxServiceInstance();
        $this->assertInstanceOf(FluxService::class, $service);
    }

    /**
     * @test
     */
    public function canResolvePrimaryConfigurationProviderWithEmptyArray()
    {
        $service = $this->createFluxServiceInstance();

        GeneralUtility::setSingletonInstance(
            ProviderResolver::class,
            $this->getMockBuilder(ProviderResolver::class)->disableOriginalConstructor()->getMock()
        );

        $result = $service->resolvePrimaryConfigurationProvider('foobar', null);
        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function testGetTypoScriptByPath()
    {
        $service = $this->getMockBuilder(DummyFluxService::class)
            ->onlyMethods(['getRuntimeCache'])
            ->getMock();
        $service->method('getRuntimeCache')->willReturn(
            $this->getMockBuilder(FrontendInterface::class)->getMockForAbstractClass()
        );

        $configurationManager = $this->getMockBuilder(ConfigurationManagerInterface::class)->getMockForAbstractClass();
        $configurationManager->method('getConfiguration')->willReturn(
            [
                'plugin' => [
                    'tx_test' => [
                        'settings' => [
                            'foo' => 'bar',
                        ],
                    ],
                ],
            ]
        );

        $service->setServerRequest(new ServerRequest());
        $service->setConfigurationManager($configurationManager);

        $result = $service->getTypoScriptByPath('plugin.tx_test.settings');
        $this->assertEquals(['foo' => 'bar'], $result);
    }

    /**
     * @test
     */
    public function testGetTypoScriptByPathWhenCacheHasEntry()
    {
        $cache = $this->getMockBuilder(FrontendInterface::class)->getMockForAbstractClass();
        $cache->method('get')->willReturn(['test_var' => 'test_val']);

        $service = $this->getMockBuilder(FluxService::class)
            ->setMethods(['getRuntimeCache'])
            ->disableOriginalConstructor()
            ->getMock();
        $service->method('getRuntimeCache')->willReturn($cache);

        $result = $service->getTypoScriptByPath('plugin.tx_test.settings');
        $this->assertEquals(['test_var' => 'test_val'], $result);
    }

    /**
     * @test
     */
    public function testGetSettingsForExtensionName()
    {
        $instance = $this->getMockBuilder(FluxService::class)
            ->setMethods(['getTypoScriptByPath'])
            ->disableOriginalConstructor()
            ->getMock();
        $instance->expects($this->once())->method('getTypoScriptByPath')
            ->with('plugin.tx_underscore.settings')
            ->willReturn(['test' => 'test']);
        $result = $instance->getSettingsForExtensionName('under_score');
        $this->assertEquals(['test' => 'test'], $result);
    }

    /**
     * @test
     * @dataProvider getConvertFlexFormContentToArrayTestValues
     * @param string $flexFormContent
     * @param Form|NULL $form
     * @param string|NULL $languagePointer
     * @param string|NULL $valuePointer
     * @param array $expected
     */
    public function testConvertFlexFormContentToArray($flexFormContent, $form, $languagePointer, $valuePointer, $expected)
    {
        /** @var class-string $serviceClassName */
        $serviceClassName = class_exists(FlexFormService::class) ? FlexFormService::class : \TYPO3\CMS\Extbase\Service\FlexFormService::class;
        $flexFormService = $this->getMockBuilder($serviceClassName)->setMethods(['convertFlexFormContentToArray'])->disableOriginalConstructor()->getMock();
        $flexFormService->method('convertFlexFormContentToArray')->willReturn($expected);
        $instance = $this->createFluxServiceInstance();
        $instance->setFlexFormService($flexFormService);

        $result = $instance->convertFlexFormContentToArray($flexFormContent, $form, $languagePointer, $valuePointer);
        $this->assertEquals($expected, $result);
    }

    /**
     * @return array
     */
    public function getConvertFlexFormContentToArrayTestValues()
    {
        $form = $this->getMockBuilder(Form::class)->setMethods(['dummy'])->getMock();
        return [
            ['', null, '', '', []],
            ['', $form, '', '', []],
            [Xml::SIMPLE_FLEXFORM_SOURCE_DEFAULT_SHEET_ONE_FIELD, $form, '', '', ['settings' => ['input' => 0]]]
        ];
    }

    public function testConvertFlexFormContentToArrayWithTransform(): void
    {
        $expected = [
            'foo' => 'bar',
        ];

        $flexFormContent = 'abc';
        $languagePointer = null;
        $valuePointer = null;

        $form = Form::create();
        $form->setOption(Form::OPTION_TRANSFORM, true);

        $instance = $this->createFluxServiceInstance();

        /** @var class-string $serviceClassName */
        $serviceClassName = class_exists(FlexFormService::class)
            ? FlexFormService::class
            : \TYPO3\CMS\Extbase\Service\FlexFormService::class;
        $flexFormService = $this->getMockBuilder($serviceClassName)
            ->setMethods(['convertFlexFormContentToArray'])
            ->disableOriginalConstructor()
            ->getMock();
        $flexFormService->method('convertFlexFormContentToArray')->willReturn($expected);

        $dataTransformer = $this->getMockBuilder(Form\Transformation\FormDataTransformer::class)
            ->setMethods(['transformAccordingToConfiguration'])
            ->disableOriginalConstructor()
            ->getMock();
        $dataTransformer->method('transformAccordingToConfiguration')->willReturnArgument(0);

        $instance->setFlexFormService($flexFormService);
        $instance->setFormDataTransformer($dataTransformer);

        $result = $instance->convertFlexFormContentToArray($flexFormContent, $form, $languagePointer, $valuePointer);
        $this->assertEquals($expected, $result);
    }


    public function testResolveConfigurationProviders(): void
    {
        $subject = $this->createFluxServiceInstance();

        $resolver = $this->getMockBuilder(ProviderResolver::class)
            ->setMethods(['resolveConfigurationProviders'])
            ->disableOriginalConstructor()
            ->getMock();
        $resolver->expects(self::once())
            ->method('resolveConfigurationProviders')
            ->with('table', 'field', ['uid' => 123], 'ext', [GridProviderInterface::class])
            ->willReturn([]);

        $subject->setProviderResolver($resolver);

        $subject->resolveConfigurationProviders(
            'table',
            'field',
            ['uid' => 123],
            'ext',
            [GridProviderInterface::class]
        );
    }

    public function testSetInCaches(): void
    {
        $runtimeCache = $this->getMockBuilder(FrontendInterface::class)->getMockForAbstractClass();
        $persistentCache = $this->getMockBuilder(FrontendInterface::class)->getMockForAbstractClass();

        $runtimeCache->expects(self::once())->method('set')->with('flux-ec10e0c7a344da191700ab4ace1a5e26', 'foobar');
        $persistentCache->expects(self::once())->method('set')->with('flux-ec10e0c7a344da191700ab4ace1a5e26', 'foobar');

        $subject = $this->getMockBuilder(FluxService::class)
            ->onlyMethods(['getRuntimeCache', 'getPersistentCache'])
            ->disableOriginalConstructor()
            ->getMock();
        $subject->method('getRuntimeCache')->willReturn($runtimeCache);
        $subject->method('getPersistentCache')->willReturn($persistentCache);

        $subject->setInCaches('foobar', true, 'a', 'b', 'c');
    }

    public function testGetFromCaches(): void
    {
        $runtimeCache = $this->getMockBuilder(FrontendInterface::class)->getMockForAbstractClass();
        $persistentCache = $this->getMockBuilder(FrontendInterface::class)->getMockForAbstractClass();

        $runtimeCache->expects(self::once())
            ->method('get')
            ->with('flux-ec10e0c7a344da191700ab4ace1a5e26')
            ->willReturn(false);
        $persistentCache->expects(self::once())
            ->method('get')
            ->with('flux-ec10e0c7a344da191700ab4ace1a5e26')
            ->willReturn('foobar');

        $subject = $this->getMockBuilder(FluxService::class)
            ->setMethods(['getRuntimeCache', 'getPersistentCache'])
            ->disableOriginalConstructor()
            ->getMock();
        $subject->method('getRuntimeCache')->willReturn($runtimeCache);
        $subject->method('getPersistentCache')->willReturn($persistentCache);

        $output = $subject->getFromCaches('a', 'b', 'c');
        self::assertSame('foobar', $output);
    }
}
