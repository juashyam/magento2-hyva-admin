<?php declare(strict_types=1);

namespace Hyva\Admin\Test\Integration\Model\GridSource;

use Hyva\Admin\Model\GridSource\SearchCriteriaBindings;
use Hyva\Admin\Model\GridSource\UnableToFetchPropertyFromValueException;
use Magento\Framework\Api\Filter;
use Magento\Framework\Api\Search\FilterGroup;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\RequestInterface;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

use function array_filter as filter;
use function array_map as map;
use function array_merge as merge;
use function array_values as values;

class SearchCriteriaBindingsTest extends TestCase
{
    private function getObjectManager(): ObjectManager
    {
        /** @var ObjectManager $objectManager */
        $objectManager = ObjectManager::getInstance();

        return $objectManager;
    }

    private function createSearchCriteriaBindings(array $config): SearchCriteriaBindings
    {
        /** @var SearchCriteriaBindings $sut */
        $sut = ObjectManager::getInstance()->create(SearchCriteriaBindings::class, ['bindingsConfig' => $config]);

        return $sut;
    }

    private function assertHasFilter($field, $value, $condition, SearchCriteriaInterface $searchCriteria): void
    {
        $groups  = $searchCriteria->getFilterGroups();
        $filters = merge([], ...values(map(fn(FilterGroup $group): array => $group->getFilters(), $groups)));

        /** @var Filter[] $filtersForField */
        $filtersForField = values(filter($filters, fn(Filter $filter): bool => $filter->getField() === $field));
        if (!$filtersForField) {
            $this->fail(sprintf('No filter found for field: "%s"', $field));
        }
        $match    = $filtersForField[0];
        $expected = ['field' => $field, 'value' => $value, 'condition' => $condition];
        $actual   = ['field' => $field, 'value' => $match->getValue(), 'condition' => $match->getConditionType()];

        $this->assertSame($expected, $actual, sprintf('Filter does not match expectation.'));
    }

    /**
     * This method is used as a getter in tests
     */
    public function getTestMethodValue($arg = 'no argument')
    {
        return $arg;
    }

    public function testNoBindingConfigIsHandledGracefully(): void
    {
        $sut = $this->createSearchCriteriaBindings([]);

        $searchCriteria = new SearchCriteria();
        $sut->apply($searchCriteria);

        $this->assertEmpty($searchCriteria->getFilterGroups());
    }

