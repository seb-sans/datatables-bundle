<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Adapter\Doctrine\ORM;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;

class AutomaticQueryBuilder implements QueryBuilderProcessorInterface
{
    private const DEFAULT_ALIAS = 'entity';

    private EntityManagerInterface $em;

    /** @var ClassMetadata<object> */
    private ClassMetadata $metadata;

    private string $entityShortName;

    /** @var class-string */
    private string $entityName;

    /** @var array<string, string[]> */
    private array $selectColumns = [];

    /** @var array<string, string[]> */
    private array $joins = [];

    /**
     * @param ClassMetadata<object> $metadata
     */
    public function __construct(EntityManagerInterface $em, ClassMetadata $metadata)
    {
        $this->em = $em;
        $this->metadata = $metadata;

        $this->entityName = $this->metadata->getName();
        $this->entityShortName = mb_strtolower($metadata->reflClass?->getShortName() ?? self::DEFAULT_ALIAS);
    }

    public function process(QueryBuilder $builder, DataTableState $state): void
    {
        if (empty($this->selectColumns) && empty($this->joins)) {
            foreach ($state->getDataTable()->getColumns() as $column) {
                if ($column->isVisible() || $column->getName() == 'id') {
                    $this->processColumn($column);
                }
            }
        }

        $this->handleDQL($builder, $state);
        $this->handleOrderBy($builder, $state);

        $builder->from($this->entityName, $this->entityShortName);
        $this->setSelectFrom($builder);
        $this->setJoins($builder);
    }

    protected function processColumn(AbstractColumn $column): void
    {
        $field = $column->getField();

        // Default to the column name if that corresponds to a field mapping
        if (!isset($field) && isset($this->metadata->fieldMappings[$column->getName()])) {
            $field = $column->getName();
        }
        if (null !== $field) {
            $savedOrderField = $column->getOption('orderField');
            $this->addSelectColumns($column, $field);
            if ($savedOrderField) {
                $column->setOption('orderField', $savedOrderField);
            }
        }
    }

    private function addSelectColumns(AbstractColumn $column, string $field): void
    {
        $currentPart = $this->entityShortName;
        $currentAlias = $currentPart;
        $metadata = $this->metadata;

        $parts = explode('.', $field);

        if (count($parts) > 1 && $parts[0] === $currentPart) {
            array_shift($parts);
        }

        if (sizeof($parts) > 1 && $field = $metadata->hasField(implode('.', $parts))) {
            $this->addSelectColumn($currentAlias, implode('.', $parts));
        } else {
            while (count($parts) > 1) {
                $previousPart = $currentPart;
                $previousAlias = $currentAlias;
                $currentPart = array_shift($parts);
                $currentAlias = ($previousPart === $this->entityShortName ? '' : $previousPart . '_') . $currentPart;

                $this->joins[$previousAlias . '.' . $currentPart] = ['alias' => $currentAlias, 'type' => 'join'];

                // Read field alias
                $tokens = explode('.', $column->getField());
                $lastToken = array_pop($tokens);
                $column->setOption('orderField', $currentAlias.'.'.$lastToken);
                $column->setOption('leftExpr', $currentAlias.'.'.$lastToken);

                $metadata = $this->setIdentifierFromAssociation($currentAlias, $currentPart, $metadata);
            }

            $this->addSelectColumn($currentAlias, $this->getIdentifier($metadata));
            $this->addSelectColumn($currentAlias, $parts[0]);
        }
    }

    private function addSelectColumn(string $columnTableName, string $data): void
    {
        if (isset($this->selectColumns[$columnTableName])) {
            if (!in_array($data, $this->selectColumns[$columnTableName], true)) {
                $this->selectColumns[$columnTableName][] = $data;
            }
        } else {
            $this->selectColumns[$columnTableName][] = $data;
        }
    }

