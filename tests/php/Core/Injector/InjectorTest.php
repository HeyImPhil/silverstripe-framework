<?php

namespace SilverStripe\Core\Tests\Injector;

use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\InjectorNotFoundException;
use SilverStripe\Core\Injector\SilverStripeServiceConfigurationLocator;
use SilverStripe\Core\Tests\Injector\AopProxyServiceTest\AnotherService;
use SilverStripe\Core\Tests\Injector\AopProxyServiceTest\SampleService;
use SilverStripe\Core\Tests\Injector\InjectorTest\CircularOne;
use SilverStripe\Core\Tests\Injector\InjectorTest\CircularTwo;
use SilverStripe\Core\Tests\Injector\InjectorTest\ConstructableObject;
use SilverStripe\Core\Tests\Injector\InjectorTest\DummyRequirements;
use SilverStripe\Core\Tests\Injector\InjectorTest\EmptyFactory;
use SilverStripe\Core\Tests\Injector\InjectorTest\MyChildClass;
use SilverStripe\Core\Tests\Injector\InjectorTest\MyParentClass;
use SilverStripe\Core\Tests\Injector\InjectorTest\NeedsBothCirculars;
use SilverStripe\Core\Tests\Injector\InjectorTest\NewRequirementsBackend;
use SilverStripe\Core\Tests\Injector\InjectorTest\OriginalRequirementsBackend;
use SilverStripe\Core\Tests\Injector\InjectorTest\OtherTestObject;
use SilverStripe\Core\Tests\Injector\InjectorTest\TestObject;
use SilverStripe\Core\Tests\Injector\InjectorTest\TestSetterInjections;
use SilverStripe\Core\Tests\Injector\InjectorTest\TestStaticInjections;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Dev\TestOnly;
use stdClass;

define('TEST_SERVICES', __DIR__ . '/AopProxyServiceTest');

/**
 * Tests for the dependency injector
 *
 * Note that these are SS conversions of the existing Simpletest unit tests
 *
 * @author      marcus@silverstripe.com.au
 * @license     BSD License http://silverstripe.org/bsd-license/
 * @skipUpgrade
 */
class InjectorTest extends SapphireTest
{