    public function testGetterWithNoArguments(): void
    {
        $field          = 'test';
        $bindingsConfig = [
            'field'  => $field,
            'method' => __CLASS__ . '::getTestMethodValue',
        ];
        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, $this->getTestMethodValue(), 'eq', $searchCriteria);
    }

    public function testGetterWithArgument(): void
    {
        $field          = 'test';
        $bindingsConfig = [
            'field'  => $field,
            'method' => __CLASS__ . '::getTestMethodValue',
            'param'  => 123,
        ];
        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, $this->getTestMethodValue(123), 'eq', $searchCriteria);
    }

    public function testGetterWithArgumentAndProperty(): void
    {
        $field          = 'test';
        $bindingsConfig = [
            'field'    => $field,
            'method'   => __CLASS__ . '::getTestMethodValue',
            'param'    => ['foo' => 'bar'],
            'property' => 'foo',
        ];
        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, 'bar', 'eq', $searchCriteria);
    }

    public function testFetchesRequestParam(): void
    {
        $requestParamValue = 'foo';
        $field             = 'test';
        $bindingsConfig    = [
            'field'        => $field,
            'requestParam' => 'id',
        ];

        $stubRequest = $this->createMock(RequestInterface::class);
        $stubRequest->method('getParam')->with('id')->willReturn($requestParamValue);
        $this->getObjectManager()->addSharedInstance($stubRequest, Request::class);

        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, $requestParamValue, 'eq', $searchCriteria);
    }

    public function testClassWithMethodOverrideRequestParam(): void
    {
        $requestParamValue = 'requestParamValue';
        $field             = 'test';
        $bindingsConfig    = [
            'field'        => $field,
            'requestParam' => 'id',
            'method'       => __CLASS__ . '::getTestMethodValue',
            'param'        => 'class method value',
        ];

        $stubRequest = $this->createMock(RequestInterface::class);
        $stubRequest->method('getParam')->with('id')->willReturn($requestParamValue);
        $this->getObjectManager()->addSharedInstance($stubRequest, Request::class);

        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, $bindingsConfig['param'], 'eq', $searchCriteria);
    }

    public function testUsesConditionTypeFromConfig(): void
    {
        $field          = 'test';
        $bindingsConfig = [
            'field'     => $field,
            'method'    => __CLASS__ . '::getTestMethodValue',
            'condition' => 'finset',
        ];
        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, $this->getTestMethodValue(), 'finset', $searchCriteria);
    }

    public function testAppliesMultipleFieldBindings(): void
    {
        $requestParamValue = 111;
        $bindingsConfigs   = [
            [
                'field'        => 'field_a',
                'requestParam' => 'id',
            ],
            [
                'field'     => 'field_b',
                'method'    => __CLASS__ . '::getTestMethodValue',
                'param'     => '%abc%',
                'condition' => 'like',
            ],
        ];

        $stubRequest = $this->createMock(RequestInterface::class);
        $stubRequest->method('getParam')->with('id')->willReturn($requestParamValue);
        $this->getObjectManager()->addSharedInstance($stubRequest, Request::class);

        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings($bindingsConfigs);
        $sut->apply($searchCriteria);
        $this->assertHasFilter('field_a', 111, 'eq', $searchCriteria);
        $this->assertHasFilter('field_b', '%abc%', 'like', $searchCriteria);
    }

    public function testThrowsExceptionForNonArrayOrObjectPropertyConfiguration(): void
    {
        $field          = 'test';
        $bindingsConfig = [
            'field'    => $field,
            'method'   => __CLASS__ . '::getTestMethodValue',
            'param'    => 'a string has no properties to access here',
            'property' => 'prop',
        ];
        $searchCriteria = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings([$bindingsConfig]);
        $this->expectException(UnableToFetchPropertyFromValueException::class);
        $sut->apply($searchCriteria);
    }

    public function testThrowsExceptionForNonExistingObjectPropertyAccessor(): void
    {
        $field           = 'test';
        $bindingsConfigs = [
            [
                'field'    => $field,
                'method'   => __CLASS__ . '::getTestMethodValue',
                'param'    => new class() {

                },
                'property' => 'prop',
            ],
        ];
        $searchCriteria  = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings($bindingsConfigs);
        $this->expectException(UnableToFetchPropertyFromValueException::class);
        $sut->apply($searchCriteria);
    }

    public function testFetchesObjectPropertiesByGetter(): void
    {
        $field           = 'test';
        $bindingsConfigs = [
            [
                'field'    => $field,
                'method'   => __CLASS__ . '::getTestMethodValue',
                'param'    => new class() {
                    public function getProp(): string
                    {
                        return 'yay!';
                    }
                },
                'property' => 'prop',
            ],
        ];
        $searchCriteria  = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings($bindingsConfigs);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, 'yay!', 'eq', $searchCriteria);
    }

    public function testFetchesObjectPropertiesByGetData(): void
    {
        $field           = 'test';
        $bindingsConfigs = [
            [
                'field'    => $field,
                'method'   => __CLASS__ . '::getTestMethodValue',
                'param'    => new class() {
                    public function getData($key): string
                    {
                        return $key === 'prop'
                            ? 'ok'
                            : 'not ok';
                    }
                },
                'property' => 'prop',
            ],
        ];
        $searchCriteria  = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings($bindingsConfigs);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, 'ok', 'eq', $searchCriteria);
    }

    public function testFetchesObjectPropertiesByArrayAccess(): void
    {
        $field           = 'test';
        $bindingsConfigs = [
            [
                'field'    => $field,
                'method'   => __CLASS__ . '::getTestMethodValue',
                'param'    => new class() implements \ArrayAccess {
                    public function offsetExists($offset)
                    {
                        return $offset === 'prop';
                    }

                    public function offsetGet($offset)
                    {
                        return $offset === 'prop' ? 'ok' : 'not ok';
                    }

                    public function offsetSet($offset, $value)
                    {
                        // not needed for test
                    }

                    public function offsetUnset($offset)
                    {
                        // not needed for test
                    }
                },
                'property' => 'prop',
            ],
        ];
        $searchCriteria  = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings($bindingsConfigs);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, 'ok', 'eq', $searchCriteria);
    }

    public function testFetchesObjectPropertiesByProperty(): void
    {
        $field           = 'test';
        $bindingsConfigs = [
            [
                'field'    => $field,
                'method'   => __CLASS__ . '::getTestMethodValue',
                'param'    => new class() {
                    public $prop = 'fine :)';
                },
                'property' => 'prop',
            ],
        ];
        $searchCriteria  = new SearchCriteria();

        $sut = $this->createSearchCriteriaBindings($bindingsConfigs);
        $sut->apply($searchCriteria);
        $this->assertHasFilter($field, 'fine :)', 'eq', $searchCriteria);
    }
}