    public function addJoinColumns(\Omines\DataTablesBundle\Column\AbstractColumn $column, string $field)
    {
        $currentPart = $this->entityShortName;
        $currentAlias = $currentPart;
        $metadata = $this->metadata;

        $parts = explode('.', $field);

        if (count($parts) > 1 && $parts[0] === $currentPart) {
            array_shift($parts);
        }

        if (sizeof($parts) > 1 && $field = $metadata->hasField(implode('.', $parts))) {
            // do nothing
        } else {
            while (count($parts) > 1) {
                $previousPart = $currentPart;
                $previousAlias = $currentAlias;
                $currentPart = array_shift($parts);
                $currentAlias = ($previousPart === $this->entityShortName ? '' : $previousPart . '_') . $currentPart;
                if ($this->prefix) {
                    $currentAlias = $this->prefix . '_' . $currentAlias;
                }

                $this->joins[$previousAlias . '.' . $currentPart] = ['alias' => $currentAlias, 'type' => 'leftJoin'];

                // Read field alias
                $tokens = explode('.', $column->getField());
                $lastToken = array_pop($tokens);
                $column->setOption('orderField', $currentAlias.'.'.$lastToken);
                $column->setOption('leftExpr', $currentAlias.'.'.$lastToken);

                $metadata = $this->setIdentifierFromAssociation($currentAlias, $currentPart, $metadata);
            }
        }
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function getIdentifier(ClassMetadata $metadata): string
    {
        $identifiers = $metadata->getIdentifierFieldNames();

        return array_shift($identifiers) ?? throw new \LogicException(sprintf('Class %s has no identifiers', $metadata->getName()));
    }

    /**
     * @param ClassMetadata<object> $metadata
     * @return ClassMetadata<object>
     */
    private function setIdentifierFromAssociation(string $association, string $key, ClassMetadata $metadata): ClassMetadata
    {
        $targetEntityClass = $metadata->getAssociationTargetClass($key);

        /** @var ClassMetadata<object> $targetMetadata */
        $targetMetadata = $this->em->getMetadataFactory()->getMetadataFor($targetEntityClass);
        $this->addSelectColumn($association, $this->getIdentifier($targetMetadata));

        return $targetMetadata;
    }

    private function setSelectFrom(QueryBuilder $qb): void
    {
        foreach ($this->selectColumns as $key => $value) {
            $qb->addSelect($key ?: $value);
        }
    }

    private function setJoins(QueryBuilder $qb): void
    {
        foreach ($this->joins as $key => $value) {
            $qb->{$value['type']}($key, $value['alias']);
        }
    }

    protected function handleDQL(QueryBuilder $builder, DataTableState $state): void
    {
        // Remove comments + trim
        $advancedSearch = trim(preg_replace("/\/\*[\s\S]*?\*\/|([^\\:]|^)\/\/.*$/m", "$1", $state->getDQL()));

        if (!$advancedSearch) {
            return ;
        }

        foreach ($state->getDataTable()->getColumns() as $column) {
            if ($column->getField()) {
                $search = '#'.$column->getName().'#';
                $replacement = $column->getField();

                if (strstr($advancedSearch, $search)) {
                    $this->processColumn($column);
                    if ($column->getOption('orderField')) {
                        $replacement = $column->getOption('orderField');
                    }
                    $advancedSearch = str_replace($search, $replacement, $advancedSearch);
                }
            }
        }

        // Special #id# column
        $search = '#id#';
        $replacement = $this->entityShortName.'.id';
        $advancedSearch = str_replace($search, $replacement, $advancedSearch);

        // SmartMatch
        preg_match_all('/@\S+@/', $advancedSearch, $matches);
        if ($matches) {
            foreach ($matches[0] as $match) {
                $field = substr($match, 1, -1);
                $replacement = '';

                $currentPart = $this->entityShortName;
                $currentAlias = $currentPart;
                $metadata = $this->metadata;

                $parts = explode('.', $field);

                if (count($parts) > 1 && $parts[0] === $currentPart) {
                    array_shift($parts);
                }

                if (sizeof($parts) > 1 && $field = $metadata->hasField(implode('.', $parts))) {
                    $this->addSelectColumn($currentAlias, implode('.', $parts));
                    $replacement = $currentAlias.'.'.implode('.', $parts);
                } else {
                    while (count($parts) > 1) {
                        $previousPart = $currentPart;
                        $previousAlias = $currentAlias;
                        $currentPart = array_shift($parts);
                        $currentAlias = ($previousPart === $this->entityShortName ? '' : $previousPart . '_') . $currentPart;

                        $this->joins[$previousAlias . '.' . $currentPart] = ['alias' => $currentAlias, 'type' => 'leftJoin'];

                        $metadata = $this->setIdentifierFromAssociation($currentAlias, $currentPart, $metadata);
                    }

                    $this->addSelectColumn($currentAlias, $this->getIdentifier($metadata));
                    $this->addSelectColumn($currentAlias, $parts[0]);
                    $replacement = $currentAlias.'.'.$parts[0];
                }
                if ($replacement) {
                    $advancedSearch = str_replace($match, $replacement, $advancedSearch);
                }
            }
        }


        if ($advancedSearch) {
            $builder->andWhere('(' . $advancedSearch . ')');
        }
    }

    protected function handleOrderBy(QueryBuilder $builder, DataTableState $state): void
    {
        foreach ($state->getOrderBy() as $orderBy) {
            $this->processColumn($orderBy[0]);
        }
    }

    public function getJoins(): array
    {
        return $this->joins;
    }
}