    protected $nestingLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nestingLevel = 0;
    }

    protected function tearDown(): void
    {

        while ($this->nestingLevel > 0) {
            $this->nestingLevel--;
            Config::unnest();
        }

        parent::tearDown();
    }

    public function testCorrectlyInitialised()
    {
        $injector = Injector::inst();
        $this->assertTrue(
            $injector->getConfigLocator() instanceof SilverStripeServiceConfigurationLocator,
            'Failure most likely because the injector has been referenced BEFORE being initialised in Core.php'
        );
    }

    public function testBasicInjector()
    {
        $injector = new Injector();
        $injector->setAutoScanProperties(true);
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
            ]
        ];

        $injector->load($config);


        $this->assertFalse($injector->has('UnknownService'));
        $this->assertNull($injector->getServiceName('UnknownService'));

        $this->assertTrue($injector->has('SampleService'));
        $this->assertEquals(
            'SampleService',
            $injector->getServiceName('SampleService')
        );

        $myObject = new TestObject();
        $injector->inject($myObject);

        $this->assertInstanceOf(
            SampleService::class,
            $myObject->sampleService
        );
    }

    public function testEmptyFactory()
    {
        $this->expectException(InjectorNotFoundException::class);
        $injector = new Injector();
        $services = [
            'SomeClass' => [
                'class' => AnotherService::class,
                'factory' => EmptyFactory::class,
            ]
        ];

        $injector->load($services);
        $injector->create('SomeClass');
    }

    /**
     * Fail creating object by factory that does not implement Factory
     * interface.
     */
    public function testNotFactoryInterfaceFactory()
    {
        $this->expectException(\InvalidArgumentException::class);

        $injector = new Injector([
            'service' => [
                'factory' => 'stdClass',
            ],
        ]);
        $injector->get('service');
    }

    public function testConfiguredInjector()
    {
        $injector = new Injector();
        $services = [
            'AnotherService' => [
                'class' => AnotherService::class,
                'src' => TEST_SERVICES . '/AnotherService.php',
                'properties' => ['config_property' => 'Value'],
            ],
            'SampleService' => [
                'class' => SampleService::class,
                'src' => TEST_SERVICES . '/SampleService.php',
            ]
        ];

        $injector->load($services);
        $this->assertTrue($injector->has('SampleService'));
        $this->assertEquals(
            'SampleService',
            $injector->getServiceName('SampleService')
        );
        // We expect a false because the AnotherService::class is actually
        // just a replacement of the SilverStripe\Core\Tests\Injector\AopProxyServiceTest\SampleService
        $this->assertTrue($injector->has('SampleService'));
        $this->assertEquals(
            'AnotherService',
            $injector->getServiceName('AnotherService')
        );

        $item = $injector->get('AnotherService');

        $this->assertEquals('Value', $item->config_property);
    }

    public function testIdToNameMap()
    {
        $injector = new Injector();
        $services = [
            'FirstId' => AnotherService::class,
            'SecondId' => SampleService::class,
        ];

        $injector->load($services);

        $this->assertTrue($injector->has('FirstId'));
        $this->assertEquals($injector->getServiceName('FirstId'), 'FirstId');

        $this->assertTrue($injector->has('SecondId'));
        $this->assertEquals($injector->getServiceName('SecondId'), 'SecondId');

        $this->assertTrue($injector->get('FirstId') instanceof AnotherService);
        $this->assertTrue($injector->get('SecondId') instanceof SampleService);
    }

    public function testReplaceService()
    {
        $injector = new Injector();
        $injector->setAutoScanProperties(true);

        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
            ]
        ];

        // load
        $injector->load($config);

        // inject
        $myObject = new TestObject();
        $injector->inject($myObject);

        $this->assertInstanceOf(
            SampleService::class,
            $myObject->sampleService
        );

        // also tests that ID can be the key in the array
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/AnotherService.php',
                'class' => AnotherService::class,
            ]
        ];
        // , 'id' => SampleService::class));
        // load
        $injector->load($config);

        $injector->inject($myObject);
        $this->assertInstanceOf(
            AnotherService::class,
            $myObject->sampleService
        );
    }

    public function testUpdateSpec()
    {
        $injector = new Injector();
        $services = [
            AnotherService::class => [
                'src' => TEST_SERVICES . '/AnotherService.php',
                'properties' => [
                    'filters' => [
                        'One',
                        'Two',
                    ]
                ],
            ]
        ];

        $injector->load($services);

        $injector->updateSpec(AnotherService::class, 'filters', 'Three');
        $another = $injector->get(AnotherService::class);

        $this->assertEquals(3, count($another->filters ?? []));
        $this->assertEquals('Three', $another->filters[2]);
    }

    public function testConstantUsage()
    {
        $injector = new Injector();
        $services = [
            AnotherService::class => [
                'properties' => [
                    'filters' => [
                        '`BASE_PATH`',
                        '`TEMP_PATH`',
                        '`NOT_DEFINED`',
                        'THIRDPARTY_DIR' // Not back-tick escaped
                    ]
                ],
            ]
        ];

        $injector->load($services);
        $another = $injector->get(AnotherService::class);
        $this->assertEquals(
            [
                BASE_PATH,
                TEMP_PATH,
                null,
                'THIRDPARTY_DIR',
            ],
            $another->filters
        );
    }

    public function testAutoSetInjector()
    {
        $injector = new Injector();
        $injector->setAutoScanProperties(true);
        $injector->addAutoProperty('auto', 'somevalue');
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class
            ]
        ];
        $injector->load($config);

        $this->assertTrue($injector->has('SampleService'));
        $this->assertEquals(
            'SampleService',
            $injector->getServiceName('SampleService')
        );
        // We expect a false because the AnotherService::class is actually
        // just a replacement of the SilverStripe\Core\Tests\Injector\AopProxyServiceTest\SampleService

        $myObject = new InjectorTest\TestObject();

        $injector->inject($myObject);

        $this->assertInstanceOf(
            SampleService::class,
            $myObject->sampleService
        );
        $this->assertEquals($myObject->auto, 'somevalue');
    }

    public function testSettingSpecificProperty()
    {
        $injector = new Injector();
        $config = [AnotherService::class];
        $injector->load($config);
        $injector->setInjectMapping(TestObject::class, 'sampleService', AnotherService::class);
        $testObject = $injector->get(TestObject::class);

        $this->assertInstanceOf(
            AnotherService::class,
            $testObject->sampleService
        );
    }

    public function testSettingSpecificMethod()
    {
        $injector = new Injector();
        $config = [AnotherService::class];
        $injector->load($config);
        $injector->setInjectMapping(TestObject::class, 'setSomething', AnotherService::class, 'method');

        $testObject = $injector->get(TestObject::class);

        $this->assertInstanceOf(
            AnotherService::class,
            $testObject->sampleService
        );
    }

    public function testInjectingScopedService()
    {
        $injector = new Injector();

        $config = [
            AnotherService::class,
            'SilverStripe\Core\Tests\Injector\AopProxyServiceTest\AnotherService.DottedChild'   => SampleService::class,
        ];

        $injector->load($config);

        $service = $injector->get('SilverStripe\Core\Tests\Injector\AopProxyServiceTest\AnotherService.DottedChild');
        $this->assertInstanceOf(SampleService::class, $service);

        $service = $injector->get('SilverStripe\Core\Tests\Injector\AopProxyServiceTest\AnotherService.Subset');
        $this->assertInstanceOf(AnotherService::class, $service);

        $injector->setInjectMapping(TestObject::class, 'sampleService', 'SilverStripe\Core\Tests\Injector\AopProxyServiceTest\AnotherService.Geronimo');
        $testObject = $injector->create(TestObject::class);
        $this->assertEquals(get_class($testObject->sampleService), AnotherService::class);

        $injector->setInjectMapping(TestObject::class, 'sampleService', 'SilverStripe\Core\Tests\Injector\AopProxyServiceTest\AnotherService.DottedChild.AnotherDown');
        $testObject = $injector->create(TestObject::class);
        $this->assertEquals(get_class($testObject->sampleService), SampleService::class);
    }

    public function testInjectUsingConstructor()
    {
        $injector = new Injector();
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
                'constructor' => [
                    'val1',
                    'val2',
                ]
            ]
        ];

        $injector->load($config);
        $sample = $injector->get('SampleService');
        $this->assertEquals($sample->constructorVarOne, 'val1');
        $this->assertEquals($sample->constructorVarTwo, 'val2');

        $injector = new Injector();
        $config = [
            'AnotherService' => AnotherService::class,
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
                'constructor' => [
                    'val1',
                    '%$AnotherService',
                ]
            ]
        ];

        $injector->load($config);
        $sample = $injector->get('SampleService');
        $this->assertEquals($sample->constructorVarOne, 'val1');
        $this->assertInstanceOf(
            AnotherService::class,
            $sample->constructorVarTwo
        );

        $injector = new Injector();
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
                'constructor' => [
                    'val1',
                    'val2',
                ]
            ]
        ];

        $injector->load($config);
        $sample = $injector->get('SampleService');
        $this->assertEquals($sample->constructorVarOne, 'val1');
        $this->assertEquals($sample->constructorVarTwo, 'val2');

        // test constructors on prototype
        $injector = new Injector();
        $config = [
            'SampleService' => [
                'type'  => 'prototype',
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
                'constructor' => [
                    'val1',
                    'val2',
                ]
            ]
        ];

        $injector->load($config);
        $sample = $injector->get('SampleService');
        $this->assertEquals($sample->constructorVarOne, 'val1');
        $this->assertEquals($sample->constructorVarTwo, 'val2');

        $again = $injector->get('SampleService');
        $this->assertFalse($sample === $again);

        $this->assertEquals($sample->constructorVarOne, 'val1');
        $this->assertEquals($sample->constructorVarTwo, 'val2');
    }

    public function testInjectUsingSetter()
    {
        $injector = new Injector();
        $injector->setAutoScanProperties(true);
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
            ]
        ];

        $injector->load($config);
        $this->assertTrue($injector->has('SampleService'));
        $this->assertEquals('SampleService', $injector->getServiceName('SampleService'));

        $myObject = new InjectorTest\OtherTestObject();
        $injector->inject($myObject);

        $this->assertInstanceOf(
            SampleService::class,
            $myObject->s()
        );

        // and again because it goes down a different code path when setting things
        // based on the inject map
        $myObject = new InjectorTest\OtherTestObject();
        $injector->inject($myObject);

        $this->assertInstanceOf(
            SampleService::class,
            $myObject->s()
        );
    }

    // make sure we can just get any arbitrary object - it should be created for us
    public function testInstantiateAnObjectViaGet()
    {
        $injector = new Injector();
        $injector->setAutoScanProperties(true);
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
            ]
        ];

        $injector->load($config);
        $this->assertTrue($injector->has('SampleService'));
        $this->assertEquals('SampleService', $injector->getServiceName('SampleService'));

        $myObject = $injector->get(OtherTestObject::class);
        $this->assertInstanceOf(
            SampleService::class,
            $myObject->s()
        );

        // and again because it goes down a different code path when setting things
        // based on the inject map
        $myObject = $injector->get(OtherTestObject::class);
        $this->assertInstanceOf(SampleService::class, $myObject->s());
    }

    public function testCircularReference()
    {
        $services = [
            'CircularOne' => CircularOne::class,
            'CircularTwo' => CircularTwo::class
        ];
        $injector = new Injector($services);
        $injector->setAutoScanProperties(true);

        $obj = $injector->get(NeedsBothCirculars::class);

        $this->assertTrue($obj->circularOne instanceof InjectorTest\CircularOne);
        $this->assertTrue($obj->circularTwo instanceof InjectorTest\CircularTwo);
    }

    public function testPrototypeObjects()
    {
        $services = [
            'CircularOne' => CircularOne::class,
            'CircularTwo' => CircularTwo::class,
            'NeedsBothCirculars' => [
                'class' => NeedsBothCirculars::class,
                'type' => 'prototype'
            ]
        ];
        $injector = new Injector($services);
        $injector->setAutoScanProperties(true);
        $obj1 = $injector->get('NeedsBothCirculars');
        $obj2 = $injector->get('NeedsBothCirculars');

        // if this was the same object, then $obj1->var would now be two
        $obj1->var = 'one';
        $obj2->var = 'two';

        $this->assertTrue($obj1->circularOne instanceof InjectorTest\CircularOne);
        $this->assertTrue($obj1->circularTwo instanceof InjectorTest\CircularTwo);

        $this->assertEquals($obj1->circularOne, $obj2->circularOne);
        $this->assertNotEquals($obj1, $obj2);
    }

    public function testSimpleInstantiation()
    {
        $services = [
            'CircularOne' => CircularOne::class,
            'CircularTwo' => CircularTwo::class
        ];
        $injector = new Injector($services);

        // similar to the above, but explicitly instantiating this object here
        $obj1 = $injector->create(NeedsBothCirculars::class);
        $obj2 = $injector->create(NeedsBothCirculars::class);

        // if this was the same object, then $obj1->var would now be two
        $obj1->var = 'one';
        $obj2->var = 'two';

        $this->assertEquals($obj1->circularOne, $obj2->circularOne);
        $this->assertNotEquals($obj1, $obj2);
    }

    public function testCreateWithConstructor()
    {
        $injector = new Injector();
        $obj = $injector->create(CircularTwo::class, 'param');
        $this->assertEquals($obj->otherVar, 'param');
    }

    public function testSimpleSingleton()
    {
        $injector = new Injector();

        $one = $injector->create(CircularOne::class);
        $two = $injector->create(CircularOne::class);

        $this->assertFalse($one === $two);

        $one = $injector->get(CircularTwo::class);
        $two = $injector->get(CircularTwo::class);

        $this->assertTrue($one === $two);
    }

    public function testOverridePriority()
    {
        $injector = new Injector();
        $injector->setAutoScanProperties(true);
        $config = [
            'SampleService' => [
                'src' => TEST_SERVICES . '/SampleService.php',
                'class' => SampleService::class,
                'priority' => 10,
            ]
        ];

        // load
        $injector->load($config);

        // inject
        $myObject = new InjectorTest\TestObject();
        $injector->inject($myObject);

        $this->assertInstanceOf(SampleService::class, $myObject->sampleService);

        $config = [
            [
                'src' => TEST_SERVICES . '/AnotherService.php',
                'class' => AnotherService::class,
                'id' => 'SampleService',
                'priority' => 1,
            ]
        ];
        // load
        $injector->load($config);

        $injector->inject($myObject);
        $this->assertInstanceOf(
            SampleService::class,
            $myObject->sampleService
        );
    }

    /**
     * Specific test method to illustrate various ways of setting a requirements backend
     */
    public function testRequirementsSettingOptions()
    {
        $injector = new Injector();
        $config = [
            OriginalRequirementsBackend::class,
            NewRequirementsBackend::class,
            DummyRequirements::class => [
                'constructor' => [
                    '%$' . OriginalRequirementsBackend::class
                ]
            ]
        ];

        $injector->load($config);

        $requirements = $injector->get(DummyRequirements::class);
        $this->assertInstanceOf(
            OriginalRequirementsBackend::class,
            $requirements->backend
        );

        // just overriding the definition here
        $injector->load(
            [
            DummyRequirements::class => [
                'constructor' => [
                    '%$' . NewRequirementsBackend::class
                ]
            ]
            ]
        );

        // requirements should have been reinstantiated with the new bean setting
        $requirements = $injector->get(DummyRequirements::class);
        $this->assertInstanceOf(
            NewRequirementsBackend::class,
            $requirements->backend
        );
    }

    /**
     * disabled for now
     */
    public function testStaticInjections()
    {
        $injector = new Injector();
        $config = [
            NewRequirementsBackend::class,
        ];

        $injector->load($config);

        $si = $injector->get(TestStaticInjections::class);
        $this->assertInstanceOf(
            NewRequirementsBackend::class,
            $si->backend
        );
    }

    public function testSetterInjections()
    {
        $injector = new Injector();
        $config = [
            NewRequirementsBackend::class,
        ];

        $injector->load($config);

        $si = $injector->get(TestSetterInjections::class);
        $this->assertInstanceOf(
            NewRequirementsBackend::class,
            $si->getBackend()
        );
    }

    public function testCustomObjectCreator()
    {
        $injector = new Injector();
        $injector->setObjectCreator(new InjectorTest\SSObjectCreator($injector));
        $config = [
            OriginalRequirementsBackend::class,
            DummyRequirements::class => [
                'class' => DummyRequirements::class . '(\'%$' . OriginalRequirementsBackend::class . '\')'
            ]
        ];
        $injector->load($config);

        $requirements = $injector->get(DummyRequirements::class);
        $this->assertEquals(OriginalRequirementsBackend::class, get_class($requirements->backend));
    }

    public function testInheritedConfig()
    {

        // Test that child class does not automatically inherit config
        $injector = new Injector(['locator' => SilverStripeServiceConfigurationLocator::class]);
        Config::modify()->merge(
            Injector::class,
            MyParentClass::class,
            [
            'properties' => ['one' => 'the one'],
            'class' => MyParentClass::class,
            ]
        );
        $obj = $injector->get(MyParentClass::class);
        $this->assertInstanceOf(MyParentClass::class, $obj);
        $this->assertEquals($obj->one, 'the one');

        // Class isn't inherited and parent properties are ignored
        $obj = $injector->get(MyChildClass::class);
        $this->assertInstanceOf(MyChildClass::class, $obj);
        $this->assertNotEquals($obj->one, 'the one');

        // Set child class as alias
        $injector = new Injector(
            [
            'locator' => SilverStripeServiceConfigurationLocator::class
            ]
        );
        Config::modify()->merge(
            Injector::class,
            MyChildClass::class,
            '%$' . MyParentClass::class
        );

        // Class isn't inherited and parent properties are ignored
        $obj = $injector->get(MyChildClass::class);
        $this->assertInstanceOf(MyParentClass::class, $obj);
        $this->assertEquals($obj->one, 'the one');
    }

    public function testSameNamedSingeltonPrototype()
    {
        $injector = new Injector();

        // get a singleton object
        $object = $injector->get(NeedsBothCirculars::class);
        $object->var = 'One';

        $again = $injector->get(NeedsBothCirculars::class);
        $this->assertEquals($again->var, 'One');

        // create a NEW instance object
        $new = $injector->create(NeedsBothCirculars::class);
        $this->assertNull($new->var);

        // this will trigger a problem below
        $new->var = 'Two';

        $again = $injector->get(NeedsBothCirculars::class);
        $this->assertEquals($again->var, 'One');
    }

    public function testConvertServicePropertyOnCreate()
    {
        // make sure convert service property is not called on direct calls to create, only on configured
        // declarations to avoid un-needed function calls
        $injector = new Injector();
        $item = $injector->create(ConstructableObject::class, '%$' . TestObject::class);
        $this->assertEquals('%$' . TestObject::class, $item->property);

        // do it again but have test object configured as a constructor dependency
        $injector = new Injector();
        $config = [
            ConstructableObject::class => [
                'constructor' => [
                    '%$' . TestObject::class
                ]
            ]
        ];

        $injector->load($config);
        $item = $injector->get(ConstructableObject::class);
        $this->assertTrue($item->property instanceof InjectorTest\TestObject);

        // and with a configured object defining TestObject to be something else!
        $injector = new Injector(['locator' => InjectorTest\InjectorTestConfigLocator::class]);
        $config = [
            ConstructableObject::class => [
                'constructor' => [
                    '%$' . TestObject::class
                ]
            ],
        ];

        $injector->load($config);
        $item = $injector->get(ConstructableObject::class);
        $this->assertTrue($item->property instanceof InjectorTest\ConstructableObject);

        $this->assertInstanceOf(OtherTestObject::class, $item->property->property);
    }

    /**
     * @dataProvider provideConvertServicePropertyBackTicks
     */
    public function testConvertServicePropertyBackTicks($value, $expected)
    {
        Environment::setEnv('INJECTOR_TEST_CSP_A', 'ABC');
        Environment::setEnv('INJECTOR_TEST_CSP_B', 'DEF');
        Environment::setEnv('INJECTOR_TEST_CSP_C', 'GHI');
        $actual = Injector::inst()->convertServiceProperty($value);
        $this->assertSame($expected, $actual);
    }

    public function provideConvertServicePropertyBackTicks()
    {
        return [
            ['`INJECTOR_TEST_CSP_A`', 'ABC'],
            ['`INJECTOR_TEST_CSP_A`:`INJECTOR_TEST_CSP_B`', 'ABC:DEF'],
            ['`INJECTOR_TEST_CSP_A` some text `INJECTOR_TEST_CSP_B`', 'ABC some text DEF'],
            ['`INJECTOR_TEST_CSP_A``INJECTOR_TEST_CSP_B`', 'ABCDEF'],
            ['`INJECTOR_TEST_CSP_A`:`INJECTOR_TEST_CSP_B``INJECTOR_TEST_CSP_C`', 'ABC:DEFGHI'],
            ['`INJECTOR_TEST_CSP_A`:`INJECTOR_TEST_CSP_X`', 'ABC:'],
            ['`INJECTOR_TEST_CSP_X`', null],
            ['lorem `INJECTOR_TEST_CSP_A` ipsum', 'lorem `INJECTOR_TEST_CSP_A` ipsum'],
        ];
    }

    public function testNamedServices()
    {
        $injector = new Injector();
        $service  = new TestObject();
        $service->setSomething('injected');

        // Test registering with non-class name
        $injector->registerService($service, 'NamedService');
        $this->assertTrue($injector->has('NamedService'));
        $this->assertEquals($service, $injector->get('NamedService'));

        // Unregister service by name
        $injector->unregisterNamedObject('NamedService');
        $this->assertFalse($injector->has('NamedService'));

        // Test registered with class name
        $injector->registerService($service);
        $this->assertTrue($injector->has(TestObject::class));
        $this->assertEquals($service, $injector->get(TestObject::class));

        // Unregister service by class
        $injector->unregisterNamedObject(TestObject::class);
        $this->assertFalse($injector->has(TestObject::class));
    }

    public function testCreateConfiggedObjectWithCustomConstructorArgs()
    {
        // need to make sure that even if the config defines some constructor params,
        // that we take our passed in constructor args instead
        $injector = new Injector(['locator' => InjectorTest\InjectorTestConfigLocator::class]);

        $item = $injector->create('ConfigConstructor', 'othervalue');
        $this->assertEquals($item->property, 'othervalue');
    }

    /**
     * Tests creating a service with a custom factory.
     */
    public function testCustomFactory()
    {
        $injector = new Injector(
            [
            'service' => ['factory' => 'factory', 'constructor' => [1, 2, 3]]
            ]
        );

        $factory = $this->getMockBuilder(Factory::class)->getMock();
        $factory
            ->expects($this->once())
            ->method('create')
            ->with($this->equalTo('service'), $this->equalTo([1, 2, 3]))
            ->will(
                $this->returnCallback(
                    function ($args) {
                        return new InjectorTest\TestObject();
                    }
                )
            );

        $injector->registerService($factory, 'factory');

        $this->assertInstanceOf(TestObject::class, $injector->get('service'));
    }

    /**
     * Creating object by factory method.
     */
    public function testByFactoryMethodObjectCreator()
    {
        // Dummy service giving DateTime of tommorow.
        $injector = new Injector([
            'service' => [
                'factory' => 'DateTime',
                'factory_method' => 'add',
                'constructor' => ['%$DateInterval'],
            ],
            'DateInterval' => [
                'constructor' => ['P1D'],
            ],
        ]);

        $this->assertInstanceOf(\DateTime::class, $injector->get('service'));
        $this->assertEquals(
            (new \DateTime())->add(new \DateInterval('P1D'))->format('%Y%m%d'),
            $injector->get('service')->format('%Y%m%d')
        );
    }

    /**
     * Creating object by static factory method.
     */
    public function testByStaticFactoryMethodObjectCreator()
    {
        // Dummy service changing any callable to injector service with
        // `strtoupper` as default one. Constructor disallows instantiation.
        $injector = new Injector([
            'service' => [
                'factory' => 'Closure',
                'factory_method' => 'fromCallable',
                'constructor' => ['strtoupper'],
            ],
        ]);

        $this->assertInstanceOf(\Closure::class, $injector->get('service'));

        // Default service.
        $this->assertEquals('ABC', $injector->get('service')('abc'));

        // Create service with arguments.
        $this->assertEquals('abc', $injector->create('service', 'strtolower')('ABC'));
    }

    public function testFactoryMethodNotReturnsObject()
    {
        $this->expectException(InjectorNotFoundException::class);

        $injector = new Injector([
            'service' => [
                'factory' => 'DateTime',
                'factory_method' => 'getTimeStamp',
            ],
        ]);
        $injector->get('service');
    }

    public function testFactoryMethodNotExists()
    {
        $this->expectException(\InvalidArgumentException::class);

        $injector = new Injector([
            'service' => [
                'factory' => 'stdClass',
                'factory_method' => 'method',
            ],
        ]);
        $injector->get('service');
    }

    public function testMethods()
    {
        // do it again but have test object configured as a constructor dependency
        $injector = new Injector();
        $config = [
            'A' => [
                'class' => TestObject::class,
            ],
            'B' => [
                'class' => TestObject::class,
            ],
            'TestService' => [
                'class' => TestObject::class,
                'calls' => [
                    ['myMethod', ['%$A']],
                    ['myMethod', ['%$B']],
                    ['noArgMethod']
                ]
            ]
        ];

        $injector->load($config);
        $item = $injector->get('TestService');
        $this->assertTrue($item instanceof InjectorTest\TestObject);
        $this->assertEquals(
            [$injector->get('A'), $injector->get('B'), 'noArgMethod called'],
            $item->methodCalls
        );
    }

    public function testNonExistentMethods()
    {
        $this->expectException(\InvalidArgumentException::class);
        $injector = new Injector();
        $config = [
            'TestService' => [
                'class' => TestObject::class,
                'calls' => [
                    ['thisDoesntExist']
                ]
            ]
        ];

        $injector->load($config);
        $item = $injector->get('TestService');
    }

    public function testProtectedMethods()
    {
        $this->expectException(\InvalidArgumentException::class);
        $injector = new Injector();
        $config = [
            'TestService' => [
                'class' => TestObject::class,
                'calls' => [
                    ['protectedMethod']
                ]
            ]
        ];

        $injector->load($config);
        $item = $injector->get('TestService');
    }

    public function testTooManyArrayValues()
    {
        $this->expectException(\InvalidArgumentException::class);
        $injector = new Injector();
        $config = [
            'TestService' => [
                'class' => TestObject::class,
                'calls' => [
                    ['method', ['args'], 'what is this?']
                ]
            ]
        ];

        $injector->load($config);
        $item = $injector->get('TestService');
    }

    public function testGetThrowsOnNotFound()
    {
        $this->expectException(InjectorNotFoundException::class);
        $injector = new Injector();
        $injector->get('UnknownService');
    }

    public function testGetTrimsWhitespaceFromNames()
    {
        $injector = new Injector;

        $this->assertInstanceOf(MyChildClass::class, $injector->get('    ' . MyChildClass::class . '     '));
    }

    /**
     * Test nesting of injector
     */
    public function testNest()
    {

        // Outer nest to avoid interference with other
        Injector::nest();
        $this->nestingLevel++;

        // Test services
        $config = [
            NewRequirementsBackend::class,
        ];
        Injector::inst()->load($config);
        $si = Injector::inst()->get(TestStaticInjections::class);
        $this->assertInstanceOf(TestStaticInjections::class, $si);
        $this->assertInstanceOf(NewRequirementsBackend::class, $si->backend);
        $this->assertInstanceOf(MyParentClass::class, Injector::inst()->get(MyParentClass::class));
        $this->assertInstanceOf(MyChildClass::class, Injector::inst()->get(MyChildClass::class));

        // Test that nested injector values can be overridden
        Injector::nest();
        $this->nestingLevel++;
        Injector::inst()->unregisterObjects([
            TestStaticInjections::class,
            MyParentClass::class,
        ]);
        $newsi = Injector::inst()->get(TestStaticInjections::class);
        $newsi->backend = new InjectorTest\OriginalRequirementsBackend();
        Injector::inst()->registerService($newsi, TestStaticInjections::class);
        Injector::inst()->registerService(new InjectorTest\MyChildClass(), MyParentClass::class);

        // Check that these overridden values are retrievable
        $si = Injector::inst()->get(TestStaticInjections::class);
        $this->assertInstanceOf(TestStaticInjections::class, $si);
        $this->assertInstanceOf(OriginalRequirementsBackend::class, $si->backend);
        $this->assertInstanceOf(MyParentClass::class, Injector::inst()->get(MyParentClass::class));
        $this->assertInstanceOf(MyParentClass::class, Injector::inst()->get(MyChildClass::class));

        // Test that unnesting restores expected behaviour
        Injector::unnest();
        $this->nestingLevel--;
        $si = Injector::inst()->get(TestStaticInjections::class);
        $this->assertInstanceOf(TestStaticInjections::class, $si);
        $this->assertInstanceOf(NewRequirementsBackend::class, $si->backend);
        $this->assertInstanceOf(MyParentClass::class, Injector::inst()->get(MyParentClass::class));
        $this->assertInstanceOf(MyChildClass::class, Injector::inst()->get(MyChildClass::class));

        // Test reset of cache
        Injector::inst()->unregisterObjects([
            TestStaticInjections::class,
            MyParentClass::class,
        ]);
        $si = Injector::inst()->get(TestStaticInjections::class);
        $this->assertInstanceOf(TestStaticInjections::class, $si);
        $this->assertInstanceOf(NewRequirementsBackend::class, $si->backend);
        $this->assertInstanceOf(MyParentClass::class, Injector::inst()->get(MyParentClass::class));
        $this->assertInstanceOf(MyChildClass::class, Injector::inst()->get(MyChildClass::class));

        // Return to nestingLevel 0
        Injector::unnest();
        $this->nestingLevel--;
    }
}
