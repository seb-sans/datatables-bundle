<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Omines\DataTablesBundle\Adapter\AbstractAdapter;
use Omines\DataTablesBundle\Adapter\AdapterQuery;
use Omines\DataTablesBundle\Adapter\Doctrine\ORMAdapter;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTable;
use Omines\DataTablesBundle\DataTableState;
use Omines\DataTablesBundle\Exception\InvalidConfigurationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * AdapterTest.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class AdapterTest extends KernelTestCase
{
    public function testInvalidEntity(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Doctrine has no valid entity manager for entity "foobar"');

        /** @var Registry $registryMock */
        $registryMock = $this->createMock(Registry::class);
        $adapter = new ORMAdapter($registryMock);
        $adapter->configure([
            'entity' => 'foobar',
        ]);
    }

    public function testPrepareQueryExceptionReturnsErrorResultSet(): void
    {
        $adapter = new class extends AbstractAdapter {
            public function configure(array $options): void
            {
            }

            protected function prepareQuery(AdapterQuery $query): void
            {
                throw new \RuntimeException('Invalid advanced search');
            }

            protected function mapPropertyPath(AdapterQuery $query, AbstractColumn $column): ?string
            {
                return null;
            }

            protected function getResults(AdapterQuery $query): \Traversable
            {
                yield from [];
            }
        };

        $resultSet = $adapter->getData(new DataTableState($this->createMock(DataTable::class)));

        $this->assertSame(0, $resultSet->getTotalRecords());
        $this->assertSame(0, $resultSet->getTotalDisplayRecords());
        $this->assertSame('Invalid advanced search', $resultSet->getError());
        $this->assertSame([], iterator_to_array($resultSet->getData()));
    }
}
