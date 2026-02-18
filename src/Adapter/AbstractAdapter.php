<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Adapter;

use Omines\DataTablesBundle\Adapter\ArrayResultSet;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * AbstractAdapter.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
abstract class AbstractAdapter implements AdapterInterface
{
    protected readonly PropertyAccessor $accessor;

    public function __construct()
    {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    final public function getData(DataTableState $state, bool $raw = false): ResultSetInterface
    {
        $query = new AdapterQuery($state);

        $this->prepareQuery($query);
        $propertyMap = $this->getPropertyMap($query);

        $transformer = $state->getDataTable()->getTransformer();
        $identifier = $query->getIdentifierPropertyPath();

        try {
            $data = (function () use ($query, $identifier, $transformer, $propertyMap, $raw, $state) {
                foreach ($this->getResults($query) as $result) {
                    $row = [];
                    if (!empty($identifier)) {
                        $row['DT_RowId'] = $this->accessor->getValue($result, $identifier);
                    }

                    /** @var AbstractColumn $column */
                    foreach ($propertyMap as list($column, $mapping)) {
                        if ($state->getExporterName()) {
                            // Export context
                            if ($column->getName() == 'id' || ($column->isVisible() && $column->isExportable())) {
                                $value = ($mapping && $this->accessor->isReadable($result, $mapping)) ? $this->accessor->getValue($result, $mapping) : null;
                                $row[$column->getName()] = $column->transform($value, $result, false, false);
                            }
                        } else {
                            // Display context
                            if ($column->getName() == 'id' || $column->isVisible()) {
                                $value = ($mapping && $this->accessor->isReadable($result, $mapping)) ? $this->accessor->getValue($result, $mapping) : null;
                                $row[$column->getName()] = $column->transform($value, $result);
                            } else {
                                $row[$column->getName()] = '<i class="fas fa-circle-notch fa-spin"></i>';
                            }
                        }
                    }
                    if (null !== $transformer) {
                        $transformed = call_user_func($transformer, $row, $result);
                        if ($transformed === false || $transformed === null) {
                            // Skip row if transformer returned false or null
                            continue;
                        }
                        $row = $transformed;
                    }
                    yield $row;
                }
            })();
        } catch(\Exception $e) {
            return new ArrayResultSet($rows, $query->getTotalRows(), $query->getFilteredRows(), $e->getMessage(), $query->getTotalSummary());
        }

        if (null === $query->getTotalRows() || null === $query->getFilteredRows()) {
            throw new \LogicException('Adapter did not set row counts');
        }

        return new ResultSet($data, $query->getTotalRows(), $query->getFilteredRows(), null, $query->getTotalSummary());
    }

    /**
     * @return array{AbstractColumn, ?string}[]
     */
    protected function getPropertyMap(AdapterQuery $query): array
    {
        $propertyMap = [];
        foreach ($query->getState()->getDataTable()->getColumns() as $column) {
            $propertyMap[] = [$column, $column->getPropertyPath() ?? (empty($column->getField()) ? null : $this->mapPropertyPath($query, $column))];
        }

        return $propertyMap;
    }

    abstract protected function prepareQuery(AdapterQuery $query): void;

    abstract protected function mapPropertyPath(AdapterQuery $query, AbstractColumn $column): ?string;

    /**
     * @return \Traversable<mixed[]>
     */
    abstract protected function getResults(AdapterQuery $query): \Traversable;
}
